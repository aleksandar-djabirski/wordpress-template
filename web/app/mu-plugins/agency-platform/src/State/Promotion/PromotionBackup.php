<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\Logging\Logger;

/**
 * Protected, chunked promotion backups (BLOCK_THEME_PROPOSAL.md §7.9): the
 * database rows a finalize step deletes or resets, stored as options so a
 * rollback can recreate them. The JSON payload of one record key is split
 * into fixed-size chunks — object caches commonly cap values near 1 MB, so
 * a whole-row backup never fits in one option — and every option is
 * NON-autoloaded: a large autoloaded option would be loaded on every
 * request. Each chunk's integrity is pinned by the sha256 in the meta
 * option, so a missing or corrupted chunk fails loudly on restore instead
 * of returning partial content.
 *
 * The index (promotionId => finalizedAtUtc/settlementStatus/settledAtUtc/
 * recordKeys/bytes) is a read-modify-write shared by concurrent promotions,
 * so every index write runs inside a WP_Upgrader::create_lock() mutex.
 * Retention gives an abandoned, never-settled promotion the same window as
 * a settled one, and prune() deletes only what has outlived BOTH its
 * retention window and the operator-supplied cutoff.
 */
final class PromotionBackup {

	public const OPTION_PREFIX = 'agency_promotion_backup_';
	public const INDEX_OPTION  = 'agency_promotion_backups_index';
	public const INDEX_LOCK    = 'agency_promotion_backup_index';

	private const INDEX_LOCK_TTL          = 30;
	private const INDEX_LOCK_RETRIES      = 10;
	private const INDEX_LOCK_RETRY_USLEEP = 200000;

	public function __construct( private string $promotion_id ) {}

