<?php
/**
 * Uninstall handler for Gratis AI Server.
 *
 * Runs when the plugin is deleted via the WordPress admin UI.
 * Drops all custom tables and removes options.
 *
 * @package GratisAiServer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop tables.
$tables = [
	$wpdb->prefix . 'gratis_ai_server_reports',
	$wpdb->prefix . 'gratis_ai_server_api_keys',
	$wpdb->prefix . 'gratis_ai_server_resale_clients',
	$wpdb->prefix . 'gratis_ai_server_resale_usage',
];

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Remove options.
delete_option( 'gratis_ai_server_db_version' );
