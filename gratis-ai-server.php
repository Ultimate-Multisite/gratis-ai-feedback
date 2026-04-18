<?php
/**
 * Plugin Name:       Gratis AI Server
 * Plugin URI:        https://ultimateagentwp.ai
 * Description:       Server-side services for Gratis AI Agent — feedback collection, issue triage, and resale API proxy. Receives anonymized conversation reports, manages resale API clients with quotas, and supports AI-assisted triage to GitHub issues.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Author:            Ultimate Multisite
 * Author URI:        https://ultimatemultisite.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gratis-ai-server
 * Domain Path:       /languages
 *
 * @package GratisAiServer
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GRATIS_AI_SERVER_VERSION', '1.0.0' );
define( 'GRATIS_AI_SERVER_FILE', __FILE__ );
define( 'GRATIS_AI_SERVER_DIR', plugin_dir_path( __FILE__ ) );
define( 'GRATIS_AI_SERVER_URL', plugin_dir_url( __FILE__ ) );

// Autoloader for PSR-4 namespace GratisAiServer\.
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'GratisAiServer\\';
	$len    = strlen( $prefix );

	if ( 0 !== strncmp( $class, $prefix, $len ) ) {
		return;
	}

	$relative = substr( $class, $len );
	$file     = GRATIS_AI_SERVER_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Database installation on activation.
register_activation_hook( __FILE__, [ GratisAiServer\Core\Database::class, 'install' ] );

// Initialize the plugin.
add_action( 'plugins_loaded', static function (): void {
	GratisAiServer\Core\Plugin::instance();
} );

// Register WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'gratis-ai-server api-key', GratisAiServer\CLI\ApiKeyCommand::class );
	\WP_CLI::add_command( 'gratis-ai-server triage', GratisAiServer\CLI\TriageCommand::class );
}

// Clean up on deactivation.
register_deactivation_hook( __FILE__, [ GratisAiServer\Automations\ReportTriageAutomation::class, 'unregister' ] );
