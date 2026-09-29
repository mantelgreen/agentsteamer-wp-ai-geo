<?php
/**
 * llms.txt generator and endpoint.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves /llms.txt (and optional /llms-full.txt) per the llms.txt v2 spec.
 */
class AgentSteamer_AI_Llms_Txt {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'rewrites' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'render' ), 1 );
		add_filter( 'redirect_canonical', array( $this, 'prevent_canonical' ), 10, 2 );
	}

	/**
	 * Do not let canonical redirects interfere with the endpoint.
	 *
	 * @param string|false $redirect  Redirect URL.
	 * @param string       $requested Requested URL.
	 * @return string|false
	 */
	public function prevent_canonical( $redirect, $requested ) {
		if ( get_query_var( 'asi_llms' ) ) {
			return false;
		}
		return $redirect;
	}

	/**
	 * Register rewrite rules.
	 */
	public function rewrites() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?asi_llms=1', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?asi_llms=full', 'top' );
	}

	/**
	 * Register query vars.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = 'asi_llms';
		return $vars;
	}

	/**
	 * Render the endpoint.
	 */
	public function render() {
		$flag = get_query_var( 'asi_llms' );
		if ( ! $flag || ! agentsteamer_ai_get_option( 'llms_txt_enabled', 1 ) ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );

		if ( 'full' === $flag && agentsteamer_ai_get_option( 'llms_full_enabled', 0 ) ) {
			echo $this->build_full(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo $this->build_index(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		exit;
	}

	/**
	 * Build the llms.txt index document.
	 *
	 * @return string
	 */
	public function build_index() {
		$settings = agentsteamer_ai_get_settings();

		$site_name = $settings['org_name'] ? $settings['org_name'] : get_bloginfo( 'name' );
		$intro     = $settings['llms_txt_intro'] ? $settings['llms_txt_intro'] : get_bloginfo( 'description' );

		$lines   = array();
		$lines[] = '# ' . $site_name;
		$lines[] = '';
		$lines[] = '> ' . ( $intro ? $intro : $site_name . ' 的站点内容索引，供 AI 智能体使用。' );
		$lines[] = '';

		$groups = $this->grouped_content();

		foreach ( $groups as $group ) {
			if ( empty( $group['items'] ) ) {
				continue;
			}
			$lines[] = '## ' . $group['label'];
			foreach ( $group['items'] as $item ) {
				$line = '- [' . $item['title'] . '](' . $item['url'] . ')';
				if ( $item['desc'] ) {
					$line .= ': ' . $item['desc'];
				}
				$lines[] = $line;
			}
			$lines[] = '';
		}

		$extra = $this->extra_sites();
		if ( ! empty( $extra ) ) {
			$lines[] = '## ' . __( '其他站点', 'agentsteamer-ai' );
			foreach ( $extra as $site ) {
				$lines[] = '- [' . $site['title'] . '](' . $site['url'] . ')';
			}
			$lines[] = '';
		}

		/**
		 * Filter the llms.txt output.
		 *
		 * @param string $output  Document.
		 * @param array  $settings Settings.
		 */
		return apply_filters( 'agentsteamer_ai_llms_txt', implode( "\n", $lines ) . "\n", $settings );
	}

	/**
	 * Configured other-site entries for llms.txt, one per line ("标题 | URL" or URL).
	 *
	 * @return array
	 */
	protected function extra_sites() {
		$raw = (string) agentsteamer_ai_get_option( 'llms_extra' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$url = esc_url_raw( $parts[1] );
				if ( $url ) {
					$out[] = array(
						'title' => $parts[0],
						'url'   => $url,
					);
				}
			} else {
				$url = esc_url_raw( $line );
				if ( $url ) {
					$out[] = array(
						'title' => $url,
						'url'   => $url,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Build the llms-full.txt document (concatenated content).
	 *
	 * @return string
	 */
	public function build_full() {
		$groups = $this->grouped_content();
		$out    = array();

		foreach ( $groups as $group ) {
			foreach ( $group['items'] as $item ) {
				$post = get_post( $item['id'] );
				if ( ! $post ) {
					continue;
				}
				$out[] = '# ' . $item['title'];
				$out[] = 'URL: ' . get_permalink( $post );
				$out[] = '';
				$out[] = agentsteamer_ai_plain_content( $post->ID );
				$out[] = "\n---\n";
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * Group published content by post type.
	 *
	 * @return array
	 */
	protected function grouped_content() {
		$groups = array();
		foreach ( agentsteamer_ai_supported_post_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( ! $object ) {
				continue;
			}
			$items  = array();
			$offset = 0;
			$per    = 500;
			do {
				$posts = get_posts(
					array(
						'post_type'     => $type,
						'post_status'   => 'publish',
						'numberposts'   => $per,
						'offset'        => $offset,
						'orderby'       => 'ID',
						'order'         => 'DESC',
						'no_found_rows' => true,
					)
				);
				foreach ( $posts as $post ) {
					if ( '1' === agentsteamer_ai_get_post_meta( $post->ID, 'noindex', '0' ) ) {
						continue;
					}
					$desc = agentsteamer_ai_get_post_meta( $post->ID, 'description' );
					if ( ! $desc ) {
						$desc = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( agentsteamer_ai_plain_content( $post->ID ), 24, '…' );
					}
					$items[] = array(
						'id'    => $post->ID,
						'title' => $post->post_title,
						'url'   => $this->link_for( $post ),
						'desc'  => agentsteamer_ai_trim( $desc, 120 ),
					);
				}
				$offset += $per;
			} while ( count( $posts ) === $per );
			$groups[] = array(
				'label' => $object->labels->name,
				'items' => $items,
			);
		}
		return $groups;
	}

	/**
	 * Link for an entry (Markdown variant when enabled).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	protected function link_for( $post ) {
		$permalink = get_permalink( $post );
		if ( agentsteamer_ai_get_option( 'markdown_enabled', 1 ) ) {
			return agentsteamer_ai_markdown_url( $permalink );
		}
		return $permalink;
	}
}
