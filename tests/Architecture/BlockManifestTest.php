<?php
/**
 * Enforces the block authoring contract for every directory under the
 * theme's blocks/: a valid block.json in the agency/ namespace whose folder
 * name matches its slug, a declared apiVersion, and file: asset references
 * that resolve inside the block directory (including committed build output).
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\FormatsArchitectureFailures;

require_once dirname( __DIR__ ) . '/support/FormatsArchitectureFailures.php';

final class BlockManifestTest extends TestCase {

	use FormatsArchitectureFailures;

	private const FILE_ASSET_KEYS = array( 'render', 'editorScript', 'script', 'viewScript', 'style', 'editorStyle' );

	private const FORBIDDEN_MANIFESTS = array( 'component.json', 'manifest.json' );

	/**
	 * @dataProvider block_directories
	 */
	public function test_block_json_exists_and_is_valid_json( string $block_dir ): void {
		$manifest = $block_dir . '/block.json';

		self::assertFileExists(
			$manifest,
			$this->architecture_failure(
				'Block directory has no block.json',
				$this->to_relative( $block_dir ),
				'Every block is defined by a block.json manifest; without it WordPress cannot register the block.',
				'Add a block.json following blocks/reference-callout/block.json.'
			)
		);

		self::assertIsArray(
			$this->decode( $manifest ),
			$this->architecture_failure(
				'block.json is not valid JSON',
				$this->to_relative( $manifest ),
				'An unparseable manifest silently breaks block registration at runtime.',
				'Fix the JSON syntax; validate against https://schemas.wp.org/trunk/block.json.'
			)
		);
	}

	/**
	 * @dataProvider block_directories
	 */
	public function test_block_name_and_folder_and_api_version( string $block_dir ): void {
		$manifest = $block_dir . '/block.json';
		$data     = $this->decode( $manifest );

		self::assertIsArray( $data );

		$folder   = basename( $block_dir );
		$expected = 'agency/' . $folder;

		self::assertSame(
			$expected,
			$data['name'] ?? null,
			$this->architecture_failure(
				'Block name does not match agency/<folder>',
				$this->to_relative( $manifest ),
				'Folder name and block slug must agree (agency namespace) so blocks are locatable by name and stable across databases.',
				'Set "name" to "' . $expected . '" or rename the folder to match the slug.'
			)
		);

		self::assertIsInt(
			$data['apiVersion'] ?? null,
			$this->architecture_failure(
				'block.json declares no integer apiVersion',
				$this->to_relative( $manifest ),
				'Omitting apiVersion pins the block to the legacy v1 contract and breaks modern editor features.',
				'Add "apiVersion": 3 (or the version this block targets).'
			)
		);
	}

	/**
	 * @dataProvider block_directories
	 */
	public function test_file_asset_references_resolve_inside_the_block( string $block_dir ): void {
		$manifest = $block_dir . '/block.json';
		$data     = $this->decode( $manifest );

		self::assertIsArray( $data );

		$checked = 0;

		foreach ( self::FILE_ASSET_KEYS as $key ) {
			foreach ( $this->file_references( $data[ $key ] ?? null ) as $relative ) {
				++$checked;

				self::assertStringNotContainsString(
					'..',
					$relative,
					$this->architecture_failure(
						'block.json ' . $key . ' reference escapes the block directory',
						$this->to_relative( $manifest ),
						'Block assets must stay co-located inside the block folder so a block is self-contained and portable.',
						'Point "' . $key . '" at a file inside ' . basename( $block_dir ) . '/ (no ../).'
					)
				);

				$target = $block_dir . '/' . ltrim( $relative, './' );
				$hint   = str_contains( $relative, 'build/' )
					? 'Run `npm run build` and commit the block\'s build/ output.'
					: 'Add the referenced file or correct the path in block.json.';

				self::assertFileExists(
					$target,
					$this->architecture_failure(
						'block.json ' . $key . ' references a missing file',
						$this->to_relative( $target ),
						'A file: reference that does not resolve means the editor script/style or render callback is missing at runtime.',
						$hint
					)
				);
			}
		}

		self::assertGreaterThan(
			0,
			$checked,
			$this->architecture_failure(
				'Block declares no file: asset references',
				$this->to_relative( $manifest ),
				'A dynamic block is expected to declare at least a render or editorScript file: reference.',
				'Reference this block\'s render.php / build/index.js via file: paths in block.json.'
			)
		);
	}

	/**
	 * @dataProvider block_directories
	 */
	public function test_no_competing_manifest_files( string $block_dir ): void {
		foreach ( self::FORBIDDEN_MANIFESTS as $forbidden ) {
			self::assertFileDoesNotExist(
				$block_dir . '/' . $forbidden,
				$this->architecture_failure(
					'Block directory contains a competing manifest (' . $forbidden . ')',
					$this->to_relative( $block_dir . '/' . $forbidden ),
					'block.json is the single source of truth for a block; a second manifest invites drift and ambiguity.',
					'Delete ' . $forbidden . ' and keep all block metadata in block.json.'
				)
			);
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function block_directories(): array {
		$blocks_dir = dirname( __DIR__, 2 ) . '/web/app/themes/site-theme/blocks';
		$entries    = is_dir( $blocks_dir ) ? scandir( $blocks_dir ) : false;

		if ( false === $entries ) {
			return array();
		}

		$cases = array();

		foreach ( $entries as $entry ) {
			$path = $blocks_dir . '/' . $entry;

			if ( '.' !== $entry && '..' !== $entry && is_dir( $path ) ) {
				$cases[ $entry ] = array( $path );
			}
		}

		return $cases;
	}

	/**
	 * @return array<mixed>|null
	 */
	private function decode( string $manifest ): ?array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local manifest file; no WordPress runtime in the architecture suite.
		$raw = file_get_contents( $manifest );

		if ( false === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Normalizes a block.json asset value (string or array of strings) into
	 * the list of file: paths it declares, stripping the "file:" prefix.
	 *
	 * @param mixed $value
	 * @return list<string>
	 */
	private function file_references( $value ): array {
		$candidates = is_array( $value ) ? $value : array( $value );
		$references = array();

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && str_starts_with( $candidate, 'file:' ) ) {
				$references[] = substr( $candidate, strlen( 'file:' ) );
			}
		}

		return $references;
	}
}
