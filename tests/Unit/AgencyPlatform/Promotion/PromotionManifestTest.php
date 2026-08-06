<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionExitCode;
use AgencyPlatform\State\Promotion\PromotionManifest;
use AgencyPlatform\State\Promotion\RecordRefusal;
use AgencyPlatform\State\SchemaValidator;
use AgencyPlatform\State\StateException;
use PHPUnit\Framework\TestCase;

/**
 * PromotionManifest is the value object behind every promotion lifecycle
 * step: prepare builds one, seal and finalize mutate it through the
 * with_* methods, and every later step reads it back through from_array().
 * Because the whole payload is HMAC-signed once at seal time, every
 * with_* method MUST return a new instance — an in-place mutation would
 * silently corrupt an already-signed manifest.
 *
 * The schema describes the SIGNED, STORED manifest: hmacKeyId and hmac are
 * required, and a freshly create()d manifest legitimately carries neither.
 * This class does no schema validation — that is the store's job.
 *
 * @covers \AgencyPlatform\State\Promotion\PromotionManifest
 */
final class PromotionManifestTest extends TestCase {

	private const TARGET_SITE_UUID = '99999999-2222-4333-8444-555566667777';

	public function test_create_yields_the_initial_pending_state(): void {
		$manifest = $this->manifest();

		self::assertSame( PromotionManifest::SCHEMA_VERSION, $manifest->to_array()['schemaVersion'] );
		self::assertSame( 'pending', $manifest->finalize_status() );
		self::assertSame( 'pending', $manifest->settlement_status() );
		self::assertNull( $manifest->deploy_commit() );
		self::assertFalse( $manifest->is_sealed() );
		self::assertNull( $manifest->finalized_at_utc() );
		self::assertNull( $manifest->settled_at_utc() );
		self::assertNull( $manifest->retention_until_utc() );
		self::assertNull( $manifest->backup_id() );
		self::assertSame( array(), $manifest->record_keys() );
		self::assertSame( array(), $manifest->records() );
		self::assertSame( array(), $manifest->refusals() );
	}

	public function test_site_uuid_is_the_target_argument_and_the_header_fields_pass_through(): void {
		$manifest = $this->manifest();

		self::assertSame( self::TARGET_SITE_UUID, $manifest->site_uuid() );
		self::assertSame( '11111111-2222-4333-8444-555566667777', $manifest->export_id() );
		self::assertSame( 'https://agency-starter.ddev.site', $manifest->site_url() );
		self::assertSame( 'development', $manifest->environment() );
		self::assertSame(
			array(
				'stylesheet' => 'site-theme',
				'version'    => '0.1.0',
				'gitCommit'  => 'cccccccccccccccccccccccccccccccccccccccc',
			),
			$manifest->active_theme()
		);
		self::assertSame( str_repeat( 'a', 40 ), $manifest->base_commit() );
		self::assertSame( '2026-08-02T10:00:00Z', $manifest->to_array()['preparedAtUtc'] );
		self::assertSame( array( 'npm run test:e2e' ), $manifest->verification_commands() );
	}

	public function test_a_fresh_manifest_carries_no_signature_fields(): void {
		$array = $this->manifest()->to_array();

		self::assertArrayNotHasKey( 'hmacKeyId', $array );
		self::assertArrayNotHasKey( 'hmac', $array );
	}

	public function test_with_deploy_commit_returns_a_new_instance_and_leaves_the_original_untouched(): void {
		$original = $this->manifest();
		$sealed   = $original->with_deploy_commit( str_repeat( 'b', 40 ), '2026-08-02T11:00:00Z' );

		self::assertSame( str_repeat( 'b', 40 ), $sealed->deploy_commit() );
		self::assertSame( '2026-08-02T11:00:00Z', $sealed->to_array()['sealedAtUtc'] );
		self::assertTrue( $sealed->is_sealed() );

		self::assertNull( $original->deploy_commit() );
		self::assertNull( $original->to_array()['sealedAtUtc'] );
		self::assertFalse( $original->is_sealed() );
	}

	public function test_with_record_returns_a_new_instance_and_leaves_the_original_empty(): void {
		$original = $this->manifest();
		$with     = $original->with_record( 'templates:page', $this->record( 'templates:page' ) );

		self::assertSame( $this->record( 'templates:page' ), $with->record( 'templates:page' ) );
		self::assertSame( array( 'templates:page' ), $with->record_keys() );

		self::assertNull( $original->record( 'templates:page' ) );
		self::assertSame( array(), $original->records() );
	}

