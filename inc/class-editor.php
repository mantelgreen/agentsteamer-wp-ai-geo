<?php
/**
 * Block editor (Gutenberg) integration: sidebar panel + REST-exposed meta.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers post meta for the REST API and enqueues the editor sidebar.
 */
class AgentSteamer_AI_Editor {

	/**
	 * Editable meta fields.
	 *
	 * @return array
	 */
	public static function meta_fields() {
		return array(
			'title'             => 'wp_strip_all_tags',
			'description'       => 'wp_strip_all_tags',
			'canonical'         => 'esc_url_raw',
			'noindex'           => 'wp_strip_all_tags',
			'focus_keyword'     => 'wp_strip_all_tags',
			'schema_faq'        => 'sanitize_textarea_field',
			'schema_howto'      => 'sanitize_textarea_field',
			'schema_howto_name' => 'sanitize_text_field',
			'schema_custom'     => 'sanitize_textarea_field',
			'schema_disabled'   => 'wp_strip_all_tags',
			'speakable'         => 'wp_strip_all_tags',
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Register post meta so it is available in the block editor.
	 */
	public function register_meta() {
		foreach ( agentsteamer_ai_supported_post_types() as $post_type ) {
			foreach ( self::meta_fields() as $field => $sanitize ) {
				register_post_meta(
					$post_type,
					agentsteamer_ai_meta_key( $field ),
					array(
						'type'              => 'string',
						'single'            => true,
						'default'           => '',
						'show_in_rest'      => true,
						'sanitize_callback' => $sanitize,
						'auth_callback'     => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);
			}
		}
	}

	/**
	 * Enqueue the sidebar script in the block editor.
	 */
	public function enqueue() {
		wp_enqueue_script(
			'agentsteamer-ai-editor',
			AGENTSTEAMER_AI_URL . 'assets/js/editor.js',
			array( 'wp-plugins', 'wp-editor', 'wp-components', 'wp-data', 'wp-core-data', 'wp-element', 'wp-i18n', 'wp-api-fetch' ),
			AGENTSTEAMER_AI_VERSION,
			true
		);

		wp_enqueue_style(
			'agentsteamer-ai-editor',
			AGENTSTEAMER_AI_URL . 'assets/css/admin.css',
			array( 'wp-components' ),
			AGENTSTEAMER_AI_VERSION
		);

		wp_localize_script(
			'agentsteamer-ai-editor',
			'AgentSteamerAIEditor',
			array(
				'restUrl' => esc_url_raw( rest_url( AgentSteamer_AI_Rest::NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
