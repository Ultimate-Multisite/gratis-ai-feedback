<?php

declare(strict_types=1);
/**
 * Automated triage of incoming feedback reports.
 *
 * Runs on a WP cron schedule (hourly by default). Fetches all reports with
 * status "new", groups them by report_type, and uses the WordPress AI Client
 * SDK to analyze whether they indicate systemic failures or obvious bugs.
 *
 * Actionable findings are filed as GitHub issues on the gratis-ai-agent repo.
 * Reports are then marked "issue_created" (with the issue URL) or "dismissed".
 *
 * Configuration (wp_options on the site where gratis-ai-server is active):
 *   gratis_ai_server_triage_github_repo   — "owner/repo" slug (default: Ultimate-Multisite/gratis-ai-agent)
 *   gratis_ai_server_triage_github_token  — Personal access token with issues:write scope
 *   gratis_ai_server_triage_enabled       — "1" to enable (default: disabled)
 *
 * @package GratisAiServer\Automations
 * @license GPL-2.0-or-later
 */

namespace GratisAiServer\Automations;

use GratisAiServer\Core\Database;

class ReportTriageAutomation {

	const CRON_HOOK     = 'gratis_ai_server_triage_reports';
	const OPTION_REPO   = 'gratis_ai_server_triage_github_repo';
	const OPTION_TOKEN  = 'gratis_ai_server_triage_github_token';
	const OPTION_ENABLE = 'gratis_ai_server_triage_enabled';

	const DEFAULT_REPO = 'Ultimate-Multisite/gratis-ai-agent';

	/**
	 * Maximum number of new reports to process per run.
	 */
	const BATCH_SIZE = 50;

	/**
	 * Register the cron schedule and hook.
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, [ self::class, 'run' ] );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the cron event (called on plugin deactivation).
	 */
	public static function unregister(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Main triage entry point. Called by WP cron or WP-CLI.
	 *
	 * @param bool $dry_run If true, analyze but don't create issues or update statuses.
	 * @return array{processed: int, issues_created: int, dismissed: int, errors: list<string>}
	 */
	public static function run( bool $dry_run = false ): array {
		$result = [
			'processed'      => 0,
			'issues_created' => 0,
			'dismissed'      => 0,
			'errors'         => [],
		];

		$enabled = get_option( self::OPTION_ENABLE, '0' );
		if ( '1' !== $enabled && ! $dry_run ) {
			return $result;
		}

		$reports = self::fetch_new_reports();
		if ( empty( $reports ) ) {
			return $result;
		}

		$result['processed'] = count( $reports );

		// Group by report_type to detect clusters.
		$grouped = self::group_reports( $reports );

		// Analyze each group with AI.
		foreach ( $grouped as $report_type => $group ) {
			$analysis = self::analyze_group( $report_type, $group );

			if ( null === $analysis ) {
				$result['errors'][] = "AI analysis failed for report_type: {$report_type}";
				continue;
			}

			if ( $analysis['action'] === 'create_issue' ) {
				if ( $dry_run ) {
					$result['issues_created'] += count( $group );
					continue;
				}

				$issue_url = self::create_github_issue( $analysis );

				if ( is_string( $issue_url ) ) {
					self::mark_reports_as_issued( $group, $issue_url, $analysis['summary'] );
					$result['issues_created'] += count( $group );
				} else {
					$result['errors'][] = "GitHub issue creation failed for {$report_type}: {$issue_url}";
				}
			} else {
				if ( ! $dry_run ) {
					self::mark_reports_as_dismissed( $group, $analysis['summary'] );
				}
				$result['dismissed'] += count( $group );
			}
		}

		return $result;
	}

	/**
	 * Fetch all reports with status "new", limited to BATCH_SIZE.
	 *
	 * @return list<object>
	 */
	private static function fetch_new_reports(): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::reports_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'new' ORDER BY created_at ASC LIMIT %d",
				self::BATCH_SIZE
			)
		);

		return $rows ?: [];
	}

	/**
	 * Group reports by report_type.
	 *
	 * @param list<object> $reports
	 * @return array<string, list<object>>
	 */
	private static function group_reports( array $reports ): array {
		$grouped = [];

		foreach ( $reports as $report ) {
			$type = $report->report_type ?? 'unknown';
			if ( ! isset( $grouped[ $type ] ) ) {
				$grouped[ $type ] = [];
			}
			$grouped[ $type ][] = $report;
		}

		return $grouped;
	}

	/**
	 * Use the WordPress AI Client SDK to analyze a group of reports.
	 *
	 * @param string        $report_type The report type (e.g., "spin_detected", "timeout").
	 * @param list<object>  $reports     The reports in this group.
	 * @return array{action: string, severity: string, title: string, summary: string, body: string}|null
	 */
	private static function analyze_group( string $report_type, array $reports ): ?array {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return null;
		}

		// Build a summary of the reports for the AI to analyze.
		$report_summaries = [];
		foreach ( $reports as $i => $report ) {
			$session_data = json_decode( $report->session_data ?? '{}', true );
			$environment  = json_decode( $report->environment ?? '{}', true );

			$summary = [
				'report_id'        => $report->id,
				'site_url'         => $report->site_url ?? '',
				'model_id'         => $report->model_id ?? '',
				'provider_id'      => $report->provider_id ?? '',
				'user_description' => $report->user_description ?? '',
				'wp_version'       => $environment['wp_version'] ?? '',
				'php_version'      => $environment['php_version'] ?? '',
				'plugin_version'   => $environment['plugin_version'] ?? '',
			];

			// Include last few messages for context (truncated to avoid token bloat).
			$messages = $session_data['messages'] ?? [];
			$last_messages = array_slice( $messages, -6 );
			$summary['last_messages'] = array_map( static function ( $msg ) {
				$content = $msg['content'] ?? '';
				if ( strlen( $content ) > 500 ) {
					$content = substr( $content, 0, 500 ) . '... [truncated]';
				}
				return [
					'role'    => $msg['role'] ?? 'unknown',
					'content' => $content,
				];
			}, $last_messages );

			$report_summaries[] = $summary;
		}

		$report_count = count( $reports );
		$sites_count  = count( array_unique( array_column( $report_summaries, 'site_url' ) ) );

		$prompt = <<<PROMPT