	public function test_with_record_changes_merges_and_leaves_the_original_record_untouched(): void {
		$original = $this->manifest()->with_record( 'templates:page', $this->record( 'templates:page' ) );
		$changed  = $original->with_record_changes(
			'templates:page',
			array(
				'objectId'       => 99,
				'finalizeStatus' => 'promoted',
			)
		);

		$record = $changed->record( 'templates:page' );

		self::assertSame( 99, $record['objectId'] );
		self::assertSame( 'promoted', $record['finalizeStatus'] );
		self::assertSame( str_repeat( 'c', 64 ), $record['originalContentHash'] );
		self::assertSame( 'page.html', $record['themeRelativePath'] );
		self::assertSame( 'not-attempted', $record['rollbackStatus'] );
		self::assertSame( 'templates:page', $record['key'] );

		$untouched = $original->record( 'templates:page' );

		self::assertSame( 12, $untouched['objectId'] );
		self::assertSame( 'pending', $untouched['finalizeStatus'] );
		self::assertSame( str_repeat( 'c', 64 ), $untouched['originalContentHash'] );
	}

	public function test_with_record_replaces_an_existing_record_by_key(): void {
		$original                = $this->manifest()->with_record( 'templates:page', $this->record( 'templates:page' ) );
		$replacement             = $this->record( 'templates:page' );
		$replacement['objectId'] = 77;
		$updated                 = $original->with_record( 'templates:page', $replacement );

		self::assertSame( 77, $updated->record( 'templates:page' )['objectId'] );
		self::assertCount( 1, $updated->records() );
		self::assertSame( 12, $original->record( 'templates:page' )['objectId'] );
	}

	public function test_record_keys_are_sorted_ascending_regardless_of_insertion_order(): void {
		$manifest = $this->manifest()
			->with_record( 'templates:page', $this->record( 'templates:page' ) )
			->with_record( 'template-parts:site-header', $this->record( 'template-parts:site-header' ) )
			->with_record( 'global-styles:theme', $this->record( 'global-styles:theme' ) );

		self::assertSame(
			array( 'global-styles:theme', 'template-parts:site-header', 'templates:page' ),
			$manifest->record_keys()
		);
	}

	public function test_with_field_returns_a_new_instance_and_leaves_the_original_untouched(): void {
		$original = $this->manifest();
		$signed   = $original->with_field( 'hmacKeyId', '2026-01' )->with_field( 'hmac', str_repeat( 'f', 64 ) );

		self::assertSame( '2026-01', $signed->to_array()['hmacKeyId'] );
		self::assertSame( str_repeat( 'f', 64 ), $signed->to_array()['hmac'] );

		self::assertArrayNotHasKey( 'hmacKeyId', $original->to_array() );
		self::assertArrayNotHasKey( 'hmac', $original->to_array() );
	}

	public function test_with_field_changes_an_existing_status_field_only_on_the_copy(): void {
		$original = $this->manifest();
		$complete = $original->with_field( 'finalizeStatus', 'complete' );

		self::assertSame( 'complete', $complete->finalize_status() );
		self::assertSame( 'pending', $original->finalize_status() );
	}

	public function test_with_refusals_returns_a_new_instance_and_leaves_the_original_empty(): void {
		$original = $this->manifest();
		$reported = $original->with_refusals( array( $this->refusal() ) );

		self::assertSame( 'unresolved-reference', $reported->refusals()[0]['reasonCode'] );
		self::assertSame( 'core/image', $reported->refusals()[0]['blockName'] );
		self::assertSame( 'templates:page', $reported->refusals()[0]['recordKey'] );

		self::assertSame( array(), $original->refusals() );
	}

	public function test_from_array_round_trips_an_unsigned_manifest_identically(): void {
		$manifest = $this->manifest()
			->with_record( 'templates:page', $this->record( 'templates:page' ) )
			->with_refusals( array( $this->refusal() ) );

		self::assertSame( $manifest->to_array(), PromotionManifest::from_array( $manifest->to_array() )->to_array() );
	}

	public function test_from_array_round_trips_a_signed_document_identically(): void {
		$document = $this->signed_document();

		self::assertSame( $document, PromotionManifest::from_array( $document )->to_array() );
	}

	public function test_from_array_rejects_a_foreign_schema_version_with_exit_one(): void {
		try {
			PromotionManifest::from_array(
				array(
					'schemaVersion' => 2,
					'promotionId'   => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
				)
			);
			self::fail( 'Expected a PromotionException.' );
		} catch ( PromotionException $exception ) {
			self::assertSame( PromotionExitCode::HARD_ERROR, $exception->exit_code() );
			self::assertStringContainsString( 'schemaVersion', $exception->getMessage() );
		}
	}

	public function test_the_schema_accepts_a_complete_signed_manifest(): void {
		( new SchemaValidator() )->validate( $this->signed_document(), SchemaValidator::SCHEMA_PROMOTION_MANIFEST );

		$this->addToAssertionCount( 1 );
	}

	public function test_the_schema_rejects_an_unsigned_manifest_until_it_is_stored(): void {
		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $this->manifest()->to_array(), SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
	}

