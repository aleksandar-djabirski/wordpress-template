<?php
/**
 * Enforces the theme's and plugins' top-level directory contract: a hybrid
 * theme keeps a small, fixed set of top-level folders/files, and neither the
 * theme nor any plugin grows the catch-all "junk drawer" directories
 * (inc/, includes/, helpers/, misc/, ...) that erode an AI-legible layout.
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\FormatsArchitectureFailures;

require_once dirname( __DIR__ ) . '/support/FormatsArchitectureFailures.php';

final class DirectoryRulesTest extends TestCase {

	use FormatsArchitectureFailures;

	private const ALLOWED_THEME_DIRS = array(
		'assets',
		'blocks',
		'parts',
		'patterns',
		'src',
		'templates',
		'woocommerce',
	);

	private const ALLOWED_THEME_FILES = array(
		'style.css',
		'functions.php',
		'theme.json',
		'index.php',
		'page.php',
		'single.php',
		'archive.php',
		'search.php',
		'404.php',
		'header.php',
		'footer.php',
		'screenshot.png',
		'README.md',
	);

	private const FORBIDDEN_DIR_NAMES = array(
		'components',
		'layouts',
		'inc',
		'includes',
		'helpers',
		'misc',
		'common',
		'lib',
		'utils',
	);

	public function test_theme_top_level_directories_are_on_the_whitelist(): void {
		$theme = $this->repo_root() . '/web/app/themes/site-theme';

		foreach ( $this->top_level_directories( $theme ) as $name ) {
			self::assertContains(
				$name,
				self::ALLOWED_THEME_DIRS,
				$this->architecture_failure(
					'Unexpected top-level directory in the theme',
					$this->to_relative( $theme ) . '/' . $name,
					'The theme keeps a fixed, AI-legible set of top-level folders (assets, blocks, parts, patterns, src, templates, woocommerce).',
					'Move this directory\'s contents under one of the allowed folders, or delete it if it is stray output.'
				)
			);
		}
	}

	public function test_theme_top_level_files_are_on_the_whitelist(): void {
		$theme = $this->repo_root() . '/web/app/themes/site-theme';

		foreach ( $this->top_level_files( $theme ) as $name ) {
			self::assertContains(
				$name,
				self::ALLOWED_THEME_FILES,
				$this->architecture_failure(
					'Unexpected top-level file in the theme',
					$this->to_relative( $theme ) . '/' . $name,
					'Root-level PHP is limited to the classic template-hierarchy delegates plus header.php/footer.php; any other *.php here (e.g. a stray WooCommerce override) hides real markup outside templates/.',
					'Move WooCommerce template overrides under the theme\'s woocommerce/ folder and page markup under templates/; delete build/editor cruft.'
				)
			);
		}
	}

	public function test_no_forbidden_directory_names_in_theme_or_plugins(): void {
		$roots = array(
			$this->repo_root() . '/web/app/mu-plugins/agency-platform',
			$this->repo_root() . '/web/app/plugins/site-core',
			$this->repo_root() . '/web/app/plugins/site-integrations',
			$this->repo_root() . '/web/app/plugins/site-commerce',
			$this->repo_root() . '/web/app/themes/site-theme',
		);

		foreach ( $roots as $root ) {
			foreach ( $this->all_directories( $root ) as $directory ) {
				$name = basename( $directory );

				self::assertNotContains(
					$name,
					self::FORBIDDEN_DIR_NAMES,
					$this->architecture_failure(
						'Catch-all directory name is forbidden',
						$this->to_relative( $directory ),
						'Directories like inc/, includes/, helpers/, misc/, utils/ collect unrelated code and defeat a predictable, purpose-named layout.',
						'Give the code a purpose-named home: a feature namespace under src/, or the relevant blocks/parts/templates folder.'
					)
				);
			}
		}
	}

	public function test_no_php_files_live_under_theme_assets(): void {
		$assets = $this->repo_root() . '/web/app/themes/site-theme/assets';

		foreach ( $this->all_php_files( $assets ) as $file ) {
			self::fail(
				$this->architecture_failure(
					'PHP file under the theme assets/ directory',
					$this->to_relative( $file ),
					'assets/ holds static CSS/JS/images only; executable PHP there blurs the line between assets and logic.',
					'Move PHP into the theme\'s src/ (for classes) or the appropriate blocks/parts/templates file.'
				)
			);
		}

		// The loop above fails on the first offending file; reaching here means
		// none were found. Register that clean pass so the test isn't flagged
		// as risky for making no assertion.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @return list<string>
	 */
	private function top_level_directories( string $dir ): array {
		$result = array();

		foreach ( $this->scandir( $dir ) as $entry ) {
			if ( is_dir( $dir . '/' . $entry ) ) {
				$result[] = $entry;
			}
		}

		return $result;
	}

	/**
	 * @return list<string>
	 */
	private function top_level_files( string $dir ): array {
		$result = array();

		foreach ( $this->scandir( $dir ) as $entry ) {
			if ( is_file( $dir . '/' . $entry ) ) {
				$result[] = $entry;
			}
		}

		return $result;
	}

	/**
	 * @return list<string>
	 */
	private function scandir( string $dir ): array {
		$entries = scandir( $dir );

		if ( false === $entries ) {
			return array();
		}

		return array_values(
			array_filter(
				$entries,
				static fn( string $entry ): bool => '.' !== $entry && '..' !== $entry
			)
		);
	}

	/**
	 * Every descendant directory of $root, skipping dependency/build output.
	 *
	 * @return list<string>
	 */
	private function all_directories( string $root ): array {
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$skip = array( 'vendor', 'node_modules', 'build' );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				static fn( \SplFileInfo $current ): bool =>
					! ( $current->isDir() && in_array( $current->getFilename(), $skip, true ) )
			),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		$directories = array();

		foreach ( $iterator as $item ) {
			if ( $item instanceof \SplFileInfo && $item->isDir() ) {
				$directories[] = $item->getPathname();
			}
		}

		return $directories;
	}

	/**
	 * @return list<string>
	 */
	private function all_php_files( string $root ): array {
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$files = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( $item instanceof \SplFileInfo && $item->isFile() && 'php' === strtolower( $item->getExtension() ) ) {
				$files[] = $item->getPathname();
			}
		}

		return $files;
	}
}
