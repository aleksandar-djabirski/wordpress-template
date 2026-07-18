<?php
/**
 * Shared failure-message formatter for the architecture suite. Mixed into
 * every tests/Architecture/* test so each assertion failure states, in one
 * consistent shape, all five things a reader (human or agent) needs: which
 * rule broke, the offending file, why the rule exists, where the code should
 * live instead, and how to validate the fix.
 *
 * Required explicitly (not PSR-4 autoloaded) for the same
 * case-sensitivity reason documented in ArchitectureScanner.php.
 *
 * @package Tests\Support
 */

declare(strict_types=1);

namespace Tests\Support;

/**
 * @internal
 */
trait FormatsArchitectureFailures {

	/**
	 * Absolute path to the repository root (this file lives at
	 * tests/support/, two levels below the root).
	 */
	protected function repo_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Converts an absolute path to a forward-slashed, repo-relative one so
	 * failure messages read identically on Windows, macOS, and the Linux CI
	 * containers.
	 */
	protected function to_relative( string $absolute ): string {
		$root     = str_replace( '\\', '/', $this->repo_root() ) . '/';
		$absolute = str_replace( '\\', '/', $absolute );

		return str_starts_with( $absolute, $root )
			? substr( $absolute, strlen( $root ) )
			: $absolute;
	}

	/**
	 * Builds the five-part failure message every architecture assertion uses.
	 */
	protected function architecture_failure(
		string $rule,
		string $file,
		string $why,
		string $where,
		string $how = 'ddev composer test:architecture'
	): string {
		return implode(
			"\n",
			array(
				'',
				'Architecture rule broken: ' . $rule,
				'Offending file:           ' . $file,
				'Why this rule exists:     ' . $why,
				'Where the code belongs:   ' . $where,
				'How to validate the fix:  ' . $how,
				'',
			)
		);
	}
}