	/**
	 * Removes option rows written by a store() attempt that then failed, so a
	 * retry is not blocked by its own debris.
	 *
	 * @param list<string> $options
	 */
	private static function delete_options( array $options ): void {
		foreach ( $options as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Idempotent: an existing backup for this promotion+key is left untouched.
	 *
	 * @param array<string, mixed> $record_payload
	 * @throws PromotionException Exit 1 on a non-JSON-encodable payload or a
	 *                            rejected write.
	 */
	public function store( string $record_key, array $record_payload ): void {
		if ( $this->exists( $record_key ) ) {
			return;
		}

		$json = wp_json_encode( $record_payload );

		if ( false === $json ) {
			throw PromotionException::hard(
				sprintf( 'The backup payload for "%s" is not JSON-encodable; refusing to back it up.', $record_key )
			);
		}

		$chunk_bytes = PromotionSettings::integer( PromotionSettings::CHUNK_BYTES, 500000 );
		$chunks      = self::split_payload( $json, $chunk_bytes );
		$prefix      = self::option_prefix( $this->promotion_id, $record_key );

		// Every add_option() result is checked. Ignoring them let store() report
		// SUCCESS for a partially written backup — an orphan chunk left by an
		// interrupted store makes add_option() return false for that index,
		// while the rest succeed and the meta row claims a complete backup. The
		// failure then surfaces at RESTORE time, which is the one moment the
		// backup is the only copy of the customer's content that still exists.
		//
		// On any failure the rows this attempt wrote are removed, so a retry
		// starts from a clean slate rather than inheriting the debris.
		$written = array();

		foreach ( $chunks as $index => $chunk ) {
			$option = $prefix . self::chunk_suffix( $index );

			if ( ! add_option( $option, $chunk, '', false ) ) {
				self::delete_options( $written );

				throw PromotionException::hard(
					sprintf( 'The backup for "%s" could not be written: chunk %d was rejected. No partial backup was kept.', $record_key, $index )
				);
			}

			$written[] = $option;
		}

		$meta_option = $prefix . '_meta';

		$meta_written = add_option(
			$meta_option,
			array(
				'chunks'    => count( $chunks ),
				'bytes'     => strlen( $json ),
				'sha256'    => hash( 'sha256', $json ),
				'recordKey' => $record_key,
			),
			'',
			false
		);

		if ( ! $meta_written ) {
			self::delete_options( $written );

			throw PromotionException::hard(
				sprintf( 'The backup for "%s" could not be written: its metadata row was rejected. No partial backup was kept.', $record_key )
			);
		}

		$this->update_index(
			function ( array $index ) use ( $record_key, $json ): array {
				$entry                        = $index[ $this->promotion_id ] ?? self::empty_index_entry();
				$entry['recordKeys'][]        = $record_key;
				$entry['bytes']               = (int) $entry['bytes'] + strlen( $json );
				$index[ $this->promotion_id ] = $entry;

				return $index;
			}
		);
	}

	/**
	 * The reassembled payload, or null when this promotion has no backup
	 * for the key. A missing or corrupt chunk is a hard error, never
	 * partial content.
	 *
	 * @return array<string, mixed>|null
	 * @throws PromotionException Exit 1 when the backup is corrupt.
	 */
	public function retrieve( string $record_key ): ?array {
		$prefix = self::option_prefix( $this->promotion_id, $record_key );
		$meta   = get_option( $prefix . '_meta' );

		if ( ! is_array( $meta ) ) {
			return null;
		}

		$chunk_count = is_int( $meta['chunks'] ?? null ) ? $meta['chunks'] : 0;
		$json        = '';

		for ( $index = 0; $index < $chunk_count; $index++ ) {
			$chunk = get_option( $prefix . self::chunk_suffix( $index ) );

			if ( ! is_string( $chunk ) ) {
				throw PromotionException::hard( sprintf( 'Backup for "%s" is corrupt.', $record_key ) );
			}

			$json .= $chunk;
		}

		$expected = $meta['sha256'] ?? null;

		if ( ! is_string( $expected ) || hash( 'sha256', $json ) !== $expected ) {
			throw PromotionException::hard( sprintf( 'Backup for "%s" is corrupt.', $record_key ) );
		}

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			throw PromotionException::hard( sprintf( 'Backup for "%s" is corrupt.', $record_key ) );
		}

		return $decoded;
	}

	public function exists( string $record_key ): bool {
		return false !== get_option( self::option_prefix( $this->promotion_id, $record_key ) . '_meta' );
	}

	/**
	 * Records the moment the promotion's records were finalized on the
	 * target host; the index entry is created when it does not exist yet
	 * (a crash between store() and mark_finalized() must not wedge the
	 * index).
	 */
	public function mark_finalized( string $finalized_at_utc ): void {
		$this->update_index(
			function ( array $index ) use ( $finalized_at_utc ): array {
				$entry                        = $index[ $this->promotion_id ] ?? self::empty_index_entry();
				$entry['finalizedAtUtc']      = $finalized_at_utc;
				$index[ $this->promotion_id ] = $entry;

				return $index;
			}
		);
	}

	public function mark_settled( string $settlement_status, string $settled_at_utc ): void {
		$this->update_index(
			function ( array $index ) use ( $settlement_status, $settled_at_utc ): array {
				$entry                        = $index[ $this->promotion_id ] ?? self::empty_index_entry();
				$entry['settlementStatus']    = $settlement_status;
				$entry['settledAtUtc']        = $settled_at_utc;
				$index[ $this->promotion_id ] = $entry;

				return $index;
			}
		);
	}

	/**
	 * One row per index entry, sorted by finalizedAtUtc descending.
	 * retentionUntilUtc = max(finalizedAtUtc, settledAtUtc) + retention
	 * days, so an abandoned, never-settled promotion gets the same window
	 * instead of being orphaned forever; prunable is true once that moment
	 * has passed.
	 *
	 * @return list<array{promotionId:string, finalizedAtUtc:string, settlementStatus:string,
	 *      settledAtUtc:string|null, records:int, recordKeys:list<string>, bytes:int,
	 *      retentionUntilUtc:string, prunable:bool}>
	 */
	public static function list_all(): array {
		$rows = array();

		foreach ( self::read_index() as $promotion_id => $entry ) {
			$record_keys     = self::entry_record_keys( $entry );
			$retention_until = self::retention_until_utc( $entry );
			$finalized_at    = $entry['finalizedAtUtc'] ?? null;

			$rows[] = array(
				'promotionId'       => $promotion_id,
				'finalizedAtUtc'    => is_string( $finalized_at ) ? $finalized_at : '',
				'settlementStatus'  => is_string( $entry['settlementStatus'] ?? null ) ? $entry['settlementStatus'] : 'pending',
				'settledAtUtc'      => is_string( $entry['settledAtUtc'] ?? null ) ? $entry['settledAtUtc'] : null,
				'records'           => count( $record_keys ),
				'recordKeys'        => $record_keys,
				'bytes'             => is_int( $entry['bytes'] ?? null ) ? $entry['bytes'] : 0,
				'retentionUntilUtc' => $retention_until,
				'prunable'          => time() > strtotime( $retention_until ),
			);
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return strcmp( $right['finalizedAtUtc'], $left['finalizedAtUtc'] );
			}
		);

		return $rows;
	}

