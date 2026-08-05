<?php
/**
 * The whole two-mode classification contract
 * (BLOCK_THEME_PROPOSAL.md §5.3, §6, §11.10), unit-tested without
 * WordPress: every cell of the status × classification × countsAsDrift
 * matrix, the Git-mode overlay, the explicit database-override-key
 * metadata, the summary counters, and the sort order. The two directions
 * that matter most are both pinned: a database-owned provider alone never
 * makes Git mode report drift, and a real Git-baseline divergence is
 * reported; a post-export change to a database-owned record IS drift in
 * bundle mode.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\DriftClassification;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\ReferenceScanner;
use AgencyPlatform\State\StateDiffer;
use AgencyPlatform\State\StateRecord;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\StateDiffer
 */
final class StateDifferCompareTest extends TestCase {

	/** @return array<string, array{ownership: string, promotion: string, hasGitBaseline: bool}> */
	private function meta(): array {
		return array(
			'templates'  => array(
				'ownership'      => Ownership::GIT_BASELINE_PLUS_DB,
				'promotion'      => PromotionPolicy::PROMOTABLE,
				'hasGitBaseline' => true,
			),
			'navigation' => array(
				'ownership'      => Ownership::DATABASE,
				'promotion'      => PromotionPolicy::EXPORT_AND_DIFF,
				'hasGitBaseline' => false,
			),
			'custom-css' => array(
				'ownership'      => Ownership::FORBIDDEN,
				'promotion'      => PromotionPolicy::REFUSE,
				'hasGitBaseline' => true,
			),
		);
	}