You are a bug triage assistant for the Gratis AI Agent WordPress plugin.

Analyze these {$report_count} feedback reports of type "{$report_type}" from {$sites_count} different site(s).

Reports:
```json
%s
```

Determine whether these reports indicate:
1. A systemic bug or failure in the plugin that needs a GitHub issue
2. User error, expected behavior, or one-off issues that should be dismissed

Respond with EXACTLY this JSON structure (no other text):
{
  "action": "create_issue" or "dismiss",
  "severity": "critical", "high", "medium", or "low",
  "title": "Short issue title if action is create_issue, empty string if dismiss",
  "summary": "1-2 sentence explanation of your decision",
  "body": "Full GitHub issue body in markdown if action is create_issue. Include: what's happening, how many reports, affected versions/providers, reproduction hints from the session data. Empty string if dismiss."
}

Rules:
- Multiple reports of the same failure from different sites = systemic, create an issue
- spin_detected or timeout from a single site with one report = likely user config, dismiss unless the session data shows an obvious plugin bug
- max_iterations with reasonable iteration counts (>10) = expected behavior, dismiss
- Always include report IDs in the issue body for traceability
- If the session data shows the AI repeatedly calling the same tool and getting errors, that's a bug
PROMPT;

		$prompt = sprintf( $prompt, (string) wp_json_encode( $report_summaries, JSON_PRETTY_PRINT ) );

		$ai_result = wp_ai_client_prompt(
			[
				'prompt'             => $prompt,
				'system_instruction' => 'You are a precise bug triage assistant. Respond only with valid JSON. No markdown fences, no explanation outside the JSON.',
				'max_tokens'         => 2000,
				'temperature'        => 0.2,
			]
		);

		if ( is_wp_error( $ai_result ) ) {
			return null;
		}

		$text = is_string( $ai_result ) ? $ai_result : ( $ai_result['text'] ?? '' );

		// Strip markdown fences if present.
		$text = preg_replace( '/^```(?:json)?\s*/m', '', $text );
		$text = preg_replace( '/\s*```\s*$/m', '', $text );

		$parsed = json_decode( trim( $text ), true );

		if ( ! is_array( $parsed ) || ! isset( $parsed['action'] ) ) {
			return null;
		}

		return [
			'action'   => $parsed['action'] === 'create_issue' ? 'create_issue' : 'dismiss',
			'severity' => $parsed['severity'] ?? 'medium',
			'title'    => $parsed['title'] ?? '',
			'summary'  => $parsed['summary'] ?? '',
			'body'     => $parsed['body'] ?? '',
		];
	}

	/**
	 * Create a GitHub issue via the GitHub REST API.
	 *
	 * @param array{title: string, body: string, severity: string} $analysis
	 * @return string|string Issue URL on success, error message on failure.
	 */
	private static function create_github_issue( array $analysis ): string {
		$repo  = get_option( self::OPTION_REPO, self::DEFAULT_REPO );
		$token = get_option( self::OPTION_TOKEN, '' );

		if ( '' === $token ) {
			return 'No GitHub token configured (set gratis_ai_server_triage_github_token option)';
		}

		$labels = [ 'feedback-triage' ];
		$severity = $analysis['severity'] ?? 'medium';
		if ( in_array( $severity, [ 'critical', 'high' ], true ) ) {
			$labels[] = 'priority: high';
		}

		$body = $analysis['body'] . "\n\n---\n*Auto-triaged by Gratis AI Server feedback automation.*";

		$response = wp_remote_post(
			"https://api.github.com/repos/{$repo}/issues",
			[
				'headers' => [
					'Authorization' => "Bearer {$token}",
					'Accept'        => 'application/vnd.github+json',
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'GratisAiServer/1.0',
				],
				'body'    => (string) wp_json_encode( [
					'title'  => $analysis['title'],
					'body'   => $body,
					'labels' => $labels,
				] ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 !== $status ) {
			return "GitHub API returned HTTP {$status}: " . ( $data['message'] ?? 'unknown error' );
		}

		return $data['html_url'] ?? '';
	}

	/**
	 * Mark reports as issue_created with the GitHub issue URL.
	 *
	 * @param list<object> $reports
	 * @param string       $issue_url
	 * @param string       $summary
	 */
	private static function mark_reports_as_issued( array $reports, string $issue_url, string $summary ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::reports_table();
		$now   = current_time( 'mysql', true );

		foreach ( $reports as $report ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				[
					'status'           => 'issue_created',
					'github_issue_url' => $issue_url,
					'triage_summary'   => $summary,
					'reviewed_at'      => $now,
				],
				[ 'id' => $report->id ],
				[ '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
		}
	}

	/**
	 * Mark reports as dismissed with a triage summary.
	 *
	 * @param list<object> $reports
	 * @param string       $summary
	 */
	private static function mark_reports_as_dismissed( array $reports, string $summary ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table = Database::reports_table();
		$now   = current_time( 'mysql', true );

		foreach ( $reports as $report ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				[
					'status'          => 'dismissed',
					'triage_summary'  => $summary,
					'reviewed_at'     => $now,
				],
				[ 'id' => $report->id ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);
		}
	}
}
