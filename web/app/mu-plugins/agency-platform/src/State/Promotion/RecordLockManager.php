<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * Per-record-key promotion locking (BLOCK_THEME_PROPOSAL.md §7.10): one
 * promotion at a time per canonical "provider:slug" record key. Three
 * options are involved per key — the atomic gate created by
 * \WP_Upgrader::create_lock() (an INSERT IGNORE with a release timeout, the
 * only non-racy primitive WordPress exposes for this; add_option() is
 * check-then-act), the §7.10 lock record (non-autoloaded), and a short
 * mutation mutex held ONLY while gate and metadata change together.
 *
 * The mutex is what makes the reclaim path safe: an abandoned heartbeat
 * re-reads the metadata INSIDE the mutex, sees a foreign promotionId and
 * aborts instead of writing over a lock another promotion has just
 * reclaimed. The gate's absence fails CLOSED — exit 3, never a degraded
 * racy add_option().
 */
final class RecordLockManager {

	public const OPTION_PREFIX = 'agency_promotion_lock_';
	public const MUTEX_PREFIX  = 'agency_promotion_lock_mutex_';

	private const DEFAULT_LOCK_TTL   = 900;
	private const MUTEX_RETRIES      = 25;
	private const MUTEX_RETRY_USLEEP = 200000;

	/**
	 * Test-only availability override; null defers to the real probe.
	 *
	 * @var bool|null
	 */
	private static ?bool $core_lock_api_available = null;

	public function __construct( private string $promotion_id, private string $owner ) {}

	/**
	 * Sorted acquisition. Re-enters locks this promotion already owns. On
	 * the first conflict, releases every lock acquired by THIS attempt and
	 * throws PromotionException::lock_conflict().
	 *
	 * @param list<string> $record_keys canonical "provider:slug" keys
	 * @throws PromotionException Exit 3 on a lock conflict.
	 */
	public function acquire( array $record_keys ): void {
		$this->require_core_lock_api();

		$keys = array_values( $record_keys );
		sort( $keys, SORT_STRING );

		$ttl      = $this->lock_ttl();
		$acquired = array();

		try {
			foreach ( $keys as $key ) {
				$this->with_mutex(
					$key,
					function () use ( $key, $ttl, &$acquired ): void {
						if ( \WP_Upgrader::create_lock( self::option_name( $key ), $ttl ) ) {
							$this->write_fresh_metadata( $key, $ttl );

							$acquired[] = $key;

							return;
						}

						$metadata = $this->read_metadata( $key );

						if ( null !== $metadata && ( $metadata['promotionId'] ?? null ) === $this->promotion_id ) {
							// Re-entry: refresh the expiry, keep the original
							// startedAtUtc, and do NOT add the key to the
							// attempt's $acquired list — a later conflict must
							// not release a lock this promotion owned before.
							$this->refresh_metadata( $metadata, $ttl );

							return;
						}

						throw PromotionException::lock_conflict(
							sprintf(
								'Record "%s" is locked by promotion %s (owner %s) until %s.',
								$key,
								is_string( $metadata['promotionId'] ?? null ) ? $metadata['promotionId'] : 'unknown',
								is_string( $metadata['owner'] ?? null ) ? $metadata['owner'] : 'unknown',
								is_string( $metadata['expiresAtUtc'] ?? null ) ? $metadata['expiresAtUtc'] : 'unknown'
							)
						);
					}
				);
			}
		} catch ( PromotionException $exception ) {
			if ( PromotionExitCode::LOCK_CONFLICT === $exception->exit_code() ) {
				$this->release( $acquired );
			}

			throw $exception;
		}
	}

