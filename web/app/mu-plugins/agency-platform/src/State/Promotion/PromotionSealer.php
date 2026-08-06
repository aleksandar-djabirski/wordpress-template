<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\Logging\Logger;

/**
 * The seal step of the promotion lifecycle (BLOCK_THEME_PROPOSAL.md §7.7):
 * binds the deploy commit to a prepared, signed manifest. Sealing verifies
 * every prepared file twice — on disk and in the named commit — because the
 * operator is about to make this manifest the plan the target host follows,
 * and a file that changed after prepare must never sail through. The three
 * outcomes are deliberately distinguishable: on-disk drift is tamper (4), a
 * commit that holds DIFFERENT bytes is tamper (4), and a path absent from
 * that commit is a hard error (1) — the operator must commit the prepared
 * files, not re-seal against a commit that never carried them.
 *
 * Sealing is idempotent and one-way: re-sealing the SAME commit succeeds,
 * while a second different commit refuses — the payload is signed at
 * prepare time, and a sealed manifest must never be re-pointed at another
 * commit.
 */
final class PromotionSealer {

	public function __construct( private ManifestStore $store, private GitRepository $git ) {}

	/**
	 * @throws PromotionException Exit 4 for tamper, exit 1 for hard errors.
	 */
	public function seal( string $manifest_path_or_dash, string $deploy_commit ): PromotionManifest {
		$manifest = $this->store->load( $manifest_path_or_dash );

		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $deploy_commit ) ) {
			throw PromotionException::hard( sprintf( 'The deploy commit "%s" is not a 40-hex sha.', $deploy_commit ) );
		}

		if ( ! $this->git->commit_exists( $deploy_commit ) ) {
			throw PromotionException::hard( sprintf( 'Commit "%s" does not exist in "%s".', $deploy_commit, $this->git->root() ) );
		}

		$current = $manifest->deploy_commit();

		if ( null !== $current ) {
			if ( $current === $deploy_commit ) {
				return $manifest;
			}

			throw PromotionException::hard( sprintf( 'Manifest is already sealed to %s.', $current ) );
		}

		foreach ( $manifest->records() as $record ) {
			$this->verify_record( $record, $deploy_commit );
		}

		$sealed = $manifest->with_deploy_commit( $deploy_commit, gmdate( 'Y-m-d\TH:i:s\Z' ) );

		if ( '-' !== $manifest_path_or_dash ) {
			$this->store->write( $sealed, $manifest_path_or_dash );
		}

		Logger::log(
			'promotion',
			'sealed',
			array(
				'promotionId'  => $sealed->promotion_id(),
				'deployCommit' => $deploy_commit,
			)
		);

		return $sealed;
	}

	/**
	 * One record through the two hash checks: the working-tree file must
	 * still match the recorded preparedFileHash (tamper when it does not),
	 * and the named commit must hold EXACTLY those bytes — absent from the
	 * commit is a hard error, present but different is tamper.
	 *
	 * @param array<string, mixed> $record
	 * @throws PromotionException Exit 4 for tamper, exit 1 for hard errors.
	 */
	private function verify_record( array $record, string $deploy_commit ): void {
		$prepared_path = $record['preparedFilePath'] ?? null;

		if ( ! is_string( $prepared_path ) || '' === $prepared_path ) {
			throw PromotionException::hard( 'A manifest record carries no preparedFilePath; refusing to seal.' );
		}

		$expected_hash = $record['preparedFileHash'] ?? null;

		if ( ! is_string( $expected_hash ) || '' === $expected_hash ) {
			throw PromotionException::hard( 'A manifest record carries no preparedFileHash; refusing to seal.' );
		}

		$on_disk = hash_file( 'sha256', $this->git->absolute_path( $prepared_path ) );

		if ( false === $on_disk || $on_disk !== $expected_hash ) {
			throw PromotionException::tamper(
				sprintf( 'Prepared file "%s" no longer matches the preparedFileHash recorded in the manifest; it was modified after prepare.', $prepared_path )
			);
		}

		$committed = $this->git->file_at_commit( $deploy_commit, $prepared_path );

		if ( null === $committed ) {
			throw PromotionException::hard(
				sprintf( 'Prepared file %s is not committed in %s. Commit the prepared files before sealing.', $prepared_path, $deploy_commit )
			);
		}

		if ( hash( 'sha256', $committed ) !== $expected_hash ) {
			throw PromotionException::tamper(
				sprintf( 'Prepared file "%s" is committed in %s with different content than the manifest records.', $prepared_path, $deploy_commit )
			);
		}
	}
}
