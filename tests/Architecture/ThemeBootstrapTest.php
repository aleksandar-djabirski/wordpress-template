<?php
/**
 * Enforces the theme's "thin shell" wiring contract: functions.php and
 * ThemeBootstrap own wiring, while markup lives in templates/*.html and
 * parts/*.html.
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\FormatsArchitectureFailures;

require_once dirname( __DIR__ ) . '/support/FormatsArchitectureFailures.php';

final class ThemeBootstrapTest extends TestCase {

	use FormatsArchitectureFailures;

	private const FUNCTIONS_MAX_LINES = 50;

	private const CLASSIC_ROOT_TEMPLATES = array(
		'index.php',
		'page.php',
		'single.php',
		'archive.php',
		'search.php',
		'404.php',
		'header.php',
		'footer.php',
	);

	/**
	 * A second rendering path is the failure mode this whole migration exists
	 * to remove: any of these left in the theme would let WordPress fall back
	 * to classic rendering for some request type, so the site the client edits
	 * in the Site Editor and the site a visitor sees could silently diverge.
	 *
	 * @var string[]
	 */
	private const CLASSIC_TEMPLATE_CALLS = array(
		'get_header',
		'get_footer',
		'get_template_part',
		'wp_nav_menu',
		'register_nav_menus',
	);

	private function theme(): string {
		return $this->repo_root() . '/web/app/themes/site-theme';
	}

	public function test_functions_php_is_a_thin_shell(): void {
		$file     = $this->theme() . '/functions.php';
		$contents = file( $file );
		$lines    = false === $contents ? 0 : count( $contents );

		self::assertLessThanOrEqual(
			self::FUNCTIONS_MAX_LINES,
			$lines,
			$this->architecture_failure(
				'functions.php exceeds its line ceiling',
				$this->to_relative( $file ),
				'functions.php must stay a thin shell so all theme setup is discoverable in one place instead of accreting inline logic.',
				'Move setup steps into \\SiteTheme\\Bootstrap\\ThemeBootstrap as named methods wired from boot().'
			)
		);

		self::assertLessThanOrEqual( self::FUNCTIONS_MAX_LINES, $this->significant_line_count( $file ) );
	}

	public function test_functions_php_registers_no_hooks_and_has_no_closures(): void {
		$file = $this->theme() . '/functions.php';

		self::assertFalse(
			$this->registers_hooks( $file ),
			$this->architecture_failure(
				'functions.php registers a hook directly',
				$this->to_relative( $file ),
				'Hooks belong to named class methods in ThemeBootstrap, never to functions.php, so ownership stays traceable.',
				'Register the hook from \\SiteTheme\\Bootstrap\\ThemeBootstrap::boot() using a [ self::class, \'method\' ] callback.'
			)
		);

		self::assertFalse(
			$this->contains_closure( $file ),
			$this->architecture_failure(
				'functions.php contains a closure',
				$this->to_relative( $file ),
				'Closures in functions.php hide behavior behind anonymous callbacks; the theme wires everything through named methods.',
				'Move the logic into a named method on \\SiteTheme\\Bootstrap\\ThemeBootstrap.'
			)
		);
	}

	public function test_the_theme_declares_itself_a_block_theme(): void {
		self::assertFileExists(
			$this->theme() . '/templates/index.html',
			$this->architecture_failure(
				'templates/index.html is missing',
				'web/app/themes/site-theme/templates/index.html',
				'WP_Theme::is_block_theme() keys off this exact file; without it WordPress falls back to the classic hierarchy and the Site Editor is unavailable.',
				'Add templates/index.html with the posts-index block markup.'
			)
		);
	}

	public function test_no_classic_root_template_files_remain(): void {
		$root_php_files = array_map( 'basename', $this->root_php_files( $this->theme() ) );

		foreach ( self::CLASSIC_ROOT_TEMPLATES as $name ) {
			self::assertFileDoesNotExist(
				$this->theme() . '/' . $name,
				$this->architecture_failure(
					'Classic root template survives in a block theme',
					'web/app/themes/site-theme/' . $name,
					'WordPress still honours root-level hierarchy files for some request types, so leaving one creates a second rendering path the Site Editor cannot see.',
					'Delete this file; its markup belongs in templates/*.html or parts/*.html.'
				)
			);

			self::assertNotContains( $name, $root_php_files );
		}
	}

	public function test_the_theme_has_no_second_rendering_path(): void {
		self::assertFileDoesNotExist(
			$this->theme() . '/src/Support/Parts.php',
			'SiteTheme\\Support\\Parts belonged to the classic part convention and must be deleted.'
		);

		foreach ( $this->all_theme_php_files() as $file ) {
			foreach ( self::CLASSIC_TEMPLATE_CALLS as $call ) {
				self::assertDoesNotMatchRegularExpression(
					'/\\b' . preg_quote( $call, '/' ) . '\\s*\\(/',
					$this->code_without_comments( $file ),
					$this->architecture_failure(
						'Classic template function called in a block theme',
						$this->to_relative( $file ),
						$call . '() belongs to the classic rendering path this theme no longer has; calling it reintroduces markup the Site Editor cannot edit.',
						'Express this with a block: core/template-part for chrome, core/navigation for menus.'
					)
				);
			}
		}
	}

	public function test_theme_bootstrap_owns_the_setup_hooks(): void {
		$source = $this->read( $this->theme() . '/src/Bootstrap/ThemeBootstrap.php' );

		foreach ( array( 'after_setup_theme', 'wp_enqueue_scripts', 'init' ) as $hook ) {
			self::assertStringContainsString(
				"'" . $hook . "'",
				$source,
				'ThemeBootstrap::boot() must own the ' . $hook . ' registration.'
			);
		}
	}

	private function registers_hooks( string $file ): bool {
		foreach ( token_get_all( $this->read( $file ) ) as $token ) {
			if ( is_array( $token )
				&& \T_STRING === $token[0]
				&& in_array( $token[1], array( 'add_action', 'add_filter' ), true )
			) {
				return true;
			}
		}

		return false;
	}

	private function contains_closure( string $file ): bool {
		$tokens = token_get_all( $this->read( $file ) );
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) ) {
				continue;
			}

			if ( \T_FN === $token[0] ) {
				return true;
			}

			if ( \T_FUNCTION === $token[0] ) {
				for ( $k = $i + 1; $k < $count; $k++ ) {
					$next = $tokens[ $k ];

					if ( is_array( $next ) && \T_WHITESPACE === $next[0] ) {
						continue;
					}

					// An anonymous function is `function (` / `function &(`;
					// a named function is `function name(`.
					if ( '(' === $next || ( is_array( $next ) && '&' === $next[1] ) ) {
						return true;
					}

					break;
				}
			}
		}

		return false;
	}

	private function significant_line_count( string $file ): int {
		$insignificant = array(
			\T_WHITESPACE,
			\T_COMMENT,
			\T_DOC_COMMENT,
			\T_OPEN_TAG,
			\T_OPEN_TAG_WITH_ECHO,
			\T_CLOSE_TAG,
			\T_INLINE_HTML,
		);

		$lines = array();

		foreach ( token_get_all( $this->read( $file ) ) as $token ) {
			if ( is_array( $token ) && ! in_array( $token[0], $insignificant, true ) ) {
				$lines[ $token[2] ] = true;
			}
		}

		return count( $lines );
	}

	private function code_without_comments( string $file ): string {
		$output = '';

		foreach ( token_get_all( $this->read( $file ) ) as $token ) {
			if ( is_array( $token ) ) {
				$output .= in_array( $token[0], array( \T_COMMENT, \T_DOC_COMMENT ), true )
					? ' '
					: $token[1];
			} else {
				$output .= $token;
			}
		}

		return $output;
	}

	/**
	 * Recursively lists PHP files under the theme, excluding dependency and
	 * build output.
	 *
	 * @return list<string>
	 */
	private function all_theme_php_files(): array {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $this->theme(), \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $current ): bool {
					return ! ( $current->isDir() && in_array( $current->getFilename(), array( 'vendor', 'node_modules', 'build' ), true ) );
				}
			)
		);

		$files = array();

		foreach ( $iterator as $item ) {
			if ( $item instanceof \SplFileInfo && $item->isFile() && 'php' === strtolower( $item->getExtension() ) ) {
				$files[] = $item->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * @return list<string>
	 */
	private function root_php_files( string $theme ): array {
		return $this->php_in_directory( $theme );
	}

	/**
	 * Non-recursive listing of *.php files directly inside $dir.
	 *
	 * @return list<string>
	 */
	private function php_in_directory( string $dir ): array {
		$entries = scandir( $dir );

		if ( false === $entries ) {
			return array();
		}

		$files = array();

		foreach ( $entries as $entry ) {
			$path = $dir . '/' . $entry;

			if ( is_file( $path ) && 'php' === strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ) ) {
				$files[] = $path;
			}
		}

		sort( $files );

		return $files;
	}

	private function read( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local source for tokenizing; no WordPress runtime in the architecture suite.
		$source = file_get_contents( $file );

		return false === $source ? '' : $source;
	}
}
