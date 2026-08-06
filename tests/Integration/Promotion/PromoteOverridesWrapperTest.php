<?php
/**
 * The deployment-side orchestration wrapper (plan Task 19):
 * scripts/promote-overrides finalizes a sealed promotion manifest on the
 * WordPress host, verifies the deployed frontend, then confirms the
 * promotion or rolls it back. The wrapper runs where CI runs — never on
 * the WordPress host — and drives the host only through the remote WP-CLI
 * command AGENCY_REMOTE_WP_CLI_COMMAND.
 *
 * The fixture is two fake executables: a fake remote WP-CLI command that
 * appends its argv to a log and exits with a code read from a control
 * file, and a fake Playwright command on the same pattern. The wrapper is
 * executed with proc_open from a throwaway working directory, so the
 * assertions are the argv the fake remote actually recorded — the only
 * honest proof of the quoting adapter, the mode flags, settle-at-most-once
 * and the log-preservation contract.
 *
 * The suite runs on Linux (DDEV/CI), where Bash is guaranteed; the first
 * fixture step runs `bash --version` and fails the gate with "bash is
 * required for the promotion wrapper release gate." when Bash is
 * unavailable. It never skips.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use Tests\Integration\IntegrationTestCase;

/**
 * @coversNothing
 */
final class PromoteOverridesWrapperTest extends IntegrationTestCase {

	private string $tmp_dir;
	private string $control_dir;
	private string $wrapper_path;
	private string $fake_remote_path;
	private string $fake_playwright_path;
	private string $remote_log_path;
	private string $playwright_log_path;
	private string $manifest_path;
	private string $remote_state_dir;
	private string $log_dir;
	private string $promotion_id;

	private int $last_exit;
	private string $last_stdout;
	private string $last_stderr;

	public function set_up(): void {
		parent::set_up();

		$this->assert_bash_available();
		$this->assert_node_available();

		$this->tmp_dir = sys_get_temp_dir() . '/promote-overrides-' . uniqid( '', true );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->tmp_dir, 0700 );

