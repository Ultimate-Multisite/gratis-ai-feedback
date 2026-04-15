<?php
/**
 * Plugin Name:       Gratis AI Feedback
 * Plugin URI:        https://github.com/Ultimate-Multisite/gratis-ai-feedback
 * Description:       Feedback collection and issue triage service for Gratis AI Agent. Receives anonymized conversation reports, stores them, and supports AI-assisted triage to GitHub issues.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Ultimate Multisite
 * Author URI:        https://developer.suspended.suspended
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gratis-ai-feedback
 * Domain Path:       /languages
 *
 * @package GratisAiFeedback
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GRATIS_AI_FEEDBACK_VERSION', '0.1.0' );
define( 'GRATIS_AI_FEEDBACK_FILE', __FILE__ );
define( 'GRATIS_AI_FEEDBACK_DIR', plugin_dir_path( __FILE__ ) );
define( 'GRATIS_AI_FEEDBACK_URL', plugin_dir_url( __FILE__ ) );

// Autoloader for PSR-4 namespace GratisAiFeedback\.
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'GratisAiFeedback\\';
	$len    = strlen( $prefix );

	if ( 0 !== strncmp( $class, $prefix, $len ) ) {
		return;
	}

	$relative = substr( $class, $len );
	$file     = GRATIS_AI_FEEDBACK_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Database installation on activation.
register_activation_hook( __FILE__, [ GratisAiFeedback\Core\Database::class, 'install' ] );

// Initialize the plugin.
add_action( 'plugins_loaded', static function (): void {
	GratisAiFeedback\Core\Plugin::instance();
} );

// Register WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'gratis-feedback api-key', GratisAiFeedback\CLI\ApiKeyCommand::class );
}
