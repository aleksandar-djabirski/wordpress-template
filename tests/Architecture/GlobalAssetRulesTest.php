<?php
/**
 * Enforces the theme's asset-locality rules: assets/global/ holds only the
 * three deliberately split global stylesheets, and block-specific CSS stays
 * inside its block directory.
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\FormatsArchitectureFailures;

require_once dirname( __DIR__ ) . '/support/FormatsArchitectureFailures.php';

final class GlobalAssetRulesTest extends TestCase {

	use FormatsArchitectureFailures;

	private const ALLOWED_GLOBAL_CSS = array( 'frontend-reset.css', 'shared.css', 'editor.css' );

	private function theme(): string {
		return $this->repo_root() . '/web/app/themes/site-theme';
	}

	public function test_global_stylesheet_directory_holds_only_the_three_split_files(): void {
		$global  = $this->theme() . '/assets/global';
		$entries = is_dir( $global ) ? scandir( $global ) : false;

		self::assertIsArray( $entries, 'assets/global/ should exist.' );

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			self::assertContains(
				$entry,
				self::ALLOWED_GLOBAL_CSS,
				$this->architecture_failure(
					'Unexpected file in assets/global/',
					$this->to_relative( $global ) . '/' . $entry,
					'assets/global/ is reserved for the three split global stylesheets; block styles belong co-located with their code.',
					'Move this stylesheet next to the block/part it styles. A genuinely global addition requires deliberately extending this test\'s allow-list.'
				)
			);
		}
	}

	public function test_block_specific_css_stays_inside_its_block(): void {
		$theme     = $this->theme();
		$css_files = $this->css_files( $theme );
		$slugs     = $this->block_slugs( $theme );

		self::assertNotEmpty( $slugs, 'The theme should define at least one block under blocks/.' );

		// For every block, its slug (the BEM class prefix, e.g. slug__element)
		// may only appear in CSS inside that block's own directory. Iterating
		// all blocks means new blocks are enforced automatically.
		foreach ( $slugs as $slug ) {
			$block_local = str_replace( '\\', '/', $theme ) . '/blocks/' . $slug . '/';

			foreach ( $css_files as $file ) {
				$normalized = str_replace( '\\', '/', $file );

				if ( str_starts_with( $normalized, $block_local ) ) {
					continue;
				}

				self::assertStringNotContainsString(
					$slug,
					$this->strip_css_comments( $this->read( $file ) ),
					$this->architecture_failure(
						'Block-specific class used outside its block directory',
						$this->to_relative( $file ),
						'The "' . $slug . '" block\'s classes must only be styled inside blocks/' . $slug . '/, so a block\'s styling ships and is removed with the block.',
						'Move these rules into blocks/' . $slug . '/style.css (or editor.css).'
					)
				);
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function block_slugs( string $theme ): array {
		$blocks  = $theme . '/blocks';
		$entries = is_dir( $blocks ) ? scandir( $blocks ) : false;

		if ( false === $entries ) {
			return array();
		}

		$slugs = array();

		foreach ( $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry && is_dir( $blocks . '/' . $entry ) ) {
				$slugs[] = $entry;
			}
		}

		sort( $slugs );

		return $slugs;
	}


	public function test_frontend_reset_css_is_never_registered_as_an_editor_style(): void {
		self::assertNotContains(
			'assets/global/frontend-reset.css',
			\SiteTheme\Bootstrap\ThemeBootstrap::EDITOR_STYLESHEETS,
			$this->architecture_failure(
				'Frontend reset CSS is loaded into the editor canvas',
				'web/app/themes/site-theme/src/Bootstrap/ThemeBootstrap.php',
				'frontend-reset.css is a document-level reset; inside the editor canvas it fights core\'s own canvas layout and makes the editor stop matching the frontend.',
				'Keep frontend-reset.css in FRONTEND_STYLESHEETS only; put editor-safe rules in assets/global/shared.css.'
			)
		);
	}

	public function test_every_declared_global_stylesheet_exists_on_disk(): void {
		$declared = array_merge(
			array_values( \SiteTheme\Bootstrap\ThemeBootstrap::FRONTEND_STYLESHEETS ),
			\SiteTheme\Bootstrap\ThemeBootstrap::EDITOR_STYLESHEETS
		);

		foreach ( array_unique( $declared ) as $relative_path ) {
			self::assertStringStartsWith( 'assets/global/', $relative_path );
			self::assertFileExists( $this->theme() . '/' . $relative_path );
		}
	}

	public function test_the_shared_stylesheet_is_loaded_on_both_sides(): void {
		self::assertContains( 'assets/global/shared.css', array_values( \SiteTheme\Bootstrap\ThemeBootstrap::FRONTEND_STYLESHEETS ) );
		self::assertContains( 'assets/global/shared.css', \SiteTheme\Bootstrap\ThemeBootstrap::EDITOR_STYLESHEETS );
	}

	/**
	 * @return list<string>
	 */
	private function css_files( string $root ): array {
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$skip = array( 'vendor', 'node_modules', 'build' );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				static fn( \SplFileInfo $current ): bool =>
					! ( $current->isDir() && in_array( $current->getFilename(), $skip, true ) )
			)
		);

		$files = array();

		foreach ( $iterator as $item ) {
			if ( $item instanceof \SplFileInfo && $item->isFile() && 'css' === strtolower( $item->getExtension() ) ) {
				$files[] = $item->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	private function strip_css_comments( string $css ): string {
		return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	}

	private function read( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local stylesheet for static inspection; no WordPress runtime in the architecture suite.
		$source = file_get_contents( $file );

		return false === $source ? '' : $source;
	}
}
