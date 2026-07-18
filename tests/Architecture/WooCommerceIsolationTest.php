<?php
/**
 * Keeps WooCommerce quarantined. WooCommerce symbols (WooCommerce, WC_*,
 * wc_*, woocommerce_*) may only appear inside the site-commerce plugin and
 * the theme's woocommerce/ overrides, so the base profile runs without
 * WooCommerce installed. Every other location is scanned; the only permitted
 * references outside the commerce plugin are the reviewed entries in
 * tests/Architecture/woocommerce-allowlist.php.
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\ArchitectureScanner;
use Tests\Support\FormatsArchitectureFailures;

require_once dirname( __DIR__ ) . '/support/ArchitectureScanner.php';
require_once dirname( __DIR__ ) . '/support/FormatsArchitectureFailures.php';

final class WooCommerceIsolationTest extends TestCase {

	use FormatsArchitectureFailures;

	private const PATTERNS = array(
		'/\bWooCommerce\b/',
		'/\bWC_[A-Za-z]/',
		'/\bwc_[a-z]/',
		'/\bwoocommerce_[a-z]/',
	);

	public function test_no_woocommerce_symbols_outside_commerce_and_allowlist(): void {
		$allowlist  = $this->allowlist();
		$violations = array();

		foreach ( $this->scanned_files() as $file ) {
			$relative = $this->to_relative( $file );

			if ( array_key_exists( $relative, $allowlist ) ) {
				continue;
			}

			$matches = ArchitectureScanner::find_symbol_references( $file, self::PATTERNS );

			foreach ( $matches as $match ) {
				$violations[] = $relative . ':' . $match['line'] . " uses '" . $match['match'] . "'";
			}
		}

		self::assertSame(
			array(),
			$violations,
			$this->architecture_failure(
				'WooCommerce symbol found outside the commerce boundary',
				implode( "\n                          ", $violations ),
				'The base profile must run without WooCommerce; commerce code belongs only in site-commerce and the theme woocommerce/ overrides.',
				'Move the code into web/app/plugins/site-commerce/, or — if it is a reviewed exception — add it to tests/Architecture/woocommerce-allowlist.php with a reason.'
			)
		);
	}

	public function test_allowlist_entries_all_exist(): void {
		foreach ( array_keys( $this->allowlist() ) as $relative ) {
			self::assertFileExists(
				$this->repo_root() . '/' . $relative,
				$this->architecture_failure(
					'WooCommerce allow-list references a missing file',
					$relative,
					'A stale allow-list entry hides the fact that the exception no longer exists and weakens the rule over time.',
					'Remove the entry from tests/Architecture/woocommerce-allowlist.php.'
				)
			);
		}
	}

	public function test_allowlist_entries_still_reference_woocommerce(): void {
		foreach ( array_keys( $this->allowlist() ) as $relative ) {
			$path = $this->repo_root() . '/' . $relative;

			if ( ! is_file( $path ) ) {
				// Existence is asserted by the sibling test; skip here so both
				// failures are not reported for the same missing file.
				continue;
			}

			self::assertNotSame(
				array(),
				ArchitectureScanner::find_symbol_references( $path, self::PATTERNS ),
				$this->architecture_failure(
					'WooCommerce allow-list entry is obsolete',
					$relative,
					'This file no longer references any WooCommerce symbol, so its exemption protects nothing and only weakens the rule.',
					'Remove this stale entry from tests/Architecture/woocommerce-allowlist.php.'
				)
			);
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function allowlist(): array {
		/** @var array<string, string> $allowlist */
		$allowlist = require __DIR__ . '/woocommerce-allowlist.php';

		return $allowlist;
	}

	/**
	 * Every file the isolation rule applies to: the mu-plugin, site-core,
	 * site-integrations, the theme (minus its woocommerce/ overrides), and the
	 * test suite (minus tests/commerce/ and tests/Architecture/).
	 *
	 * Two directories are excluded by definition rather than by allow-list:
	 * site-commerce (WooCommerce's approved home) and tests/Architecture/
	 * (this rule's own enforcement code — this test file, its allow-list, and
	 * sibling tests necessarily NAME the forbidden symbols in their patterns
	 * and failure messages; scanning them would be scanning the ruler with
	 * itself).
	 *
	 * @return list<string>
	 */
	private function scanned_files(): array {
		$root  = $this->repo_root();
		$files = array_merge(
			ArchitectureScanner::php_files( $root . '/web/app/mu-plugins/agency-platform' ),
			ArchitectureScanner::php_files( $root . '/web/app/plugins/site-core' ),
			ArchitectureScanner::php_files( $root . '/web/app/plugins/site-integrations' ),
			$this->php_files_excluding( $root . '/web/app/themes/site-theme', array( '/woocommerce/' ) ),
			$this->php_files_excluding( $root . '/tests', array( '/tests/commerce/', '/tests/Architecture/' ) )
		);

		sort( $files );

		return $files;
	}

	/**
	 * @param list<string> $excluded_fragments
	 * @return list<string>
	 */
	private function php_files_excluding( string $dir, array $excluded_fragments ): array {
		return array_values(
			array_filter(
				ArchitectureScanner::php_files( $dir ),
				static function ( string $path ) use ( $excluded_fragments ): bool {
					$normalized = str_replace( '\\', '/', $path );

					foreach ( $excluded_fragments as $fragment ) {
						if ( str_contains( $normalized, $fragment ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}
}
