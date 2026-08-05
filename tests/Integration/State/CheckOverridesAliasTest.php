<?php
/**
 * The REAL `wp agency check-overrides` alias test: every other suite drives
 * StateCommandRunner in-process, which cannot tell you that the command a
 * human types still works. This file shells out to actual WP-CLI inside
 * DDEV, so one path exercises AgencyCommands::register(), the
 * `WP_CLI::add_command` registration, the check_overrides() body,
 * CliOutput::emit(), and the real process exit code.
 *
 * The fixture is seeded through the same real WP-CLI surface into the site
 * WP-CLI boots (the DDEV dev database, which no other integration test
 * touches): a child process cannot see this suite's uncommitted test rows,
 * and seeding through WP-CLI keeps every step honest end-to-end. Both
 * --fail-on-drift states are required: a single case would pass against a
 * command wired to ignore $assoc_args entirely.
 *
 * WP-CLI being unreachable here is a FAILED gate, never a skip: the alias
 * test is a required Release 2 condition (BLOCK_THEME_PROPOSAL.md §13), so
 * an unreachable `wp` binary must fail loudly with direct assertions.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\Cli\AgencyCommands
 */
final class CheckOverridesAliasTest extends IntegrationTestCase {

	/**
	 * Runs one real WP-CLI command in the repository root and returns its
	 * exit code and both streams separately. proc_open is the only way to
	 * observe a real WP-CLI exit code and both streams; the array command
	 * form avoids any shell interpolation of the arguments.
	 *
	 * @param string ...$arguments WP-CLI arguments after `wp`, e.g. `agency`, `check-overrides`, `--fail-on-drift`.
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
			dirname( __DIR__, 3 )
		);

		if ( ! is_resource( $process ) ) {
			self::fail( 'WP-CLI is not reachable from the integration suite: proc_open( wp ... ) failed to start the binary. The real alias test requires the `wp` command on PATH inside DDEV.' );
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

	/**
	 * Seeds one published wp_template override named `page` into the site
	 * WP-CLI boots, attached to that site's active wp_theme term — the same
	 * shape the Site Editor produces, so the real command reports it as
	 * drift. Returns the created post ID for cleanup.
	 */
	private function seed_template_override(): int {
		$create = $this->wp_cli(
			'post',
			'create',
			'--post_type=wp_template',
			'--post_name=page',
			'--post_title=Alias test fixture',
			'--post_status=publish',
			'--post_content=<!-- wp:paragraph --><p>Client edit</p><!-- /wp:paragraph -->',
			'--porcelain'
		);

		self::assertSame( 0, $create['exit_code'], 'Seeding the alias fixture requires `wp post create` to succeed. stderr: ' . $create['stderr'] );

		$id = (int) trim( $create['stdout'] );

		self::assertGreaterThan( 0, $id, 'Seeding the alias fixture requires `wp post create --porcelain` to print the new post ID.' );

		$stylesheet = $this->wp_cli( 'option', 'get', 'stylesheet' );

		self::assertSame( 0, $stylesheet['exit_code'], 'Reading the active theme requires `wp option get stylesheet` to succeed. stderr: ' . $stylesheet['stderr'] );

		$term = $this->wp_cli( 'post', 'term', 'set', (string) $id, 'wp_theme', trim( $stylesheet['stdout'] ) );

		self::assertSame( 0, $term['exit_code'], 'The fixture must be attached to the active theme term or the provider cannot see it. stderr: ' . $term['stderr'] );

		return $id;
	}

	/**
	 * Removes a seeded fixture row again, so repeated runs never accumulate
	 * fixtures in the site database.
	 */
	private function remove_template_override( int $id ): void {
		$removed = $this->wp_cli( 'post', 'delete', (string) $id, '--force' );

		self::assertSame( 0, $removed['exit_code'], 'Cleanup requires `wp post delete --force` to succeed. stderr: ' . $removed['stderr'] );
	}

	public function test_the_registered_command_runs_and_reports_without_failing(): void {
		$id = $this->seed_template_override();

		try {
			$result = $this->wp_cli( 'agency', 'check-overrides' );

			self::assertSame( 0, $result['exit_code'], 'The default run must never fail because a client edited a template. stderr: ' . $result['stderr'] );
			self::assertStringContainsString( 'templates:page (changed, promotable)', $result['stdout'] );
			self::assertStringContainsString( '1 record(s) differ from the Git baseline. Database overrides are expected under the block-theme editing model; this report is informational.', $result['stdout'] );
			self::assertStringContainsString( 'check-overrides is deprecated. Use `wp agency state-diff` for the machine-readable report.', $result['stderr'] );
		} finally {
			$this->remove_template_override( $id );
		}
	}

	public function test_the_registered_command_honours_fail_on_drift(): void {
		$id = $this->seed_template_override();

		try {
			$result = $this->wp_cli( 'agency', 'check-overrides', '--fail-on-drift' );

			self::assertSame( 1, $result['exit_code'], '--fail-on-drift must turn drift into exit 1. stderr: ' . $result['stderr'] );
			self::assertStringContainsString( '1 record(s) differ from the Git baseline (--fail-on-drift).', $result['stderr'], 'The drift failure must name its reason on STDERR; an unregistered command also exits 1, so the exit code alone cannot prove the flag works.' );
			self::assertStringNotContainsString( 'templates:page', $result['stderr'], 'The report belongs on STDOUT; the drift failure must not leak report lines onto STDERR.' );
			self::assertStringNotContainsString( 'check-overrides is deprecated', $result['stdout'], 'The deprecation notice belongs on STDERR; STDOUT must carry the report only.' );
		} finally {
			$this->remove_template_override( $id );
		}
	}
}
