<?php

declare(strict_types=1);

namespace AgencyPlatform\Cli;

use AgencyPlatform\State\CliOutput;
use AgencyPlatform\State\Promotion\PromotionCommandRunner;

/**
 * The `wp agency promote-overrides` and `wp agency promotion-backups`
 * command surface (BLOCK_THEME_PROPOSAL.md §6). Every command body lives in
 * PromotionCommandRunner so the exit-code and stream contracts are testable
 * without WP-CLI loaded; each method here runs the body and emits the
 * captured result through CliOutput — the ONLY writer to either stream, so
 * a stray echo can never corrupt the machine-readable STDOUT document a
 * deployment wrapper is about to parse. register() follows the
 * AgencyCommands precedent exactly: guarded so it never touches the WP_CLI
 * class outside an actual WP-CLI request, because Plugin::boot() runs on
 * every web request.
 */
final class PromotionCommands {

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
			'agency promote-overrides' => array( self::class, 'promote_overrides' ),
			'agency promotion-backups' => array( self::class, 'promotion_backups' ),
		);
	}

	/**
	 * Promotes database overrides into the Git-owned theme and settles the
	 * promotion lifecycle (BLOCK_THEME_PROPOSAL.md §7.5–§7.10).
	 *
	 * Exactly ONE mode flag is required: --prepare, --seal, --finalize,
	 * --confirm, --rollback or --heartbeat. STDOUT carries exactly one JSON
	 * document per run — the outcome report, or the signed manifest itself
	 * when --manifest=- — and every diagnostic goes to STDERR.
	 *
	 * ## OPTIONS
	 *
	 * --prepare
	 * : Stage the selected records into prepared files and write a signed,
	 *   unsealed manifest. Requires --source, --select and --manifest.
	 *
	 * --seal
	 * : Bind the prepared files to a deploy commit. Requires --manifest
	 *   and --deploy-commit.
	 *
	 * --finalize
	 * : Apply the sealed manifest to this host's database. Requires
	 *   --manifest.
	 *
	 * --confirm
	 * : Settle a finalized promotion as confirmed, releasing its locks.
	 *   Requires --manifest.
	 *
	 * --rollback
	 * : Restore the pre-finalize database rows from the protected backups.
	 *   Requires --manifest.
	 *
	 * --heartbeat
	 * : Refresh the promotion's per-record locks. Requires --manifest.
	 *
	 * --source=<path|-->
	 * : The state bundle to promote from. `-` reads the bundle JSON from
	 *   STDIN. Required for --prepare.
	 *
	 * --select=<provider:slug,...>
	 * : The comma-separated records to promote, e.g.
	 *   `templates:page,template-parts:site-header`. Required for
	 *   --prepare.
	 *
	 * --manifest=<path|-->
	 * : The promotion manifest. `-` writes the manifest JSON to STDOUT for
	 *   --prepare and --seal, and reads it from STDIN for every other mode.
	 *   Required for every mode.
	 *
	 * --deploy-commit=<sha>
	 * : The 40-hex commit the prepared files are sealed to. Required for
	 *   --seal.
	 *
	 * @param array<int, string>   $args       Positional arguments (unused; required by the WP-CLI command signature).
	 * @param array<string, mixed> $assoc_args Associative arguments/flags, e.g. `--prepare`.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $args is required by the WP-CLI command signature; this command takes no positional arguments.
	public static function promote_overrides( array $args, array $assoc_args = array() ): void {
		CliOutput::emit( ( new PromotionCommandRunner() )->promote_overrides( $assoc_args ) );
	}

	/**
	 * Lists or prunes the protected promotion backups (BLOCK_THEME_PROPOSAL.md
	 * §7.9). `list` renders one row per backup as JSON by default;
	 * --format=table renders the same rows as a fixed-width table. `prune`
	 * deletes the backups past BOTH their retention window and the
	 * --older-than cutoff, and returns the pruned promotion ids.
	 *
	 * ## OPTIONS
	 *
	 * <list|prune>
	 * : The subcommand: `list` renders the backups, `prune` deletes the
	 *   ones past their retention window.
	 *
	 * [--format=<format>]
	 * : json or table. Default: json. Applies to `list` only.
	 *
	 * [--older-than=<duration>]
	 * : The cutoff for `prune`: "30d", "12h" or a plain number of seconds.
	 *   Default: 30d.
	 *
	 * [--dry-run]
	 * : Preview what `prune` would delete without deleting anything.
	 *
	 * @param array<int, string>   $args       Positional arguments: the subcommand, `list` or `prune`.
	 * @param array<string, mixed> $assoc_args Associative arguments/flags, e.g. `--format=table`.
	 */
	public static function promotion_backups( array $args, array $assoc_args = array() ): void {
		CliOutput::emit( ( new PromotionCommandRunner() )->promotion_backups( $args, $assoc_args ) );
	}

	/**
	 * The human --format=table for promotion-backups list: one fixed-width
	 * row per backup with the columns promotionId, finalizedAtUtc,
	 * settlementStatus, records, bytes, retentionUntilUtc and prunable.
	 * String building only — never WP_CLI\Utils::format_items() — so it
	 * stays testable without WP-CLI.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	public static function format_backups_table( array $rows ): string {
		$columns = array( 'promotionId', 'finalizedAtUtc', 'settlementStatus', 'records', 'bytes', 'retentionUntilUtc', 'prunable' );
		$widths  = array();

		foreach ( $columns as $column ) {
			$widths[ $column ] = strlen( $column );
		}

		foreach ( $rows as $row ) {
			foreach ( $columns as $column ) {
				$widths[ $column ] = max( $widths[ $column ], strlen( self::backup_cell( $row, $column ) ) );
			}
		}

		$header = array();

		foreach ( $columns as $column ) {
			$header[ $column ] = $column;
		}

		$lines = array( self::backup_table_row( $header, $widths ) );

		foreach ( $rows as $row ) {
			$cells = array();

			foreach ( $columns as $column ) {
				$cells[ $column ] = self::backup_cell( $row, $column );
			}

			$lines[] = self::backup_table_row( $cells, $widths );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * One cell of a backup row: `prunable` renders as yes/no, everything
	 * else string-casts the value.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function backup_cell( array $row, string $column ): string {
		if ( 'prunable' === $column ) {
			return ! empty( $row['prunable'] ) ? 'yes' : 'no';
		}

		$value = $row[ $column ] ?? null;

		if ( null === $value ) {
			return '';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * One padded table line.
	 *
	 * @param array<string, string> $cells
	 * @param array<string, int>    $widths
	 */
	private static function backup_table_row( array $cells, array $widths ): string {
		$padded = array();

		foreach ( $widths as $column => $width ) {
			$padded[] = str_pad( $cells[ $column ] ?? '', $width );
		}

		return implode( '  ', $padded );
	}
}
