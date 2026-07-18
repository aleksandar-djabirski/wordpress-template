<?php
/**
 * Asserts the committed block index is current. The index is generated from
 * block.json manifests plus the patterns/templates/tests that reference each
 * block; if a block changes and the file is not regenerated, this fails so
 * the checked-in documentation can never silently drift from the code.
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\BlockIndexGenerator;
use Tests\Support\FormatsArchitectureFailures;

require_once dirname( __DIR__ ) . '/support/BlockIndexGenerator.php';
require_once dirname( __DIR__ ) . '/support/FormatsArchitectureFailures.php';

final class GeneratedIndexFreshnessTest extends TestCase {

	use FormatsArchitectureFailures;

	private const INDEX_RELATIVE = 'docs/generated-block-index.md';

	public function test_committed_block_index_matches_regeneration(): void {
		$index_path = $this->repo_root() . '/' . self::INDEX_RELATIVE;

		self::assertFileExists(
			$index_path,
			$this->architecture_failure(
				'Generated block index is missing',
				self::INDEX_RELATIVE,
				'The block index is committed documentation the freshness check depends on; it must exist in the repository.',
				'Run `php scripts/generate-block-index` and commit ' . self::INDEX_RELATIVE . '.'
			)
		);

		$expected = BlockIndexGenerator::generate( $this->repo_root() );

		self::assertSame(
			$expected,
			$this->read( $index_path ),
			$this->architecture_failure(
				'Generated block index is stale',
				self::INDEX_RELATIVE,
				'The committed index no longer matches what the generator produces from the current blocks/patterns/tests.',
				'Run `php scripts/generate-block-index` and commit the regenerated ' . self::INDEX_RELATIVE . '.'
			)
		);
	}

	private function read( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local documentation file for comparison; no WordPress runtime in the architecture suite.
		$contents = file_get_contents( $file );

		return false === $contents ? '' : $contents;
	}
}