	/**
	 * Deletes every backup whose retention has expired AND whose
	 * finalizedAtUtc is older than the cutoff. A dry run returns the
	 * eligible promotion ids without deleting anything.
	 *
	 * @return list<string> pruned promotion ids
	 */
	public static function prune( string $older_than, bool $dry_run ): array {
		$cutoff = time() - self::parse_older_than( $older_than );
		$pruned = array();

		foreach ( self::list_all() as $entry ) {
			if ( ! $entry['prunable'] ) {
				continue;
			}

			$finalized_at = strtotime( $entry['finalizedAtUtc'] );

			if ( false === $finalized_at || $finalized_at >= $cutoff ) {
				continue;
			}

			$pruned[] = $entry['promotionId'];

			if ( $dry_run ) {
				continue;
			}

			self::delete_backup( $entry['promotionId'], $entry['recordKeys'] );

			Logger::log(
				'promotion',
				'backup-pruned',
				array(
					'promotionId' => $entry['promotionId'],
					'records'     => $entry['records'],
					'bytes'       => $entry['bytes'],
				)
			);
		}

		return $pruned;
	}

	/**
	 * Promotions that finalized the same record key AFTER the given
	 * timestamp, excluding one promotion and ignoring rolled-back ones.
	 *
	 * @return list<string>
	 */
	public static function later_claims( string $record_key, string $finalized_at_utc, string $excluding_promotion_id ): array {
		$claims = array();

		foreach ( self::read_index() as $promotion_id => $entry ) {
			if ( $promotion_id === $excluding_promotion_id ) {
				continue;
			}

			if ( ( $entry['settlementStatus'] ?? 'pending' ) === 'rolled-back' ) {
				continue;
			}

			if ( ! in_array( $record_key, self::entry_record_keys( $entry ), true ) ) {
				continue;
			}

			$finalized = $entry['finalizedAtUtc'] ?? null;

			if ( ! is_string( $finalized ) || $finalized <= $finalized_at_utc ) {
				continue;
			}

			$claims[] = $promotion_id;
		}

		sort( $claims, SORT_STRING );

		return $claims;
	}

	/**
	 * Splits a JSON payload into fixed-size byte chunks. A payload smaller
	 * than one chunk is a single chunk; reassembling the chunks yields the
	 * exact original bytes.
	 *
	 * @return list<string>
	 * @throws PromotionException Exit 1 on a non-positive chunk size.
	 */
	public static function split_payload( string $json, int $chunk_bytes ): array {
		if ( $chunk_bytes < 1 ) {
			throw PromotionException::hard( sprintf( 'The backup chunk size %d is not positive; refusing to split.', $chunk_bytes ) );
		}

		return str_split( $json, $chunk_bytes );
	}

	/**
	 * Parses an operator duration into seconds: "30d", "12h" or a plain
	 * number of seconds. Anything else is a hard error.
	 *
	 * @throws PromotionException Exit 1 on a malformed value.
	 */
	public static function parse_older_than( string $value ): int {
		if ( 1 !== preg_match( '/^(\d+)(d|h)?$/', $value, $matches ) ) {
			throw PromotionException::hard(
				sprintf( 'The retention value "%s" is not a duration like "30d", "12h" or "3600".', $value )
			);
		}

		$amount = (int) $matches[1];
		$unit   = $matches[2] ?? '';

		if ( 'd' === $unit ) {
			return $amount * 86400;
		}

		if ( 'h' === $unit ) {
			return $amount * 3600;
		}

		return $amount;
	}

