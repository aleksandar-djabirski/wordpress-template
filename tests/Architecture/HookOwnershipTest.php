<?php
/**
 * Enforces two hook-ownership rules across all production code:
 *
 * 1. No closures are passed to add_action()/add_filter(). Hooks must use
 *    named class methods so every registration has a traceable owner.
 * 2. Theme presentation files (templates/, parts/, root delegates, and block
 *    render.php) register no hooks at all — hooks belong in src/Bootstrap or
 *    a plugin provider, never in markup.
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

final class HookOwnershipTest extends TestCase {

	use FormatsArchitectureFailures;

	public function test_no_closures_are_registered_as_hooks(): void {
		$violations = array();

		foreach ( $this->production_php_files() as $file ) {
			foreach ( ArchitectureScanner::tokens_contain_closure_hook( $file ) as $hit ) {
				$violations[] = $this->to_relative( $file ) . ':' . $hit['line'] . ' (' . $hit['hook'] . ')';
			}
		}

		self::assertSame(
			array(),
			$violations,
			$this->architecture_failure(
				'Closure passed to add_action()/add_filter()',
				implode( "\n                          ", $violations ),
				'Anonymous callbacks cannot be unhooked, identified in stack traces, or unit-tested in isolation; every hook needs a named owner.',
				'Replace the closure with a [ self::class, \'method\' ] / [ $this, \'method\' ] callback on a named class method.'
			)
		);
	}

	public function test_theme_presentation_files_register_no_hooks(): void {
		$violations = array();

		foreach ( $this->theme_presentation_files() as $file ) {
			$matches = ArchitectureScanner::find_symbol_references(
				$file,
				array( '/\badd_(?:action|filter)\s*\(/' )
			);

			foreach ( $matches as $match ) {
				$violations[] = $this->to_relative( $file ) . ':' . $match['line'];
			}
		}

		self::assertSame(
			array(),
			$violations,
			$this->architecture_failure(
				'Hook registered inside a theme presentation file',
				implode( "\n                          ", $violations ),
				'Templates, parts, delegates, and block render.php are for markup/output only; registering hooks there scatters wiring across the render path.',
				'Move the add_action()/add_filter() call into \\SiteTheme\\Bootstrap\\ThemeBootstrap or a plugin provider.'
			)
		);
	}

	/**
	 * All authored production PHP: the mu-plugin and every site-* plugin
	 * (main files + src), plus the whole theme. Composer-installed mu-plugins
	 * and plugins are never under these roots.
	 *
	 * @return list<string>
	 */
	private function production_php_files(): array {
		$root = $this->repo_root();

		return array_merge(
			ArchitectureScanner::php_files( $root . '/web/app/mu-plugins/agency-platform' ),
			ArchitectureScanner::php_files( $root . '/web/app/plugins/site-core' ),
			ArchitectureScanner::php_files( $root . '/web/app/plugins/site-integrations' ),
			ArchitectureScanner::php_files( $root . '/web/app/plugins/site-commerce' ),
			ArchitectureScanner::php_files( $root . '/web/app/themes/site-theme' )
		);
	}

	/**
	 * Theme PHP that produces output — everything except the theme's src/
	 * (where hooks legitimately live). Covers root delegates + header/footer,
	 * templates/, parts/, and block render.php files.
	 *
	 * @return list<string>
	 */
	private function theme_presentation_files(): array {
		$theme = $this->repo_root() . '/web/app/themes/site-theme';

		return array_values(
			array_filter(
				ArchitectureScanner::php_files( $theme ),
				static function ( string $path ) use ( $theme ): bool {
					$normalized = str_replace( '\\', '/', $path );
					$src        = str_replace( '\\', '/', $theme ) . '/src/';

					return ! str_starts_with( $normalized, $src );
				}
			)
		);
	}
}
