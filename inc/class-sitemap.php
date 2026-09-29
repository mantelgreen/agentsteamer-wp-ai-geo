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
	 * Output the sitemap XML.
	 */
	public function render() {
		if ( ! get_query_var( 'asi_sitemap' ) || ! agentsteamer_ai_get_option( 'sitemap_enabled', 1 ) ) {
			return;
		}

		$urls = array();

		$urls[] = array(
			'loc'     => home_url( '/' ),
			'lastmod' => gmdate( 'c' ),
		);

		$post_types = agentsteamer_ai_supported_post_types();
		foreach ( $post_types as $type ) {
			$posts = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'numberposts'      => 2000,
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
		}

		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'number'     => 1000,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$urls[] = array(
					'loc'     => get_term_link( $term ),
					'lastmod' => gmdate( 'c' ),
				);
			}
		}

		/**
		 * Filter the sitemap URLs.
		 *
		 * @param array $urls Sitemap URLs.
		 */
		$urls = apply_filters( 'agentsteamer_ai_sitemap_urls', $urls );

		nocache_headers();
		header( 'Content-Type: application/xml; charset=utf-8' );

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
		exit;
	}
}
