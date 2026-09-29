<?php
/**
 * XML sitemap endpoint.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves a controllable XML sitemap at /asi-sitemap.xml.
 */
class AgentSteamer_AI_Sitemap {

	/**
	 * Max URLs per sitemap shard (the spec limit is 50000).
	 */
	const SHARD_SIZE = 5000;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'rewrites' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'render' ), 1 );
		add_filter( 'redirect_canonical', array( $this, 'prevent_canonical' ), 10, 2 );
		add_filter( 'wp_sitemaps_enabled', array( $this, 'maybe_disable_core' ) );
	}

	/**
	 * Do not let canonical redirects interfere with the endpoint.
	 *
	 * @param string|false $redirect  Redirect URL.
	 * @param string       $requested Requested URL.
	 * @return string|false
	 */
	public function prevent_canonical( $redirect, $requested ) {
		if ( get_query_var( 'asi_sitemap' ) ) {
			return false;
		}
		return $redirect;
	}

	/**
	 * Register rewrite rule.
	 */
	public function rewrites() {
		add_rewrite_rule( '^asi-sitemap\.xml$', 'index.php?asi_sitemap=1', 'top' );
	}

	/**
	 * Register query var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = 'asi_sitemap';
		$vars[] = 'asi_sitemap_local';
		$vars[] = 'asi_sitemap_shard';
		return $vars;
	}

	/**
	 * Disable core sitemaps when our own is enabled.
	 *
	 * @param bool $enabled Whether enabled.
	 * @return bool
	 */
	public function maybe_disable_core( $enabled ) {
		if ( agentsteamer_ai_get_option( 'sitemap_enabled', 1 ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Configured extra (other-site) sitemap URLs, one per line.
	 *
	 * @return array
	 */
	protected function extra_sitemaps() {
		$raw = (string) agentsteamer_ai_get_option( 'sitemap_extra' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$url = esc_url_raw( $line );
			if ( $url ) {
				$out[] = $url;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * All local URLs (site home, published posts, public term archives).
	 *
	 * @return array
	 */
	protected function local_urls() {
		$urls = array();

		$home_mod = get_lastpostmodified( 'GMT' );
		$urls[]   = array(
			'loc'     => home_url( '/' ),
			'lastmod' => $home_mod ? mysql2date( 'c', $home_mod, false ) : gmdate( 'c' ),
		);

		foreach ( agentsteamer_ai_supported_post_types() as $type ) {
			$offset = 0;
			$per    = 500;
			do {
				$posts = get_posts(
					array(
						'post_type'        => $type,
						'post_status'      => 'publish',
						'numberposts'      => $per,
						'offset'           => $offset,
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'suppress_filters' => false,
						'no_found_rows'    => true,
					)
				);
				foreach ( $posts as $post ) {
					if ( '1' === agentsteamer_ai_get_post_meta( $post->ID, 'noindex', '0' ) ) {
						continue;
					}
					$urls[] = array(
						'loc'     => get_permalink( $post ),
						'lastmod' => mysql2date( 'c', $post->post_modified_gmt, false ),
					);
				}
				$offset += $per;
			} while ( count( $posts ) === $per );
		}

		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$offset = 0;
			$per    = 500;
			do {
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => true,
						'number'     => $per,
						'offset'     => $offset,
					)
				);
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					break;
				}
				foreach ( $terms as $term ) {
					$loc = get_term_link( $term );
					if ( is_wp_error( $loc ) ) {
						continue;
					}
					$urls[] = array(
						'loc'     => $loc,
						'lastmod' => '',
					);
				}
				$offset += $per;
			} while ( count( $terms ) === $per );
		}

		/**
		 * Filter the sitemap URLs.
		 *
		 * @param array $urls Sitemap URLs.
		 */
		return apply_filters( 'agentsteamer_ai_sitemap_urls', $urls );
	}

	/**
	 * Output the sitemap: a <urlset>, or a <sitemapindex> when extra sites are
	 * configured or the local URL list exceeds one shard.
	 */
	public function render() {
		if ( ! get_query_var( 'asi_sitemap' ) || ! agentsteamer_ai_get_option( 'sitemap_enabled', 1 ) ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: application/xml; charset=utf-8' );

		// Child (local shard) sitemap.
		if ( get_query_var( 'asi_sitemap_local' ) ) {
			$shard = max( 1, (int) get_query_var( 'asi_sitemap_shard' ) );
			$slice = array_slice( $this->local_urls(), ( $shard - 1 ) * self::SHARD_SIZE, self::SHARD_SIZE );
			$this->print_urlset( $slice );
			exit;
		}

		$urls   = $this->local_urls();
		$extras = $this->extra_sitemaps();

		// Simple case: no other sites and a single shard fits.
		if ( empty( $extras ) && count( $urls ) <= self::SHARD_SIZE ) {
			$this->print_urlset( $urls );
			exit;
		}

		$entries = array();
		$shards  = max( 1, (int) ceil( count( $urls ) / self::SHARD_SIZE ) );
		for ( $i = 1; $i <= $shards; $i++ ) {
			$slice   = array_slice( $urls, ( $i - 1 ) * self::SHARD_SIZE, self::SHARD_SIZE );
			$lastmod = '';
			foreach ( $slice as $u ) {
				if ( ! empty( $u['lastmod'] ) && $u['lastmod'] > $lastmod ) {
					$lastmod = $u['lastmod'];
				}
			}
			$query     = 'asi_sitemap_local=1' . ( $shards > 1 ? '&asi_sitemap_shard=' . $i : '' );
			$entries[] = array(
				'loc'     => home_url( '/asi-sitemap.xml' ) . '?' . $query,
				'lastmod' => $lastmod,
			);
		}
		foreach ( $extras as $url ) {
			$entries[] = array(
				'loc'     => $url,
				'lastmod' => '',
			);
		}

		$this->print_index( $entries );
		exit;
	}

	/**
	 * Print a <urlset>.
	 *
	 * @param array $urls URLs.
	 */
	protected function print_urlset( array $urls ) {
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $urls as $url ) {
			if ( empty( $url['loc'] ) || is_wp_error( $url['loc'] ) ) {
				continue;
			}
			echo "\t<url>\n";
			echo "\t\t<loc>" . esc_url( $url['loc'] ) . "</loc>\n";
			if ( ! empty( $url['lastmod'] ) ) {
				echo "\t\t<lastmod>" . esc_html( $url['lastmod'] ) . "</lastmod>\n";
			}
			echo "\t</url>\n";
		}
		echo '</urlset>' . "\n";
	}

	/**
	 * Print a <sitemapindex>.
	 *
	 * @param array $entries Entries.
	 */
	protected function print_index( array $entries ) {
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $entries as $entry ) {
			if ( empty( $entry['loc'] ) ) {
				continue;
			}
			echo "\t<sitemap>\n";
			echo "\t\t<loc>" . esc_url( $entry['loc'] ) . "</loc>\n";
			if ( ! empty( $entry['lastmod'] ) ) {
				echo "\t\t<lastmod>" . esc_html( $entry['lastmod'] ) . "</lastmod>\n";
			}
			echo "\t</sitemap>\n";
		}
		echo '</sitemapindex>' . "\n";
	}
}
