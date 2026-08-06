<?php
/**
 * The seal step of the promotion lifecycle (BLOCK_THEME_PROPOSAL.md §7.7):
 * binds the deploy commit to a prepared, signed manifest. Sealing hashes
 * `git show <sha>:<path>` against the recorded preparedFileHash, and the
 * three outcomes must be distinguishable: content matching in that commit
 * seals, content present but DIFFERENT is tamper (exit 4), and a path not
 * present in that commit at all is a hard error (exit 1) — the operator
 * must commit the prepared files. Sealing is also idempotent for the same
 * commit and one-way: a second DIFFERENT commit refuses.
 *
 * The fixture is a throwaway repository with three commits: a baseline
 * commit before the prepared file exists (the "not in that commit" case),
 * a commit carrying the prepared content (the sealing target), and a
 * commit carrying DIFFERENT content under the same path (the
 * "present-but-different" tamper case). The working-tree file is restored
 * to the prepared content after that third commit, so the on-disk check
 * passes and only the committed-bytes comparison can catch the drift.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Promotion\GitRepository;
use AgencyPlatform\State\Promotion\ManifestStore;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionManifest;
use AgencyPlatform\State\Promotion\PromotionSealer;
use AgencyPlatform\State\Promotion\StateGateway;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\PromotionSealer
 */
