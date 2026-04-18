<?php

declare(strict_types=1);
/**
 * Strips sensitive information from feedback report payloads.
 *
 * Designed to run on the SENDING side (Gratis AI Agent plugin) before
 * transmission, but also callable on the RECEIVING side as a defense-in-depth
 * measure. The sanitizer is deliberately aggressive — it's better to lose
 * some context than to leak credentials or PII.
 *
 * Sensitive patterns removed:
 * - API keys, tokens, passwords, secrets in any context
 * - Database connection strings and credentials
 * - Email addresses and IP addresses
 * - File system paths that reveal server structure
 * - wp-config.php values (DB_NAME, DB_USER, DB_PASSWORD, etc.)
 * - Authorization headers
 * - Cookie values
 * - Custom credential keys from tool call arguments
 *
 * @package GratisAiServer
 */

namespace GratisAiServer\Sanitization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReportSanitizer {

	/**
	 * Placeholder used when redacting values.
	 */
	const REDACTED = '[REDACTED]';

	/**
	 * Regex patterns that match sensitive string values.
	 *
	 * Each pattern is applied to string values throughout the payload.
	 *
	 * @var array<string, string>
	 */
	private static array $sensitive_patterns = [
		// API keys: sk-*, key-*, tok-*, Bearer tokens, etc.
		'api_key_prefixed'   => '/\b(sk|pk|rk|ak|key|tok|token|pat|ghp|gho|ghs|ghu|ghr|glpat|xox[bpas]|whsec|wh_)[-_][A-Za-z0-9_\-]{16,}\b/',
		// Generic long hex/base64 strings that look like secrets (40+ chars).
		'long_hex_secret'    => '/\b[A-Fa-f0-9]{40,}\b/',
		// Bearer / Basic auth headers.
		'auth_header'        => '/\b(Bearer|Basic)\s+[A-Za-z0-9+\/=_\-]{8,}\b/i',
		// Email addresses.
		'email'              => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
		// IPv4 addresses (except localhost and common private ranges kept as-is).
		'ipv4'               => '/\b(?!127\.0\.0\.1|0\.0\.0\.0|localhost)(\d{1,3}\.){3}\d{1,3}\b/',
		// Database connection strings.
		'db_dsn'             => '/\b(mysql|pgsql|sqlite|mongodb(\+srv)?):\/\/[^\s]+/i',
		// Password-like key=value pairs.
		'password_kv'        => '/(password|passwd|pwd|secret|token|api_key|apikey|auth|credential|private_key)\s*[:=]\s*\S+/i',
	];

	/**
	 * Keys in associative arrays/objects whose VALUES should always be redacted.
	 *
	 * @var list<string>
	 */
	private static array $sensitive_keys = [
		'password',
		'passwd',
		'pwd',
		'secret',
		'token',
		'api_key',
		'apikey',
		'api_secret',
		'access_token',
		'refresh_token',
		'private_key',
		'secret_key',
		'client_secret',
		'authorization',
		'cookie',
		'set-cookie',
		'x-api-key',
		'x-webhook-secret',
		'db_password',
		'db_user',
		'db_name',
		'db_host',
		'auth_key',
		'secure_auth_key',
		'logged_in_key',
		'nonce_key',
		'auth_salt',
		'secure_auth_salt',
		'logged_in_salt',
		'nonce_salt',
	];

	/**
	 * Keys in environment data whose values are safe to keep.
	 * Everything else in the environment block gets sanitized normally.
	 *
	 * @var list<string>
	 */
	private static array $safe_environment_keys = [
		'wp_version',
		'php_version',
		'plugin_version',
		'theme',
		'site_locale',
		'is_multisite',
		'active_plugins',
		'mysql_version',
		'server_software',
		'max_execution_time',
		'memory_limit',
	];

