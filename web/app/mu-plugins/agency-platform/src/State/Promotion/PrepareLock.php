<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\StateDirectory;
use AgencyPlatform\State\StateException;

/**
 * The local prepare lock (plan Task 5): one prepare run per repository at a
 * time. The lock is an exclusive flock() on <AGENCY_STATE_DIR>/prepare.lock
 * — the same lock file is shared by every worktree of the repository, so
 * two worktrees preparing the same target commit can never race. flock() is
 * released automatically when the process exits, so a crashed prepare run
 * cannot leave a stale lock behind; an older lock never needs reclaiming.
 */
final class PrepareLock {

	/** @var resource|null */
	private $handle;

	public function __construct( private string $lock_file ) {}

	public static function for_state_dir(): self {
		try {
			return new self( StateDirectory::ensure() . '/prepare.lock' );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * Takes the exclusive lock, creating the lock file when needed. Throws
	 * lock_conflict() (exit 3) when another prepare run already holds it;
	 * any other failure to obtain the file is a hard error.
	 */
	public function acquire(): void {
		wp_mkdir_p( dirname( $this->lock_file ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the prepare lock needs a raw file descriptor: flock() is the atomic primitive, and WP_Filesystem exposes no flock()-capable handle.
		$handle = fopen( $this->lock_file, 'c' );

		if ( false === $handle ) {
			throw PromotionException::hard( sprintf( 'Could not open the prepare lock file "%s".', $this->lock_file ) );
		}

		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the lock file descriptor opened above; WP_Filesystem cannot own a flock() descriptor.
			fclose( $handle );

			throw PromotionException::lock_conflict( sprintf( 'Another prepare run holds %s.', $this->lock_file ) );
		}

		$this->handle = $handle;
	}

	/**
	 * Releases the lock. Safe to call twice, or when acquire() never
	 * succeeded: a released lock has no handle left.
	 */
	public function release(): void {
		if ( null === $this->handle ) {
			return;
		}

		flock( $this->handle, LOCK_UN );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the lock file descriptor; WP_Filesystem cannot own a flock() descriptor.
		fclose( $this->handle );

		$this->handle = null;
	}
}
