<?php
/**
 * REST API controller.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers plugin REST routes.
 */
class AgentSteamer_AI_Rest {

	/**
	 * REST namespace.
	 */
	const NS = 'agentsteamer-ai/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Capability check for editing content.
	 *
	 * @return bool
	 */
	public function can_edit() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Capability check for managing settings.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/generate/article',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_article' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/generate/meta',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_meta' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/articles',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_article' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/schema/extract',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'schema_extract' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/optimize/content',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'optimize_content' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/links/suggest',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'links_suggest' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/links/insert',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'links_insert' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/generate/alt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_alt' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/providers/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_provider' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Normalise a WP_Error so REST replies with a sensible HTTP status.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	protected function as_error( WP_Error $error ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			$error->add_data( array( 'status' => 400 ) );
		}
		return $error;
	}

	/**
	 * Generate an article.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_article( WP_REST_Request $request ) {
		$ai      = new AgentSteamer_AI();
		$article = $ai->generate_article(
			array(
				'prompt'   => sanitize_textarea_field( (string) $request->get_param( 'prompt' ) ),
				'topic'    => sanitize_text_field( (string) $request->get_param( 'topic' ) ),
				'keywords' => sanitize_text_field( (string) $request->get_param( 'keywords' ) ),
				'audience' => sanitize_text_field( (string) $request->get_param( 'audience' ) ),
				'tone'     => sanitize_text_field( (string) $request->get_param( 'tone' ) ),
				'length'   => (int) $request->get_param( 'length' ),
				'language' => sanitize_text_field( (string) $request->get_param( 'language' ) ),
			)
		);

		if ( is_wp_error( $article ) ) {
			return $this->as_error( $article );
		}

		return new WP_REST_Response( $article, 200 );
	}

	/**
	 * Generate meta for a post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_meta( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$keyword = sanitize_text_field( (string) $request->get_param( 'keyword' ) );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'agentsteamer_ai_forbidden', __( '无权编辑该文章。', 'agentsteamer-ai' ), array( 'status' => 403 ) );
		}

		$content = $request->get_param( 'content' );
		if ( ! $content ) {
			$content = agentsteamer_ai_plain_content( $post_id );
		}
		if ( ! $content ) {
			return new WP_Error( 'agentsteamer_ai_no_content', __( '文章内容为空，无法生成。', 'agentsteamer-ai' ), array( 'status' => 400 ) );
		}

		$ai     = new AgentSteamer_AI();
		$result = $ai->generate_meta( $content, $keyword );
		if ( is_wp_error( $result ) ) {
			return $this->as_error( $result );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Create a draft from generated article data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_article( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || empty( $params['title'] ) || empty( $params['content_html'] ) ) {
			return new WP_Error( 'agentsteamer_ai_bad_request', __( '缺少文章标题或内容。', 'agentsteamer-ai' ), array( 'status' => 400 ) );
		}

		$post_type = isset( $params['post_type'] ) ? sanitize_key( $params['post_type'] ) : 'post';
		$status    = isset( $params['status'] ) && 'publish' === $params['status'] && current_user_can( 'publish_posts' ) ? 'publish' : 'draft';

		$article = array(
			'title'            => sanitize_text_field( $params['title'] ),
			'excerpt'          => isset( $params['excerpt'] ) ? sanitize_textarea_field( $params['excerpt'] ) : '',
			'content_html'     => wp_kses_post( $params['content_html'] ),
			'meta_title'       => isset( $params['meta_title'] ) ? sanitize_text_field( $params['meta_title'] ) : '',
			'meta_description' => isset( $params['meta_description'] ) ? sanitize_text_field( $params['meta_description'] ) : '',
			'focus_keyword'    => isset( $params['focus_keyword'] ) ? sanitize_text_field( $params['focus_keyword'] ) : '',
			'tags'             => isset( $params['tags'] ) && is_array( $params['tags'] ) ? array_map( 'sanitize_text_field', $params['tags'] ) : array(),
			'faq'              => isset( $params['faq'] ) && is_array( $params['faq'] ) ? $params['faq'] : array(),
			'howto'            => isset( $params['howto'] ) && is_array( $params['howto'] ) ? $params['howto'] : array(),
		);

		$ai      = new AgentSteamer_AI();
		$post_id = $ai->create_draft(
			$article,
			array(
				'post_type'   => $post_type,
				'post_status' => $status,
				'post_author' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return new WP_REST_Response(
			array(
				'id'       => $post_id,
				'status'   => $status,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
				'view_url' => get_permalink( $post_id ),
			),
			201
		);
	}

	/**
	 * Extract FAQ / HowTo structured data from a post's content.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function schema_extract( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'agentsteamer_ai_forbidden', __( '无权编辑该文章。', 'agentsteamer-ai' ), array( 'status' => 403 ) );
		}

		$content = $request->get_param( 'content' );
		if ( ! $content ) {
			$content = agentsteamer_ai_plain_content( $post_id );
		}
		if ( ! $content ) {
			return new WP_Error( 'agentsteamer_ai_no_content', __( '文章内容为空，无法提取。', 'agentsteamer-ai' ), array( 'status' => 400 ) );
		}

		$ai     = new AgentSteamer_AI();
		$result = $ai->extract_schema( $content );
		if ( is_wp_error( $result ) ) {
			return $this->as_error( $result );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Generate an optimized version of a post's content and queue it for review.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function optimize_content( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'agentsteamer_ai_forbidden', __( '无权编辑该文章。', 'agentsteamer-ai' ), array( 'status' => 403 ) );
		}

		$ai     = new AgentSteamer_AI();
		$result = $ai->optimize_content( $post_id );
		if ( is_wp_error( $result ) ) {
			return $this->as_error( $result );
		}

		$review = new AgentSteamer_AI_Review();
		$old    = (string) get_post_field( 'post_content', $post_id );
		$id     = $review->add( $post_id, 'content', $old, $result['content_html'], $result['summary'], $result['changes'] );

		return new WP_REST_Response(
			array(
				'review_id'  => $id,
				'summary'    => $result['summary'],
				'changes'    => $result['changes'],
				'review_url' => admin_url( 'admin.php?page=agentsteamer-ai-reviews' ),
			),
			200
		);
	}

	/**
	 * Suggest internal links for a post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function links_suggest( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'agentsteamer_ai_forbidden', __( '无权编辑该文章。', 'agentsteamer-ai' ), array( 'status' => 403 ) );
		}
		$ai = new AgentSteamer_AI();
		return new WP_REST_Response( array( 'links' => $ai->suggest_links( $post_id, 8 ) ), 200 );
	}

	/**
	 * Append suggested internal links to a post and queue the change for review.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function links_insert( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'agentsteamer_ai_forbidden', __( '无权编辑该文章。', 'agentsteamer-ai' ), array( 'status' => 403 ) );
		}

		$links = $request->get_param( 'links' );
		if ( ! is_array( $links ) || empty( $links ) ) {
			return new WP_Error( 'agentsteamer_ai_no_links', __( '没有可插入的链接。', 'agentsteamer-ai' ), array( 'status' => 400 ) );
		}

		$items = array();
		foreach ( $links as $link ) {
			$url    = isset( $link['url'] ) ? esc_url_raw( $link['url'] ) : '';
			$anchor = isset( $link['anchor'] ) ? sanitize_text_field( $link['anchor'] ) : '';
			if ( '' === $url ) {
				continue;
			}
			if ( '' === $anchor ) {
				$anchor = $url;
			}
			$items[] = '<li><a href="' . $url . '">' . $anchor . '</a></li>';
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'agentsteamer_ai_no_links', __( '没有有效的链接。', 'agentsteamer-ai' ), array( 'status' => 400 ) );
		}

		$block = "\n<h2>" . __( '延伸阅读', 'agentsteamer-ai' ) . "</h2>\n<ul>\n" . implode( "\n", $items ) . "\n</ul>\n";
		$old   = (string) get_post_field( 'post_content', $post_id );
		$new   = $old . $block;

		$review = new AgentSteamer_AI_Review();
		$rid    = $review->add(
			$post_id,
			'content',
			$old,
			$new,
			__( '在文末插入内部链接（延伸阅读）', 'agentsteamer-ai' ),
			array( sprintf( __( '新增 %d 条内链', 'agentsteamer-ai' ), count( $items ) ) )
		);

		return new WP_REST_Response(
			array(
				'review_id'  => $rid,
				'count'      => count( $items ),
				'review_url' => admin_url( 'admin.php?page=agentsteamer-ai-reviews' ),
			),
			200
		);
	}

	/**
	 * Generate alt text for an image (does not save).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_alt( WP_REST_Request $request ) {
		$attachment_id = (int) $request->get_param( 'attachment_id' );
		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new WP_Error( 'agentsteamer_ai_forbidden', __( '无权编辑该图片。', 'agentsteamer-ai' ), array( 'status' => 403 ) );
		}
		$ai  = new AgentSteamer_AI();
		$alt = $ai->generate_alt( $attachment_id );
		if ( is_wp_error( $alt ) ) {
			return $this->as_error( $alt );
		}
		return new WP_REST_Response( array( 'alt' => $alt ), 200 );
	}

	/**
	 * Test the configured provider connection.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_provider() {
		$ai     = new AgentSteamer_AI();
		$result = $ai->test_connection();
		if ( is_wp_error( $result ) ) {
			return $this->as_error( $result );
		}
		return new WP_REST_Response( $result, 200 );
	}
}
