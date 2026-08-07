<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\Logging\Logger;
use AgencyPlatform\State\EnvironmentConfig;
use AgencyPlatform\State\StateRecord;

/**
 * The finalise stage of the promotion lifecycle (BLOCK_THEME_PROPOSAL.md
 * §7.8). The run-level guards run first and abort the whole run writing
 * NOTHING: an unsealed manifest, a deploy-commit mismatch, a theme
 * stylesheet OR version mismatch, a site uuid / URL / environment mismatch,
 * and any deployed file whose bytes no longer match its signed hash are all
 * exit 1 (exit 4 for tamper). Then the canonical host manifest is written
 * as the durable recovery record — BEFORE the first reset, with every
 * record still pending, and again as each record completes — and the
 * per-record loop promotes every pending record: re-read the live row and
 * refuse on any concurrent change, verify the navigation expectation
 * against the target's deterministic fallback, back the row up AND verify
 * the backup is retrievable, reset it, flush the runtime cache and confirm
 * the resolved state equals the recorded expected hash — restoring the row
 * from the backup when it does not. Every record that is NOT left promoted
 * has its lock released, so a refused or self-restored record never holds
 * a lock until its TTL, and an exception mid-loop cannot strand one either.
 *
 * Task 9 ships the target-side navigation fallback resolution the §7.4
 * navigation policy judges at finalisation; the per-record finalise loop
 * that consumes it landed in Task 14.
 */
final class PromotionFinalizer {

	private readonly StateGateway $gateway;
	private readonly ManifestStore $store;

	/**
	 * $store is optional ONLY because Task 9's NavigationResolutionTest — a
	 * file outside this task's grant — constructs the class with the gateway
	 * alone and must keep working unchanged; production code always passes
	 * both. The property types stay exactly the interface's StateGateway and
	 * ManifestStore, never nullable.
	 */
	public function __construct( StateGateway $gateway, ?ManifestStore $store = null ) {
		$this->gateway = $gateway;
		$this->store   = $store ?? new ManifestStore( $gateway );
	}

	/**
	 * The deterministic target-side resolution WordPress core's navigation
	 * fallback uses: the most recently published wp_navigation post
	 * (post_type=wp_navigation, post_status=publish, orderby=date,
	 * order=DESC, posts_per_page=1 — the same query WP_Navigation_Fallback
	 * runs). The hash is read through Task 2's own navigation provider so
	 * the comparison uses that provider's content convention rather than a
	 * guess.
	 *
	 * @return array{id:int|null, hash:string|null, identity:array<string, mixed>|null}
	 */
	public function resolve_navigation_fallback(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		if ( array() === $posts ) {
			return array(
				'id'       => null,
				'hash'     => null,
				'identity' => null,
			);
		}

		// Read the hash through Task 2's own navigation provider so the comparison
		// uses that provider's content convention rather than a guess.
		foreach ( $this->gateway->live_records( 'navigation' ) as $record ) {
			if ( (int) ( $record['objectId'] ?? 0 ) === $posts[0]->ID ) {
				return array(
					'id'       => $posts[0]->ID,
					'hash'     => (string) $record['contentHash'],
					'identity' => array(
						'slug'   => $record['slug'],
						'status' => $record['status'],
					),
				);
			}
		}

		return array(
			'id'       => $posts[0]->ID,
			'hash'     => null,
			'identity' => null,
		);
	}

