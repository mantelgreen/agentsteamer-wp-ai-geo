<?php
/**
 * Settings registration and sanitization.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the settings option.
 */
class AgentSteamer_AI_Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'agentsteamer_ai_settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Register the setting with the Settings API.
	 */
	public function register() {
		register_setting(
			'agentsteamer_ai_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => agentsteamer_ai_default_settings(),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$existing = agentsteamer_ai_get_settings();
		$input    = is_array( $input ) ? $input : array();
		$out      = $existing;

		$checkboxes = array( 'enabled', 'auto_meta', 'noindex_search', 'noindex_author', 'noindex_date', 'sitemap_enabled', 'llms_txt_enabled', 'llms_full_enabled', 'markdown_enabled', 'ai_enabled', 'auto_fill_blank', 'indexnow_enabled', 'indexnow_auto', 'baidu_enabled', 'redirect_slug_change', 'notfound_log' );
		foreach ( $checkboxes as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$text_fields = array( 'title_separator', 'home_title', 'org_name', 'org_type', 'model', 'api_version', 'indexnow_key', 'baidu_token' );
		foreach ( $text_fields as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}

		// Flush rewrite rules when the IndexNow key changes (the key file rule is key-specific).
		if ( isset( $out['indexnow_key'] ) && $out['indexnow_key'] !== agentsteamer_ai_get_option( 'indexnow_key' ) ) {
			update_option( 'agentsteamer_ai_flush_rewrites', 1 );
		}

		$textarea_fields = array( 'home_desc', 'default_desc', 'llms_txt_intro', 'llms_extra', 'sitemap_extra', 'social_profiles', 'author_knows_about', 'schema_global_jsonld', 'prompt_article', 'prompt_finalize', 'prompt_optimize', 'prompt_meta', 'prompt_alt', 'prompt_schema', 'prompt_topics' );
		foreach ( $textarea_fields as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_textarea_field( $input[ $key ] );
			}
		}

		if ( isset( $input['org_logo'] ) ) {
			$out['org_logo'] = esc_url_raw( $input['org_logo'] );
		}
		if ( isset( $input['base_url'] ) ) {
			$out['base_url'] = esc_url_raw( $input['base_url'] );
		}
		if ( isset( $input['baidu_site'] ) ) {
			$out['baidu_site'] = esc_url_raw( $input['baidu_site'] );
		}
		if ( isset( $input['baidu_endpoint'] ) ) {
			$out['baidu_endpoint'] = esc_url_raw( str_replace( '&amp;', '&', (string) $input['baidu_endpoint'] ) );
		}

		// API key: keep the existing value when the field is left blank (masked form).
		if ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
			$out['api_key'] = trim( (string) $input['api_key'] );
		}

		if ( isset( $input['temperature'] ) ) {
			$out['temperature'] = max( 0, min( 2, (float) $input['temperature'] ) );
		}
		if ( isset( $input['max_tokens'] ) ) {
			$out['max_tokens'] = max( 256, min( 200000, (int) $input['max_tokens'] ) );
		}
		if ( isset( $input['timeout'] ) ) {
			$out['timeout'] = max( 10, min( 600, (int) $input['timeout'] ) );
		}

		$providers = array_keys( AgentSteamer_AI_Provider_Manager::presets() );
		if ( isset( $input['provider'] ) && in_array( $input['provider'], $providers, true ) ) {
			$out['provider'] = $input['provider'];
		}

		$thinking_modes = array( 'auto', 'off', 'on' );
		if ( isset( $input['thinking_mode'] ) && in_array( $input['thinking_mode'], $thinking_modes, true ) ) {
			$out['thinking_mode'] = $input['thinking_mode'];
		}

		// Crawler policy: whitelist user agents, restrict values.
		$out['crawler_policy'] = array();
		if ( isset( $input['crawler_policy'] ) && is_array( $input['crawler_policy'] ) ) {
			foreach ( $input['crawler_policy'] as $ua => $value ) {
				$ua = sanitize_text_field( $ua );
				if ( '' === $ua ) {
					continue;
				}
				$out['crawler_policy'][ $ua ] = ( 'disallow' === $value ) ? 'disallow' : 'allow';
			}
		}

		return $out;
	}

	/**
	 * Mask a secret for display.
	 *
	 * @param string $secret Secret value.
	 * @return string
	 */
	public static function mask( $secret ) {
		$secret = (string) $secret;
		$len    = strlen( $secret );
		if ( $len <= 8 ) {
			return $secret ? str_repeat( '•', max( 4, $len ) ) : '';
		}
		return substr( $secret, 0, 4 ) . str_repeat( '•', 8 ) . substr( $secret, -4 );
	}
}
