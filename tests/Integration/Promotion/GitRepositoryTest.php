<?php
/**
 * The git context class for the promotion lifecycle (plan Task 5):
 * GitRepository must read HEAD, the branch, working-tree dirtiness and
 * committed-file content. Task 10's run-level refusals (dirty tree,
 * unexpected branch) rest entirely on this class, so the dirty-path
 * parsing is proven in five states — clean, untracked, modified-tracked,
 * renamed (the "R  old -> new" branch) and quoted (spaces and double
 * quotes in a name) — against a throwaway fixture repository.
 *
 * The fixture is a real `git init` in a temp directory, never the ambient
 * repository: the integration suite runs inside DDEV, where this worktree's
 * .git is a FILE pointing at a Windows path outside the container mount, so
 * every git command fails there. The fixture also pins GIT_CONFIG_GLOBAL and
 * GIT_CONFIG_SYSTEM to /dev/null for the whole process, so a host
 * ~/.gitconfig (init.defaultBranch, signing, hooks) cannot change the
 * result. Git being unavailable is a FAILED gate, never a skip.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\Promotion\GitRepository;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionExitCode;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\GitRepository
 */
// putenv() drives GIT_CONFIG_GLOBAL/GIT_CONFIG_SYSTEM (so the fixture git
// calls and GitRepository's own subprocesses cannot be changed by a host
// gitconfig) and AGENCY_REPO_ROOT (the discover() override branch) through
// EnvironmentConfig's process-environment fallback; WordPress's
// discouraged-function sniff would otherwise flag every call.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class GitRepositoryTest extends IntegrationTestCase {

	private string $fixture_dir;

	public function set_up(): void {
		parent::set_up();

		$this->fixture_dir = sys_get_temp_dir() . '/git-repository-' . uniqid( '', true );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating the fixture repository directory; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->fixture_dir, 0700 );

		$this->assert_git_available();

		putenv( 'GIT_CONFIG_GLOBAL=/dev/null' );
		putenv( 'GIT_CONFIG_SYSTEM=/dev/null' );

		$this->build_fixture();
	}

	public function tear_down(): void {
		$this->remove_tree( $this->fixture_dir );

		putenv( 'GIT_CONFIG_GLOBAL' );
		putenv( 'GIT_CONFIG_SYSTEM' );

		parent::tear_down();
	}

	public function test_head_commit_is_40_hex_and_root_holds_the_fixture(): void {
		$repo = new GitRepository( $this->fixture_dir );

		self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $repo->head_commit() );
		self::assertFileExists( $repo->root() . '/composer.json' );
	}

	public function test_commit_exists_accepts_the_head_and_rejects_a_bogus_sha(): void {
		$repo = new GitRepository( $this->fixture_dir );

		self::assertTrue( $repo->commit_exists( $repo->head_commit() ) );
		self::assertFalse( $repo->commit_exists( str_repeat( '0', 40 ) ) );
	}

	public function test_file_at_commit_reads_committed_content_and_returns_null_for_an_absent_file(): void {
		$repo     = new GitRepository( $this->fixture_dir );
		$composer = $repo->file_at_commit( $repo->head_commit(), 'composer.json' );

		self::assertIsString( $composer );
		self::assertStringContainsString( '"agency/agency-starter"', $composer );
		self::assertNull( $repo->file_at_commit( $repo->head_commit(), 'no/such/file' ) );
	}

	public function test_current_branch_reports_the_fixture_branch_and_null_when_detached(): void {
		$repo   = new GitRepository( $this->fixture_dir );
		$branch = trim( $this->run_git( array( 'symbolic-ref', '--quiet', '--short', 'HEAD' ) )['stdout'] );

		self::assertNotSame( '', $branch, 'The fixture must have a named branch to compare against.' );
		self::assertSame( $branch, $repo->current_branch() );

		$this->run_git( array( 'checkout', '--detach' ) );

		self::assertNull( $repo->current_branch(), 'A detached HEAD must yield null.' );
	}

	public function test_dirty_paths_is_empty_on_a_fresh_fixture(): void {
		self::assertSame( array(), ( new GitRepository( $this->fixture_dir ) )->dirty_paths() );
	}

	public function test_dirty_paths_reports_an_untracked_file(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- creating an untracked fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->fixture_dir . '/untracked.txt', 'untracked' );

		self::assertContains( 'untracked.txt', ( new GitRepository( $this->fixture_dir ) )->dirty_paths() );
	}

	public function test_dirty_paths_reports_a_modified_tracked_file(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- rewriting a tracked fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->fixture_dir . '/composer.json', '{"name": "agency/agency-starter", "modified": true}' );

		self::assertContains( 'composer.json', ( new GitRepository( $this->fixture_dir ) )->dirty_paths() );
	}

	public function test_dirty_paths_keeps_the_right_hand_path_of_a_rename(): void {
		$this->run_git( array( 'mv', 'templates/page.html', 'templates/renamed.html' ) );

		$paths = ( new GitRepository( $this->fixture_dir ) )->dirty_paths();

		self::assertContains( 'templates/renamed.html', $paths );
		self::assertNotContains( 'templates/page.html', $paths, 'A rename line must contribute only its right-hand path.' );
	}

	public function test_dirty_paths_unquotes_paths_with_spaces_and_quotes(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- creating an untracked fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->fixture_dir . '/file with space.txt', 'space' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- creating an untracked fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->fixture_dir . '/quote"mark.txt', 'quote' );

		$paths = ( new GitRepository( $this->fixture_dir ) )->dirty_paths();

		self::assertContains( 'file with space.txt', $paths );
		self::assertContains( 'quote"mark.txt', $paths );
	}

	public function test_relative_path_and_absolute_path_round_trip_including_backslashes(): void {
		$repo     = new GitRepository( $this->fixture_dir );
		$absolute = $this->fixture_dir . '/templates/page.html';

		self::assertSame( 'templates/page.html', $repo->relative_path( $absolute ) );
		self::assertSame( $absolute, $repo->absolute_path( 'templates/page.html' ) );
		self::assertSame(
			'templates/page.html',
			$repo->relative_path( str_replace( '/', '\\', $absolute ) ),
			'Backslashes must be normalised before the root comparison.'
		);
		self::assertSame( $absolute, $repo->absolute_path( 'templates\\page.html' ), 'Backslashes in a repo-relative path must be normalised.' );
	}

	public function test_relative_path_throws_for_a_path_outside_the_root(): void {
		$outside = dirname( $this->fixture_dir ) . '/outside.txt';

		try {
			( new GitRepository( $this->fixture_dir ) )->relative_path( $outside );

			self::fail( 'relative_path() must refuse a path outside the repository root.' );
		} catch ( PromotionException $exception ) {
			self::assertSame( PromotionExitCode::HARD_ERROR, $exception->exit_code() );
			self::assertStringContainsString( 'outside the repository root', $exception->getMessage() );
		}
	}

	public function test_discover_uses_agency_repo_root_when_set(): void {
		putenv( 'AGENCY_REPO_ROOT=' . $this->fixture_dir );

		try {
			self::assertSame( $this->fixture_dir, GitRepository::discover()->root() );
		} finally {
			putenv( 'AGENCY_REPO_ROOT' );
		}
	}

	public function test_discover_fallback_runs_from_the_repository_root(): void {
		$expected = realpath( dirname( __DIR__, 3 ) );
		$source   = dirname( ( new \ReflectionClass( GitRepository::class ) )->getFileName(), 8 );

		self::assertIsString( $expected );
		self::assertSame( $expected, $source, 'discover() must start git from the repository root, not from a directory above or below it.' );
	}

	/**
	 * Runs one git command against the fixture repository and returns both
	 * streams plus the exit code. The argv array form never invokes a shell,
	 * so no argument can be injected; the process environment is the test's
	 * own, with GIT_CONFIG_GLOBAL and GIT_CONFIG_SYSTEM already pinned to
	 * /dev/null by set_up().
	 *
	 * @param list<string> $argv
	 * @return array{stdout: string, stderr: string, exit: int}
	 */
	private function run_git( array $argv ): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- the fixture repository needs a real git binary; proc_open with an argv array is the only way to run it without a shell.
		$process = proc_open(
			array_merge( array( 'git' ), $argv ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->fixture_dir
		);

		if ( false === $process ) {
			self::fail( 'Git is required for the promotion release gate.' );
		}

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the captured fixture git pipes; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the captured fixture git pipes; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[2] );

		return array(
			'stdout' => false === $stdout ? '' : $stdout,
			'stderr' => false === $stderr ? '' : $stderr,
			'exit'   => proc_close( $process ),
		);
	}

	/**
	 * The fixture repository: git init, a small committed tree (a
	 * composer.json whose name is agency/agency-starter and a
	 * templates/page.html), and no commits made with any host gpg-signing
	 * configuration.
	 */
	private function build_fixture(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating the fixture tree directory; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->fixture_dir . '/templates', 0700 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the fixture tree; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->fixture_dir . '/composer.json', '{"name": "agency/agency-starter"}' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the fixture tree; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->fixture_dir . '/templates/page.html', '<!-- wp:paragraph --><p>Fixture</p><!-- /wp:paragraph -->' );

		$this->run_git( array( 'init' ) );
		$this->run_git( array( 'config', 'user.email', 'promotion-fixture@example.invalid' ) );
		$this->run_git( array( 'config', 'user.name', 'Promotion Fixture' ) );
		$this->run_git( array( 'add', '-A' ) );
		$this->run_git( array( '-c', 'commit.gpgsign=false', 'commit', '-m', 'fixture' ) );
	}

	/**
	 * Runs git --version and fails the test when Git is unavailable. It
	 * never skips: a missing environment stays a failed gate.
	 */
	private function assert_git_available(): void {
		$result = $this->run_git( array( '--version' ) );

		if ( 0 !== $result['exit'] ) {
			self::fail( 'Git is required for the promotion release gate.' );
		}
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
