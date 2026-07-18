<?php

declare(strict_types=1);

namespace AgencyPlatform\Logging;

/**
 * Thin structured-logging wrapper around `error_log()`, shared by anything
 * in this project that needs a log line with context. Every call goes
 * through `redact()` first, so secrets can never leak into logs just
 * because a caller forgot to scrub them.
 */
final class Logger {

	/**
	 * Matches context keys that must never be logged verbatim: anything
	 * that looks like a password, secret, token, API key, auth value, or
	 * credential — case-insensitively, wherever it appears in the key.
	 */
	private const SENSITIVE_KEY_PATTERN = '/pass(word)?|secret|token|api[_-]?key|auth|credential/i';

	private function __construct() {
		// Static-only utility class; never instantiated.
	}

	/**
	 * Logs a single-line JSON record: {"channel":..., "message":..., "context":...}.
	 *
	 * @param array<string, mixed> $context
	 */
	public static function log( string $channel, string $message, array $context = array() ): void {
		$payload = array(
			'channel' => $channel,
			'message' => $message,
			'context' => self::redact( $context ),
		);

		$encoded = wp_json_encode( $payload );

		if ( false === $encoded ) {
			// The payload contained something wp_json_encode() couldn't
			// handle (e.g. invalid UTF-8 in $channel/$message/$context); log
			// a fixed, always-valid fallback line rather than silently
			// dropping it or risking a second encode failure.
			$encoded = '{"channel":"agency-platform","message":"log payload omitted: not JSON-encodable"}';
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional structured logging sink, not leftover debug code.
		error_log( $encoded );
	}

	/**
	 * Recursively replaces any value whose key looks sensitive with the
	 * literal string '[redacted]'. Pure and side-effect free, so it can be
	 * unit-tested without WordPress or touching the error log.
	 *
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public static function redact( array $context ): array {
		$redacted = array();

		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && 1 === preg_match( self::SENSITIVE_KEY_PATTERN, $key ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$redacted[ $key ] = self::redact( $value );
				continue;
			}

			$redacted[ $key ] = $value;
		}

		return $redacted;
	}
}
