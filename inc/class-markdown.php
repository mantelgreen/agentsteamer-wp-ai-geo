<?php
/**
 * Markdown endpoint for AI agents.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves clean Markdown versions of singular content at /{path}.md.
 */
class AgentSteamer_AI_Markdown {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'rewrites' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'render' ), 1 );
		add_filter( 'redirect_canonical', array( $this, 'prevent_canonical' ), 10, 2 );
	}

	/**
	 * Do not let canonical redirects interfere with the endpoint.
	 *
	 * @param string|false $redirect  Redirect URL.
	 * @param string       $requested Requested URL.
	 * @return string|false
	 */
	public function prevent_canonical( $redirect, $requested ) {
		if ( get_query_var( 'asi_md' ) ) {
			return false;
		}
		return $redirect;
	}

	/**
	 * Register the rewrite rule.
	 */
	public function rewrites() {
		add_rewrite_rule( '^(.+)\.md$', 'index.php?asi_md=$matches[1]', 'top' );
	}

	/**
	 * Register the query var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = 'asi_md';
		return $vars;
	}

	/**
	 * Resolve the request path to a post ID.
	 *
	 * @param string $path Path without extension.
	 * @return int
	 */
	protected function resolve_post_id( $path ) {
		$path = trim( (string) $path, '/' );
		if ( '' === $path ) {
			return 0;
		}

		$post_id = url_to_postid( home_url( '/' . $path . '/' ) );
		if ( $post_id ) {
			return $post_id;
		}

		// Fall back to matching by slug path.
		$page = get_page_by_path( $path, OBJECT, agentsteamer_ai_supported_post_types() );
		if ( $page ) {
			return $page->ID;
		}

		return 0;
	}

	/**
	 * Output Markdown.
	 */
	public function render() {
		$path = get_query_var( 'asi_md' );
		if ( ! $path || ! agentsteamer_ai_get_option( 'markdown_enabled', 1 ) ) {
			return;
		}

		$post_id = $this->resolve_post_id( $path );
		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "# 404 Not Found\n";
			exit;
		}

		$markdown = $this->build( $post_id );

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Build the Markdown document for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function build( $post_id ) {
		$post = get_post( $post_id );
		$lines   = array();
		$lines[] = '# ' . $post->post_title;
		$lines[] = '';
		$lines[] = 'URL: ' . get_permalink( $post );
		$lines[] = 'Updated: ' . mysql2date( 'c', $post->post_modified_gmt, false );

		$author = get_the_author_meta( 'display_name', $post->post_author );
		if ( $author ) {
			$lines[] = 'Author: ' . $author;
		}

		$desc = agentsteamer_ai_get_post_meta( $post_id, 'description' );
		if ( $desc ) {
			$lines[] = '';
			$lines[] = '> ' . $desc;
		}

		$lines[] = '';
		$lines[] = agentsteamer_ai_html_to_markdown( $post->post_content );
		$lines[] = '';

		return apply_filters( 'agentsteamer_ai_markdown', implode( "\n", $lines ) . "\n", $post_id );
	}
}
