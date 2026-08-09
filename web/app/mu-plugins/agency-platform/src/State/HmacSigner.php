<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * Keyring-backed HMAC signing for state bundles and promotion manifests
 * (BLOCK_THEME_PROPOSAL.md §7.7). A keyring rather than a single key lets
 * production accept an older key id during a controlled rotation; the
 * distinct purpose prefixes make a bundle signature unusable as a manifest
 * signature and vice versa. The keyring and signing key id resolve lazily
 * from the environment, so a misconfigured environment fails at sign or
 * verify time with a message naming the missing setting — never silently
 * degrades to WordPress salts.
 */
final class HmacSigner {

	public const PURPOSE_BUNDLE   = 'state-bundle-v1:';
	public const PURPOSE_MANIFEST = 'promotion-manifest-v1:';

	public const SETTING_KEYS           = 'AGENCY_PROMOTION_HMAC_KEYS';
	public const SETTING_SIGNING_KEY_ID = 'AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID';

	public const MINIMUM_KEY_LENGTH = 32;

	/**
	 * @var array<string, string>|null
	 */
	private ?array $keyring;

	private ?string $signing_key_id;

	/**
	 * @param array<string, string>|null $keyring        null reads AGENCY_PROMOTION_HMAC_KEYS.
	 * @param string|null                $signing_key_id null reads AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID.
	 */
	public function __construct( ?array $keyring = null, ?string $signing_key_id = null ) {
		$this->keyring        = $keyring;
		$this->signing_key_id = $signing_key_id;
	}

	public static function from_environment(): self {
		return new self( null, null );
	}

	/**
	 * Signs a payload for one purpose, returning the signature fields a
	 * bundle or manifest carries alongside it. The signature covers the
	 * payload with hmac and hmacKeyId stripped, so a signature never covers
	 * itself.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{hmacKeyId: string, hmac: string}
	 */
	public function sign( array $payload, string $purpose ): array {
		$keyring = $this->keyring();
		$key_id  = $this->signing_key_id();

		if ( ! isset( $keyring[ $key_id ] ) ) {
			throw StateException::hard_error( 'The signing key id "' . $key_id . '" is not present in ' . self::SETTING_KEYS . '.' );
		}

		return array(
			'hmacKeyId' => $key_id,
			'hmac'      => hash_hmac( 'sha256', $purpose . $this->canonicalize( $payload ), $keyring[ $key_id ] ),
		);
	}

	/**
	 * Verifies a signed document for one purpose. The key is looked up by
	 * the document's own hmacKeyId, so any key id present in the keyring is
	 * accepted — rotation only ever changes which id signs new documents.
	 *
	 * @param array<string, mixed> $document A signed document including hmac + hmacKeyId.
	 * @throws StateException Exit 4 on a bad/absent signature, exit 1 on keyring misconfiguration.
	 */
	public function verify( array $document, string $purpose ): void {
		$signature = $document['hmac'] ?? null;
		$key_id    = $document['hmacKeyId'] ?? null;

		if ( ! is_string( $signature ) || '' === $signature || ! is_string( $key_id ) || '' === $key_id ) {
			throw StateException::tamper( 'The document carries no HMAC signature.' );
		}

		$keyring = $this->keyring();

		if ( ! isset( $keyring[ $key_id ] ) ) {
			throw StateException::tamper( 'The document carries the unknown HMAC key id "' . $key_id . '".' );
		}

		$expected = hash_hmac( 'sha256', $purpose . $this->canonicalize( $document ), $keyring[ $key_id ] );

		if ( ! hash_equals( $signature, $expected ) ) {
			throw StateException::tamper( 'The HMAC does not match: the document was modified, or it was not signed for this purpose.' );
		}
	}

	/**
	 * Canonical JSON of the payload with the signature fields stripped, so
	 * the signed string is identical whether it came from a payload or from
	 * a full signed document.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function canonicalize( array $payload ): string {
		return Normalizer::canonical_json( $this->strip_signature( $payload ) );
	}

	/**
	 * Resolves the keyring lazily: an explicitly passed keyring is
	 * validated as-is; a null keyring reads AGENCY_PROMOTION_HMAC_KEYS at
	 * first use, so a missing setting is a hard error at sign/verify time
	 * with a message, never at construction.
	 *
	 * @return array<string, string>
	 */
	private function keyring(): array {
		if ( null !== $this->keyring ) {
			return $this->validate_keyring( $this->keyring );
		}

		$raw = EnvironmentConfig::get( self::SETTING_KEYS );

		if ( null === $raw ) {
			throw StateException::hard_error( self::SETTING_KEYS . ' is not set: no HMAC key is available.' );
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			throw StateException::hard_error( self::SETTING_KEYS . ' is not a JSON object.' );
		}

		return $this->validate_keyring( $decoded );
	}

	/**
	 * Resolves the signing key id lazily, reading
	 * AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID at first use.
	 */
	private function signing_key_id(): string {
		if ( null !== $this->signing_key_id ) {
			return $this->signing_key_id;
		}

		$from_environment = EnvironmentConfig::get( self::SETTING_SIGNING_KEY_ID );

		if ( null === $from_environment ) {
			throw StateException::hard_error( self::SETTING_SIGNING_KEY_ID . ' is not set.' );
		}

		return $from_environment;
	}

	/**
	 * Requires a non-empty keyring of string keys with non-empty values of
	 * at least MINIMUM_KEY_LENGTH characters, and returns a clean
	 * string-to-string copy. Any violation is a hard failure naming the
	 * setting and the offending key id.
	 *
	 * @param array<mixed> $keyring
	 * @return array<string, string>
	 */
	private function validate_keyring( array $keyring ): array {
		if ( array() === $keyring ) {
			throw StateException::hard_error( self::SETTING_KEYS . ' is empty: no HMAC key is available.' );
		}

		$validated = array();

		foreach ( $keyring as $key_id => $key ) {
			if ( ! is_string( $key_id ) || '' === $key_id ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the id representation must not depend on WordPress being loaded; the unit suite covers this method with no WordPress present.
				throw StateException::hard_error( self::SETTING_KEYS . ' contains a key id that is not a non-empty string: ' . json_encode( $key_id ) . '.' );
			}

			if ( ! is_string( $key ) || '' === $key || self::MINIMUM_KEY_LENGTH > strlen( $key ) ) {
				throw StateException::hard_error( 'The key "' . $key_id . '" in ' . self::SETTING_KEYS . ' is missing, empty, or shorter than ' . self::MINIMUM_KEY_LENGTH . ' characters.' );
			}

			$validated[ $key_id ] = $key;
		}

		return $validated;
	}

	/**
	 * Removes the signature fields so they are never part of the signed
	 * string.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function strip_signature( array $payload ): array {
		unset( $payload['hmac'], $payload['hmacKeyId'] );

		return $payload;
	}
}
