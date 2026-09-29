<?php
/**
 * WP-CLI commands.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage AgentSteamer SEO / GEO from the command line.
 */
class AgentSteamer_AI_CLI {

	/**
	 * Register the command when running under WP-CLI.
	 */
	public function __construct() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'agentsteamer-ai', $this );
		}
	}

	/**
	 * Run the site audit and print the results.
	 *
	 * ## EXAMPLES
	 *
	 *     wp agentsteamer-ai audit
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function audit( $args, $assoc_args ) {
		$audit   = new AgentSteamer_AI_Audit();
		$results = $audit->get_results( true );
		$rows    = array();
		foreach ( $results as $row ) {
			$rows[] = array(
				'status' => $row['status'],
				'check'  => $row['label'],
				'detail' => wp_strip_all_tags( $row['detail'] ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'check', 'detail' ) );
	}

	/**
	 * Generate an AI optimization draft for a post (queued for review).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 *
	 * @param array $args Positional args.
	 */
	public function optimize( $args ) {
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $id ) {
			\WP_CLI::error( '请提供文章 ID：wp agentsteamer-ai optimize <id>' );
		}
		$ai  = new AgentSteamer_AI();
		$res = $ai->optimize_content( $id );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_message() );
		}
		$review = new AgentSteamer_AI_Review();
		$rid    = $review->add( $id, 'content', get_post_field( 'post_content', $id ), $res['content_html'], $res['summary'], $res['changes'] );
		\WP_CLI::success( sprintf( '已生成优化草案，审阅 ID：%d', $rid ) );
	}

	/**
	 * Fill blank SEO fields for posts.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Post IDs.
	 *
	 * [--all]
	 * : Process all published content.
	 *
	 * [--schema]
	 * : Also fill FAQ / HowTo structured data.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function autofill( $args, $assoc_args ) {
		$scope = array( 'seo' => true, 'schema' => isset( $assoc_args['schema'] ) );
		$ai    = new AgentSteamer_AI();
		$done  = 0;

		$ids = array_map( 'intval', $args );
		if ( isset( $assoc_args['all'] ) || empty( $ids ) ) {
			$offset = 0;
			$per    = 50;
			while ( true ) {
				$batch = get_posts(
					array(
						'post_type'     => agentsteamer_ai_supported_post_types(),
						'post_status'   => 'publish',
						'numberposts'   => $per,
						'offset'        => $offset,
						'orderby'       => 'ID',
						'order'         => 'ASC',
						'no_found_rows' => true,
						'fields'        => 'ids',
					)
				);
				if ( empty( $batch ) ) {
					break;
				}
				foreach ( $batch as $id ) {
					$r = $ai->fill_post( (int) $id, $scope );
					if ( ! empty( $r['filled'] ) ) {
						$done++;
					}
				}
				if ( count( $batch ) < $per ) {
					break;
				}
				$offset += $per;
			}
		} else {
			foreach ( $ids as $id ) {
				$r = $ai->fill_post( (int) $id, $scope );
				if ( ! empty( $r['filled'] ) ) {
					$done++;
				}
			}
		}
		\WP_CLI::success( sprintf( '已补全 %d 篇。', $done ) );
	}

	/**
	 * Generate missing image alt text.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs.
	 *
	 * [--all]
	 * : Process all images missing alt text.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function alt( $args, $assoc_args ) {
		$seo = new AgentSteamer_AI_Image_Seo();
		$ids = array_map( 'intval', $args );
		if ( isset( $assoc_args['all'] ) || empty( $ids ) ) {
			$ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'post_status'    => 'inherit',
					'numberposts'    => 2000,
					'no_found_rows'  => true,
					'fields'         => 'ids',
				)
			);
		}
		$done = 0;
		foreach ( $ids as $id ) {
			$alt = get_post_meta( (int) $id, '_wp_attachment_image_alt', true );
			if ( isset( $assoc_args['all'] ) && '' !== trim( (string) $alt ) ) {
				continue;
			}
			if ( $seo->run_alt( (int) $id ) ) {
				$done++;
			}
		}
		\WP_CLI::success( sprintf( '已为 %d 张图片生成 Alt。', $done ) );
	}

	/**
	 * Suggest internal links for a post.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 *
	 * @param array $args Positional args.
	 */
	public function links( $args ) {
		$id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $id ) {
			\WP_CLI::error( '请提供文章 ID：wp agentsteamer-ai links <id>' );
		}
		$ai    = new AgentSteamer_AI();
		$links = $ai->suggest_links( $id, 10 );
		if ( empty( $links ) ) {
			\WP_CLI::success( '未找到相关文章建议。' );
			return;
		}
		$rows = array();
		foreach ( $links as $link ) {
			$rows[] = array(
				'score'  => round( $link['score'], 1 ),
				'title'  => $link['title'],
				'anchor' => $link['anchor'],
				'url'    => $link['url'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'score', 'title', 'anchor', 'url' ) );
	}

	/**
	 * Submit all published URLs to the configured indexing engines.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function indexnow( $args, $assoc_args ) {
		$indexing = new AgentSteamer_AI_Indexing();
		if ( ! $indexing->is_configured() ) {
			\WP_CLI::error( '未配置 IndexNow 或百度推送。' );
		}
		$urls = array( home_url( '/' ) );
		foreach ( agentsteamer_ai_supported_post_types() as $type ) {
			$posts = get_posts(
				array(
					'post_type'     => $type,
					'post_status'   => 'publish',
					'numberposts'   => 2000,
					'no_found_rows' => true,
				)
			);
			foreach ( $posts as $post ) {
				$urls[] = get_permalink( $post );
			}
		}
		$results = $indexing->submit_urls( $urls, 'cli' );
		\WP_CLI::success( sprintf( '已提交 %d 个 URL。', count( $urls ) ) );
		foreach ( $results as $engine => $result ) {
			\WP_CLI::log( sprintf( '%s: %s (%s)', $engine, ! empty( $result['ok'] ) ? 'OK' : 'FAIL', isset( $result['code'] ) ? $result['code'] : '' ) );
		}
	}

	/**
	 * Print the llms.txt document.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function llms( $args, $assoc_args ) {
		$llms = new AgentSteamer_AI_Llms_Txt();
		\WP_CLI::line( $llms->build_index() );
	}
}
