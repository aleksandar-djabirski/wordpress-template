<?php
/**
 * The bundle reader's whole reason to exist is spec §9.5's "verify bundle
 * HMAC before reading": records, provider metadata, and single records are
 * mechanically unreadable until verify_signature() has succeeded, so a
 * reader that hands out content from an unverified document would defeat
 * the signer entirely. The stateHash input excludes every provider metadata
 * field and every wrapper field, so those can never participate in the
 * hash.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\StateBundle;
use AgencyPlatform\State\StateException;
use AgencyPlatform\State\StateExporter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\StateBundle
 * @covers \AgencyPlatform\State\StateExporter
 */
final class StateBundleTest extends TestCase {

	private function signer(): HmacSigner {
		return new HmacSigner( array( '2026-01' => str_repeat( 'k', 40 ) ), '2026-01' );
	}

	/**
	 * A complete bundle document with a correct stateHash computed through
	 * the exporter's own canonical input — exactly what StateExporter::export()
	 * does — and a real signature. Mutating it and re-reading it through
	 * load() must fail, which is what most of this file proves.
	 *
	 * @return array<string, mixed>
	 */
	private function signed_document(): array {
		$document = array(
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
				'templates' => $this->provider_node(),
			),
		);

		$document['stateHash'] = Normalizer::hash( StateExporter::canonical_provider_records( $document['providers'] ) );