	/**
	 * The §7.8 entry point: run-level guards, then the per-record promotion
	 * loop, then the durable manifest write. The canonical host manifest is
	 * written BEFORE the first reset — with every record still pending — and
	 * rewritten as each record completes, so a failed write or a crash
	 * mid-loop can never leave the rows deleted with no recovery record.
	 * When $manifest_path_or_dash is a real file, the updated manifest is
	 * written back to it AND to the canonical host copy.
	 *
	 * @return array{manifest: PromotionManifest, outcome: PromotionOutcome}
	 * @throws PromotionException Exit 1 for run-level refusals, exit 4 for
	 *                            tamper, exit 3 for a lock conflict.
	 */
	public function finalize( string $manifest_path_or_dash ): array {
		$manifest = $this->store->load( $manifest_path_or_dash );

		$this->assert_run_level_guards( $manifest );

		$pending = $this->pending_record_keys( $manifest );

		if ( array() === $pending ) {
			// Idempotent re-run: every record is already promoted. Nothing is
			// touched — not even the locks, which --confirm or --rollback
			// still needs to release.
			return array(
				'manifest' => $manifest,
				'outcome'  => new PromotionOutcome( $this->all_skipped_outcomes( $manifest ), array() ),
			);
		}

		$locks = new RecordLockManager(
			$manifest->promotion_id(),
			PromotionSettings::text( PromotionSettings::DEPLOYMENT_ID, $manifest->promotion_id() )
		);
		$locks->acquire( $pending );

		$backup   = new PromotionBackup( $manifest->promotion_id() );
		$outcomes = array();
		$refusals = array();

		try {
			// CRITICAL 2 (whole-unit review): the canonical manifest is the
			// durable recovery record, and it is written BEFORE the first
			// reset — with every record still pending. If THIS write fails,
			// nothing has been reset yet and the run aborts with the database
			// untouched. The old order wrote it only AFTER every row was
			// reset: a failed write then left the rows deleted, the backup
			// present, and NO canonical for --rollback to load.
			$this->store->write_canonical( $manifest );

			foreach ( $pending as $key ) {
				$manifest = $this->promote_record( $key, $manifest, $backup, $outcomes, $refusals );

				// ...and rewritten as each record completes, so an exception
				// mid-loop cannot take the completed records' state with it.
				$this->store->write_canonical( $manifest );
			}

			$finalized_at = gmdate( 'Y-m-d\TH:i:s\Z' );

			$manifest = $manifest
				->with_field( 'finalizeStatus', $this->all_records_promoted( $manifest ) ? 'complete' : 'partial' )
				->with_field( 'finalizedAtUtc', $finalized_at )
				->with_field( 'backupId', $manifest->promotion_id() )
				// A manifest that was previously rolled back is settleable again.
				->with_field( 'settlementStatus', 'pending' )
				->with_refusals( $this->merged_refusals( $manifest, $refusals ) );

			$backup->mark_finalized( $finalized_at );

			$this->store->write_canonical( $manifest );

			if ( '-' !== $manifest_path_or_dash ) {
				$this->store->write( $manifest, $manifest_path_or_dash );
			}

			Logger::log(
				'promotion',
				'finalized',
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
			// A refused or self-restored record never holds a lock until its
			// TTL; locks of PROMOTED records stay held for settlement.
			$locks->release( $this->not_promoted_record_keys( $manifest ) );
		}
	}

	/**
	 * The run-level guard sequence (master spec §7.7/§7.8). Every refusal
	 * here aborts the whole run before anything is touched.
	 *
	 * @throws PromotionException Exit 1 for hard refusals, exit 4 for tamper.
	 */
	private function assert_run_level_guards( PromotionManifest $manifest ): void {
		$deploy_commit = $manifest->deploy_commit();

		if ( null === $deploy_commit ) {
			throw PromotionException::hard( 'Manifest is not sealed. Run --seal with the deploy commit first.' );
		}

		$configured_commit = EnvironmentConfig::get( 'AGENCY_DEPLOY_COMMIT' );

		if ( $configured_commit !== $deploy_commit ) {
			throw PromotionException::hard(
				sprintf(
					'The configured deploy commit "%s" does not match the manifest\'s sealed deploy commit "%s".',
					null === $configured_commit || '' === $configured_commit ? '(unset)' : $configured_commit,
					$deploy_commit
				)
			);
		}

		$active_theme = $manifest->active_theme();

		if ( get_stylesheet() !== $active_theme['stylesheet'] ) {
			throw PromotionException::hard(
				sprintf(
					'The active theme stylesheet "%s" does not match the manifest\'s "%s".',
					get_stylesheet(),
					$active_theme['stylesheet']
				)
			);
		}

		$deployed_version = (string) wp_get_theme()->get( 'Version' );

		if ( $deployed_version !== $active_theme['version'] ) {
			throw PromotionException::hard(
				sprintf(
					'The active theme version "%s" does not match the manifest\'s "%s".',
					$deployed_version,
					$active_theme['version']
				)
			);
		}

		// Master spec §7.7 requires all four identities checked together,
		// never the uuid alone.
		$site_uuid = get_option( 'agency_platform_site_uuid' );

		if ( $site_uuid !== $manifest->site_uuid() ) {
			throw PromotionException::hard(
				sprintf(
					'The site uuid "%s" does not match the manifest\'s "%s".',
					is_string( $site_uuid ) ? $site_uuid : '(unset)',
					$manifest->site_uuid()
				)
			);
		}

		if ( home_url() !== $manifest->site_url() ) {
			throw PromotionException::hard(
				sprintf(
					'The site URL "%s" does not match the manifest\'s "%s".',
					home_url(),
					$manifest->site_url()
				)
			);
		}

		if ( wp_get_environment_type() !== $manifest->environment() ) {
			throw PromotionException::hard(
				sprintf(
					'The environment "%s" does not match the manifest\'s "%s".',
					wp_get_environment_type(),
					$manifest->environment()
				)
			);
		}

		foreach ( $manifest->records() as $record ) {
			$this->assert_deployed_file_matches( $record );
		}
	}

	/**
	 * One record's deployed file must carry exactly the bytes the manifest
	 * signed at prepare time — an altered file is tamper (exit 4), because
	 * the finalize is about to make the database agree with that file. A
	 * MISSING file is deliberately not tamper: the loop's post-reset
	 * resolution fails instead, the database row is restored from the
	 * backup and the record is refused as post-reset-unresolved — nothing
	 * is destroyed and nothing is silently promoted.
	 *
	 * @param array<string, mixed> $record
	 * @throws PromotionException Exit 4 for tamper, exit 1 for a shape error.
	 */
	private function assert_deployed_file_matches( array $record ): void {
		$relative_path = $record['themeRelativePath'] ?? null;

		if ( ! is_string( $relative_path ) || '' === $relative_path ) {
			throw PromotionException::hard( 'A manifest record carries no themeRelativePath; refusing to finalize.' );
		}

		$expected_hash = $record['preparedFileHash'] ?? null;

		if ( ! is_string( $expected_hash ) || '' === $expected_hash ) {
			throw PromotionException::hard( 'A manifest record carries no preparedFileHash; refusing to finalize.' );
		}

		$deployed_path = get_stylesheet_directory() . '/' . $relative_path;

		if ( ! is_file( $deployed_path ) ) {
			return;
		}

		$on_disk = hash_file( 'sha256', $deployed_path );

		if ( false === $on_disk || $on_disk !== $expected_hash ) {
			throw PromotionException::tamper(
				sprintf(
					'Prepared file "%s" no longer matches the preparedFileHash recorded in the manifest; it was modified after prepare.',
					$deployed_path
				)
			);
		}
	}

	/**
	 * @return list<string> The canonical record keys whose finalizeStatus is
	 *                      not 'promoted' — the records this run must promote.
	 */
	private function pending_record_keys( PromotionManifest $manifest ): array {
		$pending = array();

		foreach ( $manifest->records() as $record ) {
			if ( 'promoted' !== ( $record['finalizeStatus'] ?? null ) ) {
				$key = $record['key'] ?? null;

				if ( is_string( $key ) && '' !== $key ) {
					$pending[] = $key;
				}
			}
		}

		sort( $pending, SORT_STRING );

		return $pending;
	}

	/**
	 * One pending record through the §7.8 ten-step loop.
	 *
	 * @param array<string, string> $outcomes
	 * @param list<RecordRefusal>   $refusals
	 */
	private function promote_record(
		string $key,
		PromotionManifest $manifest,
		PromotionBackup $backup,
		array &$outcomes,
		array &$refusals
	): PromotionManifest {
		$record = $manifest->record( $key );

		if ( null === $record ) {
			throw PromotionException::hard( sprintf( 'The manifest carries no record for "%s"; refusing to finalize.', $key ) );
		}

		$provider = is_string( $record['provider'] ?? null ) ? $record['provider'] : '';
		$slug     = is_string( $record['slug'] ?? null ) ? $record['slug'] : '';

		$strategy = $this->preparable_strategy( $provider );

		// 1. Re-read the live row: a missing row is a concurrent deletion,
		// never an idempotent success.
		$live = $this->gateway->live_state_record( $provider, $slug );

		if ( null === $live ) {
			return $this->refuse_record(
				$manifest,
				$key,
				new RecordRefusal(
					$key,
					$provider,
					$slug,
					'concurrent-delete',
					'The database override no longer exists. It was removed between export and finalisation; re-export before promoting.'
				),
				'refused',
				$outcomes,
				$refusals
			);
		}

		// 2. The concurrency check: object id, content hash AND modification
		// marker must all still match what the manifest recorded. The object
		// id comparison catches a row that was deleted and recreated with
		// identical content and modification marker — the other two alone
		// would accept the new row as the one the export prepared against.
		if ( $live->object_id() !== ( $record['objectId'] ?? null )
			|| $live->content_hash() !== ( is_string( $record['originalContentHash'] ?? null ) ? $record['originalContentHash'] : '' )
			|| $live->modified_gmt() !== ( $record['originalModifiedGmt'] ?? null ) ) {
			return $this->refuse_record(
				$manifest,
				$key,
				new RecordRefusal(
					$key,
					$provider,
					$slug,
					'concurrent-edit',
					'The database override changed between export and finalisation; re-export before promoting.'
				),
				'refused',
				$outcomes,
				$refusals
			);
		}

		// 3. Navigation expectation: the target's deterministic fallback must
		// resolve the exported navigation hash.
		$expectation = is_array( $record['navigationExpectation'] ?? null ) ? $record['navigationExpectation'] : array();

		if ( array() !== $expectation ) {
			$fallback = $this->resolve_navigation_fallback();
			$refusal  = NavigationPolicy::verify_target( $key, $provider, $slug, $expectation, $fallback['hash'], $fallback['identity'] );

			if ( null !== $refusal ) {
				return $this->refuse_record( $manifest, $key, $refusal, 'refused', $outcomes, $refusals );
			}
		}

		// 3b. The deferred-hash branch (master spec §7.6 step 3): a strategy
		// that defers its expected hash cannot know it at prepare time — the
		// fully resolved settings/styles depend on the TARGET host, and the
		// bundle exports the user origin, never a resolved snapshot. The
		// snapshot is therefore captured here, right after the concurrency
		// check has proven the live user origin still matches the exported
		// record, and its hash is persisted as preResetResolvedHash for audit
		// and rollback. A strategy that defers WITHOUT the target-side
		// equivalence contract is refused per record, never fatal.
		$deferred          = $strategy->defers_expected_hash();
		$expected_resolved = array();

		if ( $deferred ) {
			if ( ! $strategy instanceof DeferredHashPromotionStrategy ) {
				return $this->refuse_record(
					$manifest,
					$key,
					$this->deferred_hash_refusal( $key, $provider, $slug ),
					'refused',
					$outcomes,
					$refusals
				);
			}

			$expected_resolved = $strategy->capture_pre_reset_state();

			$manifest = $manifest->with_record_changes(
				$key,
				// The strategy's own resolved_hash() is the single source of
				// truth: verify_resolved_equivalence() compares the same
				// convention, so the audit record and the gate can never
				// silently disagree.
				array( 'preResetResolvedHash' => $strategy->resolved_hash( $expected_resolved ) )
			);
		}

		// 4. The backup is the ONLY copy of the customer's content once the
		// reset runs; it is captured before anything is destroyed.
		$backup->store( $key, $strategy->capture_backup( $live ) );

		// 4a. CRITICAL 1 (whole-unit review): the backup must be RETRIEVABLE
		// before the reset. store() returning success proves nothing until
		// retrieve() reads it back — a missing meta row or a corrupt chunk
		// used to surface only at restore time, AFTER the reset had deleted
		// the customer's row. A backup that cannot be retrieved refuses this
		// record before any reset: nothing is destroyed.
		try {
			$retrieved = $backup->retrieve( $key );
		} catch ( PromotionException $exception ) {
			return $this->refuse_record(
				$manifest,
				$key,
				new RecordRefusal(
					$key,
					$provider,
					$slug,
					'backup-unavailable',
					'The backup could not be retrieved before the reset: ' . $exception->getMessage() . ' The database override was NOT modified.'
				),
				'refused',
				$outcomes,
				$refusals
			);
		}

		if ( null === $retrieved ) {
			return $this->refuse_record(
				$manifest,
				$key,
				new RecordRefusal(
					$key,
					$provider,
					$slug,
					'backup-unavailable',
					'The backup could not be retrieved after it was stored; the database override was NOT modified.'
				),
				'refused',
				$outcomes,
				$refusals
			);
		}

		// 5. Reset: remove the database override. A failure here means nothing
		// was removed, so there is nothing to restore — refuse and move on.
		try {
			$strategy->reset( $live );
		} catch ( PromotionException $exception ) {
			return $this->refuse_record(
				$manifest,
				$key,
				new RecordRefusal(
					$key,
					$provider,
					$slug,
					'reset-failed',
					$exception->getMessage()
				),
				'refused',
				$outcomes,
				$refusals
			);
		}

		// 6. The deleted row can still sit in this process's runtime object
		// cache; flush it before re-resolving.
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}

		$actual = $strategy->resolve_current_hash( $slug );

		if ( null === $actual ) {
			return $this->restore_and_refuse(
				$manifest,
				$key,
				$live,
				$strategy,
				$backup,
				'post-reset-unresolved',
				'After the database override was removed, the record could not be resolved from the deployed theme files; the database record was restored from the backup.',
				$outcomes,
				$refusals
			);
		}

		// 7. The post-reset semantic comparison: the resolved state must equal
		// what the prepare stage recorded. A deferred-hash strategy compares
		// against the target-side pre-reset snapshot instead of
		// expectedPostResetHash (which stays null for it); a mismatch restores
		// the row — the backup is deliberately NOT deleted.
		if ( $deferred ) {
			if ( ! $strategy instanceof DeferredHashPromotionStrategy ) {
				return $this->refuse_record(
					$manifest,
					$key,
					$this->deferred_hash_refusal( $key, $provider, $slug ),
					'refused',
					$outcomes,
					$refusals
				);
			}

			$equivalence = $strategy->verify_resolved_equivalence( $expected_resolved );

			if ( false === $equivalence['equivalent'] ) {
				return $this->restore_and_refuse(
					$manifest,
					$key,
					$live,
					$strategy,
					$backup,
					'resolved-output-drift',
					'After the database override was removed, the resolved output differed from the pre-reset snapshot; the database record was restored from the backup. First divergent key: ' . (string) $equivalence['difference'],
					$outcomes,
					$refusals
				);
			}
		} elseif ( ( is_string( $record['expectedPostResetHash'] ?? null ) ? $record['expectedPostResetHash'] : '' ) !== $actual ) {
			return $this->restore_and_refuse(
				$manifest,
				$key,
				$live,
				$strategy,
				$backup,
				'post-reset-mismatch',
				'After the database override was removed, the resolved state differed from the recorded expected hash; the database record was restored from the backup.',
				$outcomes,
				$refusals
			);
		}

		// 8. Success. The record is left promoted and re-finalisable via
		// --rollback, whose lock it keeps.
		$state   = $strategy->post_finalize_record_state();
		$re_read = $this->gateway->live_state_record( $provider, $slug );

		$manifest = $manifest->with_record_changes(
			$key,
			array(
				'finalizeStatus'           => 'promoted',
				'postFinalizeRecordState'  => $state,
				'postFinalizeSemanticHash' => $actual,
				'postFinalizeModifiedGmt'  => 'present' === $state ? ( null !== $re_read ? $re_read->modified_gmt() : null ) : null,
				'rollbackStatus'           => 'not-attempted',
			)
		);

		$outcomes[ $key ] = PromotionOutcome::OUTCOME_PROMOTED;

		return $manifest;
	}

