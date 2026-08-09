<?php
/**
 * Protects the IMMUTABLE migration-parity baselines. These PNGs photograph a
 * frontend that no longer exists once the classic theme is deleted, so nothing
 * in the repository may be able to silently regenerate them — in particular,
 * `playwright test --update-snapshots` must have no path to them.
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\FormatsArchitectureFailures;

require_once dirname( __DIR__ ) . '/support/FormatsArchitectureFailures.php';

final class MigrationBaselineGuardTest extends TestCase {

	use FormatsArchitectureFailures;

	private const BASELINE_RELATIVE      = 'tests/parity/__migration_baselines__';
	private const DEFAULT_MAX_DIFF_RATIO = 0.05;
	private const PARITY_PAGE_COUNT      = 4;

	/**
	 * @return array<string, mixed>
	 */
	private function metadata(): array {
		$path = $this->repo_root() . '/' . self::BASELINE_RELATIVE . '/metadata.json';

		self::assertFileExists( $path, 'Migration baselines must carry a metadata.json sidecar.' );

		$decoded = json_decode( $this->read( $path ), true );

		self::assertIsArray( $decoded, 'metadata.json must be a JSON object.' );

		return $decoded;
	}

	public function test_metadata_records_every_reproducibility_field(): void {
		$metadata = $this->metadata();

		$required = array(
			'capturedAtCommit',
			'capturedAtUtc',
			'siteTitle',
			'runnerImage',
			'browser',
			'browserVersion',
			'playwrightVersion',
			'fontStack',
			'fontPackage',
			'contentFixtures',
			'projects',
			'pages',
		);

		foreach ( $required as $key ) {
			self::assertArrayHasKey(
				$key,
				$metadata,
				$this->architecture_failure(
					'Migration baseline metadata is missing a reproducibility field',
					self::BASELINE_RELATIVE . '/metadata.json',
					'A baseline that does not record the browser, viewport, fixtures, fonts and commit it was taken at cannot be trusted or reproduced.',
					'Add the "' . $key . '" key with the value the capture run actually used.'
				)
			);

			self::assertNotSame(
				'TO-BE-FILLED-BY-CAPTURE-RUN',
				$metadata[ $key ] ?? null,
				'metadata.json still holds a capture-run placeholder for "' . $key . '".'
			);
		}

		foreach ( array( 'capturedAtCommit', 'capturedAtUtc', 'siteTitle', 'runnerImage', 'browser', 'browserVersion', 'playwrightVersion', 'fontStack', 'fontPackage' ) as $key ) {
			self::assertIsString( $metadata[ $key ], 'metadata.json field "' . $key . '" must be a string.' );
			self::assertNotSame( '', trim( $metadata[ $key ] ), 'metadata.json field "' . $key . '" must not be empty.' );
		}

		self::assertIsArray( $metadata['contentFixtures'], 'metadata.json field "contentFixtures" must be an array.' );
		self::assertNotEmpty( $metadata['contentFixtures'], 'metadata.json field "contentFixtures" must not be empty.' );

		foreach ( $metadata['contentFixtures'] as $fixture ) {
			self::assertIsString( $fixture, 'Each metadata.json content fixture must be a string.' );
			self::assertNotSame( '', trim( $fixture ), 'Each metadata.json content fixture must not be empty.' );
		}

		$this->assert_projects_are_complete( $metadata['projects'] );
		$this->assert_pages_are_complete( $metadata['pages'] );
	}

	public function test_every_declared_baseline_png_exists(): void {
		$metadata = $this->metadata();
		$root     = $this->repo_root() . '/' . self::BASELINE_RELATIVE;

		foreach ( array_keys( (array) $metadata['projects'] ) as $project ) {
			foreach ( (array) $metadata['pages'] as $page ) {
				$name = (string) ( $page['name'] ?? '' );

				self::assertFileExists(
					$root . '/' . $project . '/' . $name . '.png',
					$this->architecture_failure(
						'Declared migration baseline is missing on disk',
						self::BASELINE_RELATIVE . '/' . $project . '/' . $name . '.png',
						'metadata.json declares this baseline, so the parity suite will try to compare against it.',
						'Re-run the capture workflow against the pre-migration commit and commit the uploaded artifact.'
					)
				);
			}
		}
	}

	public function test_no_page_can_raise_the_five_percent_limit(): void {
		$helper = $this->read( $this->repo_root() . '/tests/parity/helpers/parity.ts' );
		preg_match_all(
			'/export const (?:MIGRATION|EDITING)_PARITY_PAGES: ParityPage\[\] = \[(.*?)\];/s',
			$helper,
			$page_lists
		);

		$declared_entries = array();

		foreach ( $page_lists[1] as $page_list ) {
			preg_match_all( '/^\s*\{.*$/m', $page_list, $list_entries );
			$declared_entries = array_merge( $declared_entries, $list_entries[0] );
		}

		// Each entry must keep the complete one-line ParityPage shape. This makes
		// every threshold a direct literal that this guard can resolve.
		preg_match_all(
			'/^\s*\{\s*name:\s*\'([^\']+)\',\s*path:\s*\'[^\']+\',\s*maxDiffRatio:\s*([0-9]+(?:\.[0-9]+)?),\s*maskSelectors:\s*\[\],\s*maxEdgeDeltaPx:\s*[0-9]+\s*\},?\s*$/m',
			$helper,
			$entries
		);

		if ( self::PARITY_PAGE_COUNT !== count( $declared_entries ) || count( $declared_entries ) !== count( $entries[0] ) ) {
			self::fail(
				$this->architecture_failure(
					'Parity page threshold cannot be resolved',
					'tests/parity/helpers/parity.ts',
					'Expected ' . self::PARITY_PAGE_COUNT . ' declared parity pages and ' . self::PARITY_PAGE_COUNT . ' complete entries with direct numeric maxDiffRatio literals, but found ' . count( $declared_entries ) . ' declared pages and ' . count( $entries[0] ) . ' direct entries. A computed value or object spread can bypass the ratio ceiling.',
					'Use one complete ParityPage literal per page and set maxDiffRatio to a direct numeric literal.'
				)
			);
		}

		$home_pages        = 0;
		$sample_page_pages = 0;

		foreach ( $entries[0] as $index => $entry ) {
			$name  = $entries[1][ $index ];
			$ratio = (float) $entries[2][ $index ];

			self::assertLessThanOrEqual(
				self::DEFAULT_MAX_DIFF_RATIO,
				$ratio,
				$this->architecture_failure(
					'A parity page raises its threshold above ' . self::DEFAULT_MAX_DIFF_RATIO,
					'tests/parity/helpers/parity.ts',
					'The release gate permits no page above five percent.',
					'Correct the rendering or mask only a proven nondeterministic region: ' . $entry
				)
			);

			if ( 'home' === $name ) {
				++$home_pages;
				self::assertSame( 0.02, $ratio, 'The migration home page must keep its 0.02 maxDiffRatio.' );
			}

			if ( 'sample-page' === $name ) {
				++$sample_page_pages;
				self::assertSame( 0.05, $ratio, 'Each sample page must keep its 0.05 maxDiffRatio.' );
			}
		}

		self::assertSame( 1, $home_pages, 'The parity page settings must declare one home page.' );
		self::assertSame( 2, $sample_page_pages, 'The parity page settings must declare two sample pages.' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @param mixed $projects
	 */
	private function assert_projects_are_complete( $projects ): void {
		self::assertIsArray( $projects, 'metadata.json field "projects" must be an object.' );
		self::assertNotEmpty( $projects, 'metadata.json field "projects" must not be empty.' );

		foreach ( $projects as $name => $project ) {
			self::assertIsString( $name, 'Each metadata.json project name must be a string.' );
			self::assertNotSame( '', trim( $name ), 'Each metadata.json project name must not be empty.' );
			self::assertMatchesRegularExpression( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name, 'Each metadata.json project name must be a file-safe directory name.' );
			self::assertIsArray( $project, 'Each metadata.json project must be an object.' );
			self::assertArrayHasKey( 'viewport', $project, 'Each metadata.json project must declare a viewport.' );
			self::assertIsArray( $project['viewport'], 'Each metadata.json viewport must be an object.' );

			foreach ( array( 'width', 'height' ) as $dimension ) {
				self::assertArrayHasKey( $dimension, $project['viewport'], 'Each metadata.json viewport must declare ' . $dimension . '.' );
				self::assertIsInt( $project['viewport'][ $dimension ], 'Each metadata.json viewport ' . $dimension . ' must be an integer.' );
				self::assertGreaterThan( 0, $project['viewport'][ $dimension ], 'Each metadata.json viewport ' . $dimension . ' must be positive.' );
			}

			self::assertArrayHasKey( 'deviceScaleFactor', $project, 'Each metadata.json project must declare a deviceScaleFactor.' );
			self::assertIsNumeric( $project['deviceScaleFactor'], 'Each metadata.json deviceScaleFactor must be numeric.' );
			self::assertGreaterThan( 0, $project['deviceScaleFactor'], 'Each metadata.json deviceScaleFactor must be positive.' );
		}
	}

	/**
	 * @param mixed $pages
	 */
	private function assert_pages_are_complete( $pages ): void {
		self::assertIsArray( $pages, 'metadata.json field "pages" must be an array.' );
		self::assertNotEmpty( $pages, 'metadata.json field "pages" must not be empty.' );

		foreach ( $pages as $page ) {
			self::assertIsArray( $page, 'Each metadata.json page must be an object.' );

			foreach ( array( 'name', 'path' ) as $key ) {
				self::assertArrayHasKey( $key, $page, 'Each metadata.json page must declare ' . $key . '.' );
				self::assertIsString( $page[ $key ], 'Each metadata.json page ' . $key . ' must be a string.' );
				self::assertNotSame( '', trim( $page[ $key ] ), 'Each metadata.json page ' . $key . ' must not be empty.' );
			}

			self::assertMatchesRegularExpression( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $page['name'], 'Each metadata.json page name must be a file-safe PNG basename.' );
			self::assertStringStartsWith( '/', $page['path'], 'Each metadata.json page path must be site-relative.' );
			self::assertArrayHasKey( 'maxDiffRatio', $page, 'Each metadata.json page must declare maxDiffRatio.' );
			self::assertIsNumeric( $page['maxDiffRatio'], 'Each metadata.json page maxDiffRatio must be numeric.' );
			self::assertArrayHasKey( 'maskSelectors', $page, 'Each metadata.json page must declare maskSelectors.' );
			self::assertIsArray( $page['maskSelectors'], 'Each metadata.json page maskSelectors must be an array.' );
		}
	}

	public function test_no_spec_can_regenerate_a_migration_baseline(): void {
		foreach ( $this->spec_files() as $file ) {
			$contents  = $this->read( $file );
			$is_parity = str_contains( str_replace( '\\', '/', $file ), '/tests/parity/' );

			if ( ! $is_parity && ! str_contains( $contents, '__migration_baselines__' ) ) {
				continue;
			}

			self::assertStringNotContainsString(
				'toHaveScreenshot',
				$contents,
				$this->architecture_failure(
					'A parity/baseline spec uses toHaveScreenshot()',
					$this->to_relative( $file ),
					'toHaveScreenshot() baselines are regenerated by `--update-snapshots`, which would let an agent overwrite the pre-migration evidence and make a regression pass.',
					'Compare with pixelmatch through compareToBaseline() in tests/parity/helpers/parity.ts instead.'
				)
			);
		}

		$this->addToAssertionCount( 1 );
	}

	public function test_baselines_live_outside_the_playwright_snapshot_tree(): void {
		$config = $this->read( $this->repo_root() . '/playwright.config.ts' );

		self::assertStringContainsString(
			'__screenshots__',
			$config,
			'playwright.config.ts must keep resolving snapshots under __screenshots__, away from __migration_baselines__.'
		);

		self::assertStringNotContainsString(
			'__migration_baselines__',
			$config,
			'playwright.config.ts must not teach Playwright how to resolve migration baselines as snapshots.'
		);

		self::assertDirectoryDoesNotExist(
			$this->repo_root() . '/tests/visual/__screenshots__/__migration_baselines__',
			'Migration baselines must never live under the Playwright snapshot directory.'
		);
	}

	/**
	 * @return list<string>
	 */
	private function spec_files(): array {
		$root = $this->repo_root() . '/tests';
		$out  = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( $item instanceof \SplFileInfo && $item->isFile() && str_ends_with( $item->getFilename(), '.ts' ) ) {
				$out[] = $item->getPathname();
			}
		}

		sort( $out );

		return $out;
	}

	private function read( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- static source inspection in the architecture suite; no WordPress runtime here.
		$source = file_get_contents( $file );

		return false === $source ? '' : $source;
	}
}
