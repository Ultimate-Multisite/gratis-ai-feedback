<?php

declare(strict_types=1);
/**
 * Resale API controller.
 *
 * Manages resale API clients and proxy endpoint. The proxy endpoint accepts
 * OpenAI-compatible chat completions requests, authenticates via API key,
 * enforces quota limits, forwards to the site's AI provider via the
 * WordPress 7.0 AI Client SDK, and returns the response.
 *
 * Endpoints:
 *   POST   /gratis-ai-server/v1/resale/proxy               (API key auth)
 *   GET    /gratis-ai-server/v1/resale/clients              (admin)
 *   POST   /gratis-ai-server/v1/resale/clients              (admin)
 *   GET    /gratis-ai-server/v1/resale/clients/{id}         (admin)
 *   PATCH  /gratis-ai-server/v1/resale/clients/{id}         (admin)
 *   DELETE /gratis-ai-server/v1/resale/clients/{id}         (admin)
 *   POST   /gratis-ai-server/v1/resale/clients/{id}/rotate-key (admin)
 *   GET    /gratis-ai-server/v1/resale/clients/{id}/usage   (admin)
 *   GET    /gratis-ai-server/v1/resale/clients/{id}/usage/summary (admin)
 *
 * @package GratisAiServer
 * @license GPL-2.0-or-later
 */

namespace GratisAiServer\REST;

use GratisAiServer\Core\Database;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages resale API clients and proxy endpoint via REST.
 */
class ResaleApiController {

	const REST_NAMESPACE = 'gratis-ai-server/v1';