// putenv() is how these tests drive AGENCY_REPO_ROOT (the sealer's manifest
// write resolves through the shared web-root guard, which needs the fixture
// repository root) and the HMAC keyring through EnvironmentConfig's
// process-environment fallback; WordPress's discouraged-function sniff
// would otherwise flag every call.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class PromotionSealerTest extends IntegrationTestCase {

	/** The key id every fixture in this file signs with. */
	private const KEY_ID = '2026-01';

	private string $repo_root;
	private string $state_dir;
	private string $manifest_path;
	private string $prepared_file;
	private string $prepared_content;
	private string $other_content;
	private string $earlier_commit_sha;
	private string $commit_sha;
	private string $other_commit_sha;

	private StateGateway $gateway;
	private ManifestStore $store;
	private PromotionSealer $sealer;

	public function set_up(): void {
		parent::set_up();

		$tmp = sys_get_temp_dir() . '/promotion-sealer-' . uniqid( '', true );

		$this->repo_root        = $tmp . '/repo';
		$this->state_dir        = $tmp . '/state';
		$this->manifest_path    = $this->state_dir . '/manifest.json';
		$this->prepared_file    = $this->repo_root . '/templates/page.html';
		$this->prepared_content = "<!-- wp:paragraph --><p>Page body</p><!-- /wp:paragraph -->\n";
		$this->other_content    = "<!-- wp:paragraph --><p>Other body</p><!-- /wp:paragraph -->\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->repo_root, 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->state_dir, 0700, true );

		$this->assert_git_available();

		putenv( 'GIT_CONFIG_GLOBAL=/dev/null' );
		putenv( 'GIT_CONFIG_SYSTEM=/dev/null' );

		$this->build_fixture_repository();

		$this->set_keyring();
		putenv( 'AGENCY_REPO_ROOT=' . $this->repo_root );

		$this->gateway = new StateGateway();
		$this->store   = new ManifestStore( $this->gateway );
		$this->sealer  = new PromotionSealer( $this->store, new GitRepository( $this->repo_root ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the prepared fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->prepared_file, $this->prepared_content );

		$this->store->write( $this->signed_manifest(), $this->manifest_path );
	}

	public function tear_down(): void {
		putenv( 'AGENCY_REPO_ROOT' );
		putenv( 'GIT_CONFIG_GLOBAL' );
		putenv( 'GIT_CONFIG_SYSTEM' );

		$this->clear_keyring();

		$this->remove_tree( dirname( $this->repo_root ) );

		parent::tear_down();
	}

	public function test_seal_binds_the_deploy_commit_and_re_signs(): void {
		$sealed = $this->sealer->seal( $this->manifest_path, $this->commit_sha );

		self::assertSame( $this->commit_sha, $sealed->deploy_commit() );
		self::assertTrue( $sealed->is_sealed() );
		// Loading re-verifies the signature, so this proves it was re-signed.
		self::assertSame( $this->commit_sha, $this->store->load( $this->manifest_path )->deploy_commit() );
	}

	public function test_seal_exits_four_when_a_prepared_file_changed_after_prepare(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- rewriting the prepared fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->prepared_file, "<!-- wp:paragraph --><p>tampered</p><!-- /wp:paragraph -->\n" );

		$this->assert_exit_code( 4, fn() => $this->sealer->seal( $this->manifest_path, $this->commit_sha ) );
	}

	public function test_seal_exits_one_when_the_prepared_file_is_not_in_that_commit(): void {
		$this->assert_exit_code( 1, fn() => $this->sealer->seal( $this->manifest_path, $this->earlier_commit_sha ) );
	}

	public function test_seal_is_idempotent_for_the_same_commit(): void {
		$this->sealer->seal( $this->manifest_path, $this->commit_sha );
		$first = $this->store->load( $this->manifest_path )->to_array();

		// A re-seal inside the same second would rewrite byte-identical
		// bytes (sealedAtUtc has one-second resolution), which would hide the
		// difference. Sleeping past the second boundary makes a re-sign with
		// a fresh sealedAtUtc observable.
		sleep( 1 );

		$sealed = $this->sealer->seal( $this->manifest_path, $this->commit_sha );

		self::assertSame( $this->commit_sha, $sealed->deploy_commit() );
		self::assertSame(
			$first['sealedAtUtc'],
			$sealed->to_array()['sealedAtUtc'],
			'Re-sealing the same commit must not refresh sealedAtUtc or rewrite the manifest.'
		);
	}

	public function test_seal_refuses_a_second_different_commit(): void {
		$this->sealer->seal( $this->manifest_path, $this->commit_sha );

		$exception = $this->assert_exit_code( 1, fn() => $this->sealer->seal( $this->manifest_path, $this->other_commit_sha ) );

		self::assertStringContainsString( 'already sealed to', $exception->getMessage() );
		self::assertStringContainsString( $this->commit_sha, $exception->getMessage(), 'The refusal must name the commit the manifest is already sealed to.' );
	}

	public function test_seal_refuses_a_malformed_sha(): void {
		$this->assert_exit_code( 1, fn() => $this->sealer->seal( $this->manifest_path, 'HEAD' ) );
	}

	/**
	 * The third distinguishable outcome: the path IS in the named commit,
	 * but with bytes that differ from the recorded preparedFileHash. The
	 * on-disk file still matches the manifest, so only the committed-bytes
	 * comparison can catch this — and it must report tamper, exit 4, not a
	 * hard error.
	 */
	public function test_seal_exits_four_when_the_committed_content_differs_from_the_recorded_hash(): void {
		$this->assert_exit_code( 4, fn() => $this->sealer->seal( $this->manifest_path, $this->other_commit_sha ) );
	}

	/**
	 * Asserts that the operation refuses with exactly the expected exit code,
	 * and returns the exception so the caller can also assert the message.
	 * Fails when no exception is thrown, when a non-PromotionException
	 * escapes, or when the code differs.
	 *
	 * @param callable():void $operation
	 */
	private function assert_exit_code( int $expected, callable $operation ): PromotionException {
		try {
			$operation();
		} catch ( PromotionException $exception ) {
			self::assertSame(
				$expected,
				$exception->exit_code(),
				sprintf( 'Expected exit code %d, got %d: %s', $expected, $exception->exit_code(), $exception->getMessage() )
			);

			return $exception;
		}

		self::fail( sprintf( 'Expected a PromotionException with exit code %d, but no exception was thrown.', $expected ) );
	}

	/**
	 * The fixture repository: three commits. The baseline commit predates
	 * the prepared file (the "not committed" case), the second carries the
	 * prepared content (the sealing target), and the third carries DIFFERENT
	 * content under the same path (the "present but different" tamper case).
	 * After the third commit the working-tree file is restored to the
	 * prepared content, so the seal step's on-disk check passes and only the
	 * committed-bytes comparison sees the drift.
	 */
	private function build_fixture_repository(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating the fixture tree directory; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->repo_root . '/templates', 0700 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the fixture tree; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->repo_root . '/baseline.txt', 'baseline' );

		$this->run_git( array( 'init' ) );
		$this->run_git( array( 'config', 'user.email', 'promotion-fixture@example.invalid' ) );
		$this->run_git( array( 'config', 'user.name', 'Promotion Fixture' ) );
		$this->run_git( array( 'add', '-A' ) );
		$this->run_git( array( '-c', 'commit.gpgsign=false', 'commit', '-m', 'baseline' ) );

		$this->earlier_commit_sha = trim( $this->run_git( array( 'rev-parse', 'HEAD' ) )['stdout'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the fixture tree; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->prepared_file, $this->prepared_content );

		$this->run_git( array( 'add', '-A' ) );
		$this->run_git( array( '-c', 'commit.gpgsign=false', 'commit', '-m', 'prepare' ) );

		$this->commit_sha = trim( $this->run_git( array( 'rev-parse', 'HEAD' ) )['stdout'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the fixture tree; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->prepared_file, $this->other_content );

		$this->run_git( array( 'add', '-A' ) );
		$this->run_git( array( '-c', 'commit.gpgsign=false', 'commit', '-m', 'other' ) );

		$this->other_commit_sha = trim( $this->run_git( array( 'rev-parse', 'HEAD' ) )['stdout'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- restoring the prepared fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->prepared_file, $this->prepared_content );
	}

	/**
	 * A schema-valid signed manifest whose single record points at the
	 * fixture's prepared file with its REAL sha256: the seal step must
	 * succeed against a file it has actually never seen.
	 */
	private function signed_manifest(): PromotionManifest {
		return PromotionManifest::create(
			wp_generate_uuid4(),
			'2026-08-01T10:00:00Z',
			array(
				'exportId'      => '11111111-2222-4333-8444-555555555555',
				'exportedAtUtc' => '2026-08-01T09:00:00Z',
				'siteUrl'       => 'https://client.example.com',
				'environment'   => 'production',
				'activeTheme'   => array(
					'stylesheet' => 'site-theme',
					'version'    => '1.0.0',
					'gitCommit'  => str_repeat( 'a', 40 ),
				),
			),
			'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
			str_repeat( 'b', 40 ),
			array( 'npm run test:e2e' )
		)->with_record( 'templates:page', $this->record() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function record(): array {
		return array(
			'key'                      => 'templates:page',
			'provider'                 => 'templates',
			'slug'                     => 'page',
			'objectId'                 => null,
			'originalContentHash'      => str_repeat( 'c', 64 ),
			'originalModifiedGmt'      => null,
			'preparedFilePath'         => 'templates/page.html',
			'themeRelativePath'        => 'templates/page.html',
			'preparedFileHash'         => $this->file_sha256( $this->prepared_file ),
			'originalFileHash'         => null,
			'referenceScan'            => array(),
			'navigationExpectation'    => array(),
			'expectedPostResetHash'    => null,
			'preResetResolvedHash'     => null,
			'postFinalizeRecordState'  => null,
			'postFinalizeSemanticHash' => null,
			'postFinalizeModifiedGmt'  => null,
			'finalizeStatus'           => 'pending',
			'finalizeRefusalReason'    => null,
			'rollbackStatus'           => 'not-attempted',
			'rollbackRefusalReason'    => null,
			'restoredObjectId'         => null,
		);
	}

	private function file_sha256( string $path ): string {
		$hash = hash_file( 'sha256', $path );

		return false === $hash ? '' : $hash;
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
			$this->repo_root
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
	 * The keyring the gateway's from_environment() signer must find: valid
	 * JSON, one key id, a 40-char key — the same key the fixtures sign with.
	 */
	private function keyring_json(): string {
		return '{"' . self::KEY_ID . '":"' . str_repeat( 'k', 40 ) . '"}';
	}

	private function set_keyring(): void {
		putenv( HmacSigner::SETTING_KEYS . '=' . $this->keyring_json() );
		putenv( HmacSigner::SETTING_SIGNING_KEY_ID . '=' . self::KEY_ID );
	}

	private function clear_keyring(): void {
		putenv( HmacSigner::SETTING_KEYS );
		putenv( HmacSigner::SETTING_SIGNING_KEY_ID );
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
