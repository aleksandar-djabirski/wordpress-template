<?php
/**
 * Keeps commerce block markup inside its sanctioned files.
 *
 * The symbol-level rule (WooCommerceIsolationTest) only scans PHP, so it
 * cannot see block markup in *.html. This test is the block-theme half of the
 * same boundary: commerce blocks may appear ONLY in the commerce templates
 * named by tests/Architecture/commerce-template-list.php, never in a base
 * template, never in a template part, never in a theme pattern. It also pins
 * the retirement of the classic override directory: a block theme's only
 * override surfaces are templates/*.html and the commerce plugin's hooks.
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\FormatsArchitectureFailures;

require_once dirname( __DIR__ ) . '/support/FormatsArchitectureFailures.php';

final class CommerceBoundaryTest extends TestCase {

	use FormatsArchitectureFailures;

	private const COMMERCE_BLOCK_PATTERN = '/<!--\s+\/?wp:woocommerce\//';

	public function test_classic_override_directory_is_gone(): void {
		self::assertDirectoryDoesNotExist(
			$this->theme_root() . '/woocommerce',
			$this->architecture_failure(
				'The classic commerce template override directory is back',
				'web/app/themes/site-theme/woocommerce',
				'A block theme has one rendering path. Classic PHP template overrides would reintroduce the second path the migration removed.',
				'Override through templates/<slug>.html or a commerce hook in site-commerce instead; see docs/adding-commerce-behaviour.md.'
			)
		);
	}

	public function test_every_declared_commerce_template_exists(): void {
		foreach ( $this->declared()['templates'] as $slug ) {
			self::assertFileExists(
				$this->theme_root() . '/templates/' . $slug . '.html',
				$this->architecture_failure(
					'A declared commerce template is missing',
					$slug,
					'The declared set is the contract the commerce suites and the storefront depend on.',
					'Add templates/' . $slug . '.html, or remove the slug from tests/Architecture/commerce-template-list.php.'
				)
			);
		}
	}

	public function test_commerce_blocks_only_appear_in_declared_commerce_templates(): void {
		$declared   = $this->declared()['templates'];
		$violations = array();

		foreach ( $this->html_files( $this->theme_root() . '/templates' ) as $file ) {
			$slug = basename( $file, '.html' );

			if ( in_array( $slug, $declared, true ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- pure static analysis of local source files; this test must stay WordPress-free.
			if ( 1 === preg_match( self::COMMERCE_BLOCK_PATTERN, (string) file_get_contents( $file ) ) ) {
				$violations[] = 'templates/' . $slug . '.html';
			}
		}

		self::assertSame( array(), $violations, $this->boundary_failure( $violations ) );
	}

	public function test_parts_and_patterns_carry_no_commerce_blocks(): void {
		$violations = array();

		foreach ( array( '/parts', '/patterns' ) as $relative ) {
			foreach ( $this->all_files( $this->theme_root() . $relative ) as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- pure static analysis of local source files; this test must stay WordPress-free.
				if ( 1 === preg_match( self::COMMERCE_BLOCK_PATTERN, (string) file_get_contents( $file ) ) ) {
					$violations[] = $relative . '/' . basename( $file );
				}
			}
		}

		self::assertSame( array(), $violations, $this->boundary_failure( $violations ) );
	}

	public function test_declared_commerce_templates_render_the_theme_chrome(): void {
		$missing = array();

		foreach ( $this->declared()['templates'] as $slug ) {
			$path = $this->theme_root() . '/templates/' . $slug . '.html';

			if ( ! is_file( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- pure static analysis of local source files; this test must stay WordPress-free.
			$content = (string) file_get_contents( $path );

			foreach ( array( 'site-header', 'site-footer' ) as $part ) {
				if ( ! str_contains( $content, '"slug":"' . $part . '"' ) ) {
					$missing[] = $slug . ' -> ' . $part;
				}
			}

			if ( preg_match( '/<!--\s+wp:template-part\s+\{[^}]*"theme":/', $content ) === 1 ) {
				$missing[] = $slug . ' -> environment-specific theme attribute';
			}
		}

		self::assertSame(
			array(),
			$missing,
			$this->architecture_failure(
				'A commerce template does not render the theme chrome',
				implode( "\n                          ", $missing ),
				'Rendering the theme header/footer parts is the ONLY reason these overrides exist; a template without them is worse than no override at all.',
				'Re-derive the file from the upstream template, rewrite only the header/footer template-part slugs, and remove environment-specific template-part theme attributes.'
			)
		);
	}

	/**
	 * @param list<string> $violations
	 */
	private function boundary_failure( array $violations ): string {
		return $this->architecture_failure(
			'Commerce block markup outside the declared commerce templates',
			implode( "\n                          ", $violations ),
			'The base profile must run with no commerce plugin installed; a commerce block in a base template, part, or pattern renders as a broken block there.',
			'Move the markup into a declared commerce template, or register it as a pattern from web/app/plugins/site-commerce/.'
		);
	}

	/**
	 * @return array{templates: list<string>, excluded: array<string, string>}
	 */
	private function declared(): array {
		/** @var array{templates: list<string>, excluded: array<string, string>} $declared */
		$declared = require __DIR__ . '/commerce-template-list.php';

		return $declared;
	}

	private function theme_root(): string {
		return $this->repo_root() . '/web/app/themes/site-theme';
	}

	/**
	 * @return list<string>
	 */
	private function html_files( string $dir ): array {
		return $this->files_with_extension( $dir, 'html' );
	}

	/**
	 * @return list<string>
	 */
	private function all_files( string $dir ): array {
		return $this->files_with_extension( $dir, null );
	}

	/**
	 * @return list<string>
	 */
	private function files_with_extension( string $dir, ?string $extension ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files = array();

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $item ) {
			if ( ! $item instanceof \SplFileInfo || ! $item->isFile() ) {
				continue;
			}

			if ( null !== $extension && strtolower( $item->getExtension() ) !== $extension ) {
				continue;
			}

			$files[] = $item->getPathname();
		}

		sort( $files );

		return $files;
	}
}