	/**
	 * Releases only locks this promotion owns. Safe to call twice.
	 *
	 * @param list<string> $record_keys
	 */
	public function release( array $record_keys ): void {
		$this->require_core_lock_api();

		foreach ( array_values( $record_keys ) as $key ) {
			$this->with_mutex(
				$key,
				function () use ( $key ): void {
					$metadata = $this->read_metadata( $key );

					if ( null === $metadata || ( $metadata['promotionId'] ?? null ) !== $this->promotion_id ) {
						return;
					}

					delete_option( self::option_name( $key ) );
					\WP_Upgrader::release_lock( self::option_name( $key ) );
				}
			);
		}
	}

	/**
	 * Refreshes expiry. Throws lock_conflict() when a lock was lost.
	 *
	 * @param list<string> $record_keys
	 * @throws PromotionException Exit 3 when a lock is no longer this promotion's.
	 */
	public function heartbeat( array $record_keys ): void {
		$this->require_core_lock_api();

		$ttl = $this->lock_ttl();

		foreach ( array_values( $record_keys ) as $key ) {
			$this->with_mutex(
				$key,
				function () use ( $key, $ttl ): void {
					$metadata = $this->read_metadata( $key );

					if ( null === $metadata || ( $metadata['promotionId'] ?? null ) !== $this->promotion_id ) {
						throw PromotionException::lock_conflict(
							sprintf( 'Lock for "%s" is no longer held by this promotion; it was reclaimed or released.', $key )
						);
					}

					// Refresh the gate's TTL clock and the metadata expiry
					// together, both inside the mutex, so a concurrent
					// reclaim can never interleave with either write.
					update_option( self::option_name( $key ) . '.lock', time(), false );
					$this->refresh_metadata( $metadata, $ttl );
				}
			);
		}
	}

	/**
	 * The §7.10 lock record for one key, or null when this promotion holds
	 * nothing there.
	 *
	 * @return array{promotionId:string, startedAtUtc:string, owner:string, expiresAtUtc:string, recordKey:string}|null
	 */
	public function inspect( string $record_key ): ?array {
		$metadata = $this->read_metadata( $record_key );

		if ( null === $metadata ) {
			return null;
		}

		return array(
			'promotionId'  => is_string( $metadata['promotionId'] ?? null ) ? $metadata['promotionId'] : '',
			'startedAtUtc' => is_string( $metadata['startedAtUtc'] ?? null ) ? $metadata['startedAtUtc'] : '',
			'owner'        => is_string( $metadata['owner'] ?? null ) ? $metadata['owner'] : '',
			'expiresAtUtc' => is_string( $metadata['expiresAtUtc'] ?? null ) ? $metadata['expiresAtUtc'] : '',
			'recordKey'    => is_string( $metadata['recordKey'] ?? null ) ? $metadata['recordKey'] : '',
		);
	}

	public static function option_name( string $record_key ): string {
		return self::OPTION_PREFIX . hash( 'sha256', $record_key );
	}

	public static function mutex_name( string $record_key ): string {
		return self::MUTEX_PREFIX . hash( 'sha256', $record_key );
	}

	/**
	 * Test-only availability override; null defers to the real probe. The
	 * fail-closed path cannot be reached any other way — the integration
	 * suite boots a WordPress that does expose the API — and the gate's
	 * absence is exactly the branch that must not rot.
	 */
	public static function override_core_lock_api_availability( ?bool $available ): void {
		self::$core_lock_api_available = $available;
	}

	/**
	 * \WP_Upgrader::create_lock() lives in an admin include that a WP-CLI
	 * context never loads, so it is required here when absent. When the
	 * static API is genuinely missing the promotion lifecycle fails CLOSED
	 * with a lock conflict — exit 3 — never a degraded, racy add_option().
	 *
	 * @throws PromotionException Exit 3 when the atomic gate is unavailable.
	 */
	private function require_core_lock_api(): void {
		if ( null !== self::$core_lock_api_available ) {
			if ( ! self::$core_lock_api_available ) {
				throw PromotionException::lock_conflict(
					'This WordPress build does not expose \WP_Upgrader::create_lock()/release_lock(); promotion locking cannot be made atomic without it. Refusing to finalize.'
				);
			}

			return;
		}

		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		// PHPStan knows the PINNED WordPress always defines both methods, but
		// the promotion lifecycle must fail closed on ANY build that lacks
		// them. The method name is carried in a variable so the probe stays a
		// runtime check instead of a compile-time tautology.
		$required_methods = array( 'create_lock', 'release_lock' );

		foreach ( $required_methods as $required_method ) {
			if ( ! method_exists( 'WP_Upgrader', $required_method ) ) {
				throw PromotionException::lock_conflict(
					'This WordPress build does not expose \WP_Upgrader::create_lock()/release_lock(); promotion locking cannot be made atomic without it. Refusing to finalize.'
				);
			}
		}
	}

