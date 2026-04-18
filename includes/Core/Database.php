<?php

declare(strict_types=1);
/**
 * Database schema management.
 *
 * Creates and upgrades all tables: feedback reports, API keys, resale clients,
 * and resale usage logs. Uses WordPress dbDelta() for safe incremental schema changes.
 *
 * @package GratisAiServer
 */

namespace GratisAiServer\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database {

	const DB_VERSION_OPTION = 'gratis_ai_server_db_version';
	const DB_VERSION        = '2.0.0';

	/**
	 * Get the reports table name.
	 */
	public static function reports_table(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'gratis_ai_server_reports';
	}

	/**
	 * Get the API keys table name.
	 */
	public static function api_keys_table(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'gratis_ai_server_api_keys';
	}

	/**
	 * Get the resale clients table name.
	 */
	public static function resale_clients_table(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'gratis_ai_server_resale_clients';
	}

	/**
	 * Get the resale usage log table name.
	 */
	public static function resale_usage_table(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */
		return $wpdb->prefix . 'gratis_ai_server_resale_usage';
	}

	/**
	 * Install tables. Called on plugin activation.
	 */
	public static function install(): void {
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Upgrade tables if the stored version is outdated.
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( self::DB_VERSION_OPTION, '0' );
		if ( version_compare( (string) $installed, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Create or update all tables via dbDelta.
	 */
	private static function create_tables(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$charset = $wpdb->get_charset_collate();

		$reports_table        = self::reports_table();
		$api_keys_table       = self::api_keys_table();
		$resale_clients_table = self::resale_clients_table();
		$resale_usage_table   = self::resale_usage_table();

		$sql = "CREATE TABLE {$reports_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_url varchar(255) NOT NULL DEFAULT '',
			api_key_id bigint(20) unsigned NOT NULL DEFAULT 0,
			report_type varchar(50) NOT NULL DEFAULT 'user_reported',
			model_id varchar(100) NOT NULL DEFAULT '',
			provider_id varchar(100) NOT NULL DEFAULT '',
			session_data longtext NOT NULL,
			environment longtext NOT NULL,
			user_description text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'new',
			github_issue_url varchar(255) NOT NULL DEFAULT '',
			triage_summary text NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			reviewed_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_report_type (report_type),
			KEY idx_api_key_id (api_key_id),
			KEY idx_created_at (created_at)
		) {$charset};

		CREATE TABLE {$api_keys_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			key_hash varchar(64) NOT NULL,
			label varchar(255) NOT NULL DEFAULT '',
			site_url varchar(255) NOT NULL DEFAULT '',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			rate_limit_per_hour int unsigned NOT NULL DEFAULT 10,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_used_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_key_hash (key_hash),
			KEY idx_is_active (is_active)
		) {$charset};

		CREATE TABLE {$resale_clients_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description text NOT NULL DEFAULT '',
			api_key varchar(64) NOT NULL,
			monthly_token_quota bigint(20) unsigned NOT NULL DEFAULT 0,
			tokens_used_this_month bigint(20) unsigned NOT NULL DEFAULT 0,
			quota_reset_at datetime DEFAULT NULL,
			allowed_models longtext NOT NULL DEFAULT '',
			markup_percent decimal(5,2) NOT NULL DEFAULT 0.00,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			request_count int(11) NOT NULL DEFAULT 0,
			last_used_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY api_key (api_key),
			KEY enabled (enabled)
		) {$charset};

		CREATE TABLE {$resale_usage_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint(20) unsigned NOT NULL,
			provider_id varchar(100) NOT NULL DEFAULT '',
			model_id varchar(100) NOT NULL DEFAULT '',
			prompt_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			completion_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			cost_usd decimal(10,6) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'success',
			error_message text NOT NULL DEFAULT '',
			duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY client_id (client_id),
			KEY created_at (created_at),
			KEY model_id (model_id),
			KEY status (status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a report row and return the new ID.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function insert_report( array $data ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$defaults = [
			'site_url'         => '',
			'api_key_id'       => 0,
			'report_type'      => 'user_reported',
			'model_id'         => '',
			'provider_id'      => '',
			'session_data'     => '{}',
			'environment'      => '{}',
			'user_description' => '',
			'status'           => 'new',
			'created_at'       => current_time( 'mysql', true ),
		];

		$row = array_merge( $defaults, $data );

		$result = $wpdb->insert(
			self::reports_table(),
			$row,
			[
				'%s', // site_url
				'%d', // api_key_id
				'%s', // report_type
				'%s', // model_id
				'%s', // provider_id
				'%s', // session_data
				'%s', // environment
				'%s', // user_description
				'%s', // status
				'%s', // created_at
			]
		);

		if ( false === $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Look up an API key by its SHA-256 hash.
	 *
	 * @param string $key_hash SHA-256 hex hash of the raw API key.
	 * @return object|null Row object or null.
	 */
	public static function get_api_key_by_hash( string $key_hash ): ?object {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE key_hash = %s AND is_active = 1",
				self::api_keys_table(),
				$key_hash
			)
		);

		return $row ?: null;
	}

	/**
	 * Count reports from a given API key in the last hour.
	 *
	 * @param int $api_key_id The API key row ID.
	 * @return int Report count.
	 */
	public static function count_recent_reports( int $api_key_id ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE api_key_id = %d AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
				self::reports_table(),
				$api_key_id
			)
		);

		return (int) $count;
	}

	/**
	 * Update the last_used_at timestamp for an API key.
	 *
	 * @param int $api_key_id The API key row ID.
	 */
	public static function touch_api_key( int $api_key_id ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$wpdb->update(
			self::api_keys_table(),
			[ 'last_used_at' => current_time( 'mysql', true ) ],
			[ 'id' => $api_key_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}
}
