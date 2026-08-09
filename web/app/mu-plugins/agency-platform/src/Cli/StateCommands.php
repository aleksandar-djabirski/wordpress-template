<?php

declare(strict_types=1);

namespace AgencyPlatform\Cli;

use AgencyPlatform\State\CliOutput;
use AgencyPlatform\State\StateCommandRunner;
use AgencyPlatform\State\StateDiffer;
use AgencyPlatform\State\StateException;

/**
 * The `wp agency state-export` and `wp agency state-diff` command surface
 * (BLOCK_THEME_PROPOSAL.md §6). Every command body lives in
 * StateCommandRunner so the exit-code and stream contracts are testable
 * without WP-CLI loaded; each method here is two lines — run the body, emit
 * the captured result. register() follows the AgencyCommands precedent
 * exactly: guarded so it never touches the WP_CLI class outside an actual
 * WP-CLI request, because Plugin::boot() runs on every web request.
 */
final class StateCommands {

	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		foreach ( self::commands() as $name => $callable ) {
			\WP_CLI::add_command( $name, $callable );
		}
	}

	/**
	 * The registered command map: command name => named static method.
	 *
	 * @return array<string, array{0: class-string, 1: string}> command name => callable.
	 */
	public static function commands(): array {
		return array(
			'agency state-export' => array( self::class, 'state_export' ),
			'agency state-diff'   => array( self::class, 'state_diff' ),
		);
	}

	/**
	 * ## OPTIONS
	 *
	 * --output=<path>
	 * : Where to write the bundle. `-` writes the bundle JSON to STDOUT.
	 *   Required. There is no default output path.
	 *
	 * [--providers=<list>]
	 * : Comma-separated provider slugs. Default: every structural provider
	 *   (templates, template-parts, global-styles, navigation,
	 *   synced-patterns, fonts, media-references, custom-css).
	 *
	 * [--include-content]
	 * : Also export page/post/CPT content. Off by default: a full content
	 *   export can be very large and carries client content that structural
	 *   work does not need.
	 *
	 * @param array<int, string>   $args       Positional arguments (unused; required by the WP-CLI command signature).
	 * @param array<string, mixed> $assoc_args Associative arguments/flags, e.g. `--output=-`.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $args is required by the WP-CLI command signature (positional args precede $assoc_args); this command takes no positional arguments.
	public static function state_export( array $args, array $assoc_args = array() ): void {
		CliOutput::emit( ( new StateCommandRunner() )->state_export( $assoc_args ) );
	}

	/**
	 * ## OPTIONS
	 *
	 * [--source=<state-bundle>]
	 * : Compare against an exported bundle instead of the Git baseline. `-`
	 *   reads the bundle JSON from STDIN. Without it, current database state
	 *   is compared against the Git baseline files and database-owned
	 *   providers are informational only.
	 *
	 * [--providers=<list>]
	 * : Comma-separated provider slugs to compare.
	 *
	 * [--include-content]
	 * : Also compare page/post/CPT content.
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 *
	 * @param array<int, string>   $args       Positional arguments (unused; required by the WP-CLI command signature).
	 * @param array<string, mixed> $assoc_args Associative arguments/flags, e.g. `--source=-`.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $args is required by the WP-CLI command signature (positional args precede $assoc_args); this command takes no positional arguments.
	public static function state_diff( array $args, array $assoc_args = array() ): void {
		CliOutput::emit( ( new StateCommandRunner() )->state_diff( $assoc_args ) );
	}

	/**
	 * Pure. The exit-code contract of the deploy gate: 0 when the report
	 * carries no drift, 2 when it does.
	 *
	 * @param array<string, mixed> $report
	 */
	public static function diff_exit_code( array $report ): int {
		if ( StateDiffer::has_drift( $report ) ) {
			return StateException::EXIT_DRIFT;
		}

		return 0;
	}

	/**
	 * Pure. The human --format=table: a fixed-width table naming every diff
	 * entry with its status, classification, and whether it counts as drift.
	 * String building only — never WP_CLI\Utils::format_items() — so it
	 * stays unit-testable without WP-CLI.
	 *
	 * @param array<string, mixed> $report
	 */
	public static function format_table( array $report ): string {
		$rows = array();

		foreach ( $report['entries'] as $entry ) {
			$rows[] = array(
				'key'            => (string) $entry['key'],
				'status'         => (string) $entry['status'],
				'classification' => (string) $entry['classification'],
				'drift'          => ! empty( $entry['countsAsDrift'] ) ? 'yes' : 'no',
			);
		}

		$widths = array(
			'key'            => 3,
			'status'         => 6,
			'classification' => 14,
			'drift'          => 5,
		);

		foreach ( $rows as $row ) {
			foreach ( $widths as $column => $width ) {
				$widths[ $column ] = max( $width, strlen( $row[ $column ] ) );
			}
		}

		$lines = array(
			self::table_row(
				array(
					'key'            => 'key',
					'status'         => 'status',
					'classification' => 'classification',
					'drift'          => 'drift',
				),
				$widths
			),
		);

		foreach ( $rows as $row ) {
			$lines[] = self::table_row( $row, $widths );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * One padded table line.
	 *
	 * @param array<string, string> $row
	 * @param array<string, int>    $widths
	 */
	private static function table_row( array $row, array $widths ): string {
		$cells = array();

		foreach ( $widths as $column => $width ) {
			$cells[] = str_pad( $row[ $column ] ?? '', $width );
		}

		return implode( '  ', $cells );
	}
}
