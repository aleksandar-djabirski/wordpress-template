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
 *
 * The canonicalisation used to build the signed bytes is LOSSLESS
 * (canonicalize_lossless()), not the lossy, diff-friendly Normalizer::
 * canonical_json() that the rest of the state subsystem uses for drift
 * detection. Normalizer::canonical_json() collapses trailing whitespace,
 * CR/CRLF, and integral floats on purpose, so two distinct documents can
 * canonicalise to identical bytes and therefore share a signature — the
 * signature would authenticate the normalised form, not the document. A
 * signature must authenticate exactly what was signed.
 *
 * Every new signature carries hmacVersion = CURRENT_VERSION, included in
 * the signed bytes themselves (so it cannot be stripped or downgraded
 * without invalidating the signature). verify() dispatches on the
 * document's own hmacVersion: CURRENT_VERSION verifies losslessly; an
 * absent field, or an explicit LEGACY_VERSION, verifies through the old
 * lossy canonicaliser — a bundle or manifest already signed and sitting in
 * its retention window keeps verifying, with no migration and no flag day.
 */
final class HmacSigner {

	public const PURPOSE_BUNDLE   = 'state-bundle-v1:';
	public const PURPOSE_MANIFEST = 'promotion-manifest-v1:';

	public const SETTING_KEYS           = 'AGENCY_PROMOTION_HMAC_KEYS';
	public const SETTING_SIGNING_KEY_ID = 'AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID';

	public const MINIMUM_KEY_LENGTH = 32;

	public const LEGACY_VERSION  = 1;
	public const CURRENT_VERSION = 2;

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
	 * payload (plus the version tag below) with hmac and hmacKeyId
	 * stripped, so a signature never covers itself. Every new signature is
	 * CURRENT_VERSION, built over the LOSSLESS canonical form, and the
	 * version tag itself is part of the signed bytes — it cannot be
	 * stripped or downgraded to the legacy check without invalidating the
	 * signature.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{hmacKeyId: string, hmacVersion: int, hmac: string}
	 */
	public function sign( array $payload, string $purpose ): array {
		$keyring = $this->keyring();
		$key_id  = $this->signing_key_id();

		if ( ! isset( $keyring[ $key_id ] ) ) {
			throw StateException::hard_error( 'The signing key id "' . $key_id . '" is not present in ' . self::SETTING_KEYS . '.' );
		}

		$versioned_payload                = $payload;
		$versioned_payload['hmacVersion'] = self::CURRENT_VERSION;

		return array(
			'hmacKeyId'   => $key_id,
			'hmacVersion' => self::CURRENT_VERSION,
			'hmac'        => hash_hmac( 'sha256', $purpose . $this->canonicalize_lossless( $versioned_payload ), $keyring[ $key_id ] ),
		);
	}

	/**
	 * Verifies a signed document for one purpose. The key is looked up by
	 * the document's own hmacKeyId, so any key id present in the keyring is
	 * accepted — rotation only ever changes which id signs new documents.
	 *
	 * Dispatches on the document's own hmacVersion: CURRENT_VERSION is
	 * re-hashed through the lossless canonicaliser, an absent field or an
	 * explicit LEGACY_VERSION through the old lossy one. The field is never
	 * trusted blindly — it is hashed as part of the document either way, so
	 * a document signed as CURRENT_VERSION cannot be re-labelled
	 * LEGACY_VERSION (or have the field dropped) to fall back onto the
	 * weaker check: that would change the signed bytes and the signature
	 * would no longer match.
	 *
	 * @param array<string, mixed> $document A signed document including hmac + hmacKeyId.
	 * @throws StateException Exit 4 on a bad/absent/unsupported-version signature, exit 1 on keyring misconfiguration.
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

		$version = $document['hmacVersion'] ?? self::LEGACY_VERSION;

		if ( ! is_int( $version ) || ( self::LEGACY_VERSION !== $version && self::CURRENT_VERSION !== $version ) ) {
			throw StateException::tamper( 'The document carries an unsupported HMAC version.' );
		}

		$canonical = self::CURRENT_VERSION === $version
			? $this->canonicalize_lossless( $document )
			: $this->canonicalize( $document );

		$expected = hash_hmac( 'sha256', $purpose . $canonical, $keyring[ $key_id ] );

		if ( ! hash_equals( $signature, $expected ) ) {
			throw StateException::tamper( 'The HMAC does not match: the document was modified, or it was not signed for this purpose.' );
		}
	}

	/**
	 * Legacy (lossy) canonical JSON of the payload with the signature
	 * fields stripped: Normalizer::canonical_json() collapses trailing
	 * whitespace, CR/CRLF, and integral floats, which is what the
	 * malleability finding exploits. Kept only for LEGACY_VERSION
	 * verification of documents signed before this canonicaliser existed;
	 * sign() never uses it.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function canonicalize( array $payload ): string {
		return Normalizer::canonical_json( $this->strip_signature( $payload ) );
	}

	/**
	 * Lossless canonical JSON of the payload with the signature fields
	 * stripped: keys are sorted for build-order independence (matching the
	 * legacy canonicaliser), but every scalar is encoded exactly as given —
	 * no trailing-whitespace or line-ending collapsing, no integral-float
	 * coercion — so two payloads that differ can never canonicalise to the
	 * same bytes. serialize_precision is pinned to -1 for the same reason
	 * Normalizer::canonical_json() pins it: deterministic float encoding,
	 * restored in the finally so the process-wide setting is never left
	 * changed.
	 *
	 * @param array<string, mixed> $payload
	 */
	private function canonicalize_lossless( array $payload ): string {
		$previous = ini_get( 'serialize_precision' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- pinning serialize_precision to -1 is what makes float encoding deterministic; the previous value is restored in the finally below, so the process-wide setting is never left changed.
		ini_set( 'serialize_precision', '-1' );

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- canonicalisation must not depend on WordPress being loaded; the unit suite covers this method with no WordPress present.
			$encoded = json_encode(
				Normalizer::sort_recursive( $this->strip_signature( $payload ) ),
				JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
			);
		} catch ( \JsonException $error ) {
			throw StateException::hard_error( 'The payload could not be canonicalised as JSON: ' . $error->getMessage(), $error );
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- the finally guarantees the process-wide serialize_precision is never left changed by the deterministic-float pin above.
			ini_set( 'serialize_precision', false === $previous ? '-1' : $previous );
		}

		return $encoded;
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
