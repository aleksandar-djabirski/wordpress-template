<?php
/**
 * BLOCK_THEME_PROPOSAL.md §11.10: database overrides are now expected, so
 * `wp agency check-overrides` is a deprecated, informational drift report
 * built on StateDiffer. It must not fail merely because a legitimate
 * override exists; only --fail-on-drift may produce a non-zero exit.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

use AgencyPlatform\State\DriftClassification;
use AgencyPlatform\State\StateCommandRunner;
use AgencyPlatform\State\StateDiffer;
use AgencyPlatform\State\StateRegistry;
use Tests\Integration\IntegrationTestCase;

/**
 * Pins the StateCommandRunner::check_overrides() contract — exit codes and
 * both streams — without WP-CLI being loaded. The REAL alias (the command a
 * human types, from the WP-CLI registration down to the process exit code)
 * is covered separately by CheckOverridesAliasTest; nothing here proves the
 * alias is wired, only that the runner it must call behaves.
 *
 * @covers \AgencyPlatform\State\StateCommandRunner
 */
final class StateCommandRunnerCheckOverridesTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	/**
	 * The runner with no injected stdin reader, git baseline, or signer:
	 * check_overrides needs none of them (the differ builds its own
	 * baseline, and the alias never reads stdin or verifies signatures).
	 */
	private function runner(): StateCommandRunner {
		return new StateCommandRunner();
	}

	public function test_an_override_alone_does_not_fail_the_command(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Client edit</p><!-- /wp:paragraph -->' );

		$result = $this->runner()->check_overrides( array() );

		self::assertSame( 0, $result->exit_code, 'Overrides are expected now; the default run must never fail because one exists.' );
		self::assertStringContainsString( 'templates:page', $result->stdout );
		self::assertStringContainsString( 'deprecated', $result->stderr );
	}

	public function test_fail_on_drift_turns_the_same_run_into_exit_one(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Client edit</p><!-- /wp:paragraph -->' );

		self::assertSame( 1, $this->runner()->check_overrides( array( 'fail-on-drift' => true ) )->exit_code );
	}

	public function test_a_clean_site_exits_zero_with_and_without_the_flag(): void {
		self::assertSame( 0, $this->runner()->check_overrides( array() )->exit_code );
		self::assertSame( 0, $this->runner()->check_overrides( array( 'fail-on-drift' => true ) )->exit_code );
	}

	public function test_the_report_covers_content_changes(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About us',
			)
		);

		$result = $this->runner()->check_overrides( array() );

		self::assertStringContainsString( 'content:page-' . $page_id, $result->stdout, 'Section 11.10 requires content changes in the report.' );
		self::assertSame( 0, $result->exit_code, 'Content is database-owned, so it can never be Git drift.' );
	}

	public function test_a_content_change_alone_never_trips_fail_on_drift(): void {
		self::factory()->post->create( array( 'post_type' => 'page' ) );

		self::assertSame( 0, $this->runner()->check_overrides( array( 'fail-on-drift' => true ) )->exit_code );
	}

	public function test_a_published_template_override_is_reported_as_promotable_not_as_corruption(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Client edit</p><!-- /wp:paragraph -->' );

		$report = ( new StateDiffer() )->diff_against_git( StateRegistry::resolve( null, false ) );
		$entry  = $this->find_entry( $report, 'templates:page' );

		self::assertSame( DriftClassification::PROMOTABLE, $entry['classification'] );
		self::assertTrue( StateDiffer::has_drift( $report ) );
	}

	public function test_synced_patterns_and_navigation_stay_informational(): void {
		$this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );
		$this->make_synced_pattern( 'callout', '<!-- wp:paragraph --><p>Pattern</p><!-- /wp:paragraph -->' );

		$result = $this->runner()->check_overrides( array() );

		self::assertStringContainsString( 'synced-patterns:callout (added, db-owned)', $result->stdout, 'Section 11.10 requires the synced-patterns report entry; a missing or broken wp_block provider must not pass.' );
		self::assertSame( 0, $result->exit_code, 'Database-owned providers alone must never make check-overrides fail.' );

		$report = ( new StateDiffer() )->diff_against_git( StateRegistry::resolve( null, false ) );

		self::assertFalse( StateDiffer::has_drift( $report ), 'Database-owned providers alone must never make check-overrides report drift.' );
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