	public function test_an_identical_record_is_unchanged_and_is_not_drift(): void {
		$entries = StateDiffer::compare(
			array( $this->template( 'page', 'same' ) ),
			array( $this->template( 'page', 'same' ) ),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( 'unchanged', $entries[0]['status'] );
		self::assertSame( DriftClassification::UNCHANGED, $entries[0]['classification'] );
		self::assertFalse( $entries[0]['countsAsDrift'] );
	}

	public function test_a_changed_template_is_promotable_drift_in_git_mode(): void {
		$entries = StateDiffer::compare(
			array( $this->template( 'page', 'after' ) ),
			array( $this->template( 'page', 'before' ) ),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( 'changed', $entries[0]['status'] );
		self::assertSame( DriftClassification::PROMOTABLE, $entries[0]['classification'] );
		self::assertTrue( $entries[0]['countsAsDrift'] );
		self::assertNotSame( $entries[0]['currentHash'], $entries[0]['targetHash'] );
	}

	public function test_a_template_absent_from_git_is_added_and_is_drift(): void {
		$entries = StateDiffer::compare(
			array( $this->template( 'page', 'only-in-db' ) ),
			array(),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( 'added', $entries[0]['status'] );
		self::assertTrue( $entries[0]['countsAsDrift'] );
		self::assertNull( $entries[0]['targetHash'] );
	}

	public function test_a_template_present_only_in_the_bundle_is_removed_and_is_drift(): void {
		$entries = StateDiffer::compare(
			array(),
			array( $this->template( 'page', 'was-exported' ) ),
			StateDiffer::MODE_BUNDLE,
			$this->meta()
		);

		self::assertSame( 'removed', $entries[0]['status'] );
		self::assertTrue( $entries[0]['countsAsDrift'], 'An override deleted after export is real post-export drift.' );
		self::assertNull( $entries[0]['currentHash'] );
	}

	public function test_overlay_fills_every_git_slug_that_has_no_database_override(): void {
		$baseline = array( $this->template( 'page', 'from-git' ), $this->template( 'single', 'from-git-too' ) );
		$database = array( $this->template( 'page', 'overridden' ) );

		$current = StateDiffer::overlay( $baseline, $database );

		self::assertSame( array( 'templates:page', 'templates:single' ), array_map( static fn ( $record ) => $record->key(), $current ) );
		self::assertSame( 'overridden', $current[0]->content()['markup'], 'A database override replaces its baseline counterpart.' );
		self::assertSame( 'from-git-too', $current[1]->content()['markup'], 'A slug with no override keeps the Git baseline.' );
	}

	public function test_overlay_keeps_a_database_record_that_has_no_git_counterpart(): void {
		$current = StateDiffer::overlay( array(), array( $this->template( 'custom-page', 'db-only' ) ) );

		self::assertCount( 1, $current );
		self::assertSame( 'templates:custom-page', $current[0]->key() );
	}

	/**
	 * The regression this overlay exists to prevent: a healthy block theme has
	 * every template in Git and NO wp_template row. Comparing raw database
	 * records against Git would mark every template `removed` and exit 2.
	 */
	public function test_a_git_only_template_set_produces_no_drift_at_all(): void {
		$baseline = array( $this->template( 'page', 'shipped' ), $this->template( 'single', 'shipped-too' ) );

		$entries = StateDiffer::compare(
			StateDiffer::overlay( $baseline, array() ),
			$baseline,
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( array( 'unchanged', 'unchanged' ), array_column( $entries, 'status' ) );
		self::assertSame( 0, StateDiffer::summarize( $entries )['drift'] );
		self::assertFalse( $entries[0]['hasDatabaseOverride'] );
	}

	public function test_compare_marks_only_explicit_database_override_keys(): void {
		$entries = StateDiffer::compare(
			array( $this->template( 'page', 'same' ), $this->template( 'single', 'same' ) ),
			array( $this->template( 'page', 'same' ), $this->template( 'single', 'same' ) ),
			StateDiffer::MODE_GIT,
			$this->meta(),
			array( 'templates:page' )
		);

		self::assertTrue( $entries[0]['hasDatabaseOverride'] );
		self::assertFalse( $entries[1]['hasDatabaseOverride'] );
	}

	public function test_a_changed_navigation_is_db_owned_and_is_NOT_git_drift(): void {
		$entries = StateDiffer::compare(
			array( $this->navigation( 'primary', 'after' ) ),
			array( $this->navigation( 'primary', 'before' ) ),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( DriftClassification::DB_OWNED, $entries[0]['classification'] );
		self::assertFalse( $entries[0]['countsAsDrift'], 'Database-owned providers have no Git counterpart and must never register as Git drift.' );
	}

	public function test_a_changed_navigation_IS_drift_in_bundle_mode(): void {
		$entries = StateDiffer::compare(
			array( $this->navigation( 'primary', 'after' ) ),
			array( $this->navigation( 'primary', 'before' ) ),
			StateDiffer::MODE_BUNDLE,
			$this->meta()
		);

		self::assertSame( 'navigation:primary', $entries[0]['key'], 'The bundle-mode entry must be the navigation row being compared.' );
		self::assertSame( 'changed', $entries[0]['status'], 'A post-export edit to an existing row must compare as changed, not added.' );
		self::assertSame( DriftClassification::DB_OWNED, $entries[0]['classification'], 'The mode changes the drift rule, never the classification: a database-owned record stays db-owned.' );
		self::assertNotSame( $entries[0]['currentHash'], $entries[0]['targetHash'], 'The row counts as changed only when its current and target content hashes differ.' );
		self::assertTrue( $entries[0]['countsAsDrift'], 'A navigation change made after export must be caught as post-export drift.' );
	}

	public function test_a_promotable_record_with_an_unresolved_reference_is_classified_unresolved(): void {
		$reference = array(
			'record'     => 'templates:page',
			'provider'   => 'templates',
			'blockName'  => 'core/block',
			'attribute'  => 'ref',
			'value'      => 8,
			'kind'       => ReferenceScanner::KIND_SYNCED_PATTERN,
			'resolution' => ReferenceScanner::RESOLUTION_ENVIRONMENT,
			'policy'     => 'Synced patterns stay database-owned in v1.',
		);

		$entries = StateDiffer::compare(
			array( $this->template( 'page', 'after', array( $reference ) ) ),
			array( $this->template( 'page', 'before' ) ),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( DriftClassification::UNRESOLVED, $entries[0]['classification'] );
		self::assertTrue( $entries[0]['countsAsDrift'] );
		self::assertCount( 1, $entries[0]['unresolvedReferences'] );
	}

	public function test_non_empty_custom_css_is_forbidden_drift_in_both_modes(): void {
		foreach ( array( StateDiffer::MODE_GIT, StateDiffer::MODE_BUNDLE ) as $mode ) {
			$entries = StateDiffer::compare(
				array( $this->custom_css( 'global-styles', 'body{color:red}' ) ),
				array( $this->custom_css( 'global-styles', '' ) ),
				$mode,
				$this->meta()
			);

			self::assertSame( DriftClassification::FORBIDDEN, $entries[0]['classification'], $mode );
			self::assertTrue( $entries[0]['countsAsDrift'], $mode );
		}
	}

	public function test_empty_custom_css_is_unchanged_and_is_not_drift(): void {
		$entries = StateDiffer::compare(
			array( $this->custom_css( 'global-styles', '' ) ),
			array( $this->custom_css( 'global-styles', '' ) ),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( DriftClassification::UNCHANGED, $entries[0]['classification'] );
		self::assertFalse( $entries[0]['countsAsDrift'] );
	}

	public function test_entries_are_sorted_by_key(): void {
		$entries = StateDiffer::compare(
			array( $this->template( 'search', 'x' ), $this->template( 'archive', 'y' ) ),
			array(),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( array( 'templates:archive', 'templates:search' ), array_column( $entries, 'key' ) );
	}

	public function test_summarize_counts_every_classification_and_has_drift_agrees(): void {
		$entries = StateDiffer::compare(
			array( $this->template( 'page', 'after' ), $this->navigation( 'primary', 'after' ) ),
			array( $this->template( 'page', 'before' ), $this->navigation( 'primary', 'before' ) ),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		$summary = StateDiffer::summarize( $entries );

		self::assertSame( 1, $summary['drift'] );
		self::assertSame( 1, $summary['promotable'] );
		self::assertSame( 1, $summary['dbOwned'] );
		self::assertTrue( StateDiffer::has_drift( array( 'entries' => $entries ) + array( 'summary' => $summary ) ) );
	}

	/**
	 * @param list<array<string, mixed>> $references
	 */
	private function template( string $slug, string $markup, array $references = array() ): StateRecord {
		return StateRecord::create(
			'templates',
			$slug,
			null,
			'publish',
			null,
			array( 'markup' => $markup ),
			$references,
			Ownership::GIT_BASELINE_PLUS_DB,
			PromotionPolicy::PROMOTABLE
		);
	}

	private function navigation( string $slug, string $markup ): StateRecord {
		return StateRecord::create(
			'navigation',
			$slug,
			null,
			'publish',
			null,
			array( 'markup' => $markup ),
			array(),
			Ownership::DATABASE,
			PromotionPolicy::EXPORT_AND_DIFF
		);
	}

	private function custom_css( string $slug, string $css ): StateRecord {
		return StateRecord::create(
			'custom-css',
			$slug,
			null,
			'publish',
			null,
			array(
				'css'    => $css,
				'length' => strlen( $css ),
			),
			array(),
			Ownership::FORBIDDEN,
			PromotionPolicy::REFUSE
		);
	}
}
