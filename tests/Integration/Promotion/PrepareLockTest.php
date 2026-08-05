<?php
/**
 * The local prepare lock (plan Task 5): one prepare run per repository at a
 * time. The lock is a kernel flock() on a lock file, and the load-bearing
 * property is EXCLUSION — a second acquire() from a second instance on the
 * same file must refuse while the first holds it, and must succeed once the
 * first releases. release() must be safe to call twice, so a crash or an
 * early return can never wedge the next prepare run.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\Promotion\PrepareLock;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionExitCode;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\PrepareLock
 */
final class PrepareLockTest extends IntegrationTestCase {

	private string $tmp_dir;
	private string $lock_file;

	public function set_up(): void {
		parent::set_up();

		$this->tmp_dir   = sys_get_temp_dir() . '/prepare-lock-' . uniqid( '', true );
		$this->lock_file = $this->tmp_dir . '/prepare.lock';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->tmp_dir, 0700 );
	}

	public function tear_down(): void {
		$this->remove_tree( $this->tmp_dir );

		parent::tear_down();
	}

	public function test_a_second_instance_cannot_acquire_while_the_first_holds_the_lock(): void {
		$first  = new PrepareLock( $this->lock_file );
		$second = new PrepareLock( $this->lock_file );

		$first->acquire();

		try {
			try {
				$second->acquire();

				self::fail( 'A second acquire() must refuse while the first instance holds the lock.' );
			} catch ( PromotionException $exception ) {
				self::assertSame( PromotionExitCode::LOCK_CONFLICT, $exception->exit_code(), 'The refusal must surface as a lock conflict, exit 3.' );
				self::assertStringContainsString( 'Another prepare run holds', $exception->getMessage() );
				self::assertStringContainsString( $this->lock_file, $exception->getMessage() );
			}

			$first->release();

			$second->acquire();
		} finally {
			$second->release();
			$first->release();
		}
	}

	public function test_release_is_safe_to_call_twice_and_frees_the_lock(): void {
		$lock = new PrepareLock( $this->lock_file );

		$lock->acquire();
		self::assertFileExists( $this->lock_file, 'acquire() must create the lock file.' );

		$lock->release();
		$lock->release();

		$reacquired = new PrepareLock( $this->lock_file );

		$reacquired->acquire();

		try {
			( new PrepareLock( $this->lock_file ) )->acquire();

			self::fail( 'A lock reacquired after a double release must exclude again.' );
		} catch ( PromotionException $exception ) {
			self::assertSame( PromotionExitCode::LOCK_CONFLICT, $exception->exit_code() );
		} finally {
			$reacquired->release();
		}
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
