<?php
/**
 * The tamper-detection contract for state bundles and promotion manifests
 * (BLOCK_THEME_PROPOSAL.md §7.2 and §7.7): a keyring rather than a single
 * key so production can accept an older key id during a controlled
 * rotation, distinct purpose prefixes so a bundle signature can never be
 * replayed as a manifest signature, and a hard failure — never a WordPress
 * salt fallback — when the keyring is missing.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\StateException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\HmacSigner
 */
final class HmacSignerTest extends TestCase {

	private const KEY_OLD = 'old-key-with-at-least-32-characters!!';
	private const KEY_NEW = 'new-key-with-at-least-32-characters!!';

	/** @return array<string, mixed> */
	private function payload(): array {
		return array(
			'schemaVersion' => 1,
			'siteUuid'      => 'b7c5b3a2-1111-4222-8333-444455556666',
			'providers'     => array( 'templates' => array( 'records' => array() ) ),
		);
	}

	private function signer( string $signing_key_id = '2026-06' ): HmacSigner {
		return new HmacSigner(
			array(
				'2026-01' => self::KEY_OLD,
				'2026-06' => self::KEY_NEW,
			),
			$signing_key_id
		);
	}

	public function test_sign_returns_the_signing_key_id_and_a_sha256_hex_signature(): void {
		$signature = $this->signer()->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );

		self::assertSame( '2026-06', $signature['hmacKeyId'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $signature['hmac'] );
	}

	public function test_verify_accepts_a_document_this_signer_signed(): void {
		$signer   = $this->signer();
		$document = $this->payload() + $signer->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );

		$signer->verify( $document, HmacSigner::PURPOSE_BUNDLE );

		$this->addToAssertionCount( 1 );
	}

	public function test_signature_ignores_the_order_the_payload_was_built_in(): void {
		$signer = $this->signer();

		$forward = $signer->sign(
			array(
				'a' => 1,
				'b' => 2,
			),
			HmacSigner::PURPOSE_BUNDLE
		);
		$reverse = $signer->sign(
			array(
				'b' => 2,
				'a' => 1,
			),
			HmacSigner::PURPOSE_BUNDLE
		);

		self::assertSame( $forward['hmac'], $reverse['hmac'] );
	}

	public function test_verify_rejects_a_tampered_payload(): void {
		$signer   = $this->signer();
		$document = $this->payload() + $signer->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );

		$document['siteUuid'] = 'ffffffff-1111-4222-8333-444455556666';

		$this->expectException( StateException::class );

		try {
			$signer->verify( $document, HmacSigner::PURPOSE_BUNDLE );
		} catch ( StateException $exception ) {
			self::assertSame( StateException::EXIT_TAMPER, $exception->exit_code() );
			throw $exception;
		}
	}

	public function test_a_bundle_signature_cannot_be_replayed_as_a_manifest_signature(): void {
		$signer   = $this->signer();
		$document = $this->payload() + $signer->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );

		$this->expectException( StateException::class );

		$signer->verify( $document, HmacSigner::PURPOSE_MANIFEST );
	}

	public function test_a_manifest_signature_cannot_be_replayed_as_a_bundle_signature(): void {
		$signer   = $this->signer();
		$document = $this->payload() + $signer->sign( $this->payload(), HmacSigner::PURPOSE_MANIFEST );

		$this->expectException( StateException::class );

		$signer->verify( $document, HmacSigner::PURPOSE_BUNDLE );
	}

	public function test_rotation_still_verifies_a_document_signed_by_the_older_key(): void {
		$old_signer = $this->signer( '2026-01' );
		$document   = $this->payload() + $old_signer->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );

		// Production has advanced its signing key id but kept the old key.
		$this->signer( '2026-06' )->verify( $document, HmacSigner::PURPOSE_BUNDLE );

		$this->addToAssertionCount( 1 );
	}

	public function test_verify_rejects_an_unknown_key_id(): void {
		$document = $this->payload() + array(
			'hmacKeyId' => '1999-01',
			'hmac'      => str_repeat( 'a', 64 ),
		);

		$this->expectException( StateException::class );

		try {
			$this->signer()->verify( $document, HmacSigner::PURPOSE_BUNDLE );
		} catch ( StateException $exception ) {
			self::assertSame( StateException::EXIT_TAMPER, $exception->exit_code() );
			throw $exception;
		}
	}

	public function test_verify_rejects_a_document_with_no_signature_at_all(): void {
		$this->expectException( StateException::class );

		$this->signer()->verify( $this->payload(), HmacSigner::PURPOSE_BUNDLE );
	}

	public function test_an_empty_keyring_is_a_hard_failure_not_a_salt_fallback(): void {
		$this->expectException( StateException::class );

		try {
			( new HmacSigner( array(), '2026-06' ) )->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );
		} catch ( StateException $exception ) {
			self::assertSame( StateException::EXIT_HARD_ERROR, $exception->exit_code() );
			self::assertStringContainsString( 'AGENCY_PROMOTION_HMAC_KEYS', $exception->getMessage() );
			throw $exception;
		}
	}

	public function test_a_signing_key_id_missing_from_the_keyring_is_a_hard_failure(): void {
		$this->expectException( StateException::class );

		$this->signer( '2027-01' )->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );
	}

	public function test_a_short_key_is_rejected(): void {
		$this->expectException( StateException::class );

		( new HmacSigner( array( '2026-06' => 'too-short' ), '2026-06' ) )->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );
	}

	public function test_canonicalize_excludes_the_signature_fields(): void {
		$signer = $this->signer();

		self::assertSame(
			$signer->canonicalize( $this->payload() ),
			$signer->canonicalize(
				$this->payload() + array(
					'hmac'      => str_repeat( 'b', 64 ),
					'hmacKeyId' => '2026-06',
				)
			)
		);
	}
}
