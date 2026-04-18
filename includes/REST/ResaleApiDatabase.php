<?php

declare(strict_types=1);
/**
 * Database layer for the Resale API.
 *
 * Manages two tables via Database::resale_clients_table() and
 * Database::resale_usage_table(). Schema is created by Database::install().
 *
 * @package GratisAiServer\REST
 * @license GPL-2.0-or-later
 */

namespace GratisAiServer\REST;

use GratisAiServer\Core\Database;

class ResaleApiDatabase {

	// ─── Client CRUD ─────────────────────────────────────────────────

	/**
	 * List all resale clients ordered by name.
	 *
	 * @return object[]
	 */
	public static function list_clients(): array {
		global $wpdb;
		/** @var \wpdb $wpdb */
		$table = Database::resale_clients_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" ) ?: [];
	}

	/**
	 * Get a single client by ID.
	 *
	 * @param int $id Client ID.
	 * @return object|null
	 */
	public static function get_client( int $id ): ?object {
		global $wpdb;
		/** @var \wpdb $wpdb */
		$table = Database::resale_clients_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		return $row ?: null;
	}

	/**
	 * Get a client by API key (used for authentication on the proxy endpoint).
	 *
	 * @param string $api_key The client's API key.
	 * @return object|null
	 */
	public static function get_client_by_key( string $api_key ): ?object {
		global $wpdb;
		/** @var \wpdb $wpdb */
		$table = Database::resale_clients_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE api_key = %s", $api_key ) );
		return $row ?: null;
	}

	/**
	 * Create a new resale client.
	 *
	 * @param array<string, mixed> $data Client data.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function create_client( array $data ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now = current_time( 'mysql' );

		$insert = [
			'name'                   => $data['name'] ?? '',
			'description'            => $data['description'] ?? '',
			'api_key'                => $data['api_key'] ?? '',
			'monthly_token_quota'    => (int) ( $data['monthly_token_quota'] ?? 0 ),
			'tokens_used_this_month' => max( 0, (int) ( $data['tokens_used_this_month'] ?? 0 ) ),
			'quota_reset_at'         => $data['quota_reset_at'] ?? null,
			'allowed_models'         => wp_json_encode( $data['allowed_models'] ?? [] ),
			'markup_percent'         => (float) ( $data['markup_percent'] ?? 0.0 ),
			'enabled'                => (int) ( $data['enabled'] ?? 1 ),
			'request_count'          => 0,
			'last_used_at'           => null,
			'created_at'             => $now,
			'updated_at'             => $now,
		];

		$formats = [ '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%f', '%d', '%d', '%s', '%s', '%s' ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( Database::resale_clients_table(), $insert, $formats );

		return false !== $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update a resale client.
	 *
	 * @param int                  $id   Client ID.
	 * @param array<string, mixed> $data Fields to update.
	 * @return bool
	 */
	public static function update_client( int $id, array $data ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		if ( isset( $data['allowed_models'] ) && is_array( $data['allowed_models'] ) ) {
			$data['allowed_models'] = wp_json_encode( $data['allowed_models'] );
		}

		$data['updated_at'] = current_time( 'mysql' );

		$formats = [];
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, [ 'monthly_token_quota', 'tokens_used_this_month', 'enabled', 'request_count' ], true ) ) {
				$formats[] = '%d';
			} elseif ( 'markup_percent' === $key ) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( Database::resale_clients_table(), $data, [ 'id' => $id ], $formats, [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Delete a resale client and its usage logs.
	 *
	 * @param int $id Client ID.
	 * @return bool
	 */
	public static function delete_client( int $id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Database::resale_usage_table(), [ 'client_id' => $id ], [ '%d' ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( Database::resale_clients_table(), [ 'id' => $id ], [ '%d' ] );

		return false !== $result;
	}

	// ─── Usage logging ───────────────────────────────────────────────

	/**
	 * Log a proxied request and update client counters.
	 */
	public static function log_usage(
		int $client_id,
		string $provider_id,
		string $model_id,
		int $prompt_tokens,
		int $completion_tokens,
		float $cost_usd,
		string $status,
		string $error_message,
		int $duration_ms
	) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now = current_time( 'mysql' );

		$insert = [
			'client_id'         => $client_id,
			'provider_id'       => $provider_id,
			'model_id'          => $model_id,
			'prompt_tokens'     => $prompt_tokens,
			'completion_tokens' => $completion_tokens,
			'cost_usd'          => $cost_usd,
			'status'            => $status,
			'error_message'     => $error_message,
			'duration_ms'       => $duration_ms,
			'created_at'        => $now,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( Database::resale_usage_table(), $insert, [ '%d', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%d', '%s' ] );

		if ( false !== $result ) {
			$total_tokens  = $prompt_tokens + $completion_tokens;
			$clients_table = Database::resale_clients_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$clients_table} SET request_count = request_count + 1, tokens_used_this_month = tokens_used_this_month + %d, last_used_at = %s, updated_at = %s WHERE id = %d",
					$total_tokens,
					$now,
					$now,
					$client_id
				)
			);

			return (int) $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Get usage logs for a client.
	 */
	public static function get_usage( int $client_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */
		$table = Database::resale_usage_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d", $client_id, $limit, $offset )
		);
		return $rows ?: [];
	}

	/**
	 * Count total usage log rows for a client.
	 */
	public static function count_usage( int $client_id ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */
		$table = Database::resale_usage_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE client_id = %d", $client_id ) );
	}

	/**
	 * Get aggregated usage summary for a client.
	 */
	public static function get_usage_summary( int $client_id, ?string $start_date = null, ?string $end_date = null ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */
		$table = Database::resale_usage_table();

		$where  = 'WHERE client_id = %d';
		$params = [ $client_id ];

		if ( $start_date ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $start_date . ' 00:00:00';
		}
		if ( $end_date ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $end_date . ' 23:59:59';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS request_count, COALESCE(SUM(prompt_tokens), 0) AS total_prompt_tokens, COALESCE(SUM(completion_tokens), 0) AS total_completion_tokens, COALESCE(SUM(cost_usd), 0) AS total_cost_usd FROM {$table} {$where}",
				...$params
			),
			ARRAY_A
		);

		return $row ?? [
			'request_count'           => 0,
			'total_prompt_tokens'     => 0,
			'total_completion_tokens' => 0,
			'total_cost_usd'          => 0,
		];
	}

	/**
	 * Reset the monthly token counter for a client.
	 */
	public static function reset_monthly_quota( int $client_id ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$now           = current_time( 'mysql' );
		$next_reset    = gmdate( 'Y-m-d H:i:s', (int) strtotime( '+1 month', (int) strtotime( $now ) ) );
		$clients_table = Database::resale_clients_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$clients_table} SET tokens_used_this_month = 0, quota_reset_at = %s, updated_at = %s WHERE id = %d",
				$next_reset,
				$now,
				$client_id
			)
		);

		return false !== $result;
	}
}
