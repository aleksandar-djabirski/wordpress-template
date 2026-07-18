<?php
/**
 * Enforces the theme's "thin shell" wiring contract: functions.php and the
 * root template-hierarchy files stay delegates with no logic of their own,
 * so every real setup step and every piece of markup lives in a predictable
 * place (src/Bootstrap for wiring, templates/ for markup).
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

	/**
	 * Delegates carry a mandatory docblock + strict_types + ABSPATH guard,
	 * so "thin" is measured in SIGNIFICANT source lines (comments and blank
	 * lines excluded) rather than raw lines: a genuine delegate is a guard
	 * plus a single require, well under this ceiling.
	 */
	private const DELEGATE_MAX_SIGNIFICANT_LINES = 10;

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

	public function test_each_template_has_a_thin_root_delegate(): void {
		$theme = $this->theme();

		foreach ( $this->template_names( $theme ) as $name ) {
			$delegate = $theme . '/' . $name . '.php';

			self::assertFileExists(
				$delegate,
				$this->architecture_failure(
					'templates/' . $name . '.php has no root delegate',
					$this->to_relative( $theme ) . '/' . $name . '.php',
					'WordPress\'s template hierarchy loads root-level files; every templates/ file needs a matching root delegate or it is never used.',
					'Add a thin root ' . $name . '.php that requires templates/' . $name . '.php.'
				)
			);

			self::assertTrue(
				$this->requires_template( $delegate, $name ),
				$this->architecture_failure(
					'Root ' . $name . '.php does not delegate to its template',
					$this->to_relative( $delegate ),
					'Root templates must hold no markup; they exist only to hand off to templates/, which owns the real layout.',
					'Make this file require __DIR__ . \'/templates/' . $name . '.php\' and nothing else.'
				)
			);

			$significant = $this->significant_line_count( $delegate );

			self::assertLessThanOrEqual(
				self::DELEGATE_MAX_SIGNIFICANT_LINES,
				$significant,
				$this->architecture_failure(
					'Root ' . $name . '.php is not a thin delegate (' . $significant . ' significant lines)',
					$this->to_relative( $delegate ),
					'A delegate should be a guard plus a single require; extra logic means markup or behavior has leaked out of templates/.',
					'Move the logic into templates/' . $name . '.php (markup) or ThemeBootstrap (behavior).'
				)
			);
		}
	}

	public function test_every_root_delegate_maps_to_a_template(): void {
		$theme         = $this->theme();
		$non_delegates = array( 'functions.php', 'header.php', 'footer.php' );

		foreach ( $this->root_php_files( $theme ) as $file ) {
			$basename = basename( $file );

			if ( in_array( $basename, $non_delegates, true ) ) {
				continue;
			}

			$name = pathinfo( $basename, PATHINFO_FILENAME );

			self::assertFileExists(
				$theme . '/templates/' . $name . '.php',
				$this->architecture_failure(
					'Root delegate ' . $basename . ' has no template',
					$this->to_relative( $file ),
					'A root delegate with no templates/ counterpart is an orphan: it would require a file that does not exist.',
					'Add templates/' . $name . '.php with the real markup, or remove this delegate.'
				)
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

	private function requires_template( string $file, string $name ): bool {
		$pattern = '#\b(?:require|require_once|include|include_once)\b.*/templates/'
			. preg_quote( $name, '#' ) . '\.php#';

		return preg_match( $pattern, $this->code_without_comments( $file ) ) === 1;
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
	 * @return list<string>
	 */
	private function template_names( string $theme ): array {
		$names = array();

		foreach ( $this->php_in_directory( $theme . '/templates' ) as $file ) {
			$names[] = pathinfo( $file, PATHINFO_FILENAME );
		}

		sort( $names );

		return $names;
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
