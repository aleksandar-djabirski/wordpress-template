<?php
/**
 * Enforces the theme's asset-locality rules: assets/global/ holds only the
 * two genuinely global stylesheets, block-specific CSS stays inside its
 * block directory, and each part's CSS/JS is named after the part.
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

	private const ALLOWED_GLOBAL_CSS = array( 'base.css', 'typography.css' );

	private function theme(): string {
		return $this->repo_root() . '/web/app/themes/site-theme';
	}

	public function test_global_stylesheet_directory_holds_only_base_and_typography(): void {
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
					'assets/global/ is reserved for truly global styles (base.css, typography.css); block and part styles belong co-located with their code.',
					'Move this stylesheet next to the block/part it styles. A genuinely global addition requires deliberately extending this test\'s allow-list.'
				)
			);
		}
	}

	public function test_block_specific_css_stays_inside_its_block(): void {
		$theme       = $this->theme();
		$block_local = str_replace( '\\', '/', $theme ) . '/blocks/reference-callout/';

		foreach ( $this->css_files( $theme ) as $file ) {
			$normalized = str_replace( '\\', '/', $file );

			if ( str_starts_with( $normalized, $block_local ) ) {
				continue;
			}

			self::assertStringNotContainsString(
				'reference-callout',
				$this->strip_css_comments( $this->read( $file ) ),
				$this->architecture_failure(
					'Block-specific class used outside its block directory',
					$this->to_relative( $file ),
					'The reference-callout block\'s classes must only be styled inside blocks/reference-callout/, so a block\'s styling ships and is removed with the block.',
					'Move these rules into blocks/reference-callout/style.css (or editor.css).'
				)
			);
		}
	}

	public function test_part_assets_are_named_after_their_part(): void {
		$parts_dir = $this->theme() . '/parts';
		$entries   = is_dir( $parts_dir ) ? scandir( $parts_dir ) : false;

		self::assertIsArray( $entries, 'parts/ should exist.' );

		foreach ( $entries as $part ) {
			$part_dir = $parts_dir . '/' . $part;

			if ( '.' === $part || '..' === $part || ! is_dir( $part_dir ) ) {
				continue;
			}

			$assets = scandir( $part_dir );

			if ( false === $assets ) {
				continue;
			}

			foreach ( $assets as $asset ) {
				$extension = strtolower( pathinfo( $asset, PATHINFO_EXTENSION ) );

				if ( 'css' !== $extension && 'js' !== $extension ) {
					continue;
				}

				self::assertSame(
					$part . '.' . $extension,
					$asset,
					$this->architecture_failure(
						'Part asset is not named after its part',
						$this->to_relative( $part_dir ) . '/' . $asset,
						'Parts::assets() resolves a part\'s CSS/JS by the convention <part>/<part>.css|js; an off-name file is silently never enqueued.',
						'Rename the file to ' . $part . '.' . $extension . ' (one CSS and/or one JS per part).'
					)
				);
			}
		}
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
