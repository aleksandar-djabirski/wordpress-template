<?php
/**
 * The end-to-end export contract (BLOCK_THEME_PROPOSAL.md §7.2): the default
 * export covers every structural provider and excludes content, stateHash is
 * deterministic across repeated exports of unchanged state while exportId is
 * deliberately random, exactly the fields §7.2 excludes from the hash are
 * proven not to participate, and the written document is canonical bytes —
 * one trailing LF, no CR, encoding a fixed point. The signer is injected
 * because HMAC constants are immutable and cannot be defined per-test.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\SchemaValidator;
use AgencyPlatform\State\StateBundle;
use AgencyPlatform\State\StateException;
use AgencyPlatform\State\StateExporter;
use AgencyPlatform\State\StateRegistry;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\StateExporter
 * @covers \AgencyPlatform\State\StateRegistry
 */
final class StateExportTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	private function signer(): HmacSigner {
		return new HmacSigner( array( '2026-01' => str_repeat( 'k', 40 ) ), '2026-01' );
	}

	/**
	 * @param list<string> $provider_slugs
	 * @return array<string, mixed>
	 */
	private function export( array $provider_slugs ): array {
		return ( new StateExporter( $this->signer() ) )->export( $provider_slugs );
	}

	public function test_the_default_export_covers_every_structural_provider_and_excludes_content(): void {
		$bundle = $this->export( StateRegistry::resolve( null, false ) );

		self::assertSame( StateRegistry::STRUCTURAL_SLUGS, array_keys( $bundle['providers'] ) );
		self::assertArrayNotHasKey( 'content', $bundle['providers'] );
	}

	public function test_include_content_adds_the_content_provider(): void {
		$bundle = $this->export( StateRegistry::resolve( null, true ) );

		self::assertArrayHasKey( 'content', $bundle['providers'] );
	}

	public function test_providers_narrows_the_export(): void {
		$bundle = $this->export( StateRegistry::resolve( 'templates,global-styles', false ) );

		self::assertSame( array( 'global-styles', 'templates' ), array_keys( $bundle['providers'] ) );
	}

	public function test_repeated_exports_of_unchanged_state_produce_an_identical_state_hash(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Stable</p><!-- /wp:paragraph -->' );

		$first  = $this->export( array( 'templates' ) );
		$second = $this->export( array( 'templates' ) );

		self::assertSame( $first['stateHash'], $second['stateHash'] );
		self::assertSame( $first['providers'], $second['providers'], 'Record ordering, per-record hashes, and normalised data must all be identical.' );
		self::assertNotSame( $first['exportId'], $second['exportId'], 'exportId is deliberately random and is NOT part of stateHash.' );
	}

	/**
	 * Section 7.2 states exactly which wrapper fields are outside stateHash.
	 * Every mutation is pushed through the hash the EXPORTER actually
	 * produced: the mutated document is re-signed (the signature covers
	 * every field, so any mutation invalidates the original signature),
	 * written, and re-read through StateBundle::load(), which recomputes
	 * the hash over the canonical provider records and compares it with the
	 * recorded stateHash. A broken exporter that hashed any wrapper or
	 * provider-metadata field would record a hash that no longer matches
	 * after the mutation, and load() would throw exit 4 — the assertion
	 * below could not pass. The re-sign itself proves the signature fields
	 * are outside the hash too: they change on every iteration.
	 */
	public function test_no_excluded_wrapper_field_participates_in_the_state_hash(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Stable</p><!-- /wp:paragraph -->' );

		$bundle   = $this->export( array( 'templates' ) );
		$expected = $bundle['stateHash'];

		$mutations = array(
			'exportId'         => '00000000-0000-4000-8000-000000000000',
			'exportedAtUtc'    => '1999-12-31T23:59:59Z',
			'siteUrl'          => 'https://somewhere-else.invalid',
			'siteUuid'         => '00000000-1111-4222-8333-444455556666',
			'environment'      => 'production',
			'wordpressVersion' => '0.0',
		);

		foreach ( $mutations as $field => $value ) {
			$mutated           = $bundle;
			$mutated[ $field ] = $value;

			self::assertSame( $expected, $this->reported_hash( $mutated ), sprintf( '"%s" must not affect the exporter\'s stateHash.', $field ) );
		}

		$mutated                             = $bundle;
		$mutated['activeTheme']['gitCommit'] = str_repeat( '9', 40 );

		self::assertSame( $expected, $this->reported_hash( $mutated ), 'activeTheme.gitCommit must not affect the exporter\'s stateHash.' );

		foreach ( array( 'slug', 'ownership', 'promotion', 'hasGitBaseline' ) as $field ) {
			$mutated                                     = $bundle;
			$mutated['providers']['templates'][ $field ] = 'hasGitBaseline' === $field ? false : ( 'slug' === $field ? 'global-styles' : ( 'ownership' === $field ? Ownership::DATABASE : PromotionPolicy::NEVER_PROMOTE ) );

			self::assertSame( $expected, $this->reported_hash( $mutated ), sprintf( 'Provider metadata "%s" must not affect the exporter\'s stateHash.', $field ) );
		}
	}

	/**
	 * Re-signs a mutated bundle document, writes it as canonical bytes, and
	 * re-reads it through StateBundle::load(), which recomputes stateHash
	 * over the canonical provider records and compares it with the recorded
	 * hash. Returns the hash the reader reports — the exporter's recorded
	 * stateHash — and throws exit 4 when the recorded hash no longer
	 * matches the records, which is exactly what a broken exporter that
	 * hashed wrapper or metadata fields would produce.
	 *
	 * @param array<string, mixed> $document
	 */
	private function reported_hash( array $document ): string {
		$signature             = $this->signer()->sign( $document, HmacSigner::PURPOSE_BUNDLE );
		$document['hmacKeyId'] = $signature['hmacKeyId'];
		$document['hmac']      = $signature['hmac'];

		$path = tempnam( sys_get_temp_dir(), 'bundle' );

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- integration fixture file for StateBundle::load(); the WP_Filesystem credentials context does not exist here.
			file_put_contents( $path, Normalizer::canonical_json_document( $document ) );

			$loaded = StateBundle::load( $path, $this->signer() );

			self::assertTrue( $loaded->is_verified(), 'The re-signed bundle must verify.' );

			return $loaded->state_hash();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $path );
		}
	}

	public function test_the_written_bundle_is_canonical_bytes_with_one_trailing_lf(): void {
		$bundle = $this->export( array( 'templates' ) );
		$json   = Normalizer::canonical_json_document( $bundle );

		self::assertStringEndsWith( "}\n", $json );
		self::assertStringNotContainsString( "\r", $json );
		self::assertSame( $json, Normalizer::canonical_json_document( json_decode( $json, true ) ), 'Encoding must be a fixed point.' );
	}

	public function test_a_changed_record_changes_the_state_hash(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->' );
		$before = $this->export( array( 'templates' ) );

		$this->make_template( 'page', '<!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->' );
		$after = $this->export( array( 'templates' ) );

		self::assertNotSame( $before['stateHash'], $after['stateHash'] );
	}

	public function test_the_bundle_validates_against_the_schema_and_verifies_its_signature(): void {
		$bundle = $this->export( StateRegistry::resolve( null, false ) );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
		$this->signer()->verify( $bundle, HmacSigner::PURPOSE_BUNDLE );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_bundle_signature_is_not_accepted_as_a_manifest_signature(): void {
		$this->expectException( StateException::class );

		$this->signer()->verify( $this->export( array( 'templates' ) ), HmacSigner::PURPOSE_MANIFEST );
	}
}
