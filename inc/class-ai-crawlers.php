<?php
/**
 * AI crawler governance (robots.txt).
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appends AI crawler directives to robots.txt.
 */
class AgentSteamer_AI_Crawlers {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'rewrites' ) );
		add_filter( 'robots_txt', array( $this, 'filter_robots' ), 20, 2 );
	}

	/**
	 * Route /robots.txt to WordPress' virtual robots handler.
	 */
	public function rewrites() {
		add_rewrite_rule( '^robots\.txt$', 'index.php?robots=1', 'top' );
	}

	/**
	 * Append crawler policy and sitemap to robots.txt.
	 *
	 * @param string $output Robots output.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public function filter_robots( $output, $public ) {
		if ( ! agentsteamer_ai_is_enabled() ) {
			return $output;
		}

		$policy = agentsteamer_ai_get_option( 'crawler_policy', array() );
		$policy = is_array( $policy ) ? $policy : array();

		$blocks = array();
		foreach ( agentsteamer_ai_crawler_catalog() as $bot ) {
			if ( isset( $policy[ $bot['ua'] ] ) && 'disallow' === $policy[ $bot['ua'] ] ) {
				$blocks[] = 'User-agent: ' . $bot['ua'] . "\nDisallow: /";
			}
		}

		if ( $blocks ) {
			$output .= "\n# BEGIN AgentSteamer AI crawler policy\n\n" . implode( "\n\n", $blocks ) . "\n\n# END AgentSteamer AI crawler policy\n";
		}

		if ( agentsteamer_ai_get_option( 'sitemap_enabled', 1 ) ) {
			$output .= "\nSitemap: " . esc_url_raw( home_url( '/asi-sitemap.xml' ) ) . "\n";
		}

		return $output;
	}
}
