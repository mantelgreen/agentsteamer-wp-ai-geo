<?php
/**
 * Ability-style API for external AI agents (MCP-like).
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes a discoverable list of abilities and an execution endpoint.
 */
class AgentSteamer_AI_Abilities {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Capability check.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	protected function tools() {
		return array(
			'audit'            => array(
				'description' => '运行站点审计并返回各项检查结果。',
				'params'      => array(),
			),
			'generate_meta'    => array(
				'description' => '为指定文章生成 SEO 标题 / 描述 / 焦点关键词。',
				'params'      => array( 'post_id' => 'integer' ),
			),
			'optimize_content' => array(
				'description' => '为指定文章生成内容优化草案并加入审阅队列，返回审阅 ID。',
				'params'      => array( 'post_id' => 'integer' ),
			),
			'suggest_links'    => array(
				'description' => '返回指定文章的内部链接建议。',
				'params'      => array( 'post_id' => 'integer' ),
			),
			'generate_alt'     => array(
				'description' => '为指定图片生成 Alt 文本。',
				'params'      => array( 'attachment_id' => 'integer' ),
			),
			'get_llms_txt'     => array(
				'description' => '返回站点的 llms.txt 内容。',
				'params'      => array(),
			),
			'submit_indexnow'  => array(
				'description' => '将所有已发布 URL 提交到收录引擎。',
				'params'      => array(),
			),
		);
	}

	/**
	 * Register routes.
	 */
	public function routes() {
		register_rest_route(
			'agentsteamer-ai/v1',
			'/abilities',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_abilities' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			'agentsteamer-ai/v1',
			'/abilities/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_ability' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * List available abilities.
	 *
	 * @return WP_REST_Response
	 */
	public function list_abilities() {
		$abilities = array();
		foreach ( $this->tools() as $name => $spec ) {
			$abilities[] = array(
				'name'        => $name,
				'description' => $spec['description'],
				'params'      => $spec['params'],
			);
		}
		return new WP_REST_Response( array( 'abilities' => $abilities ), 200 );
	}

	/**
	 * Execute an ability.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_ability( WP_REST_Request $request ) {
		$tool   = sanitize_key( (string) $request->get_param( 'tool' ) );
		$params = $request->get_param( 'params' );
		$params = is_array( $params ) ? $params : array();

		switch ( $tool ) {
			case 'audit':
				$audit = new AgentSteamer_AI_Audit();
				return new WP_REST_Response( array( 'result' => $audit->get_results( true ) ), 200 );

			case 'generate_meta':
				$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
				if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'agentsteamer_ai_forbidden', '无权编辑该文章。', array( 'status' => 403 ) );
				}
				$ai     = new AgentSteamer_AI();
				$result = $ai->generate_meta( agentsteamer_ai_plain_content( $post_id ), agentsteamer_ai_get_post_meta( $post_id, 'focus_keyword' ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return new WP_REST_Response( array( 'result' => $result ), 200 );

			case 'optimize_content':
				$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
				if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'agentsteamer_ai_forbidden', '无权编辑该文章。', array( 'status' => 403 ) );
				}
				$ai     = new AgentSteamer_AI();
				$result = $ai->optimize_content( $post_id );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$review = new AgentSteamer_AI_Review();
				$rid    = $review->add( $post_id, 'content', get_post_field( 'post_content', $post_id ), $result['content_html'], $result['summary'], $result['changes'] );
				return new WP_REST_Response( array( 'result' => array( 'review_id' => $rid, 'summary' => $result['summary'] ) ), 200 );

			case 'suggest_links':
				$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
				if ( ! $post_id ) {
					return new WP_Error( 'agentsteamer_ai_bad_request', '缺少 post_id。', array( 'status' => 400 ) );
				}
				$ai = new AgentSteamer_AI();
				return new WP_REST_Response( array( 'result' => $ai->suggest_links( $post_id, 10 ) ), 200 );

			case 'generate_alt':
				$attachment_id = isset( $params['attachment_id'] ) ? (int) $params['attachment_id'] : 0;
				if ( ! $attachment_id ) {
					return new WP_Error( 'agentsteamer_ai_bad_request', '缺少 attachment_id。', array( 'status' => 400 ) );
				}
				$ai  = new AgentSteamer_AI();
				$alt = $ai->generate_alt( $attachment_id );
				if ( is_wp_error( $alt ) ) {
					return $alt;
				}
				return new WP_REST_Response( array( 'result' => array( 'alt' => $alt ) ), 200 );

			case 'get_llms_txt':
				$llms = new AgentSteamer_AI_Llms_Txt();
				return new WP_REST_Response( array( 'result' => $llms->build_index() ), 200 );

			case 'submit_indexnow':
				$indexing = new AgentSteamer_AI_Indexing();
				$urls     = array( home_url( '/' ) );
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
				return new WP_REST_Response( array( 'result' => $indexing->submit_urls( $urls, 'abilities' ) ), 200 );

			default:
				return new WP_Error( 'agentsteamer_ai_unknown_tool', '未知的 ability：' . $tool, array( 'status' => 400 ) );
		}
	}
}
