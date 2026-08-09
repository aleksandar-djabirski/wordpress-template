<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

	use AgencyPlatform\State\HmacSigner;
	use AgencyPlatform\State\Promotion\BundleView;
	use AgencyPlatform\State\Promotion\ManifestStore;
	use AgencyPlatform\State\Promotion\PromotionException;
	use AgencyPlatform\State\Promotion\PromotionExitCode;
	use AgencyPlatform\State\Promotion\PromotionManifest;
	use AgencyPlatform\State\Promotion\PromotionRollback;
	use AgencyPlatform\State\Promotion\RecordLockManager;
	use AgencyPlatform\State\Promotion\StagedJsonFile;
	use AgencyPlatform\State\Promotion\StagedPreparedFile;
	use AgencyPlatform\State\Promotion\StagedPromotionEntry;
	use AgencyPlatform\State\Promotion\StateGateway;
	use AgencyPlatform\State\Promotion\TemplatePromotionStrategy;
	use AgencyPlatform\State\StateBundle;
	use PHPUnit\Framework\TestCase;

	/**
	 * @covers \AgencyPlatform\State\Promotion\AbstractBlockTemplateStrategy
	 * @covers \AgencyPlatform\State\Promotion\StagedPreparedFile
	 * @covers \AgencyPlatform\State\Promotion\StagedJsonFile
	 * @covers \AgencyPlatform\State\Promotion\PromotionRollback
	 */
final class PromotionInternalsTest extends TestCase {

	/** @var resource|null */
	public $context;

	/** @var list<string> */
	private array $fixture_paths = array();