	/**
	 * A per-record refusal: the record is marked with the refusal reason,
	 * the outcome map reports it, and the refusal lands in the report.
	 *
	 * @param array<string, string> $outcomes
	 * @param list<RecordRefusal>   $refusals
	 */
	private function refuse_record(
		PromotionManifest $manifest,
		string $key,
		RecordRefusal $refusal,
		string $finalize_status,
		array &$outcomes,
		array &$refusals
	): PromotionManifest {
		$outcomes[ $key ] = PromotionOutcome::OUTCOME_REFUSED;
		$refusals[]       = $refusal;

		return $manifest->with_record_changes(
			$key,
			array(
				'finalizeStatus'        => $finalize_status,
				'finalizeRefusalReason' => $refusal->reason_code,
			)
		);
	}

	/**
	 * The post-reset failure path: put the customer's row BACK from the
	 * backup (a half-finalised record is worse than a refused one), report
	 * the refusal and leave the record in a state rollback will skip.
	 *
	 * CRITICAL 1 (whole-unit review): finalizeStatus=restored is recorded
	 * ONLY when restore() actually completed. The retrievability
	 * verification (step 4a) ran before the reset, so a null retrieval here
	 * is unreachable in a healthy run — but a backup that vanishes mid-run,
	 * or a restore() that throws, must never be reported as restored: the
	 * row is GONE in both cases, and the refusal says so instead.
	 *
	 * @param array<string, string> $outcomes
	 * @param list<RecordRefusal>   $refusals
	 */
	private function restore_and_refuse(
		PromotionManifest $manifest,
		string $key,
		StateRecord $live,
		PreparablePromotionStrategy $strategy,
		PromotionBackup $backup,
		string $reason_code,
		string $detail,
		array &$outcomes,
		array &$refusals
	): PromotionManifest {
		$payload = $backup->retrieve( $key );

		if ( null === $payload ) {
			return $this->refuse_record(
				$manifest,
				$key,
				new RecordRefusal(
					$key,
					$live->provider_slug(),
					$live->slug(),
					'restore-unavailable',
					$detail . ' The backup could not be retrieved either; the database row is deleted and needs operator attention.'
				),
				'refused',
				$outcomes,
				$refusals
			);
		}

		try {
			$strategy->restore( $live, $payload );
		} catch ( PromotionException $exception ) {
			return $this->refuse_record(
				$manifest,
				$key,
				new RecordRefusal(
					$key,
					$live->provider_slug(),
					$live->slug(),
					'restore-failed',
					$exception->getMessage() . ' The database row was already deleted by the reset and could not be recreated; it needs operator attention.'
				),
				'refused',
				$outcomes,
				$refusals
			);
		}

		return $this->refuse_record(
			$manifest,
			$key,
			new RecordRefusal(
				$key,
				$live->provider_slug(),
				$live->slug(),
				$reason_code,
				$detail
			),
			'restored',
			$outcomes,
			$refusals
		);
	}