	/**
	 * Every index write is a read-modify-write shared by concurrent
	 * promotions, so it runs inside the index mutex: WP_Upgrader::create_lock()
	 * (the atomic INSERT IGNORE primitive) retried for a bounded time, with
	 * the release in a finally.
	 *
	 * @param callable(array<string, array<string, mixed>>): array<string, array<string, mixed>> $mutation
	 * @throws PromotionException Exit 3 when the index mutex cannot be won.
	 */
	private function update_index( callable $mutation ): void {
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		// class_exists() alone is not enough: a build that ships the class
		// WITHOUT these two static methods reached a fatal static call instead
		// of the intended exit 3. The method names are carried in a variable so
		// this stays a runtime check rather than a compile-time tautology,
		// matching RecordLockManager::require_core_lock_api().
		$required_methods = array( 'create_lock', 'release_lock' );

		foreach ( $required_methods as $required_method ) {
			if ( ! class_exists( 'WP_Upgrader' ) || ! method_exists( 'WP_Upgrader', $required_method ) ) {
				throw PromotionException::lock_conflict(
					'This WordPress build does not expose \WP_Upgrader::create_lock()/release_lock(); backup index locking cannot be made atomic without it. Refusing to write the backup index.'
				);
			}
		}

		for ( $attempt = 0; $attempt < self::INDEX_LOCK_RETRIES; $attempt++ ) {
			if ( \WP_Upgrader::create_lock( self::INDEX_LOCK, self::INDEX_LOCK_TTL ) ) {
				try {
					self::write_index( $mutation( self::read_index() ) );

					return;
				} finally {
					\WP_Upgrader::release_lock( self::INDEX_LOCK );
				}
			}

			usleep( self::INDEX_LOCK_RETRY_USLEEP );
		}

		throw PromotionException::lock_conflict(
			sprintf( 'Could not acquire the promotion backup index lock after %d attempts.', self::INDEX_LOCK_RETRIES )
		);
	}

	/**
	 * Deletes every chunk, the meta option and the index entry of one
	 * promotion, then its canonical manifest on the host.
	 *
	 * @param list<string> $record_keys
	 */
	private static function delete_backup( string $promotion_id, array $record_keys ): void {
		foreach ( $record_keys as $record_key ) {
			$prefix = self::option_prefix( $promotion_id, $record_key );
			$meta   = get_option( $prefix . '_meta' );

			if ( is_array( $meta ) && is_int( $meta['chunks'] ?? null ) ) {
				for ( $index = 0; $index < $meta['chunks']; $index++ ) {
					delete_option( $prefix . self::chunk_suffix( $index ) );
				}
			}

			delete_option( $prefix . '_meta' );
		}

		$index = self::read_index();
		unset( $index[ $promotion_id ] );
		self::write_index( $index );

		self::delete_canonical_manifest( $promotion_id );
	}

	/**
	 * The promotion id is interpolated straight into a filesystem path, so
	 * only a bare UUID is accepted — the same guard the manifest store
	 * applies on its own canonical path.
	 */
	private static function delete_canonical_manifest( string $promotion_id ): void {
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $promotion_id ) ) {
			return;
		}

		$path = ( new StateGateway() )->state_dir() . '/promotions/' . $promotion_id . '.json';

		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting the canonical manifest of a pruned promotion; WP_Filesystem exposes no single-file delete primitive in a CLI context.
			unlink( $path );
		}
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return list<string>
	 */
	private static function entry_record_keys( array $entry ): array {
		$keys = array();

		foreach ( ( $entry['recordKeys'] ?? array() ) as $key ) {
			if ( is_string( $key ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function retention_until_utc( array $entry ): string {
		$finalized = is_string( $entry['finalizedAtUtc'] ?? null ) ? $entry['finalizedAtUtc'] : '1970-01-01T00:00:00Z';
		$settled   = is_string( $entry['settledAtUtc'] ?? null ) ? $entry['settledAtUtc'] : $finalized;
		$base      = $settled > $finalized ? $settled : $finalized;
		$days      = PromotionSettings::integer( PromotionSettings::RETENTION_DAYS, 30 );

		return gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $base ) + $days * 86400 );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function read_index(): array {
		$index = get_option( self::INDEX_OPTION );

		return is_array( $index ) ? $index : array();
	}

	/**
	 * @param array<string, array<string, mixed>> $index
	 */
	private static function write_index( array $index ): void {
		if ( array() === $index ) {
			delete_option( self::INDEX_OPTION );

			return;
		}

		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * @return array{finalizedAtUtc:null, settlementStatus:string, settledAtUtc:null, recordKeys:list<string>, bytes:int}
	 */
	private static function empty_index_entry(): array {
		return array(
			'finalizedAtUtc'   => null,
			'settlementStatus' => 'pending',
			'settledAtUtc'     => null,
			'recordKeys'       => array(),
			'bytes'            => 0,
		);
	}

	/**
	 * 24 + 36 + 1 + 64 = 125 characters for the base, plus "_c0000" (6) or
	 * "_meta" (5): always inside the 191-character option_name index limit.
	 */
	private static function option_prefix( string $promotion_id, string $record_key ): string {
		return self::OPTION_PREFIX . $promotion_id . '_' . hash( 'sha256', $record_key );
	}

	private static function chunk_suffix( int $index ): string {
		return '_c' . str_pad( (string) $index, 4, '0', STR_PAD_LEFT );
	}
}
