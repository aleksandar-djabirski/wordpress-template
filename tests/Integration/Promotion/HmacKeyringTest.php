<?php
/**
 * The promotion HMAC keyring contract (BLOCK_THEME_PROPOSAL.md Â§7.7 and the
 * Â§11.13 matrix): a missing or empty keyring is a hard failure (exit 1) â€”
 * never a WordPress-salt fallback â€” keys shorter than 32 characters are
 * rejected, a signature whose hmacKeyId is not in the keyring is tamper
 * (exit 4, deliberately distinguishable from the hard error), an old key id
 * keeps verifying while it remains in the keyring, and advancing the
 * signing key id changes the id stamped on newly signed manifests. The two
 * purpose prefixes are likewise proven airtight: a bundle signature is
 * unusable as a manifest signature and vice versa, both rejected as tamper.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionExitCode;
use AgencyPlatform\State\Promotion\PromotionManifest;
use AgencyPlatform\State\Promotion\StateGateway;
use AgencyPlatform\State\StateBundle;
use AgencyPlatform\State\StateException;
use AgencyPlatform\State\StateExporter;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\State\SeedsStateFixtures;

/**
 * @covers \AgencyPlatform\State\HmacSigner
 */
// putenv() drives AGENCY_PROMOTION_HMAC_KEYS and
// AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID through EnvironmentConfig's
// process-environment fallback without WordPress or real .env files loaded;
// WordPress's discouraged-function sniff would otherwise flag every call.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class HmacKeyringTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	/** The key id every fixture in this file signs with. */
	private const KEY_ID = '2026-01';

	private StateGateway $gateway;

	public function set_up(): void {
		parent::set_up();

		$this->gateway = new StateGateway();
	}

	public function tear_down(): void {
		$this->clear_keyring();

		parent::tear_down();
	}

	/**
	 * A missing keyring must be a hard error (exit 1) naming the setting â€”
	 * never a silent degradation to WordPress salts, which would let every
	 * signed artifact verify against a key no exporter ever used.
	 */
	public function test_an_unset_keyring_is_a_hard_error_with_no_salt_fallback(): void {
		$this->clear_keyring();

		$exception = $this->assert_exit_code( PromotionExitCode::HARD_ERROR, fn() => $this->gateway->verify_manifest( $this->signed_manifest_document() ) );

		self::assertStringContainsString( HmacSigner::SETTING_KEYS, $exception->getMessage() );
	}

	public function test_an_empty_keyring_object_is_a_hard_error(): void {
		putenv( HmacSigner::SETTING_KEYS . '={}' );
		putenv( HmacSigner::SETTING_SIGNING_KEY_ID . '=' . self::KEY_ID );

		$exception = $this->assert_exit_code( PromotionExitCode::HARD_ERROR, fn() => $this->gateway->verify_manifest( $this->signed_manifest_document() ) );

		self::assertStringContainsString( 'empty', $exception->getMessage() );
	}

	public function test_a_key_shorter_than_thirty_two_characters_is_rejected(): void {
		putenv( HmacSigner::SETTING_KEYS . '=' . wp_json_encode( array( self::KEY_ID => str_repeat( 'k', 31 ) ) ) );
		putenv( HmacSigner::SETTING_SIGNING_KEY_ID . '=' . self::KEY_ID );

		$exception = $this->assert_exit_code( PromotionExitCode::HARD_ERROR, fn() => $this->gateway->verify_manifest( $this->signed_manifest_document() ) );

		self::assertStringContainsString( 'shorter than ' . HmacSigner::MINIMUM_KEY_LENGTH, $exception->getMessage() );
	}

	/**
	 * A signature whose hmacKeyId is not in the keyring is tamper (exit 4),
	 * NOT a hard error (exit 1): an attacker re-signing with a foreign key
	 * must not look like an operator with a misconfigured keyring.
	 */
	public function test_a_manifest_signed_with_a_key_id_outside_the_keyring_is_tamper(): void {
		$document = $this->signed_manifest_document();

		putenv( HmacSigner::SETTING_KEYS . '=' . wp_json_encode( array( 'other-key' => str_repeat( 'o', 40 ) ) ) );
		putenv( HmacSigner::SETTING_SIGNING_KEY_ID . '=other-key' );

		$exception = $this->assert_exit_code( PromotionExitCode::TAMPER, fn() => $this->gateway->verify_manifest( $document ) );

		self::assertStringContainsString( self::KEY_ID, $exception->getMessage(), 'The refusal must name the unknown key id.' );
	}

	/**
	 * Rotation: a document signed with an OLD key id keeps verifying while
	 * that key remains in the keyring â€” only the signing key advances.
	 */
	public function test_a_manifest_signed_with_an_old_key_still_verifies_during_rotation(): void {
		$old_key = str_repeat( 'o', 40 );
		$new_key = str_repeat( 'n', 40 );

		$document  = $this->manifest_document();
		$signature = ( new HmacSigner(
			array(
				'2025-01'    => $old_key,
				self::KEY_ID => $new_key,
			),
			'2025-01'
		) )->sign( $document, HmacSigner::PURPOSE_MANIFEST );

		$document['hmacKeyId']   = $signature['hmacKeyId'];
		$document['hmacVersion'] = $signature['hmacVersion'];
		$document['hmac']        = $signature['hmac'];

		// The keyring still holds the old key; the signing key has advanced.
		putenv(
			HmacSigner::SETTING_KEYS . '=' . wp_json_encode(
				array(
					'2025-01'    => $old_key,
					self::KEY_ID => $new_key,
				)
			)
		);
		putenv( HmacSigner::SETTING_SIGNING_KEY_ID . '=' . self::KEY_ID );

		self::assertSame( '2025-01', $document['hmacKeyId'] );

		$this->gateway->verify_manifest( $document );
	}

	public function test_advancing_the_signing_key_id_changes_the_hmac_key_id(): void {
		$old_key = str_repeat( 'o', 40 );
		$new_key = str_repeat( 'n', 40 );
		$keyring = array(
			'2025-01'    => $old_key,
			self::KEY_ID => $new_key,
		);

		$first = ( new HmacSigner( $keyring, '2025-01' ) )->sign( $this->manifest_document(), HmacSigner::PURPOSE_MANIFEST );

		self::assertSame( '2025-01', $first['hmacKeyId'] );

		$second = ( new HmacSigner( $keyring, self::KEY_ID ) )->sign( $this->manifest_document(), HmacSigner::PURPOSE_MANIFEST );

		self::assertSame( self::KEY_ID, $second['hmacKeyId'], 'Advancing the signing key id must stamp the new id on newly signed manifests.' );
	}

	/**
	 * Cross-purpose replay, direction one: a document signed for the BUNDLE
	 * purpose must be rejected as tamper when it is presented as a manifest.
	 */
	public function test_verify_manifest_rejects_a_bundle_purpose_signature_as_tamper(): void {
		$this->set_keyring();
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Probe</p><!-- /wp:paragraph -->' );

		$bundle = ( new StateExporter( new HmacSigner( array( self::KEY_ID => str_repeat( 'k', 40 ) ), self::KEY_ID ) ) )->export( array( 'templates' ) );

		$exception = $this->assert_exit_code( PromotionExitCode::TAMPER, fn() => $this->gateway->verify_manifest( $bundle ) );

		self::assertStringContainsString( 'HMAC', $exception->getMessage() );
	}

	/**
	 * Cross-purpose replay, direction two: a manifest-signed document must
	 * be rejected as tamper when it is loaded as a state bundle. The
	 * document is bundle-shaped (a real export, re-signed with the MANIFEST
	 * purpose), so only the purpose prefix can catch the replay — a
	 * manifest-shaped document would be refused by the bundle shape check
	 * before the signature is ever examined.
	 */
	public function test_state_bundle_load_rejects_a_manifest_purpose_signature_as_tamper(): void {
		$this->set_keyring();
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Probe</p><!-- /wp:paragraph -->' );

		$document = ( new StateExporter( new HmacSigner( array( self::KEY_ID => str_repeat( 'k', 40 ) ), self::KEY_ID ) ) )->export( array( 'templates' ) );

		unset( $document['hmac'], $document['hmacKeyId'] );

		$signature = ( new HmacSigner( array( self::KEY_ID => str_repeat( 'k', 40 ) ), self::KEY_ID ) )->sign( $document, HmacSigner::PURPOSE_MANIFEST );

		$document['hmacKeyId']   = $signature['hmacKeyId'];
		$document['hmacVersion'] = $signature['hmacVersion'];
		$document['hmac']        = $signature['hmac'];

		$path = $this->tmp_file( $document );

		try {
			$exception = $this->assert_exit_code( 4, fn() => StateBundle::load( $path ) );

			self::assertStringContainsString( 'HMAC', $exception->getMessage() );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $path );
		}
	}

	/**
	 * The schema-valid manifest document every signature test signs, built
	 * the same way the manifest store builds its own fixtures.
	 *
	 * @return array<string, mixed>
	 */
	private function manifest_document(): array {
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
		)->to_array();
	}

	/**
	 * The manifest document signed with the environment keyring.
	 *
	 * @return array<string, mixed>
	 */
	private function signed_manifest_document(): array {
		$document  = $this->manifest_document();
		$signature = ( new HmacSigner( array( self::KEY_ID => str_repeat( 'k', 40 ) ), self::KEY_ID ) )->sign( $document, HmacSigner::PURPOSE_MANIFEST );

		$document['hmacKeyId']   = $signature['hmacKeyId'];
		$document['hmacVersion'] = $signature['hmacVersion'];
		$document['hmac']        = $signature['hmac'];

		return $document;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function tmp_file( array $document ): string {
		$path = sys_get_temp_dir() . '/hmac-keyring-' . uniqid( '', true ) . '.json';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing an integration fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $path, Normalizer::canonical_json_document( $document ) );

		return $path;
	}

	/**
	 * Asserts that the operation refuses with exactly the expected exit code,
	 * and returns the exception so the caller can also assert the message.
	 * Accepts both the promotion seam's PromotionException and the raw
	 * StateException StateBundle::load() throws when it is called directly.
	 * Fails when no exception is thrown, when an unexpected Throwable
	 * escapes, or when the code differs.
	 *
	 * @param callable():void $operation
	 */
	private function assert_exit_code( int $expected, callable $operation ): \Throwable {
		try {
			$operation();
		} catch ( \Throwable $exception ) {
			$exit_code = $exception instanceof PromotionException || $exception instanceof StateException
				? $exception->exit_code()
				: PromotionExitCode::HARD_ERROR;

			self::assertSame(
				$expected,
				$exit_code,
				sprintf( 'Expected exit code %d, got %d: %s', $expected, $exit_code, $exception->getMessage() )
			);

			return $exception;
		}

		self::fail( sprintf( 'Expected a PromotionException with exit code %d, but no exception was thrown.', $expected ) );
	}

	private function set_keyring(): void {
		putenv( HmacSigner::SETTING_KEYS . '=' . wp_json_encode( array( self::KEY_ID => str_repeat( 'k', 40 ) ) ) );
		putenv( HmacSigner::SETTING_SIGNING_KEY_ID . '=' . self::KEY_ID );
	}

	private function clear_keyring(): void {
		putenv( HmacSigner::SETTING_KEYS );
		putenv( HmacSigner::SETTING_SIGNING_KEY_ID );
	}
}