	/**
	 * PromotionSettings::integer() accepts "0" because ctype_digit('0') is
	 * true, so AGENCY_PROMOTION_LOCK_TTL=0 must NOT silently disable the
	 * lock: a zero value is invalid operator input and falls back to the
	 * default, keeping the lock effective instead of instantly reclaimable.
	 */
	private function lock_ttl(): int {
		$ttl = PromotionSettings::integer( PromotionSettings::LOCK_TTL, self::DEFAULT_LOCK_TTL );

		return $ttl > 0 ? $ttl : self::DEFAULT_LOCK_TTL;
	}

	/**
	 * Every gate/metadata mutation runs inside the per-key mutation mutex:
	 * \WP_Upgrader::create_lock() on the mutex option name, retried for a
	 * bounded time, always released in a finally. This serialises the
	 * read-modify-write pairs (reclaim, heartbeat, release) so an abandoned
	 * heartbeat can never interleave with another promotion's reclaim.
	 *
	 * @param callable():void $mutation
	 * @throws PromotionException Exit 3 when the mutex cannot be won.
	 */
	private function with_mutex( string $record_key, callable $mutation ): void {
		$mutex_name = self::mutex_name( $record_key );
		$mutex_ttl  = PromotionSettings::integer( PromotionSettings::MUTEX_TTL, 30 );

		for ( $attempt = 0; $attempt < self::MUTEX_RETRIES; $attempt++ ) {
			if ( \WP_Upgrader::create_lock( $mutex_name, $mutex_ttl ) ) {
				try {
					$mutation();

					return;
				} finally {
					\WP_Upgrader::release_lock( $mutex_name );
				}
			}

			usleep( self::MUTEX_RETRY_USLEEP );
		}

		throw PromotionException::lock_conflict(
			sprintf( 'Could not acquire the mutation mutex for record "%s" after %d attempts.', $record_key, self::MUTEX_RETRIES )
		);
	}

	/**
	 * Overwrites the lock record with this promotion's, delete-then-add so a
	 * reclaimed gate never keeps the previous owner's metadata. Non-autoloaded.
	 */
	private function write_fresh_metadata( string $record_key, int $ttl ): void {
		$option = self::option_name( $record_key );

		delete_option( $option );
		add_option(
			$option,
			array(
				'promotionId'  => $this->promotion_id,
				'startedAtUtc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'owner'        => $this->owner,
				'expiresAtUtc' => $this->expires_at_utc( $ttl ),
				'recordKey'    => $record_key,
			),
			'',
			false
		);
	}

	/**
	 * Rewrites a lock record's expiry in place, preserving the original
	 * startedAtUtc. Non-autoloaded.
	 *
	 * @param array<string, mixed> $metadata
	 */
	private function refresh_metadata( array $metadata, int $ttl ): void {
		$metadata['expiresAtUtc'] = $this->expires_at_utc( $ttl );

		update_option( self::option_name( (string) ( $metadata['recordKey'] ?? '' ) ), $metadata, false );
	}

	private function expires_at_utc( int $ttl ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', time() + $ttl );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function read_metadata( string $record_key ): ?array {
		$option = get_option( self::option_name( $record_key ) );

		return is_array( $option ) ? $option : null;
	}
}