		$this->control_dir = $this->tmp_dir . '/control';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->control_dir, 0700 );

		$this->wrapper_path         = dirname( __DIR__, 3 ) . '/scripts/promote-overrides';
		$this->fake_remote_path     = $this->tmp_dir . '/fake-remote';
		$this->fake_playwright_path = $this->tmp_dir . '/fake-playwright';
		$this->remote_log_path      = $this->tmp_dir . '/remote.log';
		$this->playwright_log_path  = $this->tmp_dir . '/playwright.log';
		$this->manifest_path        = $this->tmp_dir . '/manifest.json';
		$this->remote_state_dir     = $this->tmp_dir . '/remote-state';
		$this->log_dir              = $this->tmp_dir . '/promotion-logs';
		$this->promotion_id         = 'promo-wrapper';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the fixture manifest; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->manifest_path, '{"promotionId":"' . $this->promotion_id . '"}' );

		$this->write_fake_remote();
		$this->write_fake_playwright();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- the wrapper must be executable for proc_open to run it; the git index records the mode for CI, the worktree copy needs it here.
		chmod( $this->wrapper_path, 0755 );

		$this->fake_remote_exit( 0 );
		$this->fake_playwright_exit( 0 );
	}

	public function tear_down(): void {
		$this->remove_tree( $this->tmp_dir );

		parent::tear_down();
	}

	public function test_the_wrapper_finalizes_verifies_then_confirms(): void {
		$this->fake_remote_exit( 0 );
		$this->fake_playwright_exit( 0 );

		self::assertSame( 0, $this->run_wrapper() );
		self::assertStringContainsString( 'agency promote-overrides --finalize', $this->remote_log() );
		self::assertStringContainsString( 'agency promote-overrides --confirm', $this->remote_log() );
		self::assertStringNotContainsString( '--rollback', $this->remote_log() );
	}

	public function test_a_remote_command_containing_quotes_is_executed_as_written(): void {
		// The documented example is: ssh deploy@example.com 'cd /var/www && wp'
		// Word-splitting that string would break it, so the adapter must run it
		// through a shell while quoting only the arguments the wrapper adds.
		$env                                 = $this->wrapper_env();
		$env['AGENCY_REMOTE_WP_CLI_COMMAND'] = sprintf( "%s 'cd /var/www && wp'", $this->fake_remote_path );
		$this->fake_remote_exit( 0 );
		$this->fake_playwright_exit( 0 );

		self::assertSame( 0, $this->run_wrapper( array(), $env ) );
		self::assertStringContainsString( 'cd /var/www && wp agency promote-overrides --finalize', $this->remote_log() );
	}

	public function test_a_failing_verification_rolls_back_and_exits_non_zero(): void {
		$this->fake_remote_exit( 0 );
		$this->fake_playwright_exit( 1 );

		self::assertNotSame( 0, $this->run_wrapper() );
		self::assertStringContainsString( '--rollback', $this->remote_log() );
		self::assertStringNotContainsString( '--confirm', $this->remote_log() );
	}

	public function test_a_lost_heartbeat_stops_verification_and_rolls_back(): void {
		// Without this the lock can be reclaimed while Playwright keeps running,
		// and the wrapper would confirm a promotion it no longer owns.
		$this->fake_remote_exit( 0 );
		$this->fake_remote_exit_for( 'heartbeat', 3 );
		$this->fake_playwright_sleeps( 30 );

		self::assertNotSame( 0, $this->run_wrapper() );
		self::assertStringContainsString( '--rollback', $this->remote_log() );
		self::assertStringNotContainsString( '--confirm', $this->remote_log() );
	}

	public function test_a_verification_timeout_rolls_back_and_exits_non_zero(): void {
		$this->fake_remote_exit( 0 );
		$this->fake_playwright_sleeps( 30 );

		$env                                = $this->wrapper_env();
		$env['AGENCY_VERIFICATION_TIMEOUT'] = '2';

		self::assertNotSame( 0, $this->run_wrapper( array(), $env ) );
		self::assertStringContainsString( '--rollback', $this->remote_log() );
		self::assertStringNotContainsString( '--confirm', $this->remote_log() );
	}

	public function test_settlement_runs_at_most_once_even_when_a_trap_fires(): void {
		$this->fake_remote_exit( 0 );
		$this->fake_playwright_exit( 1 );

		$this->run_wrapper();

		self::assertSame( 1, substr_count( $this->remote_log(), '--rollback' ) );
	}

	public function test_it_preserves_logs_after_a_rollback(): void {
		$this->fake_remote_exit( 0 );
		$this->fake_playwright_exit( 1 );

		$this->run_wrapper();

		self::assertFileExists( $this->log_dir . '/' . $this->promotion_id . '/verify.log' );
		self::assertFileExists( $this->log_dir . '/' . $this->promotion_id . '/rollback.log' );
	}

	public function test_it_honours_the_deploy_url_and_project(): void {
		$this->fake_remote_exit( 0 );
		$this->fake_playwright_exit( 0 );

		$this->run_wrapper();

		self::assertStringContainsString( $this->remote_state_dir, $this->remote_log() );
		self::assertStringContainsString( 'WP_BASE_URL=https://deploy.example.test', $this->playwright_log() );
		self::assertStringContainsString( '--project=chromium-desktop', $this->playwright_log() );
	}

	public function test_a_lock_conflict_at_finalize_aborts_without_rollback(): void {
		$this->fake_remote_exit( 3 );

		self::assertSame( 3, $this->run_wrapper() );
		self::assertStringNotContainsString( '--rollback', $this->remote_log() );
	}

	public function test_a_hard_error_at_finalize_exits_one_without_rollback(): void {
		$this->fake_remote_exit( 0 );
		$this->fake_remote_exit_for( 'finalize', 1 );

		self::assertSame( 1, $this->run_wrapper() );
		self::assertStringNotContainsString( '--rollback', $this->remote_log() );
	}

	public function test_a_partial_finalize_exits_two_after_a_passing_verification(): void {
		$this->fake_remote_exit( 0 );
		$this->fake_remote_exit_for( 'finalize', 2 );
		$this->fake_playwright_exit( 0 );

		self::assertSame( 2, $this->run_wrapper() );
		self::assertStringContainsString( '--confirm', $this->remote_log() );
	}

	/**
	 * HIGH 7 (whole-unit review): the wrapper used to set SETTLED=1 BEFORE
	 * running confirm, so a failed confirm silenced every trap and left the
	 * finalized promotion neither confirmed nor rolled back. The flag must
	 * be set only after the remote command succeeds, and a failed confirm
	 * must roll back.
	 */
	public function test_a_failed_confirm_rolls_back_and_exits_non_zero(): void {
		$this->fake_remote_exit( 0 );
		$this->fake_remote_exit_for( 'confirm', 1 );
		$this->fake_playwright_exit( 0 );

		self::assertNotSame( 0, $this->run_wrapper() );
		self::assertStringContainsString( '--confirm', $this->remote_log() );
		self::assertStringContainsString( '--rollback', $this->remote_log(), 'A failed confirm must not leave the promotion settled; it rolls back.' );
		self::assertSame( 1, substr_count( $this->remote_log(), '--rollback' ), 'A successful rollback must settle exactly once.' );
		self::assertStringContainsString( 'confirmation failed', $this->last_stderr );
	}

	public function test_a_tamper_detection_at_finalize_exits_four_without_rollback(): void {
		$this->fake_remote_exit( 0 );
		$this->fake_remote_exit_for( 'finalize', 4 );

		self::assertSame( 4, $this->run_wrapper() );
		self::assertStringNotContainsString( '--rollback', $this->remote_log() );
	}

	public function test_a_failed_rollback_exits_non_zero_and_announces_manual_intervention(): void {
		$this->fake_remote_exit( 0 );
		$this->fake_remote_exit_for( 'rollback', 4 );
		$this->fake_playwright_exit( 1 );

		self::assertNotSame( 0, $this->run_wrapper() );
		self::assertStringContainsString( 'manual intervention', $this->last_stderr );
	}

	public function test_missing_required_environment_variables_exit_one(): void {
		$env = $this->wrapper_env();
		unset( $env['AGENCY_DEPLOY_URL'] );

		self::assertSame( 1, $this->run_wrapper( array(), $env ) );
		self::assertStringContainsString( 'AGENCY_DEPLOY_URL', $this->last_stderr );
	}

	public function test_dry_run_prints_the_commands_without_executing_them(): void {
		self::assertSame( 0, $this->run_wrapper( array( '--dry-run' ) ) );
		self::assertFileDoesNotExist( $this->remote_log_path );
	}

	/**
	 * Runs `bash --version` and fails the gate when Bash is unavailable.
	 * It never skips: a missing environment stays a failed gate.
	 */
	private function assert_bash_available(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- the wrapper contract requires Bash; proc_open with an argv array is the only way to probe it.
		$process = proc_open(
			array( 'bash', '--version' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		if ( false === $process ) {
			self::fail( 'bash is required for the promotion wrapper release gate.' );
		}

		stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the captured probe pipes; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the captured probe pipes; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[2] );

		if ( 0 !== proc_close( $process ) ) {
			self::fail( 'bash is required for the promotion wrapper release gate.' );
		}
	}

	/**
	 * Runs `node --version` and fails the gate when Node is unavailable.
	 * The wrapper reads the promotionId out of the manifest with Node —
	 * it is guaranteed wherever Playwright runs — and a missing Node would
	 * otherwise fail every test with a confusing exit-1 assertion.
	 */
	private function assert_node_available(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- the wrapper contract requires Node; proc_open with an argv array is the only way to probe it.
		$process = proc_open(
			array( 'node', '--version' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		if ( false === $process ) {
			self::fail( 'node is required for the promotion wrapper release gate.' );
		}

		stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the captured probe pipes; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the captured probe pipes; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[2] );

		if ( 0 !== proc_close( $process ) ) {
			self::fail( 'node is required for the promotion wrapper release gate.' );
		}
	}

	/**
	 * Runs the wrapper from the throwaway working directory with the fake
	 * remote/Playwright commands and returns its exit code. The manifest
	 * always points at the fixture file; $extra_args (e.g. --dry-run) are
	 * appended, and $env replaces the default fixture environment.
	 *
	 * @param list<string>                $extra_args
	 * @param array<string, string>|null  $env
	 */
	private function run_wrapper( array $extra_args = array(), ?array $env = null ): int {
		$argv = array_merge(
			array( $this->wrapper_path, '--manifest=' . $this->manifest_path ),
			$extra_args
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- the wrapper is an executable bash script; proc_open with an argv array is the only way to run it and capture its streams.
		$process = proc_open(
			$argv,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->tmp_dir,
			$env ?? $this->wrapper_env()
		);

		if ( false === $process ) {
			self::fail( 'The promotion wrapper must be executable.' );
		}

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the captured wrapper pipes; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the captured wrapper pipes; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[2] );

		$this->last_stdout = false === $stdout ? '' : $stdout;
		$this->last_stderr = false === $stderr ? '' : $stderr;
		$this->last_exit   = proc_close( $process );

		return $this->last_exit;
	}

	/**
	 * The environment every fixture run starts from: the process's own
	 * environment (PATH et al.), the wrapper contract variables, and the
	 * fixture plumbing the fake commands write to. The heartbeat interval
	 * is short so the lost-heartbeat test exercises the sentinel within a
	 * test lifetime; the verification timeout is far above every fixture
	 * runtime except the explicit timeout test, which overrides it.
	 *
	 * @return array<string, string>
	 */
	private function wrapper_env(): array {
		$env = getenv();

		$env['AGENCY_REMOTE_WP_CLI_COMMAND'] = $this->fake_remote_path;
		$env['AGENCY_PLAYWRIGHT_COMMAND']    = $this->fake_playwright_path;
		$env['AGENCY_DEPLOY_URL']            = 'https://deploy.example.test';
		$env['AGENCY_REMOTE_STATE_DIR']      = $this->remote_state_dir;
		$env['AGENCY_PLAYWRIGHT_PROJECT']    = 'chromium-desktop';
		$env['AGENCY_VERIFICATION_TIMEOUT']  = '120';
		$env['AGENCY_HEARTBEAT_INTERVAL']    = '1';
		$env['FAKE_REMOTE_LOG']              = $this->remote_log_path;
		$env['FAKE_PLAYWRIGHT_LOG']          = $this->playwright_log_path;
		$env['FAKE_CONTROL_DIR']             = $this->control_dir;

		return $env;
	}

	/**
	 * The fake remote WP-CLI command: appends its argv to the fixture log
	 * and exits with the code the control file declares for its mode flag
	 * (--finalize/--heartbeat/--confirm/--rollback), falling back to the
	 * default control file.
	 */
	private function write_fake_remote(): void {
		$script = <<<'SH'
#!/usr/bin/env bash
{
  printf '%s' "$0"
  printf ' %s' "$@"
  printf '\n'
} >>"$FAKE_REMOTE_LOG"

mode=''
for arg in "$@"; do
  case "$arg" in
    --*) mode="${arg#--}"; break ;;
  esac
done

if [ -n "$mode" ] && [ -f "$FAKE_CONTROL_DIR/remote-$mode" ]; then
  exit "$(cat "$FAKE_CONTROL_DIR/remote-$mode")"
fi
if [ -f "$FAKE_CONTROL_DIR/remote-default" ]; then
  exit "$(cat "$FAKE_CONTROL_DIR/remote-default")"
fi
exit 0
SH;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the fake remote fixture script; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->fake_remote_path, $script );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- the fake command must be executable for the wrapper to run it.
		chmod( $this->fake_remote_path, 0755 );
	}

	/**
	 * The fake Playwright command: logs the URL it was pointed at plus its
	 * argv, sleeps when told to, and exits with the code from its control
	 * file. The exit code is read BEFORE the sleep, so a killed process can
	 * never read a later test's control files.
	 */
	private function write_fake_playwright(): void {
		$script = <<<'SH'
#!/usr/bin/env bash
{
  echo "WP_BASE_URL=${WP_BASE_URL:-}"
  printf '%s' "$0"
  printf ' %s' "$@"
  printf '\n'
} >>"$FAKE_PLAYWRIGHT_LOG"

playwright_exit='0'
if [ -f "$FAKE_CONTROL_DIR/playwright-exit" ]; then
  playwright_exit="$(cat "$FAKE_CONTROL_DIR/playwright-exit")"
fi
playwright_sleep='0'
if [ -f "$FAKE_CONTROL_DIR/playwright-sleep" ]; then
  playwright_sleep="$(cat "$FAKE_CONTROL_DIR/playwright-sleep")"
fi
if [ "$playwright_sleep" -gt 0 ] 2>/dev/null; then
  sleep "$playwright_sleep"
fi
exit "$playwright_exit"
SH;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the fake playwright fixture script; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->fake_playwright_path, $script );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- the fake command must be executable for the wrapper to run it.
		chmod( $this->fake_playwright_path, 0755 );
	}

	/**
	 * The argv the fake remote recorded, one line per invocation.
	 */
	private function remote_log(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back a fixture log written by the fake remote; the WP_Filesystem credentials context does not exist here.
		$contents = file_get_contents( $this->remote_log_path );

		return false === $contents ? '' : $contents;
	}

	/**
	 * The argv the fake Playwright recorded, one line per invocation.
	 */
	private function playwright_log(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back a fixture log written by the fake Playwright; the WP_Filesystem credentials context does not exist here.
		$contents = file_get_contents( $this->playwright_log_path );

		return false === $contents ? '' : $contents;
	}

	private function fake_remote_exit( int $code ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the fake remote control file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->control_dir . '/remote-default', (string) $code );
	}

	private function fake_remote_exit_for( string $mode, int $code ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the fake remote control file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->control_dir . '/remote-' . $mode, (string) $code );
	}

	private function fake_playwright_exit( int $code ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the fake playwright control file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->control_dir . '/playwright-exit', (string) $code );
	}

	private function fake_playwright_sleeps( int $seconds ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the fake playwright control file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->control_dir . '/playwright-sleep', (string) $seconds );
	}

	/**
	 * Removes an integration fixture directory and everything inside it.
	 */
	private function remove_tree( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		foreach ( scandir( $directory ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $directory . '/' . $entry;

			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_tree( $path );

				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- deleting an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		rmdir( $directory );
	}
}