		return $document + $this->signer()->sign( $document, HmacSigner::PURPOSE_BUNDLE );
	}

	/**
	 * One provider node with one record whose contentHash is recomputed, so
	 * StateRecord::from_array() accepts it.
	 *
	 * @return array<string, mixed>
	 */
	private function provider_node(): array {
		$content = array( 'markup' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' );

		return array(
			'slug'           => 'templates',
			'ownership'      => Ownership::GIT_BASELINE_PLUS_DB,
			'promotion'      => PromotionPolicy::PROMOTABLE,
			'hasGitBaseline' => true,
			'records'        => array(
				array(
					'key'         => 'templates:page',
					'objectId'    => 12,
					'slug'        => 'page',
					'status'      => 'publish',
					'modifiedGmt' => '2026-08-01 10:00:00',
					'content'     => $content,
					'contentHash' => Normalizer::hash( $content ),
					'references'  => array(),
					'ownership'   => Ownership::GIT_BASELINE_PLUS_DB,
					'promotion'   => PromotionPolicy::PROMOTABLE,
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private function empty_provider( string $slug ): array {
		return array(
			'slug'           => $slug,
			'ownership'      => Ownership::DATABASE,
			'promotion'      => PromotionPolicy::NEVER_PROMOTE,
			'hasGitBaseline' => false,
			'records'        => array(),
		);
	}

	public function test_no_content_accessor_answers_until_the_signature_is_verified(): void {
		$bundle = StateBundle::from_array( $this->signed_document() );

		self::assertFalse( $bundle->is_verified(), 'is_verified() must answer before verification: it is the only ungated method.' );

		$accessors = array(
			'to_array'          => static function ( StateBundle $bundle ): void {
				$bundle->to_array();
			},
			'schema_version'    => static function ( StateBundle $bundle ): void {
				$bundle->schema_version();
			},
			'export_id'         => static function ( StateBundle $bundle ): void {
				$bundle->export_id();
			},
			'exported_at_utc'   => static function ( StateBundle $bundle ): void {
				$bundle->exported_at_utc();
			},
			'site_uuid'         => static function ( StateBundle $bundle ): void {
				$bundle->site_uuid();
			},
			'site_url'          => static function ( StateBundle $bundle ): void {
				$bundle->site_url();
			},
			'environment'       => static function ( StateBundle $bundle ): void {
				$bundle->environment();
			},
			'wordpress_version' => static function ( StateBundle $bundle ): void {
				$bundle->wordpress_version();
			},
			'active_theme'      => static function ( StateBundle $bundle ): void {
				$bundle->active_theme();
			},
			'state_hash'        => static function ( StateBundle $bundle ): void {
				$bundle->state_hash();
			},
			'provider_slugs'    => static function ( StateBundle $bundle ): void {
				$bundle->provider_slugs();
			},
			'provider_meta'     => static function ( StateBundle $bundle ): void {
				$bundle->provider_meta( 'templates' );
			},
			'records'           => static function ( StateBundle $bundle ): void {
				$bundle->records( 'templates' );
			},
			'record'            => static function ( StateBundle $bundle ): void {
				$bundle->record( 'templates:page' );
			},
		);

		foreach ( $accessors as $name => $invoke ) {
			try {
				$invoke( $bundle );
			} catch ( StateException $exception ) {
				self::assertSame( StateException::EXIT_TAMPER, $exception->exit_code(), $name . ' must fail with exit code 4 while the bundle is unverified.' );
				self::assertStringContainsString( 'not been verified', $exception->getMessage(), $name . ' must name the verification gate.' );
				continue;
			}

			self::fail( $name . ' must throw StateException with exit code ' . StateException::EXIT_TAMPER . ' while the bundle is unverified; an unverified accessor defeats the signer.' );
		}
	}

	public function test_records_are_readable_after_verification(): void {
		$bundle = StateBundle::from_array( $this->signed_document() );
		$bundle->verify_signature( $this->signer() );

		self::assertTrue( $bundle->is_verified() );
		self::assertSame( 'templates:page', $bundle->records( 'templates' )[0]->key() );
		self::assertSame( 'templates:page', $bundle->record( 'templates:page' )->key() );
		self::assertNull( $bundle->record( 'templates:missing' ) );
	}

	public function test_a_tampered_record_fails_verification_before_any_content_is_read(): void {
		$document = $this->signed_document();
		$document['providers']['templates']['records'][0]['content']['markup'] = '<!-- wp:paragraph --><p>Injected</p><!-- /wp:paragraph -->';

		$bundle = StateBundle::from_array( $document );

		$this->expectException( StateException::class );

		$bundle->verify_signature( $this->signer() );
	}

	public function test_load_rejects_a_state_hash_that_no_longer_matches_the_providers(): void {
		$path                  = tempnam( sys_get_temp_dir(), 'bundle' );
		$document              = $this->signed_document();
		$document['stateHash'] = str_repeat( '0', 64 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- unit fixture file for StateBundle::load(); no WordPress filesystem credentials context exists in this unit suite.
		file_put_contents( $path, Normalizer::canonical_json_document( $document ) );

		$this->expectException( StateException::class );

		try {
			StateBundle::load( $path, $this->signer() );
		} catch ( StateException $exception ) {
			self::assertSame( StateException::EXIT_TAMPER, $exception->exit_code() );
			throw $exception;
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting a unit fixture file; no WordPress filesystem credentials context exists in this unit suite.
			unlink( $path );
		}
	}

	public function test_the_wrapper_metadata_is_exposed_after_verification(): void {
		$bundle = StateBundle::from_array( $this->signed_document() );
		$bundle->verify_signature( $this->signer() );

		self::assertSame( 1, $bundle->schema_version() );
		self::assertSame( '11111111-2222-4333-8444-555566667777', $bundle->export_id() );
		self::assertSame( '2026-08-02T09:00:00Z', $bundle->exported_at_utc() );
		self::assertSame( '99999999-2222-4333-8444-555566667777', $bundle->site_uuid() );
		self::assertSame( 'https://agency-starter.ddev.site', $bundle->site_url() );
		self::assertSame( 'development', $bundle->environment() );
		self::assertSame( '7.0', $bundle->wordpress_version() );
		self::assertSame( 'site-theme', $bundle->active_theme()['stylesheet'] );
		self::assertSame( '0.1.0', $bundle->active_theme()['version'] );
		self::assertNull( $bundle->active_theme()['gitCommit'] );
		self::assertSame( array( 'templates' ), $bundle->provider_slugs() );
		self::assertSame( $this->signed_document()['stateHash'], $bundle->state_hash() );
		self::assertSame( Ownership::GIT_BASELINE_PLUS_DB, $bundle->provider_meta( 'templates' )['ownership'] );
		self::assertSame( $this->signed_document(), $bundle->to_array() );
	}

	public function test_provider_slugs_are_sorted(): void {
		$document              = $this->signed_document();
		$document['providers'] = array( 'templates' => $document['providers']['templates'] ) + array( 'global-styles' => $this->empty_provider( 'global-styles' ) );
		$document['stateHash'] = Normalizer::hash( StateExporter::canonical_provider_records( $document['providers'] ) );

		$signature             = $this->signer()->sign( $document, HmacSigner::PURPOSE_BUNDLE );
		$document['hmacKeyId'] = $signature['hmacKeyId'];
		$document['hmac']      = $signature['hmac'];

		$bundle = StateBundle::from_array( $document );
		$bundle->verify_signature( $this->signer() );

		self::assertSame( array( 'global-styles', 'templates' ), $bundle->provider_slugs() );
	}

	/**
	 * Section 7.2 of the proposal fixes exactly which fields stateHash
	 * covers: provider metadata (slug, ownership, promotion, hasGitBaseline)
	 * and every wrapper field are outside it, every record field is inside
	 * it. This asserts the canonical input directly instead of trusting two
	 * export runs to prove it.
	 */
	public function test_provider_metadata_never_participates_in_the_hash_but_record_fields_do(): void {
		$document = $this->signed_document();
		$expected = Normalizer::hash( StateExporter::canonical_provider_records( $document['providers'] ) );

		foreach ( array( 'slug', 'ownership', 'promotion', 'hasGitBaseline' ) as $field ) {
			$mutated                                     = $document;
			$mutated['providers']['templates'][ $field ] = 'hasGitBaseline' === $field ? false : 'changed-metadata';

			self::assertSame( $expected, Normalizer::hash( StateExporter::canonical_provider_records( $mutated['providers'] ) ), sprintf( 'Provider metadata "%s" must not affect stateHash.', $field ) );
		}

		foreach ( array( 'key', 'objectId', 'slug', 'status', 'modifiedGmt', 'content', 'contentHash', 'references', 'ownership', 'promotion' ) as $field ) {
			$mutated = $document;
			$record  = &$mutated['providers']['templates']['records'][0];

			if ( 'content' === $field ) {
				$record['content']['markup'] = '<!-- wp:paragraph --><p>Changed</p><!-- /wp:paragraph -->';
			} elseif ( 'references' === $field ) {
				$record['references'] = array( array( 'record' => 'templates:other' ) );
			} elseif ( 'objectId' === $field ) {
				$record['objectId'] = 99;
			} elseif ( 'modifiedGmt' === $field ) {
				$record['modifiedGmt'] = '2026-08-02 00:00:00';
			} else {
				$record[ $field ] = 'changed-value';
			}

			self::assertNotSame( $expected, Normalizer::hash( StateExporter::canonical_provider_records( $mutated['providers'] ) ), sprintf( 'Record field "%s" must affect stateHash.', $field ) );
		}
	}
}
