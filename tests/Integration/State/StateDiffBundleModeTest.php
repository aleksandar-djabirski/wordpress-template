<?php
/**
 * The bundle-mode differ contract (BLOCK_THEME_PROPOSAL.md §6): a diff
 * against a previously exported bundle treats every post-export change as
 * drift — including database-owned navigation and content, because the
 * bundle is the promotion track's record of what it exported — while a
 * provider that was never exported is skipped, never drift. A bundle from
 * another site is a hard error, and an unverified bundle cannot be diffed
 * at all.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\StateBundle;
use AgencyPlatform\State\StateDiffer;
use AgencyPlatform\State\StateException;
use AgencyPlatform\State\StateExporter;
use AgencyPlatform\State\StateRegistry;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\StateDiffer
 * @covers \AgencyPlatform\State\StateExporter
 * @covers \AgencyPlatform\State\StateBundle
 */
final class StateDiffBundleModeTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	private function signer(): HmacSigner {
		return new HmacSigner( array( '2026-01' => str_repeat( 'k', 40 ) ), '2026-01' );
	}

	/**
	 * @param list<string>|null $slugs
	 * @return array<string, mixed>
	 */
	private function exported_document( ?array $slugs = null ): array {
		return ( new StateExporter( $this->signer() ) )->export( $slugs ?? StateRegistry::resolve( null, false ) );
	}

	/**
	 * @param list<string>|null $slugs
	 */
	private function exported_bundle( ?array $slugs = null ): StateBundle {
		$bundle = StateBundle::from_array( $this->exported_document( $slugs ) );
		$bundle->verify_signature( $this->signer() );

		return $bundle;
	}

	/**
	 * A verified bundle that provably belongs to another site: the document
	 * is re-signed after the siteUuid swap, otherwise the test would fail on
	 * the signature rather than on the site check it is meant to prove.
	 */
	private function bundle_from_another_site(): StateBundle {
		$document             = $this->exported_document();
		$document['siteUuid'] = '00000000-1111-4222-8333-444455556666';

		$signature             = $this->signer()->sign( $document, HmacSigner::PURPOSE_BUNDLE );
		$document['hmacKeyId'] = $signature['hmacKeyId'];
		$document['hmac']      = $signature['hmac'];

		$bundle = StateBundle::from_array( $document );
		$bundle->verify_signature( $this->signer() );

		return $bundle;
	}

	/**
	 * The post-export navigation edit, used for both drift shapes: when the
	 * row exists it is UPDATED in place (the changed-row case), when it does
	 * not exist yet it is created (the added-row case). Both are post-export
	 * drift and the gate must catch both.
	 */
	private function update_navigation( string $slug, string $markup ): void {
		$post = get_page_by_path( $slug, OBJECT, 'wp_navigation' );

		if ( $post instanceof \WP_Post ) {
			wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => $markup,
				)
			);

			return;
		}

		$this->make_navigation( $slug, $markup );
	}

	public function test_no_drift_immediately_after_an_export(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Baseline</p><!-- /wp:paragraph -->' );
		$this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );

		$report = ( new StateDiffer() )->diff_against_bundle( StateRegistry::resolve( null, false ), $this->exported_bundle() );

		self::assertFalse( StateDiffer::has_drift( $report ), 'Nothing changed between the export and the diff, so there is no post-export drift.' );
	}

	public function test_a_navigation_change_after_export_counts_as_drift(): void {
		$this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );

		$bundle = $this->exported_bundle();

		$this->update_navigation( 'primary', '<!-- wp:navigation-link {"label":"Changed"} /-->' );

		$report = ( new StateDiffer() )->diff_against_bundle( StateRegistry::resolve( null, false ), $bundle );

		$entry = $this->find_entry( $report, 'navigation:primary' );

		self::assertSame( 'changed', $entry['status'], 'The navigation row existed at export time, so the post-export edit must compare as a changed row — a broken existing-row comparison must not pass.' );
		self::assertTrue( $entry['countsAsDrift'], 'A navigation change made after export must be caught as post-export drift.' );
		self::assertTrue( StateDiffer::has_drift( $report ) );
	}

	public function test_a_navigation_added_after_export_counts_as_drift(): void {
		$bundle = $this->exported_bundle();

		$this->update_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );

		$report = ( new StateDiffer() )->diff_against_bundle( StateRegistry::resolve( null, false ), $bundle );

		$entry = $this->find_entry( $report, 'navigation:primary' );

		self::assertSame( 'added', $entry['status'], 'A navigation row created only after export has no bundle counterpart and must compare as added.' );
		self::assertTrue( $entry['countsAsDrift'], 'A navigation row that appeared after export must be caught as post-export drift.' );
		self::assertTrue( StateDiffer::has_drift( $report ) );
	}

	public function test_a_content_change_after_export_counts_as_drift_when_content_was_exported(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
			)
		);
		$bundle  = $this->exported_bundle( StateRegistry::resolve( null, true ) );

		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => '<!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->',
			)
		);

		$report = ( new StateDiffer() )->diff_against_bundle( StateRegistry::resolve( null, true ), $bundle );

		self::assertTrue( StateDiffer::has_drift( $report ) );
		self::assertSame( 'changed', $this->find_entry( $report, 'content:page-' . $page_id )['status'] );
	}

	public function test_a_provider_missing_from_the_bundle_is_skipped_not_drift(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Only templates were exported</p><!-- /wp:paragraph -->' );
		$bundle = $this->exported_bundle( array( 'templates' ) );

		$this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );

		$report = ( new StateDiffer() )->diff_against_bundle( array( 'templates', 'navigation' ), $bundle );

		self::assertContains( 'navigation', $report['skippedProviders'] );
		self::assertFalse( StateDiffer::has_drift( $report ), 'A provider that was never exported cannot be post-export drift.' );
	}

	public function test_a_bundle_from_another_site_is_a_hard_error(): void {
		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/siteUuid|site UUID/' );

		( new StateDiffer() )->diff_against_bundle( array( 'templates' ), $this->bundle_from_another_site() );
	}

	public function test_an_unverified_bundle_cannot_be_diffed(): void {
		$this->expectException( StateException::class );

		( new StateDiffer() )->diff_against_bundle( array( 'templates' ), StateBundle::from_array( $this->exported_document() ) );
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
