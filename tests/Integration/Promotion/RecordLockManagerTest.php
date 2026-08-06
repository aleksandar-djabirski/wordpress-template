<?php
/**
 * Per-record-key promotion locking (BLOCK_THEME_PROPOSAL.md §7.10). The
 * atomic gate is WP_Upgrader::create_lock()/release_lock() — an INSERT
 * IGNORE with a release timeout — because add_option() is check-then-act
 * and genuinely races; the gate's absence fails CLOSED (exit 3), never a
 * degraded racy add_option(). Every gate/metadata mutation additionally
 * runs inside a short per-key mutation mutex, which is what stops an
 * abandoned heartbeat from writing over a lock another promotion has just
 * reclaimed.
 *
 * The properties that matter: exclusion (a second acquire on the same key
 * fails with exit 3 while the first holds), independence (two DIFFERENT
 * keys must lock independently), reclaimability (a lock older than its TTL
 * is reclaimable, replacing the stale metadata), re-entry by the same
 * promotion, heartbeat extending a live lock but never stealing a
 * reclaimed one, and release touching only locks this promotion owns.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionExitCode;
use AgencyPlatform\State\Promotion\RecordLockManager;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\RecordLockManager
 */
// putenv() drives AGENCY_PROMOTION_LOCK_TTL (the lock TTL override the
// reclaim/heartbeat tests need) through EnvironmentConfig's
// process-environment fallback; WordPress's discouraged-function sniff
// would otherwise flag every call.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class RecordLockManagerTest extends IntegrationTestCase {

	private string $promotion_id;

	public function set_up(): void {
		parent::set_up();

		$this->promotion_id = wp_generate_uuid4();
	}

	public function tear_down(): void {
		putenv( 'AGENCY_PROMOTION_LOCK_TTL' );
		putenv( 'AGENCY_PROMOTION_MUTEX_TTL' );

		RecordLockManager::override_core_lock_api_availability( null );

		parent::tear_down();
	}

	public function test_locks_are_acquired_in_sorted_key_order(): void {
		( new RecordLockManager( $this->promotion_id, 'deploy-1' ) )
			->acquire( array( 'templates:page', 'template-parts:site-header' ) );

		$other = new RecordLockManager( wp_generate_uuid4(), 'deploy-2' );

		$this->assert_exit_code( 3, fn() => $other->acquire( array( 'template-parts:site-header' ) ) );
	}

	public function test_a_conflicting_attempt_releases_its_own_locks_before_exiting_three(): void {
		( new RecordLockManager( wp_generate_uuid4(), 'deploy-1' ) )->acquire( array( 'templates:page' ) );

		$second = new RecordLockManager( wp_generate_uuid4(), 'deploy-2' );
		$this->assert_exit_code( 3, fn() => $second->acquire( array( 'template-parts:site-header', 'templates:page' ) ) );

		self::assertNull( $second->inspect( 'template-parts:site-header' ) );
	}

	public function test_the_same_promotion_re_enters_its_own_locks(): void {
		$manager = new RecordLockManager( $this->promotion_id, 'deploy-1' );
		$manager->acquire( array( 'templates:page' ) );
		$manager->acquire( array( 'templates:page' ) );

		self::assertSame( $this->promotion_id, $manager->inspect( 'templates:page' )['promotionId'] );
	}

	public function test_an_expired_lock_is_reclaimable_and_its_stale_metadata_is_replaced(): void {
		putenv( 'AGENCY_PROMOTION_LOCK_TTL=1' );
		( new RecordLockManager( wp_generate_uuid4(), 'abandoned' ) )->acquire( array( 'templates:page' ) );
		$this->age_lock( 'templates:page', 5 );

		$fresh = new RecordLockManager( $this->promotion_id, 'deploy-2' );
		$fresh->acquire( array( 'templates:page' ) );

		self::assertSame( $this->promotion_id, $fresh->inspect( 'templates:page' )['promotionId'] );
	}

	public function test_an_abandoned_heartbeat_cannot_steal_a_reclaimed_lock(): void {
		// The race the mutex closes: the old owner reads its metadata, a new
		// promotion reclaims the expired gate, and the old heartbeat writes back.
		putenv( 'AGENCY_PROMOTION_LOCK_TTL=1' );
		$old = new RecordLockManager( wp_generate_uuid4(), 'abandoned' );
		$old->acquire( array( 'templates:page' ) );
		$this->age_lock( 'templates:page', 5 );

		$new = new RecordLockManager( $this->promotion_id, 'deploy-2' );
		$new->acquire( array( 'templates:page' ) );

		$this->assert_exit_code( 3, fn() => $old->heartbeat( array( 'templates:page' ) ) );
		self::assertSame( $this->promotion_id, $new->inspect( 'templates:page' )['promotionId'] );
	}

	public function test_heartbeat_refreshes_a_held_lock(): void {
		putenv( 'AGENCY_PROMOTION_LOCK_TTL=60' );
		$manager = new RecordLockManager( $this->promotion_id, 'deploy-1' );
		$manager->acquire( array( 'templates:page' ) );
		$before = $manager->inspect( 'templates:page' )['expiresAtUtc'];
		$this->age_lock( 'templates:page', 30 );

		// expiresAtUtc has one-second resolution; without a second boundary
		// the refreshed expiry would equal the original and the refresh
		// would be invisible.
		sleep( 1 );

		$manager->heartbeat( array( 'templates:page' ) );

		self::assertGreaterThan( $before, $manager->inspect( 'templates:page' )['expiresAtUtc'] );
	}

	public function test_release_removes_both_options_and_never_touches_another_owner(): void {
		$mine = new RecordLockManager( $this->promotion_id, 'deploy-1' );
		$mine->acquire( array( 'templates:page' ) );

		$stranger = new RecordLockManager( wp_generate_uuid4(), 'deploy-2' );
		$stranger->release( array( 'templates:page' ) );

		self::assertSame( $this->promotion_id, $mine->inspect( 'templates:page' )['promotionId'] );

		$mine->release( array( 'templates:page' ) );

		self::assertNull( $mine->inspect( 'templates:page' ) );
		self::assertFalse( get_option( RecordLockManager::option_name( 'templates:page' ) . '.lock' ) );

		$mine->release( array( 'templates:page' ) );

		self::assertNull( $mine->inspect( 'templates:page' ), 'Release must be safe to call twice.' );
	}

	public function test_a_missing_core_lock_api_fails_closed(): void {
		RecordLockManager::override_core_lock_api_availability( false );

		try {
			$manager = new RecordLockManager( $this->promotion_id, 'deploy-1' );

			$this->assert_exit_code( 3, fn() => $manager->acquire( array( 'templates:page' ) ) );

			self::assertNull( $manager->inspect( 'templates:page' ), 'A fail-closed acquire must create no lock record.' );
			self::assertFalse(
				get_option( RecordLockManager::option_name( 'templates:page' ) . '.lock' ),
				'A fail-closed acquire must create no gate option.'
			);
		} finally {
			RecordLockManager::override_core_lock_api_availability( null );
		}
	}

	/**
	 * Two DIFFERENT record keys must lock independently: acquiring one must
	 * not block the other. Without this a global lock would pass every
	 * other test in this file.
	 */
	public function test_two_different_record_keys_lock_independently(): void {
		$first_promotion_id  = wp_generate_uuid4();
		$second_promotion_id = wp_generate_uuid4();

		$first = new RecordLockManager( $first_promotion_id, 'deploy-1' );
		$first->acquire( array( 'templates:page' ) );

		$second = new RecordLockManager( $second_promotion_id, 'deploy-2' );
		$second->acquire( array( 'template-parts:site-header' ) );

		self::assertSame( $first_promotion_id, $first->inspect( 'templates:page' )['promotionId'] );
		self::assertSame( $second_promotion_id, $second->inspect( 'template-parts:site-header' )['promotionId'] );
	}

	/**
	 * PromotionSettings::integer() accepts "0" because ctype_digit('0') is
	 * true, so AGENCY_PROMOTION_LOCK_TTL=0 would otherwise make every lock
	 * instantly reclaimable. The deliberate decision: treat 0 as invalid and
	 * fall back to the default, so a misconfigured TTL keeps the lock
	 * effective instead of silently disabling it.
	 */
	public function test_a_zero_lock_ttl_falls_back_to_the_default_and_keeps_the_lock_effective(): void {
		putenv( 'AGENCY_PROMOTION_LOCK_TTL=0' );

		try {
			( new RecordLockManager( $this->promotion_id, 'deploy-1' ) )->acquire( array( 'templates:page' ) );

			$metadata = get_option( RecordLockManager::option_name( 'templates:page' ) );

			self::assertIsArray( $metadata );

			$expires_at = strtotime( (string) ( $metadata['expiresAtUtc'] ?? '' ) );

			self::assertNotFalse( $expires_at );
			self::assertGreaterThan(
				time() + 100,
				$expires_at,
				'A zero TTL must fall back to the default, not shorten the lock to nothing.'
			);
		} finally {
			putenv( 'AGENCY_PROMOTION_LOCK_TTL' );
		}
	}

	/**
	 * Ages the gate option's timestamp backwards by $seconds, simulating a
	 * lock that has outlived its TTL without a heartbeat.
	 */
	private function age_lock( string $record_key, int $seconds ): void {
		update_option( RecordLockManager::option_name( $record_key ) . '.lock', time() - $seconds, false );
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
}
