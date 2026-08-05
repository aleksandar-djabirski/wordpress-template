<?php
/**
 * The manifest store (plan Task 4): the ONLY reader/writer of promotion
 * manifests. load() verifies in a fixed order — decode, require signature
 * fields, verify the HMAC, validate the schema — so a signed document edited
 * into an invalid shape reports tamper (exit 4), never a hard error (exit 1):
 * an attacker stripping fields from a signed artifact must not look like an
 * operator with a malformed file. The second test is the load-bearing one —
 * swap verification and schema validation and it flips from 4 to 1.
 *
 * write() goes through the SHARED web-root guard
 * (StateDirectory::resolve_output()), never a second copy of the containment
 * rule, and the guard is proven BY ATTACK: every refusal asserts both the
 * throw and that no file was written. render() signs, schema-validates the
 * signed document and returns bytes; it must never touch the filesystem.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\GitBaseline;
use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Promotion\ManifestStore;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionExitCode;
use AgencyPlatform\State\Promotion\PromotionManifest;
use AgencyPlatform\State\Promotion\StateGateway;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\ManifestStore
 */
// putenv() is how these tests drive AGENCY_PROMOTION_HMAC_KEYS and
// AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID through EnvironmentConfig's
// process-environment fallback without WordPress or real .env files loaded;
// WordPress's discouraged-function sniff would otherwise flag every call.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class ManifestStoreTest extends IntegrationTestCase {

	/** The key id every fixture in this file signs with. */
	private const KEY_ID = '2026-01';

	private StateGateway $gateway;
	private ManifestStore $store;
	private string $tmp_dir;
	private PromotionManifest $manifest;

	public function set_up(): void {
		parent::set_up();

		$this->gateway  = new StateGateway();
		$this->store    = new ManifestStore( $this->gateway );
		$this->tmp_dir  = sys_get_temp_dir() . '/manifest-store-' . uniqid( '', true );
		$this->manifest = $this->signed_manifest();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->tmp_dir, 0700 );

		$this->set_keyring();
	}

	public function tear_down(): void {
		$this->clear_keyring();
		$this->remove_tree( $this->tmp_dir );

		parent::tear_down();
	}

	public function test_load_rejects_a_tampered_manifest_with_exit_code_four(): void {
		$path     = $this->write_signed_manifest();
		$document = json_decode( file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.json_decode_json_decode -- decoding the signed fixture back for mutation; the WP_Filesystem credentials context does not exist here.

		$document['records'][0]['preparedFileHash'] = str_repeat( 'a', 64 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- rewriting the signed fixture; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $path, wp_json_encode( $document ) );

		$this->assert_exit_code( 4, fn() => $this->store->load( $path ) );
	}

	public function test_a_signed_manifest_broken_into_an_invalid_shape_is_still_tamper(): void {
		// The HMAC must be checked BEFORE the schema. Deleting a required field
		// from a signed document is tampering, not a hard error.
		$path     = $this->write_signed_manifest();
		$document = json_decode( file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.json_decode_json_decode -- decoding the signed fixture back for mutation; the WP_Filesystem credentials context does not exist here.

		unset( $document['baseCommit'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- rewriting the signed fixture; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $path, wp_json_encode( $document ) );

		$this->assert_exit_code( 4, fn() => $this->store->load( $path ) );
	}

	public function test_a_manifest_with_no_signature_fields_is_tamper(): void {
		$path     = $this->write_signed_manifest();
		$document = json_decode( file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.json_decode_json_decode -- decoding the signed fixture back for mutation; the WP_Filesystem credentials context does not exist here.

		unset( $document['hmac'], $document['hmacKeyId'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- rewriting the signed fixture; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $path, wp_json_encode( $document ) );

		$this->assert_exit_code( 4, fn() => $this->store->load( $path ) );
	}

	public function test_malformed_json_is_a_hard_error(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing an integration fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->tmp_dir . '/broken.json', '{not json' );

		$this->assert_exit_code( 1, fn() => $this->store->load( $this->tmp_dir . '/broken.json' ) );
	}

	public function test_a_correctly_signed_but_schema_invalid_document_is_a_hard_error(): void {
		$path = $this->write_signed_manifest_with_extra_field();

		$this->assert_exit_code( 1, fn() => $this->store->load( $path ) );
	}

	public function test_render_returns_a_document_and_writes_nothing(): void {
		$before = scandir( $this->tmp_dir );

		self::assertStringContainsString( '"hmac"', $this->store->render( $this->manifest ) );
		self::assertSame( $before, scandir( $this->tmp_dir ) );
	}

	public function test_write_is_atomic_and_leaves_no_temporary_file(): void {
		$this->store->write( $this->manifest, $this->tmp_dir . '/manifest.json' );

		self::assertSame(
			array( 'manifest.json' ),
			array_values( array_diff( scandir( $this->tmp_dir ), array( '.', '..' ) ) )
		);
	}

	public function test_load_reads_stdin_for_a_dash(): void {
		$json  = $this->store->render( $this->manifest );
		$store = new ManifestStore( $this->gateway, static fn(): string => $json );

		self::assertSame( $this->manifest->promotion_id(), $store->load( '-' )->promotion_id() );
	}

	public function test_write_rejects_a_dash(): void {
		// '-' means STDOUT, and only the command layer decides that; the
		// store must refuse it so a document can never be streamed twice.
		$exception = $this->assert_exit_code( 1, fn() => $this->store->write( $this->manifest, '-' ) );

		self::assertStringContainsString( 'STDOUT', $exception->getMessage() );
	}

	public function test_write_refuses_an_absolute_path_inside_the_web_root(): void {
		$path = ( new GitBaseline() )->repo_root() . '/web/leak.json';

		$this->assert_exit_code( 1, fn() => $this->store->write( $this->manifest, $path ) );

		self::assertFalse( file_exists( $path ), 'A manifest must never be written inside the web root.' );
	}

	public function test_write_refuses_a_relative_path_inside_the_web_root(): void {
		$path     = 'web/app/uploads/leak.json';
		$resolved = ( new GitBaseline() )->repo_root() . '/' . $path;

		$this->assert_exit_code( 1, fn() => $this->store->write( $this->manifest, $path ) );

		self::assertFalse( file_exists( $resolved ), 'A relative path resolving into the web root must be refused.' );
	}

	public function test_write_refuses_a_dot_dot_traversal_into_the_web_root(): void {
		$path     = 'var/agency-state/../../web/leak.json';
		$resolved = ( new GitBaseline() )->repo_root() . '/web/leak.json';

		$this->assert_exit_code( 1, fn() => $this->store->write( $this->manifest, $path ) );

		self::assertFalse( file_exists( $resolved ), 'A ".." traversal must never smuggle the manifest into the web root.' );
	}

	public function test_write_refuses_a_path_nested_deep_under_uploads(): void {
		$path = ( new GitBaseline() )->repo_root() . '/web/app/uploads/2026/08/deep/leak.json';

		$this->assert_exit_code( 1, fn() => $this->store->write( $this->manifest, $path ) );

		self::assertFalse( file_exists( $path ), 'No depth of nesting inside the web root may legitimise a write.' );
	}

	public function test_write_accepts_a_legitimate_state_directory_path(): void {
		$path     = 'var/agency-state/promotions/manifest-store-test.json';
		$resolved = ( new GitBaseline() )->repo_root() . '/var/agency-state/promotions/manifest-store-test.json';

		$this->store->write( $this->manifest, $path );

		try {
			self::assertFileExists( $resolved );

			$loaded = $this->store->load( $resolved );

			self::assertSame( $this->manifest->promotion_id(), $loaded->promotion_id() );
			self::assertSame( $this->manifest->base_commit(), $loaded->base_commit(), 'The written manifest must load back with its signature intact.' );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $resolved );
		}
	}

	public function test_a_failed_render_leaves_no_temporary_or_target_file(): void {
		$path = $this->tmp_dir . '/manifest.json';

		$this->clear_keyring();

		try {
			$this->assert_exit_code( 1, fn() => $this->store->write( $this->manifest, $path ) );
		} finally {
			$this->set_keyring();
		}

		self::assertFalse( file_exists( $path ) );
		self::assertSame( array(), array_values( array_diff( scandir( $this->tmp_dir ), array( '.', '..' ) ) ), 'The temporary file must be unlinked when the write fails mid-flight.' );
	}

	public function test_write_canonical_then_load_canonical_round_trips(): void {
		$path = $this->store->canonical_path( $this->manifest->promotion_id() );

		$this->store->write_canonical( $this->manifest );

		try {
			self::assertTrue( $this->store->canonical_exists( $this->manifest->promotion_id() ) );

			$loaded = $this->store->load_canonical( $this->manifest->promotion_id() );

			self::assertSame( $this->manifest->promotion_id(), $loaded->promotion_id() );
			self::assertSame( $this->manifest->base_commit(), $loaded->base_commit() );
			self::assertSame( $this->manifest->site_uuid(), $loaded->site_uuid() );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $path );
		}
	}

	public function test_load_canonical_without_a_finalized_manifest_is_a_hard_error(): void {
		$exception = $this->assert_exit_code( 1, fn() => $this->store->load_canonical( $this->manifest->promotion_id() ) );

		self::assertStringContainsString( 'No finalized promotion found for', $exception->getMessage() );
		self::assertStringContainsString( 'Run --finalize on this host first', $exception->getMessage() );
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
	 * The promotion id is interpolated straight into a filesystem path, so a
	 * slash or a `..` segment in it becomes extra path components. That is the
	 * enabling half of the symlink escape below.
	 *
	 * @dataProvider hostile_promotion_ids
	 */
	public function test_canonical_path_refuses_a_promotion_id_that_is_not_a_uuid( string $promotion_id ): void {
		$this->assert_exit_code( 1, fn() => $this->store->canonical_path( $promotion_id ) );
	}

	/** @return array<string, array{string}> */
	public static function hostile_promotion_ids(): array {
		return array(
			'nested path'    => array( 'new-parent/leak' ),
			'traversal'      => array( '../../web/leak' ),
			'absolute'       => array( '/etc/passwd' ),
			'backslash'      => array( 'a\\b' ),
			'empty'          => array( '' ),
			'plain word'     => array( 'promotion' ),
			'uuid plus path' => array( 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee/../leak' ),
		);
	}

	/**
	 * The HIGH finding of the Task 4 bounded review, reproduced as a test.
	 *
	 * write_canonical() used to call wp_mkdir_p() and chmod() on the UNRESOLVED
	 * directory before write() ran the shared guard. With the promotions
	 * directory symlinked into the web root, the manifest itself was correctly
	 * refused while a directory had already been created under web/.
	 *
	 * The assertion is deliberately about the FILESYSTEM, not about the
	 * exception: refusing the write while still creating something inside the
	 * web root is exactly the failure this test exists to catch.
	 */
	public function test_write_canonical_creates_nothing_in_the_web_root_through_a_symlinked_state_dir(): void {
		$web_target = rtrim( ABSPATH, '/' ) . '/../probe-canonical-target';
		$promotions = $this->gateway->state_dir() . '/promotions';
		$manifest   = $this->manifest_with_promotion_id( 'new-parent/leak' );

		if ( is_dir( $promotions ) && ! is_link( $promotions ) ) {
			$this->remove_tree( $promotions );
		}

		if ( ! is_dir( $web_target ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating an integration fixture directory; the WP_Filesystem credentials context does not exist here.
			mkdir( $web_target, 0755, true );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- symlink() is EXPECTED to fail on filesystems that forbid links, and its return value is checked on the next line; the notice would otherwise be the test's only output on such a host.
		if ( ! @symlink( $web_target, $promotions ) ) {
			self::markTestSkipped( 'This filesystem does not allow the test to create a symlink.' );
		}

		try {
			$this->assert_exit_code( 1, fn() => $this->store->write_canonical( $manifest ) );

			clearstatcache( true, $web_target );

			self::assertSame(
				array(),
				array_values( array_diff( scandir( $web_target ), array( '.', '..' ) ) ),
				'write_canonical() must create nothing inside the web root, not even a directory.'
			);
		} finally {
			if ( is_link( $promotions ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing the fixture symlink; the WP_Filesystem credentials context does not exist here.
				unlink( $promotions );
			}

			$this->remove_tree( $web_target );
		}
	}

	/**
	 * A failed rename() used to be ignored: write() returned success while the
	 * manifest was never created and the temporary file survived. A trailing
	 * slash on the target is the cheapest way to make rename() fail.
	 */
	public function test_a_failed_rename_throws_and_leaves_no_temporary_file(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->tmp_dir . '/existing-directory', 0755, true );

		$this->assert_exit_code(
			1,
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- rename() onto an existing directory raises a PHP warning by design; the test asserts that write() DETECTS that failure, and the warning itself is the expected condition rather than a defect.
			fn() => @$this->store->write( $this->manifest, $this->tmp_dir . '/existing-directory/' )
		);

		$leftovers = array_values(
			array_filter(
				scandir( $this->tmp_dir ),
				static fn( string $entry ): bool => str_ends_with( $entry, '.tmp' )
			)
		);

		self::assertSame( array(), $leftovers, 'A failed write must leave no temporary file behind.' );
	}

	/**
	 * The bounded review showed the invalid-shape test could not actually pin
	 * the verification order: it deletes baseCommit, and from_array() only
	 * inspects schemaVersion, so moving from_array() ahead of the HMAC check
	 * would still have produced exit 4. This document breaks the ONE field
	 * from_array() does inspect AND breaks the signature, so it reports 4 only
	 * when verification really runs first.
	 */
	public function test_a_document_that_breaks_both_the_signature_and_from_array_reports_tamper(): void {
		$path = $this->write_signed_manifest();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back the signed fixture; the WP_Filesystem credentials context does not exist here.
		$document = json_decode( file_get_contents( $path ), true );

		$document['schemaVersion'] = 99;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- rewriting the signed fixture; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $path, wp_json_encode( $document ) );

		$this->assert_exit_code( 4, fn() => $this->store->load( $path ) );
	}

	/**
	 * render() must return canonical bytes with exactly one trailing newline.
	 * Asserting only that the output contains "hmac" would pass for almost any
	 * serialisation, including one that broke the signature's byte stability.
	 */
	public function test_render_returns_canonical_bytes_with_exactly_one_trailing_newline(): void {
		$document = $this->store->render( $this->manifest );

		self::assertStringEndsWith( "\n", $document );
		self::assertStringEndsNotWith( "\n\n", $document );
		self::assertSame( $document, $this->store->render( $this->manifest ), 'render() must be byte-stable.' );
		self::assertIsArray( json_decode( $document, true ) );
	}

	/**
	 * Writes a manifest signed by the environment keyring to a fixture file
	 * and returns its path.
	 */
	private function write_signed_manifest(): string {
		$path = $this->tmp_dir . '/signed.json';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing an integration fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $path, $this->store->render( $this->manifest ) );

		return $path;
	}

	/**
	 * Like write_signed_manifest(), but the document carries one field the
	 * schema does not allow — RE-signed, so the HMAC verifies and only the
	 * schema check can refuse it.
	 */
	private function write_signed_manifest_with_extra_field(): string {
		$path = $this->write_signed_manifest();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.json_decode_json_decode -- decoding the signed fixture back for mutation; the WP_Filesystem credentials context does not exist here.
		$document = json_decode( file_get_contents( $path ), true );

		$document['unexpectedField'] = true;

		$signature             = $this->gateway->sign_manifest( $document );
		$document['hmacKeyId'] = $signature['hmacKeyId'];
		$document['hmac']      = $signature['hmac'];

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- rewriting the signed fixture; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $path, wp_json_encode( $document ) );

		return $path;
	}

	/**
	 * A schema-valid signed manifest: one template record, keyed like the
	 * prepare step keys one, with every field the promotion-manifest schema
	 * requires on the record shape present.
	 */
	/**
	 * The same fixture with an attacker-chosen promotion id, so a test can
	 * drive the exact input the bounded review used to escape into the web
	 * root.
	 */
	private function manifest_with_promotion_id( string $promotion_id ): PromotionManifest {
		return PromotionManifest::create(
			$promotion_id,
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
			'preparedFilePath'         => 'var/agency-prepared/templates/page.html',
			'themeRelativePath'        => 'templates/page.html',
			'preparedFileHash'         => str_repeat( 'd', 64 ),
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

			// remove_tree() removes $path itself, so the caller must NOT rmdir it
			// again. It did, and the second call failed with "No such file or
			// directory" as soon as a fixture directory contained a subdirectory.
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