	/**
	 * Sanitize an entire report payload.
	 *
	 * @param array<string, mixed> $report The raw report data.
	 * @return array<string, mixed> The sanitized report data.
	 */
	public static function sanitize_report( array $report ): array {
		// Sanitize session_data (conversation + tool calls).
		if ( isset( $report['session_data'] ) ) {
			if ( is_string( $report['session_data'] ) ) {
				$decoded = json_decode( $report['session_data'], true );
				if ( is_array( $decoded ) ) {
					$decoded                = self::sanitize_session_data( $decoded );
					$report['session_data'] = (string) wp_json_encode( $decoded );
				} else {
					// Can't parse — redact the whole thing to be safe.
					$report['session_data'] = (string) wp_json_encode( [ 'redacted' => true, 'reason' => 'unparseable' ] );
				}
			} elseif ( is_array( $report['session_data'] ) ) {
				$report['session_data'] = self::sanitize_session_data( $report['session_data'] );
			}
		}

		// Sanitize environment block — keep only safe keys.
		if ( isset( $report['environment'] ) ) {
			if ( is_string( $report['environment'] ) ) {
				$decoded = json_decode( $report['environment'], true );
				if ( is_array( $decoded ) ) {
					$decoded                = self::sanitize_environment( $decoded );
					$report['environment'] = (string) wp_json_encode( $decoded );
				}
			} elseif ( is_array( $report['environment'] ) ) {
				$report['environment'] = self::sanitize_environment( $report['environment'] );
			}
		}

		// Sanitize the free-text user description.
		if ( isset( $report['user_description'] ) && is_string( $report['user_description'] ) ) {
			$report['user_description'] = self::sanitize_string( $report['user_description'] );
		}

		// Strip the site_url down to just the domain (no path).
		if ( isset( $report['site_url'] ) && is_string( $report['site_url'] ) ) {
			$parsed = wp_parse_url( $report['site_url'] );
			if ( is_array( $parsed ) && isset( $parsed['host'] ) ) {
				$scheme              = $parsed['scheme'] ?? 'https';
				$report['site_url'] = $scheme . '://' . $parsed['host'];
			}
		}

		return $report;
	}

	/**
	 * Sanitize session data (messages array + tool_calls array).
	 *
	 * @param array<string, mixed> $session_data Decoded session data.
	 * @return array<string, mixed> Sanitized session data.
	 */
	public static function sanitize_session_data( array $session_data ): array {
		// Sanitize conversation messages.
		if ( isset( $session_data['messages'] ) && is_array( $session_data['messages'] ) ) {
			$session_data['messages'] = array_map( [ self::class, 'sanitize_message' ], $session_data['messages'] );
		}

		// Sanitize tool call log.
		if ( isset( $session_data['tool_calls'] ) && is_array( $session_data['tool_calls'] ) ) {
			$session_data['tool_calls'] = array_map( [ self::class, 'sanitize_tool_call' ], $session_data['tool_calls'] );
		}

		return $session_data;
	}

	/**
	 * Sanitize a single conversation message.
	 *
	 * @param mixed $message A message array.
	 * @return mixed The sanitized message.
	 */
	public static function sanitize_message( mixed $message ): mixed {
		if ( ! is_array( $message ) ) {
			return $message;
		}

		// Sanitize text content in the message.
		if ( isset( $message['content'] ) && is_string( $message['content'] ) ) {
			$message['content'] = self::sanitize_string( $message['content'] );
		}

		// Sanitize parts array (multi-part messages).
		if ( isset( $message['parts'] ) && is_array( $message['parts'] ) ) {
			$message['parts'] = array_map( static function ( $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$part['text'] = self::sanitize_string( $part['text'] );
				}
				// Redact function call arguments that contain sensitive keys.
				if ( is_array( $part ) && isset( $part['function_call']['arguments'] ) ) {
					$part['function_call']['arguments'] = self::sanitize_value_recursive( $part['function_call']['arguments'] );
				}
				// Redact function response content.
				if ( is_array( $part ) && isset( $part['function_response']['content'] ) ) {
					$content = $part['function_response']['content'];
					if ( is_string( $content ) ) {
						$part['function_response']['content'] = self::sanitize_string( $content );
					} elseif ( is_array( $content ) ) {
						$part['function_response']['content'] = self::sanitize_value_recursive( $content );
					}
				}
				return $part;
			}, $message['parts'] );
		}

