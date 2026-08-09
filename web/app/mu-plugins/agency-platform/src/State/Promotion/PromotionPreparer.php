<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\Logging\Logger;
use AgencyPlatform\State\EnvironmentConfig;

/**
 * The prepare stage of the promotion lifecycle (BLOCK_THEME_PROPOSAL.md
 * §7.5): run-level refusals first — production environment, missing/bad
 * target uuid, detached or unexpected branch, a sealed or finalized
 * manifest, a manifest from a different export, and any working-tree
 * dirtiness that is not a recorded prepared file still matching one of its
 * recorded hashes — then the per-record staging loop, then the durable
 * commit sequence. Nothing is written before every guard has passed, and
 * the manifest is written BEFORE the two-phase file commit, so a crash in
 * the middle always leaves a state a re-run can recover from: the re-run's
 * dirty check accepts a prepared file matching either hash, and the
 * idempotent skip re-stages anything that does not match.
 *
 * The refusal taxonomy has exactly two levels and they never mix. Run-level
 * refusals abort the whole run with exit 1 (4 for a tampered bundle, 3 for
 * a lock conflict) and write NOTHING. Per-record refusals — unparseable
 * markup, an unregistered block, a referenced part that neither exists nor
 * is selected, an unresolved navigation or reference — refuse THAT record
 * only; the run continues, the refusal lands in the manifest report, and
 * the final exit code comes from PromotionOutcome, never from a decision
 * made here.
 */
final class PromotionPreparer {

	/**
	 * Test-only environment override; null defers to wp_get_environment_type().
	 *
	 * The integration suite pins WP_ENVIRONMENT_TYPE to 'development' in
	 * tests/wp-tests-config.php AND WordPress caches the resolved type in a
	 * function static, so the production branch of the environment guard
	 * cannot be reached through the real function in that suite. This static
	 * exists so tests can drive the guard to 'production'; production code
	 * never sets it.
	 *
	 * @var string|null
	 */
	private static ?string $environment_override = null;

	public function __construct(
		private StateGateway $gateway,
		private ManifestStore $store,
		private GitRepository $git,
		private PrepareLock $lock
	) {}

	/**
	 * The environment the production guard must see, or null to restore the
	 * WordPress default. Test-only; never called by production code paths.
	 */
	public static function override_environment( ?string $environment ): void {
		self::$environment_override = $environment;
	}