	public function test_the_schema_rejects_a_non_uuid_promotion_id(): void {
		$document                = $this->signed_document();
		$document['promotionId'] = 'not-a-uuid';

		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/promotionId/' );

		( new SchemaValidator() )->validate( $document, SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
	}

	public function test_the_schema_rejects_a_non_uuid_export_id(): void {
		$document             = $this->signed_document();
		$document['exportId'] = '11111111';

		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/exportId/' );

		( new SchemaValidator() )->validate( $document, SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
	}

	public function test_the_schema_rejects_an_injected_root_field(): void {
		$document             = $this->signed_document();
		$document['injected'] = 'field';

		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $document, SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
	}

	public function test_the_schema_rejects_an_injected_record_field(): void {
		$document                           = $this->signed_document();
		$document['records'][0]['injected'] = 'field';

		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $document, SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
	}

	public function test_the_schema_rejects_a_malformed_hmac(): void {
		$document         = $this->signed_document();
		$document['hmac'] = 'xyz';

		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/hmac/' );

		( new SchemaValidator() )->validate( $document, SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
	}

	public function test_the_schema_rejects_an_injected_active_theme_field(): void {
		$document                            = $this->signed_document();
		$document['activeTheme']['injected'] = 'field';

		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $document, SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
	}

	public function test_the_schema_rejects_an_unknown_record_finalize_status(): void {
		$document                                 = $this->signed_document();
		$document['records'][0]['finalizeStatus'] = 'banana';

		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $document, SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
	}

	public function test_the_schema_rejects_a_base_commit_that_is_not_forty_hex_digits(): void {
		$document               = $this->signed_document();
		$document['baseCommit'] = 'short';

		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/baseCommit/' );

		( new SchemaValidator() )->validate( $document, SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
	}

	/**
	 * StateBundle::active_theme() returns stylesheet, version AND a nullable
	 * gitCommit, and BundleView::header() hands that straight to create(). The
	 * schema forbids additional properties, so a manifest that dropped gitCommit
	 * — or a schema that did not allow it — would make every promotion prepared
	 * from a real bundle fail validation, and it would fail at Task 10 rather
	 * than here. These two tests pin both halves together.
	 */
	public function test_active_theme_carries_the_git_commit_the_bundle_supplied(): void {
		self::assertSame(
			array(
				'stylesheet' => 'site-theme',
				'version'    => '0.1.0',
				'gitCommit'  => 'cccccccccccccccccccccccccccccccccccccccc',
			),
			$this->manifest()->active_theme()
		);
	}

	public function test_the_schema_requires_the_active_theme_git_commit(): void {
		$document = $this->signed_document();
		unset( $document['activeTheme']['gitCommit'] );

		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/gitCommit/' );

		( new SchemaValidator() )->validate( $document, SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
	}

	public function test_a_null_active_theme_git_commit_is_accepted(): void {
		$document                             = $this->signed_document();
		$document['activeTheme']['gitCommit'] = null;

		( new SchemaValidator() )->validate( $document, SchemaValidator::SCHEMA_PROMOTION_MANIFEST );

		$this->addToAssertionCount( 1 );
	}

	private function manifest(): PromotionManifest {
		return PromotionManifest::create(
			'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
			'2026-08-02T10:00:00Z',
			$this->bundle_header(),
			self::TARGET_SITE_UUID,
			str_repeat( 'a', 40 ),
			array( 'npm run test:e2e' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function bundle_header(): array {
		return array(
			'exportId'      => '11111111-2222-4333-8444-555566667777',
			'exportedAtUtc' => '2026-08-02T09:00:00Z',
			'siteUrl'       => 'https://agency-starter.ddev.site',
			'environment'   => 'development',
			'activeTheme'   => array(
				'stylesheet' => 'site-theme',
				'version'    => '0.1.0',
				'gitCommit'  => 'cccccccccccccccccccccccccccccccccccccccc',
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function record( string $key ): array {
		list( $provider, $slug ) = explode( ':', $key, 2 );

		return array(
			'key'                      => $key,
			'provider'                 => $provider,
			'slug'                     => $slug,
			'objectId'                 => 12,
			'originalContentHash'      => str_repeat( 'c', 64 ),
			'originalModifiedGmt'      => '2026-08-01 10:00:00',
			'preparedFilePath'         => 'web/app/themes/site-theme/' . $slug . '.html',
			'themeRelativePath'        => $slug . '.html',
			'preparedFileHash'         => str_repeat( 'd', 64 ),
			'originalFileHash'         => null,
			'referenceScan'            => array(),
			'navigationExpectation'    => array(),
			'expectedPostResetHash'    => str_repeat( 'e', 64 ),
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

	private function refusal(): RecordRefusal {
		return new RecordRefusal(
			'templates:page',
			'templates',
			'page',
			'unresolved-reference',
			'Attachment ID 42 is environment-specific.',
			'core/image',
			'id',
			'42',
			'Replace the image with a theme asset, or exclude this record.'
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function signed_document(): array {
		return $this->manifest()
			->with_record( 'templates:page', $this->record( 'templates:page' ) )
			->with_field( 'hmacKeyId', '2026-01' )
			->with_field( 'hmac', str_repeat( 'a', 64 ) )
			->to_array();
	}
}
