<?php
/**
 * Main plugin bootstrap.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin modules together.
 */
final class AgentSteamer_AI_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var AgentSteamer_AI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Instantiated modules.
	 *
	 * @var array
	 */
	public $modules = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return AgentSteamer_AI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: load files and boot modules.
	 */
	private function __construct() {
		self::load_files();
		$this->boot();
	}

	/**
	 * Load class files. Safe to call multiple times (require_once).
	 */
	public static function load_files() {
		$files = array(
			'inc/providers/interface-provider.php',
			'inc/providers/class-provider-openai.php',
			'inc/providers/class-provider-anthropic.php',
			'inc/providers/class-provider-gemini.php',
			'inc/providers/class-provider-manager.php',
			'inc/class-settings.php',
			'inc/class-ai-crawlers.php',
			'inc/class-sitemap.php',
			'inc/class-llms-txt.php',
			'inc/class-markdown.php',
			'inc/class-indexing.php',
			'inc/class-redirect.php',
			'inc/class-audit.php',
			'inc/class-review.php',
			'inc/class-image-seo.php',
			'inc/class-bulk.php',
			'inc/class-dashboard.php',
			'inc/class-cli.php',
			'inc/class-topics.php',
			'inc/class-abilities.php',
			'inc/class-install.php',
			'inc/class-meta-tags.php',
			'inc/class-schema.php',
			'inc/class-metabox.php',
			'inc/class-editor.php',
			'inc/class-autofill.php',
			'inc/class-rest.php',
			'inc/class-admin.php',
			'inc/class-ai.php',
		);
		foreach ( $files as $file ) {
			require_once AGENTSTEAMER_AI_DIR . $file;
		}
	}

	/**
	 * Instantiate modules.
	 */
	private function boot() {
		$this->modules['settings']  = new AgentSteamer_AI_Settings();
		$this->modules['crawlers']  = new AgentSteamer_AI_Crawlers();
		$this->modules['sitemap']   = new AgentSteamer_AI_Sitemap();
		$this->modules['llms']      = new AgentSteamer_AI_Llms_Txt();
		$this->modules['markdown']  = new AgentSteamer_AI_Markdown();
		$this->modules['indexing']  = new AgentSteamer_AI_Indexing();
		$this->modules['redirect']  = new AgentSteamer_AI_Redirect();
		$this->modules['audit']     = new AgentSteamer_AI_Audit();
		$this->modules['review']    = new AgentSteamer_AI_Review();
		$this->modules['image_seo'] = new AgentSteamer_AI_Image_Seo();
		$this->modules['bulk']      = new AgentSteamer_AI_Bulk();
		$this->modules['dashboard'] = new AgentSteamer_AI_Dashboard();
		$this->modules['cli']       = new AgentSteamer_AI_CLI();
		$this->modules['topics']    = new AgentSteamer_AI_Topics();
		$this->modules['abilities'] = new AgentSteamer_AI_Abilities();
		$this->modules['meta_tags'] = new AgentSteamer_AI_Meta_Tags();
		$this->modules['schema']    = new AgentSteamer_AI_Schema();
		$this->modules['metabox']   = new AgentSteamer_AI_Metabox();
		$this->modules['editor']    = new AgentSteamer_AI_Editor();
		$this->modules['autofill']  = new AgentSteamer_AI_Autofill();
		$this->modules['rest']      = new AgentSteamer_AI_Rest();
		$this->modules['ai']        = new AgentSteamer_AI();

		if ( is_admin() ) {
			$this->modules['admin'] = new AgentSteamer_AI_Admin();
		}

		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
		add_action( 'init', array( 'AgentSteamer_AI_Install', 'maybe_upgrade' ), 5 );
	}

	/**
	 * Flush rewrite rules once after activation.
	 */
	public static function maybe_flush_rewrites() {
		if ( get_option( 'agentsteamer_ai_flush_rewrites' ) ) {
			delete_option( 'agentsteamer_ai_flush_rewrites' );
			flush_rewrite_rules();
		}
	}

	/**
	 * Activation routine.
	 */
	public static function activate() {
		self::load_files();

		$settings = get_option( 'agentsteamer_ai_settings' );
		if ( false === $settings || ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings = wp_parse_args( $settings, agentsteamer_ai_default_settings() );
		if ( empty( $settings['indexnow_key'] ) ) {
			$settings['indexnow_key'] = AgentSteamer_AI_Indexing::generate_key();
		}
		update_option( 'agentsteamer_ai_settings', $settings );
		AgentSteamer_AI_Install::run();
		update_option( 'agentsteamer_ai_flush_rewrites', 1 );
	}

	/**
	 * Deactivation routine.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
