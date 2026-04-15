<?php

declare(strict_types=1);
/**
 * REST API controller for receiving feedback reports.
 *
 * Endpoints:
 *   POST /gratis-feedback/v1/reports   — Submit a new report (API key auth)
 *   GET  /gratis-feedback/v1/reports   — List reports (admin, manage_options)
 *   GET  /gratis-feedback/v1/reports/N — Get single report (admin)
 *   PATCH /gratis-feedback/v1/reports/N — Update report status (admin)
 *
 * Authentication for the POST endpoint uses an API key passed in the
 * X-Feedback-Api-Key header. The key is hashed with SHA-256 and looked
 * up in the api_keys table.
 *
 * @package GratisAiFeedback
 */

namespace GratisAiFeedback\REST;

use GratisAiFeedback\Core\Database;
use GratisAiFeedback\Sanitization\ReportSanitizer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReportsController {

	const NAMESPACE = 'gratis-feedback/v1';

	/**
	 * Valid report types accepted by the endpoint.
	 *
	 * @var list<string>
	 */
	const VALID_REPORT_TYPES = [
		'spin_detected',
		'timeout',
		'max_iterations',
		'self_reported',
		'user_reported',
		'thumbs_down',
	];

	/**
	 * Valid report statuses.
	 *
	 * @var list<string>
	 */
	const VALID_STATUSES = [
		'new',
		'reviewing',
		'issue_created',
		'dismissed',
	];

	/**
	 * Register all REST routes.
	 */
	public static function register_routes(): void {
		$instance = new self();

		// Public: submit a report (API key auth).
		register_rest_route(
			self::NAMESPACE,
			'/reports',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $instance, 'create_report' ],
					'permission_callback' => [ $instance, 'check_api_key_permission' ],
					'args'                => self::create_report_args(),
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $instance, 'list_reports' ],
					'permission_callback' => [ $instance, 'check_admin_permission' ],
					'args'                => [
						'status'   => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'per_page' => [
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						],
						'page'     => [
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		// Admin: single report view + update.
		register_rest_route(
			self::NAMESPACE,
			'/reports/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $instance, 'get_report' ],
					'permission_callback' => [ $instance, 'check_admin_permission' ],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $instance, 'update_report' ],
					'permission_callback' => [ $instance, 'check_admin_permission' ],
					'args'                => [
						'status'           => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'github_issue_url' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_url',
						],
						'triage_summary'   => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						],
					],
				],
			]
		);
	}

	/**
	 * Argument schema for the POST /reports endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function create_report_args(): array {
		return [
			'report_type'      => [
				'required'          => true,
				'type'              => 'string',
				'enum'              => self::VALID_REPORT_TYPES,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'session_data'     => [
				'required' => true,
				'type'     => 'object',
			],
			'environment'      => [
				'required' => false,
				'type'     => 'object',
				'default'  => new \stdClass(),
			],
			'model_id'         => [
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'provider_id'      => [
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'user_description' => [
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'site_url'         => [
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_url',
			],
			'strip_tool_results' => [
				'required' => false,
				'type'     => 'boolean',
				'default'  => false,
			],
		];
	}

	/**
	 * Validate the API key from the request header.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function check_api_key_permission( WP_REST_Request $request ) {
		$raw_key = $request->get_header( 'X-Feedback-Api-Key' );

		if ( empty( $raw_key ) ) {
			return new WP_Error(
				'gratis_feedback_missing_key',
				__( 'Missing X-Feedback-Api-Key header.', 'gratis-ai-feedback' ),
				[ 'status' => 401 ]
			);
		}

		$key_hash = hash( 'sha256', $raw_key );
		$key_row  = Database::get_api_key_by_hash( $key_hash );

		if ( null === $key_row ) {
			return new WP_Error(
				'gratis_feedback_invalid_key',
				__( 'Invalid API key.', 'gratis-ai-feedback' ),
				[ 'status' => 403 ]
			);
		}

		// Rate limiting.
		$recent_count = Database::count_recent_reports( (int) $key_row->id );
		$limit        = (int) $key_row->rate_limit_per_hour;

		if ( $recent_count >= $limit ) {
			return new WP_Error(
				'gratis_feedback_rate_limited',
				sprintf(
					/* translators: %d: rate limit per hour */
					__( 'Rate limit exceeded. Maximum %d reports per hour.', 'gratis-ai-feedback' ),
					$limit
				),
				[ 'status' => 429 ]
			);
		}

		// Store the validated key ID on the request for use in the callback.
		$request->set_param( '_api_key_id', (int) $key_row->id );

		return true;
	}

	/**
	 * Check admin permission for read/update endpoints.
	 *
	 * @return bool
	 */
	public function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Handle POST /reports — create a new feedback report.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_report( WP_REST_Request $request ) {
		$api_key_id = (int) $request->get_param( '_api_key_id' );

		// Build the report payload.
		$session_data = $request->get_param( 'session_data' );
		if ( ! is_array( $session_data ) ) {
			$session_data = [];
		}

		$environment = $request->get_param( 'environment' );
		if ( ! is_array( $environment ) ) {
			$environment = [];
		}

		// Optional: strip tool results entirely before sanitizing.
		$strip_results = (bool) $request->get_param( 'strip_tool_results' );
		if ( $strip_results ) {
			$session_data = ReportSanitizer::strip_tool_results( $session_data );
		}

		// Build the raw report for sanitization.
		$raw_report = [
			'site_url'         => (string) $request->get_param( 'site_url' ),
			'api_key_id'       => $api_key_id,
			'report_type'      => (string) $request->get_param( 'report_type' ),
			'model_id'         => (string) $request->get_param( 'model_id' ),
			'provider_id'      => (string) $request->get_param( 'provider_id' ),
			'session_data'     => $session_data,
			'environment'      => $environment,
			'user_description' => (string) $request->get_param( 'user_description' ),
		];

		// Sanitize the entire report — defense in depth.
		$sanitized = ReportSanitizer::sanitize_report( $raw_report );

		// Encode complex fields to JSON strings for DB storage.
		$sanitized['session_data'] = is_array( $sanitized['session_data'] )
			? (string) wp_json_encode( $sanitized['session_data'] )
			: (string) $sanitized['session_data'];

		$sanitized['environment'] = is_array( $sanitized['environment'] )
			? (string) wp_json_encode( $sanitized['environment'] )
			: (string) $sanitized['environment'];

		// Insert into the database.
		$report_id = Database::insert_report( $sanitized );

		if ( false === $report_id ) {
			return new WP_Error(
				'gratis_feedback_insert_failed',
				__( 'Failed to save report.', 'gratis-ai-feedback' ),
				[ 'status' => 500 ]
			);
		}

		// Update the API key's last_used_at.
		Database::touch_api_key( $api_key_id );

		/**
		 * Fires after a feedback report is successfully created.
		 *
		 * @param int                  $report_id The new report row ID.
		 * @param array<string, mixed> $sanitized The sanitized report data.
		 */
		do_action( 'gratis_ai_feedback_report_created', $report_id, $sanitized );

		return new WP_REST_Response(
			[
				'id'      => $report_id,
				'status'  => 'new',
				'message' => __( 'Report received. Thank you for your feedback.', 'gratis-ai-feedback' ),
			],
			201
		);
	}

	/**
	 * Handle GET /reports — list reports with optional status filter.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function list_reports( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table    = Database::reports_table();
		$status   = $request->get_param( 'status' );
		$per_page = min( (int) $request->get_param( 'per_page' ), 100 );
		$page     = max( (int) $request->get_param( 'page' ), 1 );
		$offset   = ( $page - 1 ) * $per_page;

		if ( $status && in_array( $status, self::VALID_STATUSES, true ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, site_url, report_type, model_id, provider_id, status, github_issue_url, triage_summary, created_at, reviewed_at FROM %i WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$table,
					$status,
					$per_page,
					$offset
				)
			);
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE status = %s",
					$table,
					$status
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, site_url, report_type, model_id, provider_id, status, github_issue_url, triage_summary, created_at, reviewed_at FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$table,
					$per_page,
					$offset
				)
			);
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i",
					$table
				)
			);
		}

		$response = new WP_REST_Response( $rows ?: [], 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );

		return $response;
	}

	/**
	 * Handle GET /reports/N — get a single report with full session data.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_report( WP_REST_Request $request ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$id  = (int) $request->get_param( 'id' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %d",
				Database::reports_table(),
				$id
			)
		);

		if ( ! $row ) {
			return new WP_Error(
				'gratis_feedback_not_found',
				__( 'Report not found.', 'gratis-ai-feedback' ),
				[ 'status' => 404 ]
			);
		}

		// Decode JSON fields for the response.
		$row->session_data = json_decode( $row->session_data ?? '{}', true );
		$row->environment  = json_decode( $row->environment ?? '{}', true );

		return new WP_REST_Response( $row, 200 );
	}

	/**
	 * Handle PATCH /reports/N — update report status, triage summary, or GitHub issue link.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_report( WP_REST_Request $request ) {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$id    = (int) $request->get_param( 'id' );
		$table = Database::reports_table();

		// Verify the report exists.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE id = %d",
				$table,
				$id
			)
		);

		if ( ! $exists ) {
			return new WP_Error(
				'gratis_feedback_not_found',
				__( 'Report not found.', 'gratis-ai-feedback' ),
				[ 'status' => 404 ]
			);
		}

		$updates = [];
		$formats = [];

		$status = $request->get_param( 'status' );
		if ( null !== $status && in_array( $status, self::VALID_STATUSES, true ) ) {
			$updates['status']      = $status;
			$formats[]              = '%s';
			$updates['reviewed_at'] = current_time( 'mysql', true );
			$formats[]              = '%s';
		}

		$github_url = $request->get_param( 'github_issue_url' );
		if ( null !== $github_url ) {
			$updates['github_issue_url'] = $github_url;
			$formats[]                   = '%s';
		}

		$triage = $request->get_param( 'triage_summary' );
		if ( null !== $triage ) {
			$updates['triage_summary'] = $triage;
			$formats[]                 = '%s';
		}

		if ( empty( $updates ) ) {
			return new WP_Error(
				'gratis_feedback_no_updates',
				__( 'No valid fields to update.', 'gratis-ai-feedback' ),
				[ 'status' => 400 ]
			);
		}

		$wpdb->update(
			$table,
			$updates,
			[ 'id' => $id ],
			$formats,
			[ '%d' ]
		);

		return new WP_REST_Response( [ 'id' => $id, 'updated' => true ], 200 );
	}
}
