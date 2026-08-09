<?php
/**
 * The chunked promotion backups (BLOCK_THEME_PROPOSAL.md §7.9): a JSON
 * record payload is stored as fixed-size non-autoloaded option chunks, keyed
 * by promotion id + record-key sha256, with a meta option carrying the chunk
 * count, byte count, sha256 and record key. The load-bearing properties:
 * a payload that actually CHUNKS must reassemble byte-identically; every
 * stored option must be non-autoloaded (proven by reading the option row,
 * not by trusting the write call); store() is idempotent per key; a missing
 * or corrupted chunk must fail loudly instead of returning partial content;
 * retention must delete ONLY what it should — the old backups go, the recent
 * ones survive; and later_claims() must find later finalizations of the
 * same record key while ignoring rolled-back promotions.
 *
 * The chunk size is driven down to 1000 bytes via AGENCY_PROMOTION_BACKUP_
 * CHUNK_BYTES so the fixtures really do chunk; the retention dates are fixed
 * 2026 dates, far outside the 30-day window, so the aged-vs-recent split is
 * deterministic.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\Promotion\PromotionBackup;
use AgencyPlatform\State\Promotion\PromotionException;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\PromotionBackup
 */
// putenv() drives AGENCY_PROMOTION_BACKUP_CHUNK_BYTES (the chunk size the
// chunking tests need) and AGENCY_STATE_DIR (where prune deletes canonical
// manifests) through EnvironmentConfig's process-environment fallback;
// WordPress's discouraged-function sniff would otherwise flag every call.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class PromotionBackupTest extends IntegrationTestCase {

	private string $tmp_dir;
	private string $state_dir;
	private string $promotion_a;
	private string $promotion_b;
	private string $promotion_c;

	public function set_up(): void {
		parent::set_up();

		$this->tmp_dir   = sys_get_temp_dir() . '/promotion-backup-' . uniqid( '', true );
		$this->state_dir = $this->tmp_dir . '/state';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->state_dir . '/promotions', 0700, true );

		putenv( 'AGENCY_PROMOTION_BACKUP_CHUNK_BYTES=1000' );
		putenv( 'AGENCY_STATE_DIR=' . $this->state_dir );

		$this->promotion_a = '11111111-1111-4111-8111-111111111111';
		$this->promotion_b = '22222222-2222-4222-8222-222222222222';
		$this->promotion_c = '33333333-3333-4333-8333-333333333333';
	}

	public function tear_down(): void {
		putenv( 'AGENCY_PROMOTION_BACKUP_CHUNK_BYTES' );
		putenv( 'AGENCY_STATE_DIR' );

		$this->remove_tree( $this->tmp_dir );

		parent::tear_down();
	}

	/**
	 * The fixture payload is several kilobytes, so with a 1000-byte chunk
	 * size it is actually chunked — a payload that never chunks proves
	 * nothing. Reassembly is the property that matters.
	 */
	public function test_a_payload_larger_than_the_chunk_size_round_trips_byte_identically(): void {
		$payload = array(
			'post'  => array(
				'ID'           => 42,
				'post_title'   => str_repeat( 'The title is long enough to span many chunks. ', 200 ),
				'post_content' => str_repeat( 'content ', 2000 ),
			),
			'terms' => array( 'site-theme' ),
			'meta'  => array( 'custom_key' => array( str_repeat( 'm', 2500 ) ) ),
		);

		( new PromotionBackup( $this->promotion_a ) )->store( 'templates:page', $payload );

		$restored = ( new PromotionBackup( $this->promotion_a ) )->retrieve( 'templates:page' );

		self::assertSame( $payload, $restored, 'The reassembled backup must be byte-identical to the original payload.' );

		$meta = get_option( $this->meta_option_name( $this->promotion_a, 'templates:page' ) );

		self::assertIsArray( $meta );
		self::assertGreaterThan( 1, (int) $meta['chunks'], 'The fixture payload must actually be chunked for this test to prove chunking.' );
	}

	/**
	 * A large autoloaded option is loaded on every request and would be a
	 * real performance defect. The autoload column is read from the option
	 * ROW, never trusted from the write call.
	 */
	public function test_every_stored_backup_option_is_not_autoloaded(): void {
		( new PromotionBackup( $this->promotion_a ) )->store(
			'templates:page',
			array(
				'post' => array(
					'ID'         => 7,
					'post_title' => str_repeat( 'x', 2500 ),
				),
			)
		);

		$names = array(
			$this->meta_option_name( $this->promotion_a, 'templates:page' ),
			$this->chunk_option_name( $this->promotion_a, 'templates:page', 0 ),
			PromotionBackup::INDEX_OPTION,
		);

		foreach ( $names as $name ) {
			$autoload = $this->option_autoload( $name );

			self::assertContains(
				$autoload,
				array( 'no', 'off' ),
				sprintf( 'Option "%s" must be non-autoloaded; a backup is only read by the promotion lifecycle. Got autoload "%s".', $name, $autoload )
			);
		}
	}

	public function test_storing_the_same_key_twice_leaves_the_chunk_count_unchanged(): void {
		$backup  = new PromotionBackup( $this->promotion_a );
		$payload = array(
			'post' => array(
				'ID'         => 7,
				'post_title' => str_repeat( 'y', 2500 ),
			),
		);

		$backup->store( 'templates:page', $payload );

		$meta_before = get_option( $this->meta_option_name( $this->promotion_a, 'templates:page' ) );

		self::assertIsArray( $meta_before );

		$backup->store( 'templates:page', $payload );

		$meta_after = get_option( $this->meta_option_name( $this->promotion_a, 'templates:page' ) );

		self::assertIsArray( $meta_after );
		self::assertSame( $meta_before['chunks'], $meta_after['chunks'], 'A second store() for the same key must not duplicate chunks.' );
		self::assertSame( $payload, $backup->retrieve( 'templates:page' ) );
	}

	public function test_list_all_reports_record_keys_retention_and_prunable_flags(): void {
		$aged = new PromotionBackup( $this->promotion_a );
		$aged->store( 'templates:page', array( 'post' => array( 'ID' => 1 ) ) );
		$aged->store( 'template-parts:site-header', array( 'post' => array( 'ID' => 2 ) ) );
		$aged->mark_finalized( '2026-01-01T00:00:00Z' );
		$aged->mark_settled( 'confirmed', '2026-01-02T00:00:00Z' );

		$recent = new PromotionBackup( $this->promotion_b );
		$recent->store( 'templates:page', array( 'post' => array( 'ID' => 3 ) ) );
		$recent->mark_finalized( gmdate( 'Y-m-d\TH:i:s\Z' ) );

		$rows = PromotionBackup::list_all();

		self::assertCount( 2, $rows );

		// Newest finalizedAtUtc first.
		self::assertSame( $this->promotion_b, $rows[0]['promotionId'] );
		self::assertFalse( $rows[0]['prunable'], 'A freshly finalized backup must not be prunable.' );
		self::assertSame( array( 'templates:page' ), $rows[0]['recordKeys'] );
		self::assertSame( 1, $rows[0]['records'] );

		self::assertSame( $this->promotion_a, $rows[1]['promotionId'] );
		self::assertTrue( $rows[1]['prunable'], 'A backup settled months ago must be prunable.' );
		self::assertSame( array( 'templates:page', 'template-parts:site-header' ), $rows[1]['recordKeys'] );
		self::assertSame( 2, $rows[1]['records'] );
		self::assertSame( 'confirmed', $rows[1]['settlementStatus'] );
		self::assertSame( '2026-01-02T00:00:00Z', $rows[1]['settledAtUtc'] );
		self::assertGreaterThan( 0, $rows[1]['bytes'] );
	}

	public function test_prune_dry_run_returns_the_eligible_ids_and_deletes_nothing(): void {
		$aged = new PromotionBackup( $this->promotion_a );
		$aged->store(
			'templates:page',
			array(
				'post' => array(
					'ID'         => 1,
					'post_title' => str_repeat( 'a', 2500 ),
				),
			)
		);
		$aged->mark_finalized( '2026-01-01T00:00:00Z' );
		$aged->mark_settled( 'confirmed', '2026-01-02T00:00:00Z' );

		$recent = new PromotionBackup( $this->promotion_b );
		$recent->store(
			'templates:page',
			array(
				'post' => array(
					'ID'         => 2,
					'post_title' => str_repeat( 'b', 2500 ),
				),
			)
		);
		$recent->mark_finalized( gmdate( 'Y-m-d\TH:i:s\Z' ) );

		$ids = PromotionBackup::prune( '30d', true );

		self::assertSame( array( $this->promotion_a ), $ids );
		self::assertTrue( $aged->exists( 'templates:page' ), 'A dry run must delete nothing.' );
		self::assertTrue( $recent->exists( 'templates:page' ), 'A dry run must delete nothing.' );
	}

	public function test_prune_deletes_only_backups_whose_retention_has_expired(): void {
		$aged = new PromotionBackup( $this->promotion_a );
		$aged->store(
			'templates:page',
			array(
				'post' => array(
					'ID'         => 1,
					'post_title' => str_repeat( 'a', 2500 ),
				),
			)
		);
		$aged->mark_finalized( '2026-01-01T00:00:00Z' );
		$aged->mark_settled( 'confirmed', '2026-01-02T00:00:00Z' );

		$recent = new PromotionBackup( $this->promotion_b );
		$recent->store(
			'templates:page',
			array(
				'post' => array(
					'ID'         => 3,
					'post_title' => str_repeat( 'b', 2500 ),
				),
			)
		);
		$recent->mark_finalized( gmdate( 'Y-m-d\TH:i:s\Z' ) );

		$manifest_a = $this->state_dir . '/promotions/' . $this->promotion_a . '.json';
		$manifest_b = $this->state_dir . '/promotions/' . $this->promotion_b . '.json';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing integration fixture manifests; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $manifest_a, '{"schemaVersion":1}' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing integration fixture manifests; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $manifest_b, '{"schemaVersion":1}' );

		$ids = PromotionBackup::prune( '30d', false );

		self::assertSame( array( $this->promotion_a ), $ids );

		// The aged backup is gone: chunks, meta, index entry, canonical manifest.
		self::assertFalse( $aged->exists( 'templates:page' ), 'The expired backup must be deleted.' );
		self::assertFalse( get_option( $this->meta_option_name( $this->promotion_a, 'templates:page' ) ), 'The expired backup meta must be deleted.' );
		self::assertFalse( get_option( $this->chunk_option_name( $this->promotion_a, 'templates:page', 0 ) ), 'The expired backup chunks must be deleted.' );
		self::assertFileDoesNotExist( $manifest_a, 'The expired backup canonical manifest must be deleted.' );

		// The recent backup survives completely — deletion that also takes
		// live promotions would destroy the rollback path.
		self::assertTrue( $recent->exists( 'templates:page' ), 'The recent backup must survive.' );
		self::assertFileExists( $manifest_b, 'The recent backup canonical manifest must survive.' );
		self::assertSame( 3, $recent->retrieve( 'templates:page' )['post']['ID'] );

		$rows = PromotionBackup::list_all();

		self::assertCount( 1, $rows );
		self::assertSame( $this->promotion_b, $rows[0]['promotionId'], 'The index must no longer mention the pruned promotion.' );
	}

	/**
	 * The §11.13 retention shape: a CONFIRMED promotion inside its window is
	 * not pruned; the SAME promotion, after its finalization and settlement
	 * both age past the window, is pruned. Retention is anchored on the
	 * later of finalization and settlement (master spec §7.9). --older-than=1d
	 * keeps the cutoff out of the way: with the promotion ten days old, the
	 * retention WINDOW is the only gate that can protect the backup.
	 */
	public function test_a_confirmed_backup_is_pruned_only_after_its_retention_window(): void {
		$backup = new PromotionBackup( $this->promotion_a );
		$backup->store( 'templates:page', array( 'post' => array( 'ID' => 1 ) ) );

		$ten_days_ago = gmdate( 'Y-m-d\TH:i:s\Z', time() - 10 * 86400 );

		$backup->mark_finalized( $ten_days_ago );
		$backup->mark_settled( 'confirmed', $ten_days_ago );

		// Ten days into a 30-day window: not prunable, so a real prune keeps it.
		self::assertSame( array(), PromotionBackup::prune( '1d', false ), 'A confirmed backup inside its window must survive prune.' );
		self::assertTrue( $backup->exists( 'templates:page' ) );

		// Age the promotion past the window: the SAME promotion is now pruned.
		$index = get_option( PromotionBackup::INDEX_OPTION );

		self::assertIsArray( $index );

		$index[ $this->promotion_a ]['finalizedAtUtc'] = gmdate( 'Y-m-d\TH:i:s\Z', time() - 31 * 86400 );
		$index[ $this->promotion_a ]['settledAtUtc']   = gmdate( 'Y-m-d\TH:i:s\Z', time() - 31 * 86400 );
		update_option( PromotionBackup::INDEX_OPTION, $index, false );

		self::assertSame( array( $this->promotion_a ), PromotionBackup::prune( '1d', false ) );
		self::assertFalse( $backup->exists( 'templates:page' ), 'A confirmed backup past its window must be pruned.' );
	}

	public function test_later_claims_finds_later_finalizations_and_ignores_rolled_back_promotions(): void {
		$first = new PromotionBackup( $this->promotion_a );
		$first->store( 'templates:page', array( 'post' => array( 'ID' => 1 ) ) );
		$first->mark_finalized( '2026-01-01T00:00:00Z' );

		$second = new PromotionBackup( $this->promotion_b );
		$second->store( 'templates:page', array( 'post' => array( 'ID' => 2 ) ) );
		$second->mark_finalized( '2026-01-02T00:00:00Z' );

		$rolled_back = new PromotionBackup( $this->promotion_c );
		$rolled_back->store( 'templates:page', array( 'post' => array( 'ID' => 3 ) ) );
		$rolled_back->mark_finalized( '2026-01-03T00:00:00Z' );
		$rolled_back->mark_settled( 'rolled-back', '2026-01-04T00:00:00Z' );

		$claims = PromotionBackup::later_claims( 'templates:page', '2026-01-01T00:00:00Z', $this->promotion_a );

		self::assertSame( array( $this->promotion_b ), $claims );

		self::assertSame(
			array(),
			PromotionBackup::later_claims( 'templates:page', '2026-01-02T00:00:00Z', $this->promotion_a ),
			'A claim finalized at exactly the given timestamp is not later, and a rolled-back promotion never counts.'
		);
	}

	/**
	 * Restore must fail loudly on a partially-written backup, never return
	 * partial content — a truncated backup restored silently would destroy
	 * the record's rollback path.
	 */
	public function test_retrieve_fails_loudly_when_a_chunk_is_missing(): void {
		$backup  = new PromotionBackup( $this->promotion_a );
		$payload = array(
			'post' => array(
				'ID'         => 5,
				'post_title' => str_repeat( 'z', 2500 ),
			),
		);

		$backup->store( 'templates:page', $payload );

		delete_option( $this->chunk_option_name( $this->promotion_a, 'templates:page', 0 ) );

		$exception = $this->assert_exit_code( 1, fn() => $backup->retrieve( 'templates:page' ) );

		self::assertStringContainsString( 'corrupt', $exception->getMessage() );
		self::assertStringContainsString( 'templates:page', $exception->getMessage() );
	}

	public function test_retrieve_fails_loudly_when_a_chunk_has_been_corrupted(): void {
		$backup  = new PromotionBackup( $this->promotion_a );
		$payload = array(
			'post' => array(
				'ID'         => 5,
				'post_title' => str_repeat( 'w', 2500 ),
			),
		);

		$backup->store( 'templates:page', $payload );

		update_option( $this->chunk_option_name( $this->promotion_a, 'templates:page', 0 ), 'corrupted bytes', false );

		$this->assert_exit_code( 1, fn() => $backup->retrieve( 'templates:page' ) );
	}

	/**
	 * The sha256 in the meta is the ONLY guard that catches a corrupt chunk
	 * whose corruption still parses as valid JSON — the json_decode guard
	 * cannot. Two structurally identical payloads chunk at the same
	 * boundaries, so swapping in the last chunk of a foreign backup yields
	 * a still-valid document whose bytes differ from what the meta
	 * promises; retrieve() must refuse it loudly, never return the mixed
	 * content.
	 */
	public function test_retrieve_fails_loudly_when_a_chunk_holds_foreign_but_valid_json(): void {
		$payload_a = array(
			'post' => array(
				'ID'         => 5,
				'post_title' => str_repeat( 'a', 2500 ),
			),
		);
		$payload_b = array(
			'post' => array(
				'ID'         => 5,
				'post_title' => str_repeat( 'b', 2500 ),
			),
		);

		( new PromotionBackup( $this->promotion_a ) )->store( 'templates:page', $payload_a );
		( new PromotionBackup( $this->promotion_b ) )->store( 'template-parts:site-header', $payload_b );

		$foreign_chunk = get_option( $this->chunk_option_name( $this->promotion_b, 'template-parts:site-header', 2 ) );

		self::assertIsString( $foreign_chunk, 'The fixture must chunk into at least three chunks for the swap to be meaningful.' );

		update_option( $this->chunk_option_name( $this->promotion_a, 'templates:page', 2 ), $foreign_chunk, false );

		$exception = $this->assert_exit_code( 1, fn() => ( new PromotionBackup( $this->promotion_a ) )->retrieve( 'templates:page' ) );

		self::assertStringContainsString( 'corrupt', $exception->getMessage() );
	}

	/**
	 * A bounded review proved store() reported SUCCESS for a partial backup: it
	 * ignored every add_option() result, so an orphan chunk left by an
	 * interrupted store made one write fail while the meta row still claimed a
	 * complete backup. Its probe printed
	 *
	 *   store=success
	 *   retrieve=PromotionException:1:Backup ... is corrupt.
	 *
	 * The failure surfaced at RESTORE — the one moment the backup is the only
	 * remaining copy of the customer's content. store() must fail loudly and
	 * leave nothing behind.
	 */
	public function test_store_refuses_a_partial_write_and_keeps_no_partial_backup(): void {
		$promotion_id = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
		$record_key   = 'templates:page';

		putenv( 'AGENCY_PROMOTION_BACKUP_CHUNK_BYTES=100' );

		// The debris an interrupted store leaves behind: chunk 1 already exists,
		// so add_option() rejects that index while chunk 0 succeeds.
		add_option( $this->chunk_option_name( $promotion_id, $record_key, 1 ), 'orphan', '', false );

		try {
			$backup = new PromotionBackup( $promotion_id );

			$this->assert_exit_code(
				1,
				static fn() => $backup->store( $record_key, array( 'markup' => str_repeat( 'x', 400 ) ) )
			);

			self::assertFalse(
				get_option( $this->meta_option_name( $promotion_id, $record_key ), false ),
				'A failed store must not leave a metadata row claiming a complete backup.'
			);
			self::assertFalse(
				get_option( $this->chunk_option_name( $promotion_id, $record_key, 0 ), false ),
				'A failed store must remove the chunks it wrote before the failure.'
			);
		} finally {
			putenv( 'AGENCY_PROMOTION_BACKUP_CHUNK_BYTES' );
			delete_option( $this->chunk_option_name( $promotion_id, $record_key, 1 ) );
		}
	}

	/**
	 * Asserts that the operation refuses with exactly the expected exit code,
	 * and returns the exception so the caller can also assert the message.
	 * Fails when no exception is thrown, when a non-PromotionException
	 * escapes, or when the code differs.
	 *
	 * @param callable():void $operation
	 */
	private function assert_exit_code( int $expected, callable $operation ): PromotionException {
		try {
			$operation();
		} catch ( PromotionException $exception ) {
			self::assertSame(
				$expected,
				$exception->exit_code(),
				sprintf( 'Expected exit code %d, got %d: %s', $expected, $exception->exit_code(), $exception->getMessage() )
			);

			return $exception;
		}

		self::fail( sprintf( 'Expected a PromotionException with exit code %d, but no exception was thrown.', $expected ) );
	}

	private function option_prefix( string $promotion_id, string $record_key ): string {
		return PromotionBackup::OPTION_PREFIX . $promotion_id . '_' . hash( 'sha256', $record_key );
	}

	private function meta_option_name( string $promotion_id, string $record_key ): string {
		return $this->option_prefix( $promotion_id, $record_key ) . '_meta';
	}

	private function chunk_option_name( string $promotion_id, string $record_key, int $index ): string {
		return $this->option_prefix( $promotion_id, $record_key ) . '_c' . str_pad( (string) $index, 4, '0', STR_PAD_LEFT );
	}

	/**
	 * The autoload column of one option row, read directly: the point is to
	 * prove what is actually stored, not what the write call was asked to do.
	 */
	private function option_autoload( string $option_name ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test-only autoload probe; the option name is bound and the query is read-only.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );
	}

	/**
	 * Removes an integration fixture directory and everything inside it.
	 */
	private function remove_tree( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		foreach ( scandir( $directory ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $directory . '/' . $entry;

			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_tree( $path );

				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- deleting an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		rmdir( $directory );
	}
}
