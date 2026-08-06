<?php
/**
 * `wp agency promote-overrides` must be INVOKABLE, not merely documented.
 *
 * This exists because the command shipped uninvokable and nothing noticed. Its
 * `## OPTIONS` docblock declared every mode flag without square brackets, so
 * WP-CLI treated all of them as REQUIRED, and `--select`'s value placeholder
 * `<provider:slug,...>` was not a token WP-CLI's synopsis parser accepts. Every
 * real invocation died before reaching any promotion code:
 *
 *   Error: Parameter errors:
 *    missing --deploy-commit parameter
 *    unknown --prepare parameter
 *   Warning: The `wp agency promote-overrides` command has an invalid synopsis
 *   part: [--select=<provider:slug,...>]
 *
 * Every existing test missed it. The integration suite drives
 * PromotionCommandRunner directly, which never sees the WP-CLI synopsis, and
 * the release gate ran only `--help`, which parses the docblock happily and
 * exits 0 whatever the brackets say.
 *
 * These tests therefore assert on the CLI ARGUMENT LAYER only: a real `wp`
 * process, real argument parsing. They deliberately do NOT require a promotion
 * to succeed — that is the vertical slice's job — so they stay meaningful on
 * any host, including one where git is unreachable from the container.
 *
 * @package Tests\Cli
 */

declare(strict_types=1);

namespace Tests\Cli;

use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\Cli\PromotionCommands
 */
final class PromotionSynopsisTest extends IntegrationTestCase {

	/**
	 * The exact failures the shipped synopsis produced. None of them may ever
	 * appear again, for any mode flag.
	 *
	 * @var list<string>
	 */
	private const SYNOPSIS_FAILURES = array(
		'invalid synopsis part',
		'unknown --',
		'missing --',
	);

	/**
	 * @dataProvider mode_flags
	 */
	public function test_every_mode_flag_is_accepted_by_the_argument_parser( string $mode ): void {
		$result = $this->wp_cli( 'agency', 'promote-overrides', $mode, '--manifest=var/agency-state/synopsis-probe.json' );

		$output = $result['stdout'] . $result['stderr'];

		foreach ( self::SYNOPSIS_FAILURES as $failure ) {
			self::assertStringNotContainsString(
				$failure,
				$output,
				sprintf( 'WP-CLI rejected "%s" while PARSING ARGUMENTS, so the command never reached its own code. Output: %s', $mode, $output )
			);
		}
	}

	/** @return array<string, array{string}> */
	public static function mode_flags(): array {
		return array(
			'prepare'   => array( '--prepare' ),
			'seal'      => array( '--seal' ),
			'finalize'  => array( '--finalize' ),
			'confirm'   => array( '--confirm' ),
			'rollback'  => array( '--rollback' ),
			'heartbeat' => array( '--heartbeat' ),
		);
	}

	/**
	 * --select carries a comma-separated provider:slug list. Its placeholder
	 * broke WP-CLI's synopsis parser, which is a different failure from a
	 * missing bracket and would not be caught by the mode-flag cases above.
	 */
	public function test_the_select_option_is_accepted_by_the_argument_parser(): void {
		$result = $this->wp_cli(
			'agency',
			'promote-overrides',
			'--prepare',
			'--source=var/agency-state/synopsis-probe-bundle.json',
			'--select=templates:page,template-parts:site-header',
			'--manifest=var/agency-state/synopsis-probe.json'
		);

		$output = $result['stdout'] . $result['stderr'];

		foreach ( self::SYNOPSIS_FAILURES as $failure ) {
			self::assertStringNotContainsString( $failure, $output, 'Output: ' . $output );
		}
	}

	/**
	 * The backups command shares the same registration path.
	 */
	public function test_the_promotion_backups_command_is_invokable(): void {
		$result = $this->wp_cli( 'agency', 'promotion-backups', 'list', '--format=json' );

		self::assertSame( 0, $result['exit_code'], 'Output: ' . $result['stdout'] . $result['stderr'] );
		self::assertIsArray( json_decode( trim( $result['stdout'] ), true ), 'The list command must emit one JSON document.' );
	}

	/**
	 * Runs a real WP-CLI process and returns its exit code and both streams.
	 * The argv array form avoids any shell interpolation of the arguments.
	 *
	 * @return array{exit_code: int, stdout: string, stderr: string}
	 */
	private function wp_cli( string ...$arguments ): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- CLI-only test harness; proc_open is the only way to observe a real WP-CLI exit code and both streams separately.
		$process = proc_open(
			array_merge( array( 'wp' ), $arguments ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			dirname( __DIR__, 2 )
		);

		if ( ! is_resource( $process ) ) {
			self::fail( 'WP-CLI is not reachable from this suite: proc_open( wp ... ) failed to start the binary. This gate requires the `wp` command on PATH inside DDEV.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the captured WP-CLI pipes; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the captured WP-CLI pipes; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the captured WP-CLI pipes; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[2] );

		return array(
			'exit_code' => proc_close( $process ),
			'stdout'    => false === $stdout ? '' : $stdout,
			'stderr'    => false === $stderr ? '' : $stderr,
		);
	}
}
