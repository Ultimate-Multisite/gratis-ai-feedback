<?php
/**
 * Uninstall handler for Gratis AI Feedback.
 *
 * Runs when the plugin is deleted via the WordPress admin UI.
 * Drops all custom tables and removes options.
 *
 * @package GratisAiFeedback
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop tables.
$tables = [
	$wpdb->prefix . 'gratis_ai_feedback_reports',
	$wpdb->prefix . 'gratis_ai_feedback_api_keys',
];

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Remove options.
delete_option( 'gratis_ai_feedback_db_version' );
