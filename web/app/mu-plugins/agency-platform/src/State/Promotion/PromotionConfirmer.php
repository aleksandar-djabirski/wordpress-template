<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\Logging\Logger;

/**
 * The confirm step of the promotion lifecycle (BLOCK_THEME_PROPOSAL.md
 * §7.9): the operator's "this promotion is good, settle it" declaration.
 * Confirm authenticates the supplied manifest, then reads the CANONICAL
 * host copy — the only document it trusts — refuses a promotion that was
 * never finalized, and otherwise marks the settlement confirmed: the
 * record locks are released (they are the thing a concurrent promotion
 * waits on) and the backups are deliberately NOT deleted — only
 * `wp agency promotion-backups prune` removes them, after the retention
 * window. A second confirm on the same manifest is an idempotent
 * all-skipped success that changes nothing.
 */
final class PromotionConfirmer {

	public function __construct( private ManifestStore $store ) {}

	/**
	 * @return array{manifest: PromotionManifest, outcome: PromotionOutcome}
	 * @throws PromotionException Exit 1 when the promotion was never
	 *                            finalized or the canonical copy is missing.
	 */
	public function confirm( string $manifest_path_or_dash ): array {
		$input = $this->store->load( $manifest_path_or_dash );

		$manifest = $this->store->load_canonical( $input->promotion_id() );

		if ( 'pending' === $manifest->finalize_status() ) {
			throw PromotionException::hard( sprintf( 'Promotion %s was never finalized.', $input->promotion_id() ) );
		}

		if ( 'confirmed' === $manifest->settlement_status() ) {
			return array(
				'manifest' => $manifest,
				'outcome'  => new PromotionOutcome( $this->all_skipped_outcomes( $manifest ), array() ),
			);
		}

		$settled_at      = gmdate( 'Y-m-d\TH:i:s\Z' );
		$retention_days  = PromotionSettings::integer( PromotionSettings::RETENTION_DAYS, 30 );
		$retention_until = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $settled_at ) + $retention_days * 86400 );

		$manifest = $manifest
			->with_field( 'settlementStatus', 'confirmed' )
			->with_field( 'settledAtUtc', $settled_at )
			->with_field( 'retentionUntilUtc', $retention_until );

		// The backup is deliberately NOT deleted here (master spec §7.9):
		// only prune() removes it, after the retention window.
		( new PromotionBackup( $input->promotion_id() ) )->mark_settled( 'confirmed', $settled_at );

		( new RecordLockManager(
			$input->promotion_id(),
			PromotionSettings::text( PromotionSettings::DEPLOYMENT_ID, $input->promotion_id() )
		) )->release( $manifest->record_keys() );

		$this->store->write_canonical( $manifest );

		if ( '-' !== $manifest_path_or_dash ) {
			$this->store->write( $manifest, $manifest_path_or_dash );
		}

		Logger::log(
			'promotion',
			'confirmed',
			array(
				'promotionId' => $input->promotion_id(),
				'records'     => count( $manifest->record_keys() ),
			)
		);

		return array(
			'manifest' => $manifest,
			'outcome'  => new PromotionOutcome( $this->all_skipped_outcomes( $manifest ), array() ),
		);
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
}
