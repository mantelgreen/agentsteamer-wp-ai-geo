<?php
/**
 * Background autofill of blank SEO fields.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules an asynchronous fill of blank SEO fields after a post is saved.
 */
class AgentSteamer_AI_Autofill {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'save_post', array( $this, 'maybe_schedule' ), 20, 3 );
		add_action( 'agentsteamer_ai_autofill', array( $this, 'run' ) );
	}

	/**
	 * Schedule the autofill task when fields are blank.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 */
	public function maybe_schedule( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! agentsteamer_ai_get_option( 'ai_enabled', 1 ) || ! agentsteamer_ai_get_option( 'auto_fill_blank', 1 ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, agentsteamer_ai_supported_post_types(), true ) ) {
			return;
		}
		if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return;
		}
		if ( ! AgentSteamer_AI_Provider_Manager::is_configured() ) {
			return;
		}

		$title = get_post_meta( $post_id, agentsteamer_ai_meta_key( 'title' ), true );
		$desc  = get_post_meta( $post_id, agentsteamer_ai_meta_key( 'description' ), true );
		$kw    = get_post_meta( $post_id, agentsteamer_ai_meta_key( 'focus_keyword' ), true );
		if ( $title && $desc && $kw ) {
			return;
		}

		if ( ! wp_next_scheduled( 'agentsteamer_ai_autofill', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 5, 'agentsteamer_ai_autofill', array( $post_id ) );
		}
	}

	/**
	 * Run the autofill task.
	 *
	 * @param int $post_id Post ID.
	 */
	public function run( $post_id ) {
		$ai = new AgentSteamer_AI();
		$ai->autofill_post( (int) $post_id );
	}
}
