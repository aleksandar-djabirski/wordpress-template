<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * Assembles the signed, schema-validated state bundle
 * (BLOCK_THEME_PROPOSAL.md §7.2 and §7.3): provider records are read from
 * the registry, validated per record, and wrapped with the site metadata
 * the proposal fixes, then the stateHash is computed over the canonical
 * provider records ONLY — every wrapper field and every provider metadata
 * field (slug, ownership, promotion, hasGitBaseline) is deliberately
 * outside the hash — and the whole document is signed and schema-checked.
 *
 * The exporter never writes streams: a file or STDOUT write is the command
 * layer's job, and whatever writes a document must use
 * Normalizer::canonical_json_document() and nothing else (plan decision 23),
 * so the bytes a consumer hashes are the bytes the signer signed. Validation
 * problems are warnings, never failures: the command layer appends them to
 * STDERR after the sensitivity warning, so the signed bundle and the JSON
 * STDOUT stay machine-clean.
 */
final class StateExporter {

	private ?HmacSigner $signer;
	private ?SchemaValidator $validator;
	private ?GitBaseline $git;

	/** @var list<string> Provider validation warnings from the most recent export, sorted by record key then message. */
	private array $validation_warnings = array();

	public function __construct( ?HmacSigner $signer = null, ?SchemaValidator $validator = null, ?GitBaseline $git = null ) {
		$this->signer    = $signer;
		$this->validator = $validator;
		$this->git       = $git;
	}

	/**
	 * The signed, schema-validated bundle document. Every requested slug
	 * must resolve through the registry; a slug with no provider is a hard
	 * error. Provider nodes are sorted by slug, records are asserted and
	 * defensively re-sorted by key ascending (strcmp) so the stateHash can
	 * never vary with provider implementation order, and the result is
	 * returned through Normalizer::normalize_content() so the caller's copy
	 * has the same deterministic key order the signer saw.
	 *
	 * @param list<string> $provider_slugs
	 * @return array<string, mixed> The signed, schema-validated bundle document.
	 * @throws StateException Exit 1 on any provider, signing, or validation failure.
	 */
	public function export( array $provider_slugs ): array {
		$this->validation_warnings = array();

		$providers = array();

		foreach ( $provider_slugs as $slug ) {
			$provider = StateRegistry::provider( $slug );

			if ( null === $provider ) {
				throw StateException::hard_error( 'Unknown provider slug "' . $slug . '"; valid slugs are: ' . implode( ', ', StateRegistry::slugs() ) . '.' );
			}

			$records = $provider->records();

			$this->assert_sorted_by_key( $records, $provider->slug() );
			usort( $records, array( self::class, 'compare_record_keys' ) );

			$record_arrays = array();

			foreach ( $records as $record ) {
				foreach ( $provider->validate( $record ) as $problem ) {
					$this->validation_warnings[] = $record->key() . ': ' . $problem;
				}

				$record_arrays[] = $record->to_array();
			}

			$providers[ $slug ] = array(
				'slug'           => $provider->slug(),
				'ownership'      => $provider->ownership(),
				'promotion'      => $provider->promotion(),
				'hasGitBaseline' => $provider->has_git_baseline(),
				'records'        => $record_arrays,
			);
		}

		ksort( $providers );

		sort( $this->validation_warnings, SORT_STRING );

		$document = array(
			'schemaVersion'    => 1,
			'exportId'         => wp_generate_uuid4(),
			'exportedAtUtc'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'siteUuid'         => SiteIdentity::uuid(),
			'siteUrl'          => home_url(),
			'environment'      => wp_get_environment_type(),
			'wordpressVersion' => get_bloginfo( 'version' ),
			'activeTheme'      => array(
				'stylesheet' => get_stylesheet(),
				'version'    => (string) wp_get_theme()->get( 'Version' ),
				'gitCommit'  => $this->git()->head_commit(),
			),
			'providers'        => $providers,
		);

		$document['stateHash'] = Normalizer::hash( self::canonical_provider_records( $providers ) );

		$document += $this->signer()->sign( $document, HmacSigner::PURPOSE_BUNDLE );

		$this->validator()->validate( $document, SchemaValidator::SCHEMA_STATE_BUNDLE );

		return Normalizer::normalize_content( $document );
	}

	/**
	 * Provider validation warnings from the most recent export, sorted by
	 * record key then message. The command layer appends these lines to
	 * STDERR after sensitivity_warning() and before returning the JSON-only
	 * STDOUT.
	 *
	 * @return list<string>
	 */
	public function validation_warnings(): array {
		return $this->validation_warnings;
	}

	/**
	 * Pure stateHash input: only the sorted provider-slug map and each
	 * provider's ascending StateRecord::to_array() list survive. Every
	 * provider metadata field (slug, ownership, promotion, hasGitBaseline)
	 * and every bundle wrapper field is deliberately dropped, so none of
	 * them can ever move the hash.
	 *
	 * @param array<string, array<string, mixed>> $providers
	 * @return array<string, list<array<string, mixed>>>
	 */
	public static function canonical_provider_records( array $providers ): array {
		$canonical = array();

		foreach ( $providers as $slug => $provider ) {
			$records = array();

			if ( isset( $provider['records'] ) && is_array( $provider['records'] ) ) {
				foreach ( $provider['records'] as $record ) {
					if ( is_array( $record ) ) {
						$records[] = $record;
					}
				}
			}

			$canonical[ $slug ] = $records;
		}

		ksort( $canonical );

		return $canonical;
	}

	/**
	 * The §7.3 warning the command layer prints to STDERR before every
	 * export. It lives here, verbatim, so the exporter and the command
	 * surface can never drift apart. STDOUT stays machine-readable only.
	 */
	public static function sensitivity_warning(): string {
		return 'State bundles can contain customer content and internal site structure. Keep them out of Git (AGENCY_STATE_DIR is git-ignored), treat them as confidential, and delete them when the promotion they support has been confirmed. See BLOCK_THEME_PROPOSAL.md section 7.3.';
	}

	/**
	 * Pure record-key comparison for the defensive re-sort; named method so
	 * usort() never carries a closure.
	 */
	public static function compare_record_keys( StateRecord $a, StateRecord $b ): int {
		return strcmp( $a->key(), $b->key() );
	}

	/**
	 * The deterministic-hash contract of StateProvider::records(): every
	 * provider must return records sorted by key ascending (strcmp). A
	 * provider that breaks the contract would make the stateHash vary with
	 * implementation order, so it is a hard error, never a silent drift.
	 *
	 * @param list<StateRecord> $records
	 */
	private function assert_sorted_by_key( array $records, string $slug ): void {
		$total = count( $records );

		for ( $index = 1; $index < $total; $index++ ) {
			if ( strcmp( $records[ $index - 1 ]->key(), $records[ $index ]->key() ) > 0 ) {
				throw StateException::hard_error( 'Provider "' . $slug . '" returned records out of key order; records must be sorted by key ascending so the stateHash is deterministic.' );
			}
		}
	}

	private function signer(): HmacSigner {
		return $this->signer ?? HmacSigner::from_environment();
	}

	private function validator(): SchemaValidator {
		return $this->validator ?? new SchemaValidator();
	}

	private function git(): GitBaseline {
		return $this->git ?? new GitBaseline();
	}
}
