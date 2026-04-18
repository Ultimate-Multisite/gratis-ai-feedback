<?php

declare(strict_types=1);
/**
 * Main plugin orchestrator.
 *
 * Singleton that wires up all subsystems. Designed to be extensible —
 * future services (license validation, usage analytics, remote config)
 * register here.
 *
 * @package GratisAiServer
 */

namespace GratisAiServer\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	/** @var self|null */
	private static ?self $instance = null;

	/**
	 * Get or create the singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up hooks.
	 */
	private function __construct() {
		// Ensure DB is current on admin loads (handles upgrades).
		add_action( 'admin_init', [ Database::class, 'maybe_upgrade' ] );

		// Register REST routes.
		add_action( 'rest_api_init', [ \GratisAiServer\REST\ReportsController::class, 'register_routes' ] );
		add_action( 'rest_api_init', [ \GratisAiServer\REST\ResaleApiController::class, 'register_routes' ] );

		// Register the report triage cron automation.
		\GratisAiServer\Automations\ReportTriageAutomation::register();
	}
}