	/**
	 * Register all resale API REST routes.
	 */
	public static function register_routes(): void {
		$instance = new self();

		// ─── Proxy endpoint ──────────────────────────────────────────
		register_rest_route(
			self::REST_NAMESPACE,
			'/resale/proxy',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $instance, 'handle_proxy' ],
				'permission_callback' => [ $instance, 'check_resale_permission' ],
				'args'                => [
					'model'       => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'messages'    => [
						'required' => true,
						'type'     => 'array',
					],
					'temperature' => [
						'required' => false,
						'type'     => 'number',
					],
					'max_tokens'  => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// ─── Admin CRUD endpoints ────────────────────────────────────
		register_rest_route(
			self::REST_NAMESPACE,
			'/resale/clients',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $instance, 'handle_list_clients' ],
					'permission_callback' => [ $instance, 'check_admin_permission' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $instance, 'handle_create_client' ],
					'permission_callback' => [ $instance, 'check_admin_permission' ],
					'args'                => [
						'name'                => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'description'         => [
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						],
						'monthly_token_quota' => [
							'required'          => false,
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						],
						'allowed_models'      => [
							'required' => false,
							'type'     => 'array',
							'default'  => [],
						],
						'markup_percent'      => [
							'required' => false,
							'type'     => 'number',
							'default'  => 0.0,
						],
						'enabled'             => [
							'required' => false,
							'type'     => 'boolean',
							'default'  => true,
						],
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/resale/clients/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $instance, 'handle_get_client' ],
					'permission_callback' => [ $instance, 'check_admin_permission' ],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $instance, 'handle_update_client' ],
					'permission_callback' => [ $instance, 'check_admin_permission' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $instance, 'handle_delete_client' ],
					'permission_callback' => [ $instance, 'check_admin_permission' ],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/resale/clients/(?P<id>\d+)/rotate-key',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $instance, 'handle_rotate_key' ],
				'permission_callback' => [ $instance, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/resale/clients/(?P<id>\d+)/usage',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $instance, 'handle_get_usage' ],
				'permission_callback' => [ $instance, 'check_admin_permission' ],
				'args'                => [
					'limit'  => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					],
					'offset' => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/resale/clients/(?P<id>\d+)/usage/summary',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $instance, 'handle_get_usage_summary' ],
				'permission_callback' => [ $instance, 'check_admin_permission' ],
				'args'                => [
					'start_date' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'end_date'   => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Permission check — admin only.
	 */
	public function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission check for the public resale proxy endpoint.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return true|WP_Error
	 */
	public function check_resale_permission( WP_REST_Request $request ) {
		$api_key = $request->get_header( 'X-Resale-API-Key' );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'resale_api_key_required',
				__( 'X-Resale-API-Key header is required.', 'gratis-ai-server' ),
				[ 'status' => 401 ]
			);
		}

		$client = ResaleApiDatabase::get_client_by_key( $api_key );

		if ( ! $client ) {
			return new WP_Error(
				'resale_api_unauthorized',
				__( 'Invalid API key.', 'gratis-ai-server' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	// ─── Proxy handler ───────────────────────────────────────────────

	/**
	 * POST /resale/proxy — forward an AI request on behalf of a resale client.
	 *
	 * Uses the WordPress 7.0 AI Client SDK (wp_ai_client_prompt()) to dispatch
	 * the request to whatever provider is configured on this site.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_proxy( WP_REST_Request $request ) {
		$api_key = $request->get_header( 'X-Resale-API-Key' );
		$client  = ResaleApiDatabase::get_client_by_key( (string) $api_key );

		if ( ! $client ) {
			return new WP_Error( 'resale_api_unauthorized', __( 'Invalid API key.', 'gratis-ai-server' ), [ 'status' => 401 ] );
		}

		if ( ! (bool) $client->enabled ) {
			return new WP_Error( 'resale_api_disabled', __( 'This API client is disabled.', 'gratis-ai-server' ), [ 'status' => 403 ] );
		}

		// Check monthly quota.
		$quota = (int) $client->monthly_token_quota;
		if ( $quota > 0 ) {
			$reset_at = $client->quota_reset_at;
			if ( $reset_at && strtotime( $reset_at ) <= time() ) {
				ResaleApiDatabase::reset_monthly_quota( (int) $client->id );
				$client = ResaleApiDatabase::get_client_by_key( (string) $api_key );
				if ( ! $client ) {
					return new WP_Error( 'resale_api_unauthorized', __( 'Invalid API key.', 'gratis-ai-server' ), [ 'status' => 401 ] );
				}
			}

			$used = (int) ( $client->tokens_used_this_month ?? 0 );
			if ( $used >= $quota ) {
				return new WP_Error( 'resale_api_quota_exceeded', __( 'Monthly token quota exceeded.', 'gratis-ai-server' ), [ 'status' => 429 ] );
			}
		}

		// Resolve model.
		$requested_model = sanitize_text_field( (string) ( $request->get_param( 'model' ) ?? '' ) );
		$allowed_models  = json_decode( (string) ( $client->allowed_models ?? '[]' ), true );

		if ( ! empty( $allowed_models ) && ! in_array( $requested_model, $allowed_models, true ) ) {
			return new WP_Error(
				'resale_api_model_not_allowed',
				sprintf( __( 'Model "%s" is not allowed for this client.', 'gratis-ai-server' ), $requested_model ),
				[ 'status' => 403 ]
			);
		}

		// Build the prompt from messages array.
		$messages = $request->get_param( 'messages' );
		if ( empty( $messages ) || ! is_array( $messages ) ) {
			return new WP_Error( 'resale_api_messages_required', __( 'messages array is required.', 'gratis-ai-server' ), [ 'status' => 400 ] );
		}

		$system_instruction = '';
		$user_message       = '';
		foreach ( $messages as $msg ) {
			$role    = sanitize_text_field( (string) ( $msg['role'] ?? '' ) );
			$content = (string) ( $msg['content'] ?? '' );
			if ( 'system' === $role && '' === $system_instruction ) {
				$system_instruction = $content;
			} elseif ( 'user' === $role ) {
				$user_message = $content;
			}
		}

		if ( '' === $user_message ) {
			return new WP_Error( 'resale_api_no_user_message', __( 'No user message found in messages array.', 'gratis-ai-server' ), [ 'status' => 400 ] );
		}

		// Dispatch via WordPress AI Client SDK.
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'resale_api_no_ai_client', __( 'WordPress AI Client SDK not available.', 'gratis-ai-server' ), [ 'status' => 503 ] );
		}

		$prompt_args = [
			'prompt' => $user_message,
		];

		if ( '' !== $system_instruction ) {
			$prompt_args['system_instruction'] = $system_instruction;
		}

		if ( '' !== $requested_model ) {
			$prompt_args['model'] = $requested_model;
		}

		$max_tokens  = absint( $request->get_param( 'max_tokens' ) ?? 0 );
		$temperature = $request->get_param( 'temperature' );

		if ( $max_tokens > 0 ) {
			$prompt_args['max_tokens'] = $max_tokens;
		}

		if ( null !== $temperature ) {
			$prompt_args['temperature'] = (float) $temperature;
		}

		$start_ms = (int) round( microtime( true ) * 1000 );

		$builder = wp_ai_client_prompt( $user_message );

		if ( '' !== $requested_model ) {
			$builder = $builder->using_model_preference( $requested_model );
		}

		$result   = $builder->generate_text_result();
		$duration = (int) round( microtime( true ) * 1000 ) - $start_ms;

		// Handle errors.
		if ( is_wp_error( $result ) ) {
			ResaleApiDatabase::log_usage(
				(int) $client->id,
				'',
				$requested_model,
				0,
				0,
				0.0,
				'error',
				$result->get_error_message(),
				$duration
			);
			return new WP_Error( 'resale_api_upstream_error', $result->get_error_message(), [ 'status' => 502 ] );
		}

		// Extract response data from WP 7.0 GenerativeAiResult.
		$reply             = '';
		$prompt_tokens     = 0;
		$completion_tokens = 0;
		$model_used        = $requested_model;

		if ( $result instanceof \WordPress\AiClient\Results\DTO\GenerativeAiResult ) {
			$reply             = $result->toText();
			$usage             = $result->getTokenUsage();
			$prompt_tokens     = $usage->getPromptTokens();
			$completion_tokens = $usage->getCompletionTokens();
		} elseif ( is_string( $result ) ) {
			$reply = $result;
		}

		// Simple cost estimation (can be refined later with a dedicated cost calculator).
		$cost = 0.0;

		// Apply markup if configured.
		$markup = (float) ( $client->markup_percent ?? 0.0 );
		if ( $markup > 0.0 && $cost > 0.0 ) {
			$cost = round( $cost * ( 1 + $markup / 100 ), 6 );
		}

		ResaleApiDatabase::log_usage(
			(int) $client->id,
			'',
			$model_used,
			$prompt_tokens,
			$completion_tokens,
			$cost,
			'success',
			'',
			$duration
		);

		// Return OpenAI-compatible response.
		return new WP_REST_Response(
			[
				'id'      => 'chatcmpl-' . wp_generate_uuid4(),
				'object'  => 'chat.completion',
				'created' => time(),
				'model'   => $model_used,
				'choices' => [
					[
						'index'         => 0,
						'message'       => [
							'role'    => 'assistant',
							'content' => $reply,
						],
						'finish_reason' => 'stop',
					],
				],
				'usage'   => [
					'prompt_tokens'     => $prompt_tokens,
					'completion_tokens' => $completion_tokens,
					'total_tokens'      => $prompt_tokens + $completion_tokens,
				],
			],
			200
		);
	}

	// ─── Admin CRUD handlers ─────────────────────────────────────────

	/**
	 * GET /resale/clients — list all resale clients.
	 */
	public function handle_list_clients( WP_REST_Request $request ): WP_REST_Response {
		$clients = ResaleApiDatabase::list_clients();
		$clients = array_map( [ $this, 'sanitize_client_for_response' ], $clients );
		return new WP_REST_Response( $clients, 200 );
	}

	/**
	 * POST /resale/clients — create a new resale client.
	 */
	public function handle_create_client( WP_REST_Request $request ) {
		$api_key = self::generate_api_key();
		$quota   = absint( $request->get_param( 'monthly_token_quota' ) ?? 0 );

		$data = [
			'name'                => $request->get_param( 'name' ),
			'description'         => $request->get_param( 'description' ) ?? '',
			'api_key'             => $api_key,
			'monthly_token_quota' => $quota,
			'quota_reset_at'      => $quota > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( '+1 month' ) ) : null,
			'allowed_models'      => $request->get_param( 'allowed_models' ) ?? [],
			'markup_percent'      => (float) ( $request->get_param( 'markup_percent' ) ?? 0.0 ),
			'enabled'             => $request->get_param( 'enabled' ) ?? true,
		];

		$id = ResaleApiDatabase::create_client( $data );

		if ( false === $id ) {
			return new WP_Error( 'resale_api_create_failed', __( 'Failed to create resale client.', 'gratis-ai-server' ), [ 'status' => 500 ] );
		}

		$client = ResaleApiDatabase::get_client( $id );
		if ( ! $client ) {
			return new WP_Error( 'resale_api_not_found', __( 'Client not found after creation.', 'gratis-ai-server' ), [ 'status' => 500 ] );
		}

		$response              = $this->sanitize_client_for_response( $client );
		$response['api_key']   = $api_key;
		$response['proxy_url'] = rest_url( self::REST_NAMESPACE . '/resale/proxy' );

		return new WP_REST_Response( $response, 201 );
	}

	/**
	 * GET /resale/clients/{id}
	 */
	public function handle_get_client( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$client = ResaleApiDatabase::get_client( $id );

		if ( ! $client ) {
			return new WP_Error( 'resale_api_not_found', __( 'Resale client not found.', 'gratis-ai-server' ), [ 'status' => 404 ] );
		}

		$response              = $this->sanitize_client_for_response( $client );
		$response['proxy_url'] = rest_url( self::REST_NAMESPACE . '/resale/proxy' );

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * PATCH /resale/clients/{id}
	 */
	public function handle_update_client( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$client = ResaleApiDatabase::get_client( $id );

		if ( ! $client ) {
			return new WP_Error( 'resale_api_not_found', __( 'Resale client not found.', 'gratis-ai-server' ), [ 'status' => 404 ] );
		}

		$data = [];
		foreach ( [ 'name', 'description', 'monthly_token_quota', 'allowed_models', 'markup_percent', 'enabled' ] as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$data[ $field ] = $value;
			}
		}

		if ( isset( $data['monthly_token_quota'] ) && (int) $data['monthly_token_quota'] > 0 && empty( $client->quota_reset_at ) ) {
			$data['quota_reset_at'] = gmdate( 'Y-m-d H:i:s', strtotime( '+1 month' ) );
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'resale_api_no_data', __( 'No valid fields provided for update.', 'gratis-ai-server' ), [ 'status' => 400 ] );
		}

		if ( ! ResaleApiDatabase::update_client( $id, $data ) ) {
			return new WP_Error( 'resale_api_update_failed', __( 'Failed to update resale client.', 'gratis-ai-server' ), [ 'status' => 500 ] );
		}

		$client = ResaleApiDatabase::get_client( $id );
		if ( ! $client ) {
			return new WP_Error( 'resale_api_not_found', __( 'Client not found after update.', 'gratis-ai-server' ), [ 'status' => 500 ] );
		}

		$response              = $this->sanitize_client_for_response( $client );
		$response['proxy_url'] = rest_url( self::REST_NAMESPACE . '/resale/proxy' );

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * DELETE /resale/clients/{id}
	 */
	public function handle_delete_client( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$client = ResaleApiDatabase::get_client( $id );

		if ( ! $client ) {
			return new WP_Error( 'resale_api_not_found', __( 'Resale client not found.', 'gratis-ai-server' ), [ 'status' => 404 ] );
		}

		ResaleApiDatabase::delete_client( $id );
		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	/**
	 * POST /resale/clients/{id}/rotate-key
	 */
	public function handle_rotate_key( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$client = ResaleApiDatabase::get_client( $id );

		if ( ! $client ) {
			return new WP_Error( 'resale_api_not_found', __( 'Resale client not found.', 'gratis-ai-server' ), [ 'status' => 404 ] );
		}

		$new_key = self::generate_api_key();
		ResaleApiDatabase::update_client( $id, [ 'api_key' => $new_key ] );

		return new WP_REST_Response( [ 'id' => $id, 'api_key' => $new_key ], 200 );
	}

	/**
	 * GET /resale/clients/{id}/usage
	 */
	public function handle_get_usage( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$client = ResaleApiDatabase::get_client( $id );

		if ( ! $client ) {
			return new WP_Error( 'resale_api_not_found', __( 'Resale client not found.', 'gratis-ai-server' ), [ 'status' => 404 ] );
		}

		$limit  = min( absint( $request->get_param( 'limit' ) ?? 20 ), 100 );
		$offset = absint( $request->get_param( 'offset' ) ?? 0 );

		return new WP_REST_Response(
			[
				'logs'   => ResaleApiDatabase::get_usage( $id, $limit, $offset ),
				'total'  => ResaleApiDatabase::count_usage( $id ),
				'limit'  => $limit,
				'offset' => $offset,
			],
			200
		);
	}

	/**
	 * GET /resale/clients/{id}/usage/summary
	 */
	public function handle_get_usage_summary( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$client = ResaleApiDatabase::get_client( $id );

		if ( ! $client ) {
			return new WP_Error( 'resale_api_not_found', __( 'Resale client not found.', 'gratis-ai-server' ), [ 'status' => 404 ] );
		}

		$start_date = sanitize_text_field( (string) ( $request->get_param( 'start_date' ) ?? '' ) ) ?: null;
		$end_date   = sanitize_text_field( (string) ( $request->get_param( 'end_date' ) ?? '' ) ) ?: null;

		$summary = ResaleApiDatabase::get_usage_summary( $id, $start_date, $end_date );

		return new WP_REST_Response(
			array_merge(
				$summary,
				[
					'client_id'              => $id,
					'monthly_token_quota'    => (int) $client->monthly_token_quota,
					'tokens_used_this_month' => (int) $client->tokens_used_this_month,
					'quota_reset_at'         => $client->quota_reset_at,
				]
			),
			200
		);
	}

	// ─── Private helpers ─────────────────────────────────────────────

	/**
	 * Generate a unique resale API key.
	 */
	private static function generate_api_key(): string {
		return 'gas_' . wp_generate_password( 32, false );
	}

	/**
	 * Strip the API key from a client object before returning it.
	 *
	 * @param object|array<string, mixed> $client Raw client row.
	 * @return array<string, mixed>
	 */
	private function sanitize_client_for_response( $client ): array {
		if ( is_object( $client ) ) {
			$client = (array) $client;
		}

		unset( $client['api_key'] );

		if ( isset( $client['allowed_models'] ) && is_string( $client['allowed_models'] ) ) {
			$decoded                  = json_decode( $client['allowed_models'], true );
			$client['allowed_models'] = is_array( $decoded ) ? $decoded : [];
		}

		foreach ( [ 'id', 'monthly_token_quota', 'tokens_used_this_month', 'request_count' ] as $int_field ) {
			if ( isset( $client[ $int_field ] ) ) {
				$client[ $int_field ] = (int) $client[ $int_field ];
			}
		}

		if ( isset( $client['enabled'] ) ) {
			$client['enabled'] = (bool) $client['enabled'];
		}
		if ( isset( $client['markup_percent'] ) ) {
			$client['markup_percent'] = (float) $client['markup_percent'];
		}

		$client['has_key'] = true;

		return $client;
	}
}
