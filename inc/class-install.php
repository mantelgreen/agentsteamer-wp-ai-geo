<?php
/**
 * Centralised database installer.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs/upgrades plugin tables.
 */
class AgentSteamer_AI_Install {

	/**
	 * Schema version.
	 */
	const VERSION = '2';

	/**
	 * Run all installers.
	 */
	public static function run() {
		AgentSteamer_AI_Redirect::install();
		AgentSteamer_AI_Review::install();
		update_option( 'agentsteamer_ai_db_version', self::VERSION );
	}

	/**
	 * Run installers when the schema is outdated.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'agentsteamer_ai_db_version' ) !== self::VERSION ) {
			self::run();
		}
	}
}
