<?php
/**
 * Anthropic Claude provider.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anthropic Messages API provider.
 */
class AgentSteamer_AI_Provider_Anthropic implements AgentSteamer_AI_Provider {

	/**
	 * Provider configuration.
	 *
	 * @var array
	 */
	protected $config;

	/**
	 * Constructor.
	 *
	 * @param array $config Provider configuration.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Anthropic Claude';
	}

	/**
	 * Chat completion.
	 *
	 * @param array $messages Messages.
	 * @param array $args     Overrides.
	 * @return array|WP_Error
	 */
	public function chat( array $messages, array $args = array() ) {
		$args   = wp_parse_args(
			$args,
			array(
				'model'       => $this->config['model'],
				'temperature' => $this->config['temperature'],
				'max_tokens'  => $this->config['max_tokens'],
				'timeout'     => $this->config['timeout'],
			)
		);
		$base   = $this->config['base_url'] ? rtrim( $this->config['base_url'], '/' ) : 'https://api.anthropic.com/v1';
		$system = array();
		$turns  = array();

		foreach ( $messages as $message ) {
			if ( ! isset( $message['role'], $message['content'] ) ) {
				continue;
			}
			if ( 'system' === $message['role'] ) {
				$system[] = (string) $message['content'];
				continue;
			}
			$role    = ( 'assistant' === $message['role'] ) ? 'assistant' : 'user';
			$turns[] = array(
				'role'    => $role,
				'content' => (string) $message['content'],
			);
		}
		if ( empty( $turns ) || 'user' !== $turns[0]['role'] ) {
			array_unshift(
				$turns,
				array(
					'role'    => 'user',
					'content' => '请按系统要求执行。',
				)
			);
		}

		$body = array(
			'model'       => $args['model'],
			'max_tokens'  => (int) $args['max_tokens'],
			'temperature' => (float) $args['temperature'],
			'messages'    => $turns,
		);
		if ( $system ) {
			$body['system'] = implode( "\n\n", $system );
		}

		$response = wp_remote_post(
			$base . '/messages',
			array(
				'timeout' => (int) $args['timeout'],
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->config['api_key'],
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $raw ) && isset( $raw['error']['message'] ) ? $raw['error']['message'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'agentsteamer_ai_http_error', sprintf( '%s 返回错误 (HTTP %d)：%s', $this->get_label(), $code, wp_strip_all_tags( (string) $message ) ) );
		}

		$content = '';
		if ( is_array( $raw ) && ! empty( $raw['content'] ) ) {
			foreach ( $raw['content'] as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
					$content .= $block['text'];
				}
			}
		}

		if ( '' === trim( $content ) ) {
			return new WP_Error( 'agentsteamer_ai_empty', __( '模型返回了空内容。', 'agentsteamer-ai' ) );
		}

		return array(
			'content' => $content,
			'raw'     => $raw,
		);
	}

	/**
	 * Fallback streaming (delivers the full result as one delta).
	 *
	 * @param array         $messages Messages.
	 * @param array         $args     Overrides.
	 * @param callable|null $on_delta Delta callback.
	 * @return array|WP_Error
	 */
	public function chat_stream( array $messages, array $args = array(), $on_delta = null ) {
		$result = $this->chat( $messages, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_callable( $on_delta ) ) {
			call_user_func( $on_delta, $result['content'] );
		}
		return array( 'content' => $result['content'] );
	}
}
