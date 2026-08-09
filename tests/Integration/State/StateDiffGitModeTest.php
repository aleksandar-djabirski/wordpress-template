<?php
/**
 * The Git-mode differ contract (BLOCK_THEME_PROPOSAL.md §5.3, §6): a clean
 * install with no overrides and no custom CSS must report no drift (the
 * overlay is what makes that true — without it every Git template with no
 * wp_template row would read `removed`), a byte-identical database override
 * is reported but is not drift, a real baseline divergence IS drift, and
 * database-owned navigation plus forbidden custom CSS behave exactly as the
 * classification contract says.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

use AgencyPlatform\State\DriftClassification;
use AgencyPlatform\State\GitBaseline;
use AgencyPlatform\State\Providers\TemplatePartsState;
use AgencyPlatform\State\Providers\TemplatesState;
use AgencyPlatform\State\StateDiffer;
use AgencyPlatform\State\StateRegistry;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\StateDiffer
 * @covers \AgencyPlatform\State\GitBaseline
 * @covers \AgencyPlatform\State\Providers\TemplatesState
 */
final class StateDiffGitModeTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	public function tear_down(): void {
		parent::tear_down();

		$directory = $this->temporary_theme_dir();

		if ( is_dir( $directory ) ) {
			$this->remove_directory( $directory );
		}
	}

	public function test_a_clean_install_reports_no_drift(): void {
		$report = ( new StateDiffer() )->diff_against_git( StateRegistry::resolve( null, false ) );

		self::assertFalse( StateDiffer::has_drift( $report ), 'A site with no overrides and no custom CSS must exit 0.' );
	}

	/**
	 * The Critical regression guard. Runs only once Task 1 has merged and the
	 * theme actually ships templates/*.html. This is a required Release 2
	 * condition, so a missing block-theme baseline is a failure, never a skip.
	 * Re-run this after the Task 13 rebase.
	 */
	public function test_a_shipped_block_theme_with_no_database_overrides_reports_no_drift(): void {
		$baseline = new GitBaseline();

		self::assertTrue( $baseline->has_block_templates(), 'The required block-theme baseline is absent: templates/index.html must exist after Task 1 has merged.' );

		self::assertNotSame( array(), $baseline->template_markup(), 'Sanity: the block theme must ship template files.' );
		self::assertSame( array(), ( new TemplatesState() )->records(), 'Sanity: a fresh install has no wp_template rows.' );
		self::assertSame( array(), ( new TemplatePartsState() )->records(), 'Sanity: a fresh install has no wp_template_part rows.' );

		$report = ( new StateDiffer() )->diff_against_git( array( 'templates', 'template-parts' ) );

		$expected_keys = $this->shipped_theme_keys();

		self::assertContains( 'templates:404', $expected_keys, 'The shipped theme includes 404.html, so the expected key set must contain templates:404 as a STRING slug.' );
		self::assertSame(
			$expected_keys,
			array_column( $report['entries'], 'key' ),
			'EVERY shipped Git template and part must be compared: the report must carry exactly the shipped key set and nothing less — a differ that compares nothing must fail here.'
		);
		self::assertSame(
			array_fill( 0, count( $expected_keys ), 'unchanged' ),
			array_column( $report['entries'], 'status' ),
			'Every Git template or part with no database override must read `unchanged`, never `removed`. Without the overlay this exits 2 on a healthy site.'
		);
		self::assertSame(
			'unchanged',
			$this->find_entry( $report, 'templates:404' )['status'],
			'The numeric-slug template must be compared too, under its STRING key templates:404.'
		);
		self::assertFalse(
			StateDiffer::has_drift( $report ),
			'A site with no overrides and no custom CSS must exit 0.'
		);
	}

	/**
	 * The regression this file exists for: the shipped theme's 404.html has a
	 * numeric basename, PHP casts it to an INT array key, and StateRecord
	 * declares a string slug under strict_types — so the un-cast key threw a
	 * TypeError and wp agency state-diff fataled on a healthy block theme.
	 * A numeric template name must produce a record with the STRING slug
	 * "404" and must never throw.
	 */
	public function test_a_numeric_template_name_yields_a_string_slug_record(): void {
		$this->write_baseline_template( '404', '<!-- wp:paragraph --><p>Not found</p><!-- /wp:paragraph -->' );

		$git       = new GitBaseline( null, $this->temporary_theme_dir() );
		$providers = array( 'templates' => new TemplatesState( $git ) );
		$differ    = new StateDiffer( $git, $providers );

		$records = $providers['templates']->baseline_records();

		self::assertCount( 1, $records );
		self::assertSame( '404', $records[0]->slug(), 'The numeric basename must arrive as the STRING "404", not the int 404.' );
		self::assertSame( 'templates:404', $records[0]->key() );

		$report = $differ->diff_against_git( array( 'templates' ) );

		self::assertSame( 'unchanged', $this->find_entry( $report, 'templates:404' )['status'] );
		self::assertFalse( StateDiffer::has_drift( $report ), 'An untouched 404 baseline must not report drift.' );
	}

	public function test_a_byte_identical_database_override_is_reported_but_is_not_drift(): void {
		$markup = '<!-- wp:paragraph --><p>Same as Git</p><!-- /wp:paragraph -->';
		$this->write_baseline_template( 'page', $markup );
		$this->write_baseline_template( 'single', $markup );
		$this->make_template( 'page', $markup );

		$git       = new GitBaseline( null, $this->temporary_theme_dir() );
		$providers = array( 'templates' => new TemplatesState( $git ) );
		$differ    = new StateDiffer( $git, $providers );

		$report = $differ->diff_against_git( array( 'templates' ) );
		$entry  = $this->find_entry( $report, 'templates:page' );

		self::assertSame( 'unchanged', $entry['status'] );
		self::assertFalse( $entry['countsAsDrift'] );
		self::assertTrue( $entry['hasDatabaseOverride'], 'The operator must still be able to see that a row exists.' );
		self::assertFalse( $this->find_entry( $report, 'templates:single' )['hasDatabaseOverride'], 'A Git-only row has no database override.' );
	}

	public function test_a_database_template_override_is_promotable_drift(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Client edit</p><!-- /wp:paragraph -->' );

		$report = ( new StateDiffer() )->diff_against_git( array( 'templates' ) );

		self::assertSame( DriftClassification::PROMOTABLE, $this->find_entry( $report, 'templates:page' )['classification'] );
		self::assertTrue( StateDiffer::has_drift( $report ) );
	}

	public function test_a_navigation_record_is_reported_but_is_not_drift(): void {
		$this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );

		$report = ( new StateDiffer() )->diff_against_git( array( 'navigation' ) );

		self::assertSame( DriftClassification::DB_OWNED, $this->find_entry( $report, 'navigation:primary' )['classification'] );
		self::assertFalse( StateDiffer::has_drift( $report ) );
	}

	public function test_custom_css_in_global_styles_is_forbidden_drift(): void {
		$this->make_global_styles( '{"version":3,"styles":{"css":"body{color:red}"}}' );

		$report = ( new StateDiffer() )->diff_against_git( array( 'custom-css' ) );

		self::assertSame( DriftClassification::FORBIDDEN, $this->find_entry( $report, 'custom-css:global-styles' )['classification'] );
		self::assertTrue( StateDiffer::has_drift( $report ) );
	}

	public function test_custom_css_in_the_custom_css_post_type_is_forbidden_drift(): void {
		wp_update_custom_css_post( '.legacy { color: blue; }' );

		$report = ( new StateDiffer() )->diff_against_git( array( 'custom-css' ) );

		self::assertSame( DriftClassification::FORBIDDEN, $this->find_entry( $report, 'custom-css:custom-css-post' )['classification'] );
		self::assertTrue( StateDiffer::has_drift( $report ) );
	}

	public function test_a_customised_global_styles_row_is_promotable_drift(): void {
		$this->make_global_styles( '{"version":3,"styles":{"color":{"background":"var(--wp--preset--color--base)"}}}' );

		$report = ( new StateDiffer() )->diff_against_git( array( 'global-styles' ) );

		self::assertSame( DriftClassification::PROMOTABLE, $this->find_entry( $report, 'global-styles:active' )['classification'] );
		self::assertTrue( StateDiffer::has_drift( $report ) );
	}

	/**
	 * The COMPLETE expected Git-mode key set, derived directly from the
	 * shipped theme's files on disk — never through GitBaseline or the
	 * differ — so a differ that stops comparing files cannot hide here.
	 * A numeric basename ("404.html") arrives as the STRING key
	 * "templates:404", the same strict comparison the report must pass.
	 *
	 * @return list<string> Keys like templates:page and template-parts:site-header, sorted ascending.
	 */
	private function shipped_theme_keys(): array {
		$keys = array();

		foreach (
			array(
				'templates' => 'templates',
				'parts'     => 'template-parts',
			) as $directory => $prefix
		) {
			$files = glob( get_stylesheet_directory() . '/' . $directory . '/*.html' );

			if ( false === $files ) {
				continue;
			}

			foreach ( $files as $file ) {
				$keys[] = $prefix . ':' . basename( $file, '.html' );
			}
		}

		sort( $keys, SORT_STRING );

		return $keys;
	}

	/**
	 * A controllable Git baseline without writing into the real theme
	 * directory: a temporary theme directory containing templates/<slug>.html.
	 */
	private function write_baseline_template( string $slug, string $markup ): void {
		$directory = $this->temporary_theme_dir() . '/templates';

		if ( ! is_dir( $directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating an integration fixture directory; the WP_Filesystem credentials context does not exist here.
			mkdir( $directory, 0777, true );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing an integration fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $directory . '/' . $slug . '.html', $markup );
	}

	private function temporary_theme_dir(): string {
		$directory = sys_get_temp_dir() . '/state-diff-git-' . getmypid();

		if ( ! is_dir( $directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating an integration fixture directory; the WP_Filesystem credentials context does not exist here.
			mkdir( $directory, 0777, true );
		}

		return $directory;
	}

	private function remove_directory( string $directory ): void {
		foreach ( scandir( $directory ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $directory . '/' . $entry;

			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
				unlink( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- deleting an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		rmdir( $directory );
	}

	/**
	 * @param array<string, mixed> $report
	 * @return array<string, mixed>
	 */
	private function find_entry( array $report, string $key ): array {
		foreach ( $report['entries'] as $entry ) {
			if ( $key === $entry['key'] ) {
				return $entry;
			}
		}

		self::fail( 'No diff entry with key "' . $key . '" in the report.' );

		return array();
	}
}
