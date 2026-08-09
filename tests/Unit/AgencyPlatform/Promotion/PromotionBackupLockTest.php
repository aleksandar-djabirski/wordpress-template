<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\PromotionBackup;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionExitCode;
use PHPUnit\Framework\TestCase;

/**
 * The backup index delete path must use the same mutex as every other index
 * mutation. The test seam avoids declaring a WordPress class or function in
 * the unit suite, which does not boot WordPress.
 *
 * @covers \AgencyPlatform\State\Promotion\PromotionBackup
 */
final class PromotionBackupLockTest extends TestCase {

	protected function tearDown(): void {
		PromotionBackup::override_index_mutex( null );

		parent::tearDown();
	}

	public function test_delete_path_acquires_and_releases_the_shared_index_mutex(): void {
		$events = array();

		PromotionBackup::override_index_mutex(
			static function ( callable $mutation ) use ( &$events ): void {
				$events[] = 'acquired';

				try {
					unset( $mutation );
					$events[] = 'mutation-ready';
				} finally {
					$events[] = 'released';
				}
			}
		);

		$this->invoke_delete_backup( 'not-a-uuid', array() );

		self::assertSame( array( 'acquired', 'mutation-ready', 'released' ), $events );
	}

	public function test_delete_path_fails_closed_when_the_shared_index_mutex_cannot_be_won(): void {
		PromotionBackup::override_index_mutex(
			static function ( callable $mutation ): void {
				unset( $mutation );

				throw PromotionException::lock_conflict( 'test lock conflict' );
			}
		);

		try {
			$this->invoke_delete_backup( 'not-a-uuid', array() );
			self::fail( 'The delete path must refuse to continue after a lock conflict.' );
		} catch ( PromotionException $exception ) {
			self::assertSame( PromotionExitCode::LOCK_CONFLICT, $exception->exit_code() );
			self::assertSame( 'test lock conflict', $exception->getMessage() );
		}
	}

	/**
	 * @param list<string> $record_keys
	 */
	private function invoke_delete_backup( string $promotion_id, array $record_keys ): void {
		$method = ( new \ReflectionClass( PromotionBackup::class ) )->getMethod( 'delete_backup' );
		$method->setAccessible( true );
		$method->invoke( null, $promotion_id, $record_keys );
	}
}
