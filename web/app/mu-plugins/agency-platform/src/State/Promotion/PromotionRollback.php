<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\Logging\Logger;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\StateRecord;

/**
 * The rollback step of the promotion lifecycle (BLOCK_THEME_PROPOSAL.md
 * §7.9): recreate the database rows a finalize step deleted or reset, from
 * the protected backups, in dependency-safe leaves-first order —
 * template-parts, then templates, then global-styles. Rollback refuses
 * rather than overwrites when a client recreated a record after
 * finalisation, a NEWER promotion has claimed the record, or the backup
 * was already pruned. A restored record is returned to a RE-FINALISABLE
 * state: the restored row's objectId and originalModifiedGmt are rewritten
 * into the manifest and finalizeStatus goes back to pending, so a second
 * --finalize on the same manifest passes its concurrency check instead of
 * refusing on a stale identity.
 *
 * The locks are re-acquired for the attempt and every lock the attempt
 * acquired is released in a finally — a refused, skipped, restored or
 * exception-aborted record never holds a lock until its TTL. Rollback
 * refuses, with exit 1 before any lock is acquired, a manifest that was
 * never finalized (the same guard confirm uses) and a CONFIRMED promotion:
 * confirm is the point of no return, because retention may prune the
 * backups at any moment after it.
 */
final class PromotionRollback {

	/** Leaves-first restore order (master spec §7.9 "dependency-safe order"). */
	public const PROVIDER_ORDER = array( 'template-parts', 'templates', 'global-styles' );

	public function __construct( private StateGateway $gateway, private ManifestStore $store ) {}

	/**
	 * @return array{manifest: PromotionManifest, outcome: PromotionOutcome}
	 * @throws PromotionException Exit 1 when the promotion was never
	 *                            finalized, is already confirmed, or the
	 *                            canonical copy is missing, exit 3 on a
	 *                            lock conflict.
	 */
	public function rollback( string $manifest_path_or_dash ): array {
		$input = $this->store->load( $manifest_path_or_dash );

		$id       = $input->promotion_id();
		$manifest = $this->store->load_canonical( $id );

		if ( 'rolled-back' === $manifest->settlement_status() ) {
			// A second run must SKIP the already-restored records, not
			// re-check them and report record-recreated against its own
			// restoration.
			return array(
				'manifest' => $manifest,
				'outcome'  => new PromotionOutcome( $this->all_skipped_outcomes( $manifest ), array() ),
			);
		}

		if ( 'pending' === $manifest->finalize_status() && 'partially-rolled-back' !== $manifest->settlement_status() ) {
			throw PromotionException::hard( sprintf( 'Promotion %s was never finalized.', $id ) );
		}

		if ( 'confirmed' === $manifest->settlement_status() ) {
			// Confirm is the point of no return: retention may prune the
			// backups at any moment after it, so a rollback that appears to
			// work could silently restore nothing.
			throw PromotionException::hard(
				sprintf( 'Promotion %s is already confirmed; rollback is refused after confirm.', $id )
			);
		}

		$locks = new RecordLockManager(
			$id,
			PromotionSettings::text( PromotionSettings::DEPLOYMENT_ID, $id )
		);
		$locks->acquire( $manifest->record_keys() );

		$backup   = new PromotionBackup( $id );
		$outcomes = array();
		$refusals = array();

		try {
			foreach ( $this->ordered_record_keys( $manifest ) as $key ) {
				$manifest = $this->rollback_record( $key, $manifest, $backup, $outcomes, $refusals );
			}

			$settled_at = gmdate( 'Y-m-d\TH:i:s\Z' );

			$settlement = $this->had_refusal( $outcomes ) ? 'partially-rolled-back' : 'rolled-back';

			$manifest = $manifest
				->with_field( 'settlementStatus', $settlement )
				->with_field( 'settledAtUtc', $settled_at )
				->with_refusals( $this->merged_refusals( $manifest, $refusals ) );

			if ( $this->any_record_back_to_pending( $manifest ) ) {
				// A later --finalize on the same manifest is legitimate again.
				$manifest = $manifest->with_field( 'finalizeStatus', 'pending' );
			}

			$backup->mark_settled( $settlement, $settled_at );

			$this->store->write_canonical( $manifest );

			if ( '-' !== $manifest_path_or_dash ) {
				$this->store->write( $manifest, $manifest_path_or_dash );
			}

			Logger::log(
				'promotion',
				'rolled-back',
				array(
					'promotionId' => $id,
					'records'     => count( $outcomes ),
					'refusals'    => count( $refusals ),
				)
			);

			return array(
				'manifest' => $manifest,
				'outcome'  => new PromotionOutcome( $outcomes, $refusals ),
			);
		} finally {
			// Every lock this attempt acquired or re-entered is released —
			// including locks for records that were refused, skipped,
			// restored, or caused an exception.
			$locks->release( $manifest->record_keys() );
		}
	}

