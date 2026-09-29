<?php
/**
 * Provider manager: resolves the configured provider.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Factory for provider instances.
 */
class AgentSteamer_AI_Provider_Manager {

	/**
	 * Provider presets for the settings UI.
	 *
	 * @return array
	 */
	public static function presets() {
		return array(
			'openai'     => array(
				'label'    => 'OpenAI',
				'base_url' => 'https://api.openai.com/v1',
				'model'    => 'gpt-4o-mini',
			),
			'deepseek'   => array(
				'label'    => 'DeepSeek',
				'base_url' => 'https://api.deepseek.com/v1',
				'model'    => 'deepseek-chat',
			),
			'qwen'       => array(
				'label'    => '通义千问 (DashScope 兼容模式)',
				'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
				'model'    => 'qwen-plus',
			),
			'openrouter' => array(
				'label'    => 'OpenRouter',
				'base_url' => 'https://openrouter.ai/api/v1',
				'model'    => 'openai/gpt-4o-mini',
			),
			'azure'      => array(
				'label'    => 'Azure OpenAI',
				'base_url' => 'https://YOUR-RESOURCE.openai.azure.com/openai/deployments/YOUR-DEPLOYMENT',
				'model'    => 'gpt-4o-mini',
			),
			'anthropic'  => array(
				'label'    => 'Anthropic Claude',
				'base_url' => 'https://api.anthropic.com/v1',
				'model'    => 'claude-3-5-sonnet-latest',
			),
			'gemini'     => array(
				'label'    => 'Google Gemini',
				'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
				'model'    => 'gemini-1.5-flash',
			),
			'custom'     => array(
				'label'    => '自定义 OpenAI 兼容接口',
				'base_url' => '',
				'model'    => '',
			),
		);
	}

	/**
	 * Build the runtime provider config from settings.
	 *
	 * @return array
	 */
	public static function get_config() {
		$settings = agentsteamer_ai_get_settings();
		return array(
			'provider'    => $settings['provider'],
			'base_url'    => $settings['base_url'],
			'api_key'     => $settings['api_key'],
			'model'       => $settings['model'],
			'temperature' => $settings['temperature'],
			'max_tokens'  => $settings['max_tokens'],
			'timeout'     => $settings['timeout'],
			'api_version' => isset( $settings['api_version'] ) ? $settings['api_version'] : '',
			'thinking_mode' => isset( $settings['thinking_mode'] ) ? $settings['thinking_mode'] : 'auto',
		);
	}

	/**
	 * Whether a usable provider is configured.
	 *
	 * @param array|null $config Optional config override.
	 * @return bool
	 */
	public static function is_configured( $config = null ) {
		$config = null === $config ? self::get_config() : $config;
		return ! empty( $config['api_key'] ) && ! empty( $config['model'] ) && ! empty( $config['base_url'] );
	}

	/**
	 * Resolve a provider instance.
	 *
	 * @param array|null $config Optional config override.
	 * @return AgentSteamer_AI_Provider|WP_Error
	 */
	public static function get_provider( $config = null ) {
		$config = null === $config ? self::get_config() : $config;

		if ( empty( $config['api_key'] ) ) {
			return new WP_Error( 'agentsteamer_ai_no_key', __( '尚未配置大模型 API Key，请前往「AgentSteamer AI → 设置」填写。', 'agentsteamer-ai' ) );
		}

		switch ( $config['provider'] ) {
			case 'anthropic':
				return new AgentSteamer_AI_Provider_Anthropic( $config );
			case 'gemini':
				return new AgentSteamer_AI_Provider_Gemini( $config );
			default:
				return new AgentSteamer_AI_Provider_OpenAI( $config );
		}
	}

	/**
	 * Extract the first JSON object/array from a model response.
	 *
	 * @param string $text Raw model text.
	 * @return array|null
	 */
	public static function extract_json( $text ) {
		$text = trim( (string) $text );

		// Strip code fences.
		if ( preg_match( '/```(?:json)?\s*(.+?)\s*```/is', $text, $matches ) ) {
			$text = $matches[1];
		}

		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// Fall back to first {...} block.
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$slice   = substr( $text, $start, $end - $start + 1 );
			$decoded = json_decode( $slice, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}
}
