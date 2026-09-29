<?php
/**
 * Provider interface.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract every LLM provider must implement.
 */
interface AgentSteamer_AI_Provider {

	/**
	 * Human readable label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Send a chat completion request.
	 *
	 * @param array $messages Messages: [ [ 'role' => 'system|user|assistant', 'content' => '...' ], ... ].
	 * @param array $args     Optional overrides: model, temperature, max_tokens, timeout, json.
	 * @return array|WP_Error  [ 'content' => string, 'raw' => array ] or WP_Error.
	 */
	public function chat( array $messages, array $args = array() );

	/**
	 * Stream a chat completion, invoking $on_delta for each text chunk.
	 *
	 * @param array         $messages Messages.
	 * @param array         $args     Overrides.
	 * @param callable|null $on_delta Receives each text delta.
	 * @return array|WP_Error [ 'content' => string ] or WP_Error.
	 */
	public function chat_stream( array $messages, array $args = array(), $on_delta = null );
}