	/**
	 * @param array{source:string, select:string, manifest:string} $arguments
	 * @return array{manifest: PromotionManifest, outcome: PromotionOutcome}
	 */
	public function prepare( array $arguments ): array {
		if ( 'production' === $this->environment_type() ) {
			throw PromotionException::hard( '--prepare is a local operation and must never run in production.' );
		}

		$this->lock->acquire();

		try {
			$bundle = $this->gateway->load_bundle( $arguments['source'] );

			$target_uuid = EnvironmentConfig::get( 'AGENCY_TARGET_SITE_UUID' );

			if ( null === $target_uuid || '' === $target_uuid ) {
				throw PromotionException::hard( 'AGENCY_TARGET_SITE_UUID is not set; --prepare cannot target a promotion without it.' );
			}

			if ( $target_uuid !== $bundle->site_uuid() ) {
				throw PromotionException::hard(
					sprintf( 'The bundle was exported from site %s, but the configured target site is %s.', $bundle->site_uuid(), $target_uuid )
				);
			}

			$branch = $this->git->current_branch();

			if ( null === $branch && ! PromotionSettings::flag( 'AGENCY_ALLOW_DETACHED_HEAD' ) ) {
				throw PromotionException::hard( 'HEAD is detached; --prepare refuses to run unless AGENCY_ALLOW_DETACHED_HEAD is set.' );
			}

			$expected_branch = EnvironmentConfig::get( 'AGENCY_EXPECTED_BRANCH' );

			if ( null !== $expected_branch && '' !== $expected_branch && $expected_branch !== $branch ) {
				throw PromotionException::hard(
					sprintf( 'Expected branch "%s" but HEAD is on "%s".', $expected_branch, $branch ?? '(detached)' )
				);
			}

			$existing = null;

			if ( '-' !== $arguments['manifest'] && is_file( $arguments['manifest'] ) ) {
				$existing = $this->store->load( $arguments['manifest'] );
			}

			if ( null !== $existing ) {
				if ( $existing->is_sealed() ) {
					throw PromotionException::hard(
						sprintf( 'Manifest %s is already sealed to %s. Start a new promotion instead of re-preparing a sealed one.', $arguments['manifest'], $existing->deploy_commit() )
					);
				}

				if ( 'pending' !== $existing->finalize_status() ) {
					throw PromotionException::hard( sprintf( 'Manifest %s has already been finalized.', $arguments['manifest'] ) );
				}

				if ( $existing->export_id() !== $bundle->export_id() ) {
					throw PromotionException::hard( 'The existing manifest was prepared from a different export.' );
				}
			}

			$this->assert_dirty_tree_matches_manifest( $existing );

			$selection = PromotionSelector::parse(
				$arguments['select'],
				fn( string $provider_slug ): bool => $this->has_preparable_strategy( $provider_slug ),
				fn( string $record_key ): bool => null !== $bundle->record( $record_key )
			);

			$selected_keys = array();

			foreach ( $selection as $entry ) {
				$selected_keys[] = $entry['key'];
			}

			$declared = ThemeDeclaredSlugs::from_file( get_stylesheet_directory() . '/theme.json' );

			foreach ( $selection as $entry ) {
				$strategy = $this->preparable_strategy( $entry['provider'] );

				if ( null === $strategy || ! $strategy->declares( $declared, $entry['slug'] ) ) {
					throw PromotionException::hard(
						sprintf( 'Slug "%s" is not declared in theme.json; v1 only promotes files already declared in Git.', $entry['slug'] )
					);
				}
			}

			$promotion_id = null !== $existing ? $existing->promotion_id() : wp_generate_uuid4();

			$manifest = PromotionManifest::create(
				$promotion_id,
				gmdate( 'Y-m-d\TH:i:s\Z' ),
				$bundle->header(),
				$target_uuid,
				$this->git->head_commit(),
				PromotionSettings::verification_commands()
			);

			$staged    = array();
			$committed = array();
			$outcomes  = array();
			$refusals  = array();

			try {
				foreach ( $selection as $entry ) {
					$manifest = $this->prepare_record( $entry, $bundle, $existing, $manifest, $selected_keys, $staged, $outcomes, $refusals );
				}

				$manifest = $manifest->with_refusals( $refusals );

				if ( '-' !== $arguments['manifest'] ) {
					$this->store->write( $manifest, $arguments['manifest'] );
				}

				foreach ( $staged as $entry ) {
					$entry->commit();

					$committed[] = $entry;
				}
			} catch ( \Throwable $failure ) {
				foreach ( $committed as $entry ) {
					$entry->rollback_committed();
				}

				foreach ( $staged as $entry ) {
					$entry->discard();
				}

				if ( $failure instanceof PromotionException ) {
					throw $failure;
				}

				throw PromotionException::hard(
					sprintf( 'The prepared files could not be committed: %s', $failure->getMessage() ),
					$failure
				);
			}

			Logger::log(
				'promotion',
				'prepared',
				array(
					'promotionId' => $manifest->promotion_id(),
					'records'     => count( $outcomes ),
					'refusals'    => count( $refusals ),
				)
			);

			return array(
				'manifest' => $manifest,
				'outcome'  => new PromotionOutcome( $outcomes, $refusals ),
			);
		} finally {
			$this->lock->release();
		}
	}

	/**
	 * The environment the production guard refuses in: the test-only
	 * override when set, else WordPress's own environment type.
	 */
	private function environment_type(): string {
		return self::$environment_override ?? wp_get_environment_type();
	}

