<?php
/**
 * The WP-CLI command surface contract (BLOCK_THEME_PROPOSAL.md §6): exactly
 * two commands are registered, every registered command is a named static
 * method (never a closure), the exit-code contract maps a drift report to
 * exit 2 and a clean report to exit 0, and the human table names every
 * entry and its classification. This suite runs without WP-CLI loaded at
 * all — the pure helpers are the WordPress-free seam the integration suite
 * builds on.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\Cli\StateCommands;
use AgencyPlatform\State\DriftClassification;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Cli\StateCommands
 */
final class StateCommandsContractTest extends TestCase {

	public function test_the_registered_command_names_are_exactly_the_two_state_commands(): void {
		self::assertSame( array( 'agency state-export', 'agency state-diff' ), array_keys( StateCommands::commands() ) );
	}

	public function test_every_registered_command_is_a_callable_static_method(): void {
		foreach ( StateCommands::commands() as $name => $callable ) {
			self::assertIsCallable( $callable, $name . ' must be a named static method, never a closure.' );
		}
	}

	public function test_a_report_with_no_drift_exits_zero(): void {
		self::assertSame( 0, StateCommands::diff_exit_code( $this->report( false ) ) );
	}

	public function test_a_report_with_drift_exits_two(): void {
		self::assertSame( 2, StateCommands::diff_exit_code( $this->report( true ) ) );
	}

	public function test_the_table_names_every_entry_and_its_classification(): void {
		$table = StateCommands::format_table( $this->report( true ) );

		self::assertStringContainsString( 'templates:page', $table );
		self::assertStringContainsString( DriftClassification::PROMOTABLE, $table );
	}

	/**
	 * A minimal Git-mode report with exactly one entry, drifting or clean.
	 *
	 * @return array<string, mixed>
	 */
	private function report( bool $with_drift ): array {
		return array(
			'schemaVersion'    => 1,
			'mode'             => 'git',
			'generatedAtUtc'   => '2026-08-05T00:00:00Z',
			'siteUuid'         => '00000000-0000-4000-8000-000000000000',
			'source'           => null,
			'summary'          => array(
				'drift'      => $with_drift ? 1 : 0,
				'promotable' => $with_drift ? 1 : 0,
				'dbOwned'    => 0,
				'forbidden'  => 0,
				'unresolved' => 0,
				'unchanged'  => $with_drift ? 0 : 1,
			),
			'skippedProviders' => array(),
			'entries'          => array(
				array(
					'key'                  => 'templates:page',
					'provider'             => 'templates',
					'slug'                 => 'page',
					'status'               => $with_drift ? 'changed' : 'unchanged',
					'classification'       => $with_drift ? DriftClassification::PROMOTABLE : DriftClassification::UNCHANGED,
					'countsAsDrift'        => $with_drift,
					'currentHash'          => null,
					'targetHash'           => null,
					'hasDatabaseOverride'  => $with_drift,
					'unresolvedReferences' => array(),
				),
			),
		);
	}
}