	/**
	 * @param string $path
	 * @param int    $flags
	 * @return array<string, int>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the stream wrapper signature requires both parameters.
	public function url_stat( string $path, int $flags ): array {
		return array(
			'dev'     => 0,
			'ino'     => 0,
			'mode'    => 0100000 | 0644,
			'nlink'   => 1,
			'uid'     => 0,
			'gid'     => 0,
			'rdev'    => -1,
			'size'    => 0,
			'atime'   => time(),
			'mtime'   => time(),
			'ctime'   => time(),
			'blksize' => -1,
			'blocks'  => -1,
		);
	}

	public function unlink( string $path ): bool {
		return false;
	}

	protected function setUp(): void {
		parent::setUp();

		\WP_Block_Type_Registry::get_instance()->set_registered( array() );
	}

	protected function tearDown(): void {
		if ( in_array( 'p2rollback', stream_get_wrappers(), true ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- cleanup must not mask the test failure when a data-provider case aborts before its finally block.
			@stream_wrapper_unregister( 'p2rollback' );
		}

		foreach ( array_reverse( $this->fixture_paths ) as $path ) {
			if ( is_dir( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- deleting a unit fixture directory; the WP_Filesystem credentials context does not exist here.
				@rmdir( $path );
			} elseif ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- deleting a unit fixture file; the WP_Filesystem credentials context does not exist here.
				@unlink( $path );
			}
		}

		parent::tearDown();
	}

	public function test_validation_recurses_into_inner_blocks_for_unregistered_blocks(): void {
		\WP_Block_Type_Registry::get_instance()->set_registered( array( 'core/group' ) );
		self::assertFalse( \WP_Block_Type_Registry::get_instance()->is_registered( 'agency/secret' ) );

		$refusals = ( new TemplatePromotionStrategy() )->validate_for_promotion(
			$this->bundle_record(
				'templates:page',
				'<!-- wp:group --><!-- wp:agency/secret --><!-- /wp:agency/secret --><!-- /wp:group -->'
			),
			$this->empty_bundle(),
			array()
		);

		self::assertCount( 1, $refusals );
		self::assertSame( 'unregistered-block', $refusals[0]->reason_code );
		self::assertSame( 'agency/secret', $refusals[0]->block_name );
		self::assertStringContainsString( 'agency/secret', $refusals[0]->detail );
	}

	public function test_validation_recurses_into_inner_blocks_for_missing_template_parts(): void {
		\WP_Block_Type_Registry::get_instance()->set_registered( array( 'core/group', 'core/template-part' ) );

		$refusals = ( new TemplatePromotionStrategy() )->validate_for_promotion(
			$this->bundle_record(
				'templates:page',
				'<!-- wp:group --><!-- wp:template-part {"slug":"site-footer"} /--><!-- /wp:group -->'
			),
			$this->empty_bundle(),
			array()
		);

		self::assertCount( 1, $refusals );
		self::assertSame( 'missing-template-part', $refusals[0]->reason_code );
		self::assertSame( 'site-footer', $refusals[0]->referenced_value );
		self::assertStringContainsString( 'site-footer', $refusals[0]->detail );
	}

	/**
	 * @dataProvider staged_file_types
	 */
	public function test_rollback_reports_a_failed_restore_for_each_staged_file_type( string $type ): void {
		$path  = $this->fixture_path( $type . '-restore' );
		$temp  = $path . '.tmp';
		$entry = $this->make_entry( $type, $path, $temp, 'previous bytes' );

		$this->write_fixture( $temp, 'prepared bytes' );
		$entry->commit();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a unit fixture file.
		self::assertSame( 'prepared bytes', file_get_contents( $path ) );

		// A directory at the target path makes file_put_contents() return
		// false. The warning is the expected filesystem failure.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- replacing the test target with a directory to force rollback's write failure.
		self::assertTrue( unlink( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating a unit fixture directory.
		self::assertTrue( mkdir( $path ) );

		$exception = $this->capture_expected_warning(
			static function () use ( $entry ): void {
				$entry->rollback_committed();
			}
		);

		self::assertStringContainsString( 'could not be restored', $exception->getMessage() );
		self::assertStringContainsString( $path, $exception->getMessage() );
	}

	/**
	 * @dataProvider staged_file_types
	 */
	public function test_rollback_reports_a_failed_delete_for_each_staged_file_type( string $type ): void {
		$path  = 'p2rollback://delete-failure';
		$entry = $this->make_entry( $type, $path, 'unused-temp-path', null );
		$this->mark_committed( $entry );

		self::assertTrue( stream_wrapper_register( 'p2rollback', 'P2RollbackFailureStreamWrapper' ) );
		self::assertTrue( is_file( $path ) );

		$exception = $this->capture_expected_warning(
			static function () use ( $entry ): void {
				$entry->rollback_committed();
			}
		);

		self::assertStringContainsString( 'could not be removed', $exception->getMessage() );
		self::assertStringContainsString( $path, $exception->getMessage() );

		stream_wrapper_unregister( 'p2rollback' );
	}

	/**
	 * @dataProvider staged_file_types
	 */
	public function test_rollback_accepts_a_zero_byte_previous_file( string $type ): void {
		$path  = $this->fixture_path( $type . '-empty' );
		$temp  = $path . '.tmp';
		$entry = $this->make_entry( $type, $path, $temp, '' );

		$this->write_fixture( $temp, 'prepared bytes' );
		$entry->commit();
		$entry->rollback_committed();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a unit fixture file.
		self::assertSame( '', file_get_contents( $path ) );
	}

	public function test_partially_rolled_back_promotion_reaches_the_retry_lock_gate(): void {
		$manifest = PromotionManifest::create(
			'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
			'2026-08-09T10:00:00Z',
			array(
				'exportId'      => '11111111-2222-4333-8444-555566667777',
				'exportedAtUtc' => '2026-08-09T09:00:00Z',
				'siteUrl'       => 'https://agency-starter.ddev.site',
				'environment'   => 'development',
				'activeTheme'   => array(
					'stylesheet' => 'site-theme',
					'version'    => '0.1.0',
					'gitCommit'  => null,
				),
			),
			'99999999-2222-4333-8444-555566667777',
			str_repeat( 'a', 40 ),
			array()
		)
			->with_field( 'settlementStatus', 'partially-rolled-back' );

		$state_dir             = $this->fixture_path( 'state' );
		$promotions            = $state_dir . DIRECTORY_SEPARATOR . 'promotions';
		$this->fixture_paths[] = $promotions;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating unit fixture directories.
		self::assertTrue( mkdir( $state_dir ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating unit fixture directories.
		self::assertTrue( mkdir( $promotions ) );

		$signer = new HmacSigner( array( 'test-key' => str_repeat( 'k', 32 ) ), 'test-key' );
		$signed = $manifest->to_array() + $signer->sign( $manifest->to_array(), HmacSigner::PURPOSE_MANIFEST );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- serialising a unit fixture for ManifestStore::load().
		$json = json_encode( $signed, JSON_UNESCAPED_SLASHES );
		self::assertIsString( $json );

		$canonical_path = $promotions . DIRECTORY_SEPARATOR . $manifest->promotion_id() . '.json';
		$this->write_fixture( $canonical_path, $json );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- the test must point the real ManifestStore at an isolated state directory.
		putenv( 'AGENCY_STATE_DIR=' . $state_dir );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- the test must provide the repository root inside DDEV.
		putenv( 'AGENCY_REPO_ROOT=/var/www/html' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the test must provide an isolated HMAC keyring.
		putenv( 'AGENCY_PROMOTION_HMAC_KEYS=' . json_encode( array( 'test-key' => str_repeat( 'k', 32 ) ) ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- the test must provide an isolated signing key id.
		putenv( 'AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID=test-key' );

		$store = new ManifestStore(
			new StateGateway(),
			static function () use ( $json ): string {
				return $json;
			}
		);

		RecordLockManager::override_core_lock_api_availability( false );

		try {
			$exception = $this->assert_promotion_exception(
				static function () use ( $store ): void {
					( new PromotionRollback( new StateGateway(), $store ) )->rollback( '-' );
				}
			);

			self::assertSame( PromotionExitCode::LOCK_CONFLICT, $exception->exit_code() );
			self::assertStringContainsString( 'locking cannot be made atomic', $exception->getMessage() );
			self::assertStringNotContainsString( 'never finalized', $exception->getMessage() );
		} finally {
			RecordLockManager::override_core_lock_api_availability( null );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- restore the unit process after the isolated promotion test.
			putenv( 'AGENCY_STATE_DIR' );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- restore the unit process after the isolated promotion test.
			putenv( 'AGENCY_REPO_ROOT' );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- restore the unit process after the isolated promotion test.
			putenv( 'AGENCY_PROMOTION_HMAC_KEYS' );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- restore the unit process after the isolated promotion test.
			putenv( 'AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID' );
		}
	}

	/** @return array<string, array{string}> */
	public static function staged_file_types(): array {
		return array(
			'prepared file' => array( 'prepared' ),
			'json file'     => array( 'json' ),
		);
	}

	/** @return array<string, mixed> */
	private function bundle_record( string $key, string $markup ): array {
		list( $provider, $slug ) = explode( ':', $key, 2 );

		return array(
			'key'      => $key,
			'provider' => $provider,
			'slug'     => $slug,
			'content'  => array( 'markup' => $markup ),
		);
	}

	private function empty_bundle(): BundleView {
		$signer   = new HmacSigner( array( 'test-key' => str_repeat( 'k', 32 ) ), 'test-key' );
		$document = array( 'providers' => array() );
		$document = $document + $signer->sign( $document, HmacSigner::PURPOSE_BUNDLE );
		$bundle   = StateBundle::from_array( $document );

		$bundle->verify_signature( $signer );

		return new BundleView( $bundle );
	}

	private function make_entry( string $type, string $path, string $temp, ?string $previous_bytes ): StagedPromotionEntry {
		if ( 'json' === $type ) {
			return new StagedJsonFile( 'theme.json', $path, 'prepared-hash', 'original-hash', $temp, $previous_bytes );
		}

		return new StagedPreparedFile( 'templates/page.html', $path, 'prepared-hash', 'original-hash', 'expected-hash', $temp, $previous_bytes );
	}

	private function mark_committed( StagedPromotionEntry $entry ): void {
		$property = ( new \ReflectionClass( $entry ) )->getProperty( 'committed' );
		$property->setAccessible( true );
		$property->setValue( $entry, true );
	}

	private function fixture_path( string $name ): string {
		$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'agency-p2-' . $name . '-' . uniqid( '', true );

		$this->fixture_paths[] = $path;

		return $path;
	}

	private function write_fixture( string $path, string $contents ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a unit fixture file; the WP_Filesystem credentials context does not exist here.
		self::assertNotFalse( file_put_contents( $path, $contents ) );
	}

	/**
	 * @param callable():void $operation
	 */
	private function capture_expected_warning( callable $operation ): PromotionException {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- the test must suppress the expected filesystem warning and assert the surfaced exception.
		set_error_handler(
			static function ( int $severity, string $message ): bool {
				unset( $message );

				return E_WARNING === $severity;
			},
			E_WARNING
		);

		try {
			return $this->assert_promotion_exception( $operation );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * @param callable():void $operation
	 */
	private function assert_promotion_exception( callable $operation ): PromotionException {
		try {
			$operation();
		} catch ( PromotionException $exception ) {
			return $exception;
		}

		self::fail( 'Expected a PromotionException, but the operation completed.' );
	}
}

	class_alias( PromotionInternalsTest::class, 'P2RollbackFailureStreamWrapper' );