	/**
	 * The run-level dirty-tree refusal with hash verification: a dirty path
	 * is allowed only when the existing manifest records it as a prepared
	 * file whose CURRENT bytes still match either the recorded prepared hash
	 * or the recorded original hash. Anything else refuses the run — this
	 * is what stops a hand-edited prepared file from being silently
	 * overwritten.
	 */
	private function assert_dirty_tree_matches_manifest( ?PromotionManifest $existing ): void {
		$allowed = array();

		if ( null !== $existing ) {
			foreach ( $existing->records() as $record ) {
				$prepared_path = $record['preparedFilePath'] ?? null;

				if ( ! is_string( $prepared_path ) || '' === $prepared_path ) {
					continue;
				}

				$allowed[ $prepared_path ] = array(
					is_string( $record['preparedFileHash'] ?? null ) ? $record['preparedFileHash'] : '',
					is_string( $record['originalFileHash'] ?? null ) ? $record['originalFileHash'] : null,
				);
			}
		}

		$offending       = array();
		$outside_entries = array();

		foreach ( $this->git->dirty_paths() as $path ) {
			if ( ! isset( $allowed[ $path ] ) ) {
				$offending[] = $path;

				continue;
			}

			$current          = $this->file_sha256( $this->git->absolute_path( $path ) );
			$matches_prepared = $current === $allowed[ $path ][0];
			$matches_original = null !== $allowed[ $path ][1] && $current === $allowed[ $path ][1];

			if ( ! $matches_prepared && ! $matches_original ) {
				$outside_entries[] = $path;
			}
		}

		if ( array() !== $offending ) {
			throw PromotionException::hard(
				sprintf( 'The working tree is dirty: %s. --prepare requires a clean tree.', implode( ', ', $offending ) )
			);
		}

		if ( array() !== $outside_entries ) {
			throw PromotionException::hard(
				sprintf(
					'Prepared file %s was modified outside the promotion. Restore it or start a new promotion.',
					implode( ', ', $outside_entries )
				)
			);
		}
	}

	private function has_preparable_strategy( string $provider_slug ): bool {
		return null !== $this->preparable_strategy( $provider_slug );
	}

	private function preparable_strategy( string $provider_slug ): ?PreparablePromotionStrategy {
		$strategy = $this->gateway->strategy_for( $provider_slug );

		return $strategy instanceof PreparablePromotionStrategy ? $strategy : null;
	}

	/**
	 * One selection entry through the per-record pipeline: the idempotent
	 * skip, the strategy's three markup refusals, the §7.4 navigation and
	 * reference refusals, the strategy-owned staging, and the manifest
	 * record build. The manifest is immutable, so the possibly-new manifest
	 * is returned for the caller to keep.
	 *
	 * @param array{provider:string, slug:string, key:string} $entry
	 * @param list<string>                                    $selected_keys
	 * @param list<StagedPromotionEntry>                      $staged
	 * @param array<string, string>                           $outcomes
	 * @param list<RecordRefusal>                             $refusals
	 */
	private function prepare_record(
		array $entry,
		BundleView $bundle,
		?PromotionManifest $existing,
		PromotionManifest $manifest,
		array $selected_keys,
		array &$staged,
		array &$outcomes,
		array &$refusals
	): PromotionManifest {
		$key      = $entry['key'];
		$provider = $entry['provider'];
		$slug     = $entry['slug'];

		$record       = $bundle->record( $key );
		$state_record = $bundle->state_record( $key );

		if ( null === $record || null === $state_record ) {
			throw PromotionException::hard( sprintf( 'Record "%s" is not in the state bundle.', $key ) );
		}

		$existing_record = null !== $existing ? $existing->record( $key ) : null;

		if ( null !== $existing_record && $this->is_idempotent_skip( $existing_record, $record ) ) {
			$manifest         = $manifest->with_record( $key, $existing_record );
			$outcomes[ $key ] = PromotionOutcome::OUTCOME_SKIPPED;

			return $manifest;
		}

		$strategy = $this->preparable_strategy( $provider );

		if ( null === $strategy ) {
			throw PromotionException::hard( sprintf( 'No promotion strategy is registered for provider "%s".', $provider ) );
		}

		$strategy_refusals = $strategy->validate_for_promotion( $record, $bundle, $selected_keys );

		if ( array() !== $strategy_refusals ) {
			foreach ( $strategy_refusals as $refusal ) {
				$refusals[] = $refusal;
			}

			$outcomes[ $key ] = PromotionOutcome::OUTCOME_REFUSED;

			return $manifest;
		}

		$content = isset( $record['content'] ) && is_array( $record['content'] ) ? $record['content'] : array();
		$markup  = isset( $content['markup'] ) && is_string( $content['markup'] ) ? $content['markup'] : '';
		$refs    = is_array( $record['references'] ?? null ) ? $record['references'] : array();

		$navigation = NavigationPolicy::evaluate(
			$key,
			$provider,
			$slug,
			NavigationBlockScanner::scan( $markup ),
			$refs
		);

		if ( array() !== $navigation['refusals'] ) {
			foreach ( $navigation['refusals'] as $refusal ) {
				$refusals[] = $refusal;
			}

			$outcomes[ $key ] = PromotionOutcome::OUTCOME_REFUSED;

			return $manifest;
		}

		$reference_refusals = ReferenceRefusalPolicy::evaluate( $key, $provider, $slug, $refs );

		if ( array() !== $reference_refusals ) {
			foreach ( $reference_refusals as $refusal ) {
				$refusals[] = $refusal;
			}

			$outcomes[ $key ] = PromotionOutcome::OUTCOME_REFUSED;

			return $manifest;
		}

		$staged_entry = $strategy->stage( $state_record, get_stylesheet_directory() );

		$staged[] = $staged_entry;

		$fields              = $staged_entry->manifest_fields();
		$theme_relative_path = isset( $fields['themeRelativePath'] ) && is_string( $fields['themeRelativePath'] ) ? $fields['themeRelativePath'] : '';
		$absolute_path       = isset( $fields['absolutePath'] ) && is_string( $fields['absolutePath'] ) ? $fields['absolutePath'] : '';
		$prepared_file_hash  = isset( $fields['preparedFileHash'] ) && is_string( $fields['preparedFileHash'] ) ? $fields['preparedFileHash'] : '';
		$original_file_hash  = isset( $fields['originalFileHash'] ) && is_string( $fields['originalFileHash'] ) ? $fields['originalFileHash'] : null;
		$expected_hash       = isset( $fields['expectedPostResetHash'] ) && is_string( $fields['expectedPostResetHash'] ) ? $fields['expectedPostResetHash'] : null;

		$manifest = $manifest->with_record(
			$key,
			array(
				'key'                      => $key,
				'provider'                 => $provider,
				'slug'                     => $slug,
				'objectId'                 => $record['objectId'] ?? null,
				'originalContentHash'      => is_string( $record['contentHash'] ?? null ) ? $record['contentHash'] : '',
				'originalModifiedGmt'      => $record['modifiedGmt'] ?? null,
				'preparedFilePath'         => $this->git->relative_path( $absolute_path ),
				'themeRelativePath'        => $theme_relative_path,
				'preparedFileHash'         => $prepared_file_hash,
				'originalFileHash'         => $original_file_hash,
				'referenceScan'            => $refs,
				'navigationExpectation'    => $navigation['navigationExpectation'],
				'expectedPostResetHash'    => $strategy->defers_expected_hash() ? null : $expected_hash,
				'preResetResolvedHash'     => null,
				'postFinalizeRecordState'  => null,
				'postFinalizeSemanticHash' => null,
				'postFinalizeModifiedGmt'  => null,
				'finalizeStatus'           => 'pending',
				'finalizeRefusalReason'    => null,
				'rollbackStatus'           => 'not-attempted',
				'rollbackRefusalReason'    => null,
				'restoredObjectId'         => null,
			)
		);

		$outcomes[ $key ] = PromotionOutcome::OUTCOME_PREPARED;

		return $manifest;
	}

