<?php
/**
 * Enforces the theme's and plugins' top-level directory contract: a block
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
					'A block theme keeps a small, fixed set of top-level folders.',
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
					'A block theme has exactly one rendering path — templates/*.html and parts/*.html. Any root-level PHP other than functions.php is a classic-hierarchy file WordPress would silently start honouring again.',
					'Move markup into templates/ or parts/ as block HTML, and behaviour into src/Bootstrap/ThemeBootstrap.php; delete build/editor cruft.'
				)
			);
		}
	}

	public function test_no_php_files_live_under_theme_templates_or_parts(): void {
		foreach ( array( 'templates', 'parts' ) as $directory ) {
			$root = $this->repo_root() . '/web/app/themes/site-theme/' . $directory;

			foreach ( $this->all_php_files( $root ) as $file ) {
				self::fail(
					$this->architecture_failure(
						'PHP file under the block theme\'s ' . $directory . '/ directory',
						$this->to_relative( $file ),
						'templates/ and parts/ hold block markup only; a PHP file here is a leftover of the deleted classic rendering path.',
						'Convert the markup to a .html block template or part, or delete the file.'
					)
				);
			}
		}

		$this->addToAssertionCount( 1 );
	}

	public function test_template_parts_are_direct_html_files_under_parts(): void {
		$parts = $this->repo_root() . '/web/app/themes/site-theme/parts';

		foreach ( $this->top_level_directories( $parts ) as $name ) {
			self::fail(
				$this->architecture_failure(
					'Nested directory under the theme\'s parts/',
					$this->to_relative( $parts ) . '/' . $name,
					'WordPress resolves template parts as flat parts/<slug>.html files; a nested directory is never loaded.',
					'Move the markup into parts/' . $name . '.html and its styles into assets/global/shared.css.'
				)
			);
		}

		foreach ( $this->top_level_files( $parts ) as $name ) {
			self::assertStringEndsWith(
				'.html',
				$name,
				$this->architecture_failure(
					'Non-HTML file directly under the theme\'s parts/',
					$this->to_relative( $parts ) . '/' . $name,
					'Template parts are HTML block markup; PHP, CSS or JS here belongs to the removed classic part convention.',
					'Move styles into assets/global/shared.css and behaviour into a block\'s viewScript.'
				)
			);
		}

		$this->addToAssertionCount( 1 );
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
