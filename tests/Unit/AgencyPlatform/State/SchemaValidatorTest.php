<?php
/**
 * Every export must validate against resources/schemas/state-bundle-v1.json
 * (BLOCK_THEME_PROPOSAL.md §6). The schema is the contract Task 3's
 * promotion prepare step reads bundles through, so a malformed bundle must
 * fail loudly here rather than half-way through a promotion.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\SchemaValidator;
use AgencyPlatform\State\StateException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\SchemaValidator
 */
final class SchemaValidatorTest extends TestCase {

	/** @return array<string, mixed> */
	private function bundle(): array {
		return array(
			'schemaVersion'    => 1,
			'exportId'         => '11111111-2222-4333-8444-555566667777',
			'exportedAtUtc'    => '2026-08-02T09:00:00Z',
			'siteUuid'         => '99999999-2222-4333-8444-555566667777',
			'siteUrl'          => 'https://agency-starter.ddev.site',
			'environment'      => 'development',
			'wordpressVersion' => '7.0',
			'activeTheme'      => array(
				'stylesheet' => 'site-theme',
				'version'    => '0.1.0',
				'gitCommit'  => null,
			),
			'providers'        => array(
				'templates' => array(
					'slug'           => 'templates',
					'ownership'      => 'git-baseline+db',
					'promotion'      => 'promotable',
					'hasGitBaseline' => true,
					'records'        => array(
						array(
							'key'         => 'templates:page',
							'objectId'    => 12,
							'slug'        => 'page',
							'status'      => 'publish',
							'modifiedGmt' => '2026-08-01 10:00:00',
							'content'     => array( 'markup' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ),
							'contentHash' => str_repeat( 'a', 64 ),
							'references'  => array(),
							'ownership'   => 'git-baseline+db',
							'promotion'   => 'promotable',
						),
					),
				),
			),
			'stateHash'        => str_repeat( 'b', 64 ),
			'hmacKeyId'        => '2026-01',
			'hmac'             => str_repeat( 'c', 64 ),
		);
	}

	public function test_a_well_formed_bundle_validates(): void {
		( new SchemaValidator() )->validate( $this->bundle(), SchemaValidator::SCHEMA_STATE_BUNDLE );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_missing_required_wrapper_field_is_rejected(): void {
		$bundle = $this->bundle();
		unset( $bundle['stateHash'] );

		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/stateHash/' );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
	}

	public function test_an_unknown_wrapper_field_is_rejected(): void {
		$bundle                  = $this->bundle();
		$bundle['surpriseField'] = 'nope';

		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
	}

	public function test_a_bad_hash_shape_is_rejected(): void {
		$bundle              = $this->bundle();
		$bundle['stateHash'] = 'not-a-sha256';

		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/validation stopped at the first violation/' );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
	}

	public function test_an_unknown_ownership_value_is_rejected(): void {
		$bundle                                        = $this->bundle();
		$bundle['providers']['templates']['ownership'] = 'whatever';

		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
	}

	public function test_an_empty_provider_map_is_rejected(): void {
		$bundle              = $this->bundle();
		$bundle['providers'] = array();

		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
	}

	public function test_an_unknown_schema_name_is_a_hard_error(): void {
		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/no-such-schema/' );

		( new SchemaValidator() )->validate( $this->bundle(), 'no-such-schema' );
	}

	/**
	 * The promotion-manifest constant is declared by the state track so the
	 * promotion track never passes a magic string. The schema FILE was the
	 * promotion track's to ship, and it now HAS shipped, so this test flipped
	 * direction: it used to pin the file's ABSENCE and the "not found" failure,
	 * and it now pins the file's PRESENCE and proves the schema really enforces.
	 *
	 * The "not found" branch is not lost. It stays covered by
	 * test_a_custom_schema_dir_is_used_by_both_validate_and_schema_path, which
	 * points a validator at a schema directory that does not exist.
	 *
	 * Validating a BUNDLE document against the MANIFEST schema is the misuse
	 * case, and it must be a hard error naming the manifest schema rather than a
	 * silent pass. That is what makes this assertion able to fail: an empty or
	 * permissive schema file would let the bundle straight through.
	 */
	public function test_the_promotion_manifest_constant_names_a_real_schema_that_enforces(): void {
		self::assertSame( 'promotion-manifest-v1', SchemaValidator::SCHEMA_PROMOTION_MANIFEST );

		$path = ( new SchemaValidator() )->schema_path( SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
		self::assertStringEndsWith( 'promotion-manifest-v1.json', $path );
		self::assertFileExists( $path );

		$this->expectException( StateException::class );
		$this->expectExceptionMessage(
			sprintf( 'The document does not match schema "%s"', SchemaValidator::SCHEMA_PROMOTION_MANIFEST )
		);

		( new SchemaValidator() )->validate( $this->bundle(), SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
	}

	public function test_a_custom_schema_dir_is_used_by_both_validate_and_schema_path(): void {
		$validator = new SchemaValidator( __DIR__ . '/fixtures/absent-schemas' );
		$path      = $validator->schema_path( SchemaValidator::SCHEMA_STATE_BUNDLE );

		self::assertStringEndsWith( '/fixtures/absent-schemas/state-bundle-v1.json', $path );

		$this->expectException( StateException::class );
		$this->expectExceptionMessage(
			sprintf( 'JSON schema "%s" was not found at %s.', SchemaValidator::SCHEMA_STATE_BUNDLE, $path )
		);

		$validator->validate( $this->bundle(), SchemaValidator::SCHEMA_STATE_BUNDLE );
	}

	public function test_a_reference_without_its_resolved_target_is_rejected(): void {
		$bundle    = $this->bundle();
		$reference = array(
			'record'     => 'templates:page',
			'provider'   => 'templates',
			'blockName'  => 'core/navigation',
			'attribute'  => 'ref',
			'value'      => 12,
			'kind'       => 'navigation',
			'resolution' => 'environment-specific',
			'policy'     => 'Navigation stays database-owned in v1.',
			// targetKey / targetHash / targetIdentity deliberately omitted.
		);

		$bundle['providers']['templates']['records'][0]['references'] = array( $reference );

		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
	}
}
