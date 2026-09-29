<?php
/**
 * Bulk AI actions on the posts list.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds bulk actions to queue AI optimization / autofill for selected posts.
 */
class AgentSteamer_AI_Bulk {

	/**
	 * Bulk action: generate an optimization draft (review).
	 */
	const OPTIMIZE = 'agentsteamer_ai_bulk_optimize';

	/**
	 * Bulk action: fill blank SEO fields.
	 */
	const AUTOFILL = 'agentsteamer_ai_bulk_autofill';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'agentsteamer_ai_bulk_optimize', array( $this, 'run_optimize' ) );

		if ( is_admin() ) {
			foreach ( agentsteamer_ai_supported_post_types() as $type ) {
				add_filter( 'bulk_actions-edit-' . $type, array( $this, 'register_actions' ) );
				add_filter( 'handle_bulk_actions-edit-' . $type, array( $this, 'handle' ), 10, 3 );
			}
			add_action( 'admin_notices', array( $this, 'notice' ) );
		}
	}

	/**
	 * Register bulk actions.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public function register_actions( $actions ) {
		$actions[ self::OPTIMIZE ] = __( 'AgentSteamer：生成 AI 优化草案（审阅）', 'agentsteamer-ai' );
		$actions[ self::AUTOFILL ] = __( 'AgentSteamer：AI 补全空白 SEO 字段', 'agentsteamer-ai' );
		return $actions;
	}

	/**
	 * Handle bulk actions by scheduling async jobs.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param array  $ids      Post IDs.
	 * @return string
	 */
	public function handle( $redirect, $action, $ids ) {
		if ( self::OPTIMIZE !== $action && self::AUTOFILL !== $action ) {
			return $redirect;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return $redirect;
		}

		$count = 0;
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( self::OPTIMIZE === $action ) {
				if ( ! wp_next_scheduled( 'agentsteamer_ai_bulk_optimize', array( $id ) ) ) {
					wp_schedule_single_event( time() + ( 5 * $count ) + 5, 'agentsteamer_ai_bulk_optimize', array( $id ) );
					$count++;
				}
			} elseif ( ! wp_next_scheduled( 'agentsteamer_ai_autofill', array( $id ) ) ) {
				wp_schedule_single_event( time() + ( 5 * $count ) + 5, 'agentsteamer_ai_autofill', array( $id ) );
				$count++;
			}
		}

		return add_query_arg( 'asi_bulk', $count, $redirect );
	}

	/**
	 * Show a notice after a bulk action.
	 */
	public function notice() {
		if ( ! isset( $_GET['asi_bulk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$count = (int) $_GET['asi_bulk']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( __( '已为 %d 篇内容排队 AI 任务（后台运行）。', 'agentsteamer-ai' ), $count ) )
		);
	}

	/**
	 * Run content optimization for a post and queue the result for review.
	 *
	 * @param int $post_id Post ID.
	 */
	public function run_optimize( $post_id ) {
		if ( ! agentsteamer_ai_get_option( 'ai_enabled', 1 ) || ! AgentSteamer_AI_Provider_Manager::is_configured() ) {
			return;
		}
		$ai  = new AgentSteamer_AI();
		$res = $ai->optimize_content( (int) $post_id );
		if ( is_wp_error( $res ) ) {
			return;
		}
		$review = new AgentSteamer_AI_Review();
		$review->add( (int) $post_id, 'content', get_post_field( 'post_content', $post_id ), $res['content_html'], $res['summary'], $res['changes'] );
	}
}
