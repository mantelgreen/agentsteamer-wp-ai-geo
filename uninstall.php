<?php
/**
 * Uninstall routine.
 *
 * @package AgentSteamer_AI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'agentsteamer_ai_settings' );
delete_option( 'agentsteamer_ai_flush_rewrites' );
delete_option( 'agentsteamer_ai_db_version' );
delete_option( 'agentsteamer_ai_index_log' );
delete_transient( 'agentsteamer_ai_audit_results' );
delete_transient( 'agentsteamer_ai_topic_suggestions' );

// Remove per-post SEO meta.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_asi\_%'"
);

// Drop plugin tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentsteamer_ai_redirects" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agentsteamer_ai_404" ); // phpcs:ignore WordPress.DB
