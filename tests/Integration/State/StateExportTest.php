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
use AgencyPlatform\State\SchemaValidator;
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
	 * This asserts it directly instead of inferring it from two export runs.
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
			'hmacKeyId'        => 'other-key',
			'hmac'             => str_repeat( 'f', 64 ),
		);

		foreach ( $mutations as $field => $value ) {
			$mutated           = $bundle;
			$mutated[ $field ] = $value;

			self::assertSame( $expected, Normalizer::hash( StateExporter::canonical_provider_records( $mutated['providers'] ) ), sprintf( '"%s" must not affect stateHash.', $field ) );
		}

		$mutated                             = $bundle;
		$mutated['activeTheme']['gitCommit'] = str_repeat( '9', 40 );

		self::assertSame( $expected, Normalizer::hash( StateExporter::canonical_provider_records( $mutated['providers'] ) ), 'activeTheme.gitCommit must not affect stateHash.' );

		foreach ( array( 'slug', 'ownership', 'promotion', 'hasGitBaseline' ) as $field ) {
			$mutated                                     = $bundle;
			$mutated['providers']['templates'][ $field ] = 'hasGitBaseline' === $field ? false : 'changed-metadata';

			self::assertSame( $expected, Normalizer::hash( StateExporter::canonical_provider_records( $mutated['providers'] ) ), sprintf( 'Provider metadata "%s" must not affect stateHash.', $field ) );
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