	/**
	 * The idempotent skip: the existing manifest already holds this record,
	 * the on-disk prepared file still matches the recorded preparedFileHash,
	 * and the bundle content hash still matches the recorded
	 * originalContentHash — carry the existing record forward and stage
	 * nothing. Any mismatch re-stages, which is also the recovery path for
	 * a manifest written before its files were committed.
	 *
	 * @param array<string, mixed>|null $existing_record
	 * @param array<string, mixed>      $bundle_record
	 */
	private function is_idempotent_skip( ?array $existing_record, array $bundle_record ): bool {
		if ( null === $existing_record ) {
			return false;
		}

		$prepared_path = $existing_record['preparedFilePath'] ?? null;

		if ( ! is_string( $prepared_path ) || '' === $prepared_path ) {
			return false;
		}

		$on_disk_hash = $this->file_sha256( $this->git->absolute_path( $prepared_path ) );

		if ( ! is_string( $existing_record['preparedFileHash'] ?? null ) || $on_disk_hash !== $existing_record['preparedFileHash'] ) {
			return false;
		}

		return ( $existing_record['originalContentHash'] ?? null ) === ( $bundle_record['contentHash'] ?? null );
	}

	/**
	 * The raw-byte SHA-256 of a local file, or null when it cannot be read.
	 */
	private function file_sha256( string $absolute_path ): ?string {
		if ( ! is_file( $absolute_path ) || ! is_readable( $absolute_path ) ) {
			return null;
		}

		$hash = hash_file( 'sha256', $absolute_path );

		return false === $hash ? null : $hash;
	}
}