		return $message;
	}

	/**
	 * Sanitize a single tool call log entry.
	 *
	 * @param mixed $tool_call A tool call array.
	 * @return mixed The sanitized tool call.
	 */
	public static function sanitize_tool_call( mixed $tool_call ): mixed {
		if ( ! is_array( $tool_call ) ) {
			return $tool_call;
		}

		// Sanitize arguments.
		if ( isset( $tool_call['arguments'] ) ) {
			$tool_call['arguments'] = self::sanitize_value_recursive( $tool_call['arguments'] );
		}
		if ( isset( $tool_call['args'] ) ) {
			$tool_call['args'] = self::sanitize_value_recursive( $tool_call['args'] );
		}

		// Sanitize response/result.
		if ( isset( $tool_call['response'] ) ) {
			$tool_call['response'] = self::sanitize_value_recursive( $tool_call['response'] );
		}
		if ( isset( $tool_call['result'] ) ) {
			$tool_call['result'] = self::sanitize_value_recursive( $tool_call['result'] );
		}

		return $tool_call;
	}

	/**
	 * Sanitize the environment block — only keep known-safe keys.
	 *
	 * @param array<string, mixed> $environment Raw environment data.
	 * @return array<string, mixed> Filtered environment data.
	 */
	public static function sanitize_environment( array $environment ): array {
		$safe = [];
		foreach ( $environment as $key => $value ) {
			if ( in_array( $key, self::$safe_environment_keys, true ) ) {
				// Active plugins list: strip path info, keep only slugs.
				if ( 'active_plugins' === $key && is_array( $value ) ) {
					$value = array_map( static function ( $plugin ): string {
						// WordPress stores plugins as "folder/file.php" — keep only the folder name.
						$parts = explode( '/', (string) $plugin );
						return $parts[0] ?? (string) $plugin;
					}, $value );
				}
				$safe[ $key ] = $value;
			}
		}
		return $safe;
	}

	/**
	 * Apply sensitive patterns to a single string value.
	 *
	 * @param string $value The input string.
	 * @return string The sanitized string.
	 */
	public static function sanitize_string( string $value ): string {
		foreach ( self::$sensitive_patterns as $pattern ) {
			$value = (string) preg_replace( $pattern, self::REDACTED, $value );
		}

		// Strip absolute server paths (e.g. /var/www/html/wp-content/...).
		$value = (string) preg_replace( '#/(?:var|home|srv|opt|usr|www|tmp)/[^\s"\'<>]+#', self::REDACTED, $value );

		return $value;
	}

	/**
	 * Recursively sanitize a mixed value (array, string, or scalar).
	 *
	 * For associative arrays, keys matching $sensitive_keys get their
	 * values replaced entirely. All string values get pattern-matched.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return mixed The sanitized value.
	 */
	public static function sanitize_value_recursive( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return self::sanitize_string( $value );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$result = [];
		foreach ( $value as $key => $item ) {
			$lower_key = strtolower( (string) $key );

			// If this key is a known sensitive key, redact the entire value.
			if ( in_array( $lower_key, self::$sensitive_keys, true ) ) {
				$result[ $key ] = self::REDACTED;
				continue;
			}

			// Recurse into nested structures.
			$result[ $key ] = self::sanitize_value_recursive( $item );
		}

		return $result;
	}

	/**
	 * Strip tool call results entirely from session data.
	 *
	 * This is a more aggressive option — keeps the tool call names and
	 * arguments (sanitized) but removes all response payloads. Useful
	 * when the user wants to report the conversation flow without any
	 * tool output.
	 *
	 * @param array<string, mixed> $session_data Decoded session data.
	 * @return array<string, mixed> Session data with tool results stripped.
	 */
	public static function strip_tool_results( array $session_data ): array {
		// Strip from messages.
		if ( isset( $session_data['messages'] ) && is_array( $session_data['messages'] ) ) {
			$session_data['messages'] = array_map( static function ( $message ) {
				if ( ! is_array( $message ) ) {
					return $message;
				}
				if ( isset( $message['parts'] ) && is_array( $message['parts'] ) ) {
					$message['parts'] = array_map( static function ( $part ) {
						if ( is_array( $part ) && isset( $part['function_response'] ) ) {
							$part['function_response']['content'] = '[tool result stripped for privacy]';
						}
						return $part;
					}, $message['parts'] );
				}
				return $message;
			}, $session_data['messages'] );
		}

		// Strip from tool call log.
		if ( isset( $session_data['tool_calls'] ) && is_array( $session_data['tool_calls'] ) ) {
			$session_data['tool_calls'] = array_map( static function ( $call ) {
				if ( ! is_array( $call ) ) {
					return $call;
				}
				if ( isset( $call['response'] ) ) {
					$call['response'] = '[stripped]';
				}
				if ( isset( $call['result'] ) ) {
					$call['result'] = '[stripped]';
				}
				return $call;
			}, $session_data['tool_calls'] );
		}

		return $session_data;
	}
}
