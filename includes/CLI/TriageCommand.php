<?php

declare(strict_types=1);
/**
 * WP-CLI command for manual report triage.
 *
 * Usage:
 *   wp gratis-ai-server triage run              — Run triage now
 *   wp gratis-ai-server triage run --dry-run    — Analyze without creating issues
 *   wp gratis-ai-server triage status           — Show pending report counts
 *   wp gratis-ai-server triage enable           — Enable the hourly cron
 *   wp gratis-ai-server triage disable          — Disable the hourly cron
 *
 * @package GratisAiServer\CLI
 * @license GPL-2.0-or-later
 */

namespace GratisAiServer\CLI;

use GratisAiServer\Automations\ReportTriageAutomation;
use GratisAiServer\Core\Database;
use WP_CLI;

class TriageCommand {

	/**
	 * Run the report triage automation.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Analyze reports without creating GitHub issues or updating statuses.
	 *
	 * ## EXAMPLES
	 *
	 *   wp gratis-ai-server triage run
	 *   wp gratis-ai-server triage run --dry-run
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$dry_run = (bool) ( $assoc_args['dry-run'] ?? false );

		if ( $dry_run ) {
			WP_CLI::log( 'Dry run mode — no issues will be created, no statuses updated.' );
		}

		$token = get_option( ReportTriageAutomation::OPTION_TOKEN, '' );
		if ( '' === $token && ! $dry_run ) {
			WP_CLI::warning( 'No GitHub token configured. Set it with: wp option update gratis_ai_server_triage_github_token <token> --url=ultimateagentwp.ai' );
		}

		WP_CLI::log( 'Starting report triage...' );

		$result = ReportTriageAutomation::run( $dry_run );

		WP_CLI::log( sprintf( 'Processed: %d reports', $result['processed'] ) );
		WP_CLI::log( sprintf( 'Issues created: %d', $result['issues_created'] ) );
		WP_CLI::log( sprintf( 'Dismissed: %d', $result['dismissed'] ) );

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $error ) {
				WP_CLI::warning( $error );
			}
		}

		if ( 0 === $result['processed'] ) {
			WP_CLI::success( 'No new reports to triage.' );
		} else {
			WP_CLI::success( 'Triage complete.' );
		}
	}

	/**
	 * Show the count of pending reports by type.
	 *
	 * ## EXAMPLES
	 *
	 *   wp gratis-ai-server triage status
	 */
	public function status(): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::reports_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT report_type, COUNT(*) AS count FROM {$table} WHERE status = 'new' GROUP BY report_type ORDER BY count DESC"
		);

		if ( empty( $rows ) ) {
			WP_CLI::success( 'No pending reports.' );
			return;
		}

		$total = 0;
		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'report_type' => $row->report_type,
				'count'       => (int) $row->count,
			];
			$total += (int) $row->count;
		}

		WP_CLI\Utils\format_items( 'table', $items, [ 'report_type', 'count' ] );
		WP_CLI::log( sprintf( 'Total pending: %d', $total ) );

		$enabled = get_option( ReportTriageAutomation::OPTION_ENABLE, '0' );
		$next    = wp_next_scheduled( ReportTriageAutomation::CRON_HOOK );

		WP_CLI::log( sprintf( 'Automation: %s', '1' === $enabled ? 'enabled' : 'disabled' ) );
		if ( $next ) {
			WP_CLI::log( sprintf( 'Next run: %s UTC', gmdate( 'Y-m-d H:i:s', $next ) ) );
		}
	}

	/**
	 * Enable the hourly triage cron.
	 *
	 * ## EXAMPLES
	 *
	 *   wp gratis-ai-server triage enable
	 */
	public function enable(): void {
		update_option( ReportTriageAutomation::OPTION_ENABLE, '1' );
		ReportTriageAutomation::register();
		WP_CLI::success( 'Triage automation enabled (runs hourly).' );
	}

	/**
	 * Disable the hourly triage cron.
	 *
	 * ## EXAMPLES
	 *
	 *   wp gratis-ai-server triage disable
	 */
	public function disable(): void {
		update_option( ReportTriageAutomation::OPTION_ENABLE, '0' );
		ReportTriageAutomation::unregister();
		WP_CLI::success( 'Triage automation disabled.' );
	}
}
