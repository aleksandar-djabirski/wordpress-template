<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The only sanctioned writer to the real CLI streams (BLOCK_THEME_PROPOSAL.md
 * §6): STDOUT carries machine-readable payloads only, diagnostics and
 * warnings go to STDERR, and the process halts with the exit code the
 * contract fixes. WP_CLI::log() is never used here — it writes to STDOUT and
 * would corrupt a piped machine-readable payload.
 */
final class CliOutput {

	private function __construct() {
		// Static-only helper; never instantiated.
	}

	/** Machine-readable payload — STDOUT only. */
	public static function stdout( string $text ): void {
		\WP_CLI::line( $text );
	}

	/** Diagnostics, warnings, progress — STDERR only. */
	public static function notice( string $text ): void {
		\WP_CLI::warning( $text );
	}

	/**
	 * Writes a captured result to the real streams, then halts with its
	 * exit code: the stderr lines first, the stdout payload verbatim (only
	 * when non-empty), then the halt.
	 */
	public static function emit( StateCommandResult $result ): void {
		foreach ( self::stderr_lines( $result->stderr ) as $line ) {
			\WP_CLI::warning( $line );
		}

		if ( '' !== $result->stdout ) {
			\WP_CLI::line( $result->stdout );
		}

		\WP_CLI::halt( $result->exit_code );
	}

	/** Halts with an explicit code after any payload has been written. */
	public static function halt( int $exit_code ): void {
		\WP_CLI::halt( $exit_code );
	}

	/**
	 * The captured stderr text, split into its non-empty lines.
	 *
	 * @return list<string>
	 */
	private static function stderr_lines( string $stderr ): array {
		$lines = array();

		foreach ( explode( "\n", $stderr ) as $line ) {
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}
}