	/**
	 * The refusal for a strategy that defers its expected hash without
	 * implementing the target-side equivalence contract: the finalizer could
	 * neither capture the pre-reset snapshot nor verify the post-reset
	 * output, so promoting the record would be unverifiable. Refused per
	 * record, never fatal.
	 */
	private function deferred_hash_refusal( string $key, string $provider, string $slug ): RecordRefusal {
		return new RecordRefusal(
			$key,
			$provider,
			$slug,
			'deferred-hash-unsupported',
			'The strategy defers its post-reset hash expectation but does not implement the target-side equivalence contract; refusing the record.'
		);
	}

	/**
	 * @return list<string> Every record key whose finalizeStatus is not
	 *                      'promoted' in the CURRENT manifest — the keys
	 *                      whose locks the finally block releases.
	 */
	private function not_promoted_record_keys( PromotionManifest $manifest ): array {
		$keys = array();

		foreach ( $manifest->records() as $record ) {
			if ( 'promoted' !== ( $record['finalizeStatus'] ?? null ) ) {
				$key = $record['key'] ?? null;

				if ( is_string( $key ) && '' !== $key ) {
					$keys[] = $key;
				}
			}
		}

		return $keys;
	}

	private function all_records_promoted( PromotionManifest $manifest ): bool {
		foreach ( $manifest->records() as $record ) {
			if ( 'promoted' !== ( $record['finalizeStatus'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array<string, string>
	 */
	private function all_skipped_outcomes( PromotionManifest $manifest ): array {
		$outcomes = array();

		foreach ( $manifest->record_keys() as $key ) {
			$outcomes[ $key ] = PromotionOutcome::OUTCOME_SKIPPED;
		}

		return $outcomes;
	}

	private function preparable_strategy( string $provider_slug ): PreparablePromotionStrategy {
		$strategy = $this->gateway->strategy_for( $provider_slug );

		if ( ! $strategy instanceof PreparablePromotionStrategy ) {
			throw PromotionException::hard( sprintf( 'No promotion strategy is registered for provider "%s".', $provider_slug ) );
		}

		return $strategy;
	}

	/**
	 * The refusal report is rendered through RecordRefusal::to_array() so
	 * the manifest shape and the exception surface can never drift apart.
	 * The finalize refusals are APPENDED to whatever prepare already
	 * reported — with_refusals() replaces the whole list.
	 *
	 * @param list<RecordRefusal> $finalize_refusals
	 * @return list<RecordRefusal>
	 */
	private function merged_refusals( PromotionManifest $manifest, array $finalize_refusals ): array {
		$merged = array();

		foreach ( $manifest->refusals() as $existing ) {
			$merged[] = new RecordRefusal(
				(string) ( $existing['recordKey'] ?? '' ),
				(string) ( $existing['provider'] ?? '' ),
				(string) ( $existing['slug'] ?? '' ),
				(string) ( $existing['reasonCode'] ?? '' ),
				(string) ( $existing['detail'] ?? '' ),
				isset( $existing['blockName'] ) && is_string( $existing['blockName'] ) ? $existing['blockName'] : null,
				isset( $existing['attribute'] ) && is_string( $existing['attribute'] ) ? $existing['attribute'] : null,
				isset( $existing['referencedValue'] ) && is_string( $existing['referencedValue'] ) ? $existing['referencedValue'] : null,
				isset( $existing['suggestedPolicy'] ) && is_string( $existing['suggestedPolicy'] ) ? $existing['suggestedPolicy'] : null
			);
		}

		foreach ( $finalize_refusals as $refusal ) {
			$merged[] = $refusal;
		}

		return $merged;
	}
}
