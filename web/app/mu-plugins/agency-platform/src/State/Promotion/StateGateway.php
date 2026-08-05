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
 * The promotion lifecycle's single seam onto Task 2's state subsystem
 * (BLOCK_THEME_PROPOSAL.md §6). Every Task 2 call runs under one failure
 * boundary: a StateException is rethrown as a PromotionException carrying
 * StateException::exit_code() VERBATIM — the code comes from the exception,
 * never from inspecting its message — so a missing keyring stays exit 1 and
 * a bad signature stays exit 4, and a message-based mapping regression can
 * never collapse them.
 *
 * The gateway is stateless: load_bundle() reads, schema-validates, verifies
 * and returns a BundleView; live reads and hashing delegate to the registry,
 * the providers and the Normalizer; manifest signing and verification use
 * the environment-resolved HmacSigner instance; and strategy_for() answers
 * from the promotion-strategy registry, which ships empty until Task 7.
 */
final class StateGateway {

	/**
	 * Uses StateBundle::load() — schema, PURPOSE_BUNDLE signature and
	 * stateHash are all verified before any content is exposed.
	 *
	 * @throws PromotionException Exit 1 on a malformed or invalid bundle, exit 4 on tamper.
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
	 * The stable record key "<provider-slug>:<record-slug>". An unknown
	 * provider still yields the documented convention, so key building never
	 * fails; live reads answer null for providers that do not exist.
	 */
	public function record_key( string $provider_slug, string $record_slug ): string {
		try {
			$provider = StateRegistry::provider( $provider_slug );

			if ( null === $provider ) {
				return $provider_slug . ':' . $record_slug;
			}

			return $provider->record_key( $record_slug );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * The live record as a view array — StateRecord::to_array() plus a
	 * derived `provider` key — or null when the provider or the record is
	 * absent.
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
	 * The live record as the value object a PromotionStrategy consumes, or
	 * null when the provider or the record is absent.
	 */
	public function live_state_record( string $provider_slug, string $record_slug ): ?StateRecord {
		try {
			return StateRegistry::provider( $provider_slug )?->record( $this->record_key( $provider_slug, $record_slug ) );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * Every live record of a provider as view arrays; an empty list when the
	 * provider is absent.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function live_records( string $provider_slug ): array {
		try {
			$records = array();

			foreach ( StateRegistry::provider( $provider_slug )?->records() ?? array() as $record ) {
				$records[] = $record->to_array() + array( 'provider' => $provider_slug );
			}

			return $records;
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * The resolved-template form: the injected `theme` attribute is stripped
	 * first, then the WordPress block parser/serialiser canonicalises the
	 * markup exactly as Task 2's providers do, so a promoted file and its
	 * resolved form hash the same (plan decision 8).
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
	 * Signs a manifest payload with the environment-resolved signer for the
	 * manifest purpose — a bundle signature can never validate as a manifest
	 * and vice versa.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{hmacKeyId: string, hmac: string}
	 */
	public function sign_manifest( array $payload ): array {
		try {
			return $this->signer()->sign( $payload, HmacSigner::PURPOSE_MANIFEST );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
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
	 * The strategy serving a provider slug, or null when none is registered.
	 * The registry ships EMPTY from this task; Task 7 registers the
	 * strategies, so this is also the Release 3/Release 4 boundary check.
	 */
	public function strategy_for( string $provider_slug ): ?PromotionStrategy {
		try {
			return PromotionStrategies::for_provider( $provider_slug );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * The protected state-artifact directory, created on demand.
	 */
	public function state_dir(): string {
		try {
			return StateDirectory::ensure();
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * Exactly one JSON document plus one trailing LF — the only bytes a
	 * bundle or manifest file may be written as.
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
