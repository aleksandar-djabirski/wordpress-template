<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\PromotionStrategies;
use AgencyPlatform\State\PromotionStrategy;
use AgencyPlatform\State\ReferenceScanner;
use AgencyPlatform\State\SchemaValidator;
use AgencyPlatform\State\StateBundle;
use AgencyPlatform\State\StateDirectory;
use AgencyPlatform\State\StateException;
use AgencyPlatform\State\StateRecord;
use AgencyPlatform\State\StateRegistry;

/**
 * The seam onto Task 2 that the promotion lifecycle is allowed to use
 * (plan Task 3): bundle loading, live-record reads, hashing, reference
 * scanning, manifest signing/verification, strategy lookup, and the state
 * directory. Every Task 2 call is wrapped identically — a StateException is
 * rethrown as a PromotionException carrying the SAME exit code via
 * PromotionException::from_state_exception(), and no code here ever derives
 * an exit code from an exception message.
 */
final class StateGateway {

	/**
	 * Uses StateBundle::load() — schema, PURPOSE_BUNDLE signature and
	 * stateHash are all verified — and hands the verified bundle to a view.
	 */
	public function load_bundle( string $path_or_dash ): BundleView {
		try {
			return new BundleView( StateBundle::load( $path_or_dash, $this->signer(), $this->validator() ) );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	public function provider_exists( string $provider_slug ): bool {
		try {
			return null !== StateRegistry::provider( $provider_slug );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * The stable record key format: "<provider-slug>:<record-slug>", the
	 * same format the operator-facing --select syntax uses.
	 */
	public function record_key( string $provider_slug, string $record_slug ): string {
		return $provider_slug . ':' . $record_slug;
	}

	/**
	 * The live database record of one provider as StateRecord::to_array()
	 * plus a derived `provider` key; null when the provider or the record
	 * is absent.
	 *
	 * @return array<string, mixed>|null
	 */
	public function read_live_record( string $provider_slug, string $record_slug ): ?array {
		try {
			$record = StateRegistry::provider( $provider_slug )?->record( $this->record_key( $provider_slug, $record_slug ) );

			if ( null === $record ) {
				return null;
			}

			return $record->to_array() + array( 'provider' => $provider_slug );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * The live record as a value object, for handing to a strategy; null
	 * when the provider or the record is absent.
	 */
	public function live_state_record( string $provider_slug, string $record_slug ): ?StateRecord {
		try {
			return StateRegistry::provider( $provider_slug )?->record( $this->record_key( $provider_slug, $record_slug ) );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * Every live record of a provider as StateRecord::to_array() plus the
	 * derived `provider` key; an empty list when the provider is absent.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function live_records( string $provider_slug ): array {
		try {
			$provider = StateRegistry::provider( $provider_slug );

			if ( null === $provider ) {
				return array();
			}

			$rows = array();

			foreach ( $provider->records() as $record ) {
				$rows[] = $record->to_array() + array( 'provider' => $provider_slug );
			}

			return $rows;
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * The resolved-template policy first — strip the injected theme
	 * attribute — then Task 2's own parse/serialise normalisation, so a
	 * promoted file and its resolved form hash the same.
	 */
	public function normalize_block_markup( string $markup ): string {
		try {
			return Normalizer::normalize_block_markup( ResolvedTemplateNormalizer::strip_theme_attribute( $markup ) );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * Task 2's content-map convention: Normalizer::hash( array( 'markup' =>
	 * $markup ) ), identical to StateRecord::content_hash() for a template
	 * record, so a manifest hash and a bundle contentHash are directly
	 * comparable.
	 */
	public function hash_markup( string $normalized_markup ): string {
		try {
			return Normalizer::hash( array( 'markup' => $normalized_markup ) );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * @param array<string, mixed> $content
	 */
	public function hash_content( array $content ): string {
		try {
			return Normalizer::hash( $content );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	public function hash_string( string $value ): string {
		try {
			return Normalizer::hash_string( $value );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function scan_references( string $markup, string $record_key ): array {
		try {
			return ReferenceScanner::scan( $markup, $record_key );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * @param array<string, mixed> $reference
	 */
	public function reference_is_unresolved( array $reference ): bool {
		try {
			return ReferenceScanner::is_unresolved( $reference );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * Signs a manifest payload with the environment keyring for the
	 * promotion-manifest purpose.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{hmacKeyId: string, hmacVersion: int, hmac: string}
	 */
	public function sign_manifest( array $payload ): array {
		try {
			return $this->signer()->sign( $payload, HmacSigner::PURPOSE_MANIFEST );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * Verifies a manifest document with the environment keyring; a bad or
	 * absent signature is tamper (exit 4), a missing keyring is a hard
	 * error (exit 1) — both mapped verbatim.
	 *
	 * @param array<string, mixed> $document
	 */
	public function verify_manifest( array $document ): void {
		try {
			$this->signer()->verify( $document, HmacSigner::PURPOSE_MANIFEST );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * @param array<string, mixed> $document
	 */
	public function validate_manifest_schema( array $document ): void {
		try {
			$this->validator()->validate( $document, SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * The strategy serving a provider slug, or null when none is
	 * registered — the registry ships empty and Task 7 registers the
	 * strategies.
	 */
	public function strategy_for( string $provider_slug ): ?PromotionStrategy {
		try {
			return PromotionStrategies::for_provider( $provider_slug );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	public function state_dir(): string {
		try {
			return StateDirectory::ensure();
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * Exactly one JSON document plus one trailing LF.
	 *
	 * @param array<string, mixed> $document
	 */
	public function canonical_json_document( array $document ): string {
		try {
			return Normalizer::canonical_json_document( $document );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	private function signer(): HmacSigner {
		return HmacSigner::from_environment();
	}

	private function validator(): SchemaValidator {
		return new SchemaValidator();
	}
}
