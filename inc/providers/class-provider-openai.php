<?php
/**
 * OpenAI-compatible provider (OpenAI, DeepSeek, Qwen, OpenRouter, Azure, custom).
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI Chat Completions compatible provider.
 */
class AgentSteamer_AI_Provider_OpenAI implements AgentSteamer_AI_Provider {

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
		$provider = isset( $this->config['provider'] ) ? $this->config['provider'] : 'openai';
		$labels   = array(
			'openai'     => 'OpenAI',
			'deepseek'   => 'DeepSeek',
			'qwen'       => '通义千问 (DashScope)',
			'openrouter' => 'OpenRouter',
			'azure'      => 'Azure OpenAI',
			'custom'     => '自定义 OpenAI 兼容',
		);
		return isset( $labels[ $provider ] ) ? $labels[ $provider ] : 'OpenAI 兼容';
	}

	/**
	 * Chat completion.
	 *
	 * @param array $messages Messages.
	 * @param array $args     Overrides.
	 * @return array|WP_Error
	 */
	public function chat( array $messages, array $args = array() ) {
		$config  = wp_parse_args(
			$args,
			array(
				'model'       => $this->config['model'],
				'temperature' => $this->config['temperature'],
				'max_tokens'  => $this->config['max_tokens'],
				'timeout'     => $this->config['timeout'],
				'json'        => false,
			)
		);
		$provider = isset( $this->config['provider'] ) ? $this->config['provider'] : 'openai';
		$base     = rtrim( $this->config['base_url'], '/' );

		$body = array(
			'model'       => $config['model'],
			'messages'    => $this->normalize_messages( $messages ),
			'temperature' => (float) $config['temperature'],
			'max_tokens'  => (int) $config['max_tokens'],
		);
		if ( ! empty( $config['json'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		// Optionally disable model "thinking" (reasoning) to cut latency dramatically.
		$thinking_mode = isset( $this->config['thinking_mode'] ) ? $this->config['thinking_mode'] : 'auto';
		$disable_thinking = ( 'on' === $thinking_mode ) || (
			'auto' === $thinking_mode && (
				false !== strpos( (string) $this->config['base_url'], 'volces.com' ) ||
				false !== strpos( (string) $this->config['base_url'], 'ark.cn' )
			)
		);
		if ( $disable_thinking ) {
			$body['thinking'] = array( 'type' => 'disabled' );
		}

		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( 'azure' === $provider ) {
			$url            = $base . '/chat/completions?api-version=' . rawurlencode( isset( $this->config['api_version'] ) && $this->config['api_version'] ? $this->config['api_version'] : '2024-06-01' );
			$headers['api-key'] = $this->config['api_key'];
		} else {
			$url                     = $base . '/chat/completions';
			$headers['Authorization'] = 'Bearer ' . $this->config['api_key'];
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => (int) $config['timeout'],
				'headers' => $headers,
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
		if ( is_array( $raw ) && isset( $raw['choices'][0]['message']['content'] ) ) {
			$content = (string) $raw['choices'][0]['message']['content'];
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
	 * Drop unsupported roles and coerce content to strings.
	 *
	 * @param array $messages Messages.
	 * @return array
	 */
	protected function normalize_messages( array $messages ) {
		$out = array();
		foreach ( $messages as $message ) {
			if ( ! isset( $message['role'], $message['content'] ) ) {
				continue;
			}
			$role = in_array( $message['role'], array( 'system', 'user', 'assistant' ), true ) ? $message['role'] : 'user';
			$out[] = array(
				'role'    => $role,
				'content' => (string) $message['content'],
			);
		}
		return $out;
	}

	/**
	 * Stream a chat completion via cURL, invoking $on_delta for each chunk.
	 *
	 * @param array         $messages Messages.
	 * @param array         $args     Overrides.
	 * @param callable|null $on_delta Delta callback.
	 * @return array|WP_Error
	 */
	public function chat_stream( array $messages, array $args = array(), $on_delta = null ) {
		if ( ! function_exists( 'curl_init' ) ) {
			$result = $this->chat( $messages, $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( is_callable( $on_delta ) ) {
				call_user_func( $on_delta, $result['content'] );
			}
			return array( 'content' => $result['content'] );
		}

		$config  = wp_parse_args(
			$args,
			array(
				'model'       => $this->config['model'],
				'temperature' => $this->config['temperature'],
				'max_tokens'  => $this->config['max_tokens'],
				'timeout'     => $this->config['timeout'],
			)
		);
		$provider = isset( $this->config['provider'] ) ? $this->config['provider'] : 'openai';
		$base     = rtrim( $this->config['base_url'], '/' );

		$body = array(
			'model'       => $config['model'],
			'messages'    => $this->normalize_messages( $messages ),
			'temperature' => (float) $config['temperature'],
			'max_tokens'  => (int) $config['max_tokens'],
			'stream'      => true,
		);

		$headers = array( 'Content-Type: application/json' );
		if ( 'azure' === $provider ) {
			$url                 = $base . '/chat/completions?api-version=' . rawurlencode( isset( $this->config['api_version'] ) && $this->config['api_version'] ? $this->config['api_version'] : '2024-06-01' );
			$headers[]           = 'api-key: ' . $this->config['api_key'];
		} else {
			$url       = $base . '/chat/completions';
			$headers[] = 'Authorization: Bearer ' . $this->config['api_key'];
		}

		$config['thinking_mode'] = isset( $this->config['thinking_mode'] ) ? $this->config['thinking_mode'] : 'auto';
		$disable_thinking        = ( 'on' === $config['thinking_mode'] ) || ( 'auto' === $config['thinking_mode'] && ( false !== strpos( (string) $base, 'volces.com' ) || false !== strpos( (string) $base, 'ark.cn' ) ) );
		if ( $disable_thinking ) {
			$body['thinking'] = array( 'type' => 'disabled' );
		}

		$full   = '';
		$buffer = '';
		$err    = '';

		$ch = curl_init();
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_POST           => true,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_TIMEOUT        => (int) $config['timeout'],
				CURLOPT_WRITEFUNCTION  => function ( $handle, $chunk ) use ( &$full, &$buffer, $on_delta ) {
					$buffer .= $chunk;
					while ( false !== ( $pos = strpos( $buffer, "\n" ) ) ) {
						$line   = trim( substr( $buffer, 0, $pos ) );
						$buffer = substr( $buffer, $pos + 1 );
						if ( '' === $line || 0 !== strpos( $line, 'data:' ) ) {
							continue;
						}
						$payload = trim( substr( $line, 5 ) );
						if ( '[DONE]' === $payload ) {
							continue;
						}
						$json = json_decode( $payload, true );
						$text = isset( $json['choices'][0]['delta']['content'] ) ? (string) $json['choices'][0]['delta']['content'] : '';
						if ( '' !== $text ) {
							$full .= $text;
							if ( is_callable( $on_delta ) ) {
								call_user_func( $on_delta, $text );
							}
						}
					}
					return strlen( $chunk );
				},
			)
		);

		$ok    = curl_exec( $ch );
		$err   = curl_error( $ch );
		$code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( false === $ok || '' !== $err ) {
			return new WP_Error( 'agentsteamer_ai_stream_error', sprintf( '%s 流式请求失败：%s', $this->get_label(), $err ? $err : 'unknown' ) );
		}
		if ( $code >= 400 ) {
			return new WP_Error( 'agentsteamer_ai_http_error', sprintf( '%s 返回错误 (HTTP %d)', $this->get_label(), $code ) );
		}
		if ( '' === $full ) {
			return new WP_Error( 'agentsteamer_ai_empty', __( '模型返回了空内容。', 'agentsteamer-ai' ) );
		}

		return array( 'content' => $full );
	}
}