	/**
	 * One record through the §7.9 steps, in leaves-first order.
	 *
	 * @param array<string, string> $outcomes
	 * @param list<RecordRefusal>   $refusals
	 */
	private function rollback_record(
		string $key,
		PromotionManifest $manifest,
		PromotionBackup $backup,
		array &$outcomes,
		array &$refusals
	): PromotionManifest {
		$record = $manifest->record( $key );

		if ( null === $record ) {
			throw PromotionException::hard( sprintf( 'The manifest carries no record for "%s"; refusing to roll back.', $key ) );
		}

		$provider = is_string( $record['provider'] ?? null ) ? $record['provider'] : '';
		$slug     = is_string( $record['slug'] ?? null ) ? $record['slug'] : '';

		$rollback_status = is_string( $record['rollbackStatus'] ?? null ) ? $record['rollbackStatus'] : 'not-attempted';

		// Already restored (by this or an earlier attempt) — skip, never
		// re-check or re-restore.
		if ( 'restored' === $rollback_status || 'restored-hash-mismatch' === $rollback_status ) {
			$outcomes[ $key ] = PromotionOutcome::OUTCOME_SKIPPED;

			return $manifest;
		}

		// Records the finalize step itself refused or self-restored were
		// never changed (or were already put back) — nothing to roll back.
		$finalize_status = is_string( $record['finalizeStatus'] ?? null ) ? $record['finalizeStatus'] : '';

		if ( 'refused' === $finalize_status || 'restored' === $finalize_status ) {
			$outcomes[ $key ] = PromotionOutcome::OUTCOME_SKIPPED;

			return $manifest;
		}

		$payload = $backup->retrieve( $key );

		if ( null === $payload ) {
			return $this->refuse_record(
				$manifest,
				$key,
				new RecordRefusal(
					$key,
					$provider,
					$slug,
					'backup-missing',
					'The backup for this record was pruned or never written; it cannot be restored.'
				),
				$outcomes,
				$refusals
			);
		}

		$state = is_string( $record['postFinalizeRecordState'] ?? null ) ? $record['postFinalizeRecordState'] : 'absent';
		$live  = $this->gateway->live_state_record( $provider, $slug );

		if ( 'present' === $state ) {
			// A present-state record was reset in place: it must still be
			// exactly the state finalize left it in.
			//
			// objectId is compared as well as the content hash and the modified
			// marker. Content and marker alone can be reproduced exactly by a
			// client who deletes the row and recreates it, and the identity is
			// the only field that changes in that case — the same gap a bounded
			// review found in PromotionFinalizer's concurrency check.
			$recorded_object_id = isset( $record['objectId'] ) && is_int( $record['objectId'] ) ? $record['objectId'] : null;

			if ( null === $live
				|| $live->content_hash() !== ( is_string( $record['postFinalizeSemanticHash'] ?? null ) ? $record['postFinalizeSemanticHash'] : '' )
				|| $live->modified_gmt() !== ( $record['postFinalizeModifiedGmt'] ?? null )
				|| ( null !== $recorded_object_id && $live->object_id() !== $recorded_object_id ) ) {
				return $this->refuse_record(
					$manifest,
					$key,
					new RecordRefusal(
						$key,
						$provider,
						$slug,
						'changed-since-finalize',
						'The record changed after finalisation; refusing to overwrite it.'
					),
					$outcomes,
					$refusals
				);
			}
		} elseif ( null !== $live ) {
			// Absent case: the record was deleted at finalize. A record that
			// exists again means the client re-edited after finalisation —
			// refuse, never overwrite (master spec §7.9 deleted-record rule).
			return $this->refuse_record(
				$manifest,
				$key,
				new RecordRefusal(
					$key,
					$provider,
					$slug,
					'record-recreated',
					'The client recreated the record after finalisation; refusing to overwrite it.'
				),
				$outcomes,
				$refusals
			);
		}

		// A NEWER promotion that finalized the same record key takes
		// precedence: rolling back would undo work that already shipped.
		$finalized_at = $manifest->finalized_at_utc();

		if ( null === $finalized_at ) {
			throw PromotionException::hard( sprintf( 'The manifest for promotion %s carries no finalizedAtUtc; refusing to roll back.', $manifest->promotion_id() ) );
		}

		$later_claims = PromotionBackup::later_claims( $key, $finalized_at, $manifest->promotion_id() );

		if ( array() !== $later_claims ) {
			return $this->refuse_record(
				$manifest,
				$key,
				new RecordRefusal(
					$key,
					$provider,
					$slug,
					'claimed-by-newer-promotion',
					sprintf( 'A newer promotion (%s) has claimed this record; refusing to roll it back.', implode( ', ', $later_claims ) )
				),
				$outcomes,
				$refusals
			);
		}

		// Restore through the strategy, then verify byte-exactness.
		$strategy = $this->preparable_strategy( $provider );

		$strategy->restore( $this->record_for_restore( $provider, $slug ), $payload );

		$re_read = $this->gateway->live_state_record( $provider, $slug );
		$matches = null !== $re_read
			&& $re_read->content_hash() === ( is_string( $record['originalContentHash'] ?? null ) ? $record['originalContentHash'] : '' );

		$restored_id = $strategy instanceof AbstractBlockTemplateStrategy ? $strategy->last_restored_object_id() : null;

		if ( null === $restored_id && null !== $re_read ) {
			$restored_id = $re_read->object_id();
		}

		// Record the restored row's identity: objectId and originalModifiedGmt
		// are REWRITTEN from the re-read row, so the next finalize's
		// concurrency check passes. The restored row is NEVER deleted, even
		// when its hash does not match.
		$manifest = $manifest->with_record_changes(
			$key,
			array(
				'restoredObjectId'         => $restored_id,
				'objectId'                 => null !== $re_read ? $re_read->object_id() : null,
				'originalModifiedGmt'      => null !== $re_read ? $re_read->modified_gmt() : null,
				'finalizeStatus'           => 'pending',
				'postFinalizeRecordState'  => null,
				'postFinalizeSemanticHash' => null,
				'postFinalizeModifiedGmt'  => null,
				'rollbackStatus'           => $matches ? 'restored' : 'restored-hash-mismatch',
				'rollbackRefusalReason'    => $matches ? null : 'restored-hash-mismatch',
			)
		);

		if ( $matches ) {
			$outcomes[ $key ] = PromotionOutcome::OUTCOME_RESTORED;
		} else {
			$outcomes[ $key ] = PromotionOutcome::OUTCOME_REFUSED;

			$refusals[] = new RecordRefusal(
				$key,
				$provider,
				$slug,
				'restored-hash-mismatch',
				'The restored row does not match the original content; it was restored but must be verified by an operator.'
			);
		}

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
		array &$outcomes,
		array &$refusals
	): PromotionManifest {
		$outcomes[ $key ] = PromotionOutcome::OUTCOME_REFUSED;
		$refusals[]       = $refusal;

		return $manifest->with_record_changes(
			$key,
			array(
				'rollbackStatus'        => 'refused',
				'rollbackRefusalReason' => $refusal->reason_code,
			)
		);
	}

