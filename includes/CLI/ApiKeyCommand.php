<?php

declare(strict_types=1);
/**
 * WP-CLI commands for managing feedback API keys.
 *
 * Usage:
 *   wp gratis-ai-server api-key generate --label="Production Site"
 *   wp gratis-ai-server api-key list
 *   wp gratis-ai-server api-key revoke <id>
 *
 * @package GratisAiServer
 */

namespace GratisAiServer\CLI;

use GratisAiServer\Core\Database;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ApiKeyCommand {

	/**
	 * Generate a new API key.
	 *
	 * ## OPTIONS
	 *
	 * [--label=<label>]
	 * : A human-readable label for the key.
	 *
	 * [--site-url=<url>]
	 * : The site URL this key is intended for.
	 *
	 * [--rate-limit=<limit>]
	 * : Reports per hour limit. Default: 10.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gratis-feedback api-key generate --label="My Site"
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function generate( array $args, array $assoc_args ): void {
		$label      = (string) ( $assoc_args['label'] ?? '' );
		$site_url   = (string) ( $assoc_args['site-url'] ?? '' );
		$rate_limit = (int) ( $assoc_args['rate-limit'] ?? 10 );

		// Generate a cryptographically secure random key.
		$raw_key  = 'gas_' . bin2hex( random_bytes( 24 ) );
		$key_hash = hash( 'sha256', $raw_key );

		global $wpdb;
		/** @var \wpdb $wpdb */

		$result = $wpdb->insert(
			Database::api_keys_table(),
			[
				'key_hash'            => $key_hash,
				'label'               => $label,
				'site_url'            => $site_url,
				'is_active'           => 1,
				'rate_limit_per_hour' => $rate_limit,
				'created_at'          => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%d', '%d', '%s' ]
		);

		if ( false === $result ) {
			WP_CLI::error( 'Failed to insert API key.' );
			return;
		}

		$id = (int) $wpdb->insert_id;

		WP_CLI::success( "API key created (ID: {$id})." );
		WP_CLI::line( '' );
		WP_CLI::line( "Key: {$raw_key}" );
		WP_CLI::line( '' );
		WP_CLI::warning( 'Save this key now. It cannot be retrieved later.' );
	}

	/**
	 * List all API keys.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gratis-feedback api-key list
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::api_keys_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, label, site_url, is_active, rate_limit_per_hour, created_at, last_used_at FROM %i ORDER BY id",
				$table
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			WP_CLI::line( 'No API keys found.' );
			return;
		}

		WP_CLI\Utils\format_items(
			'table',
			$rows,
			[ 'id', 'label', 'site_url', 'is_active', 'rate_limit_per_hour', 'created_at', 'last_used_at' ]
		);
	}

	/**
	 * Revoke (deactivate) an API key.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The API key ID to revoke.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gratis-feedback api-key revoke 3
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function revoke( array $args, array $assoc_args ): void {
		$id = (int) ( $args[0] ?? 0 );

		if ( $id <= 0 ) {
			WP_CLI::error( 'Please provide a valid API key ID.' );
			return;
		}

		global $wpdb;
		/** @var \wpdb $wpdb */

		$updated = $wpdb->update(
			Database::api_keys_table(),
			[ 'is_active' => 0 ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			WP_CLI::error( 'Failed to revoke key.' );
			return;
		}

		if ( 0 === $updated ) {
			WP_CLI::warning( "No key found with ID {$id}." );
			return;
		}

		WP_CLI::success( "API key {$id} revoked." );
	}
}