	/**
	 * The dependency-safe order: PROVIDER_ORDER first, then reverse
	 * canonical-key order within each provider, so a template is never
	 * restored before the part it references.
	 *
	 * @return list<string>
	 */
	private function ordered_record_keys( PromotionManifest $manifest ): array {
		$keys = $manifest->record_keys();

		usort(
			$keys,
			function ( string $left, string $right ): int {
				$left_provider  = explode( ':', $left, 2 )[0];
				$right_provider = explode( ':', $right, 2 )[0];
				$left_index     = array_search( $left_provider, self::PROVIDER_ORDER, true );
				$right_index    = array_search( $right_provider, self::PROVIDER_ORDER, true );

				$left_index  = false === $left_index ? count( self::PROVIDER_ORDER ) : $left_index;
				$right_index = false === $right_index ? count( self::PROVIDER_ORDER ) : $right_index;

				if ( $left_index !== $right_index ) {
					return $left_index <=> $right_index;
				}

				return strcmp( $right, $left );
			}
		);

		return $keys;
	}

	/**
	 * @param array<string, string> $outcomes
	 */
	private function had_refusal( array $outcomes ): bool {
		foreach ( $outcomes as $outcome ) {
			if ( PromotionOutcome::OUTCOME_REFUSED === $outcome ) {
				return true;
			}
		}

		return false;
	}

	private function any_record_back_to_pending( PromotionManifest $manifest ): bool {
		foreach ( $manifest->records() as $record ) {
			if ( 'pending' === ( $record['finalizeStatus'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The StateRecord identity restore() needs: only the key is used, and
	 * the manifest record is not a StateRecord, so one is built from the
	 * provider and slug. Object id and content are irrelevant to restore().
	 */
	private function record_for_restore( string $provider, string $slug ): StateRecord {
		return StateRecord::create(
			$provider,
			$slug,
			null,
			'publish',
			null,
			array( 'markup' => '' ),
			array(),
			Ownership::GIT_BASELINE_PLUS_DB,
			PromotionPolicy::PROMOTABLE
		);
	}

	private function preparable_strategy( string $provider_slug ): PreparablePromotionStrategy {
		$strategy = $this->gateway->strategy_for( $provider_slug );

		if ( ! $strategy instanceof PreparablePromotionStrategy ) {
			throw PromotionException::hard( sprintf( 'No promotion strategy is registered for provider "%s".', $provider_slug ) );
		}

		return $strategy;
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

	/**
	 * The refusal report is rendered through RecordRefusal::to_array() so
	 * the manifest shape and the exception surface can never drift apart.
	 * The rollback refusals are APPENDED to whatever prepare/finalize
	 * already reported — with_refusals() replaces the whole list.
	 *
	 * @param list<RecordRefusal> $rollback_refusals
	 * @return list<RecordRefusal>
	 */
	private function merged_refusals( PromotionManifest $manifest, array $rollback_refusals ): array {
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

		foreach ( $rollback_refusals as $refusal ) {
			$merged[] = $refusal;
		}

		return $merged;
	}
}
