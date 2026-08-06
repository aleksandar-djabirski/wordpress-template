<?php
/**
 * BLOCK_THEME_PROPOSAL.md §7.4: the target-side navigation resolution must
 * match WordPress core exactly — post_type=wp_navigation, post_status=publish,
 * orderby=date, order=DESC, posts_per_page=1 — because both a source ref-less
 * block and a block whose explicit ref was removed proceed only when the
 * target's deterministic fallback resolves the exported source hash. The
 * resolved hash is read through Task 2's own navigation provider, so the
 * comparison never depends on guessing that provider's content shape.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\Promotion\NavigationBlockScanner;
use AgencyPlatform\State\Promotion\NavigationPolicy;
use AgencyPlatform\State\Promotion\PromotionFinalizer;
use AgencyPlatform\State\Promotion\StateGateway;
use AgencyPlatform\State\ReferenceResolver;
use AgencyPlatform\State\ReferenceScanner;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\PromotionFinalizer
 */
final class NavigationResolutionTest extends IntegrationTestCase {

	public function test_the_target_fallback_is_the_most_recently_published_navigation(): void {
		$older = $this->create_navigation( 'primary', '2026-01-01 00:00:00' );
		$newer = $this->create_navigation( 'secondary', '2026-06-01 00:00:00' );

		self::assertSame( $newer, $this->finalizer_navigation_fallback_id() );
	}

	public function test_three_published_navigations_resolve_to_the_newest(): void {
		// One navigation post cannot distinguish "picks the newest" from
		// "picks the only one", so the rule is proven with three. The dates
		// must be in the past: WordPress schedules a publish post whose date
		// is in the future (status becomes 'future'), which would make it
		// invisible to the fallback query.
		$this->create_navigation( 'oldest', '2026-01-01 00:00:00' );
		$this->create_navigation( 'middle', '2026-02-01 00:00:00' );
		$newest = $this->create_navigation( 'newest', '2026-03-01 00:00:00' );

		$fallback = $this->finalizer_navigation_fallback();

		self::assertSame( $newest, $fallback['id'], 'With three published navigations the fallback must be the most recently published one.' );
		self::assertSame( $this->navigation_record_content_hash( $newest ), $fallback['hash'] );
	}

	public function test_a_draft_navigation_is_never_the_fallback(): void {
		// The draft is newer than the published navigation and must be ignored.
		$published = $this->create_navigation( 'primary', '2026-01-01 00:00:00' );
		$this->create_navigation( 'draft-secondary', '2026-06-01 00:00:00', 'draft' );

		self::assertSame( $published, $this->finalizer_navigation_fallback_id() );
	}

	public function test_with_no_published_navigation_the_fallback_is_empty(): void {
		self::assertSame(
			array(
				'id'       => null,
				'hash'     => null,
				'identity' => null,
			),
			$this->finalizer_navigation_fallback()
		);
	}

	public function test_the_resolved_hash_uses_the_navigation_provider_convention(): void {
		// The target hash must be read from Task 2's own navigation records, so the
		// comparison never depends on guessing that provider's content shape.
		$id = $this->create_navigation( 'primary', '2026-01-01 00:00:00' );

		self::assertSame(
			$this->navigation_record_content_hash( $id ),
			$this->finalizer_navigation_fallback_hash()
		);
	}

	/**
	 * THE regression test for the cross-unit gap a bounded review confirmed on
	 * 2026-08-06, and the one test here that drives the REAL pipeline end to
	 * end rather than a fixture.
	 *
	 * The plan's Task 2 navigation contract says a reference is emitted for
	 * EVERY core/navigation block, with core's deterministic fallback resolved
	 * at export for a ref-less one. The second half was never implemented, so a
	 * ref-less block produced no reference at all, the navigation policy
	 * correctly refused the record, and the shipped theme's own site-header —
	 * whose navigation IS ref-less — became unpromotable. Nothing before the
	 * Task 16 vertical slice would have noticed.
	 *
	 * It stayed invisible because the policy's ref-less acceptance test feeds a
	 * SYNTHETIC reference the real scanner never produced. This test therefore
	 * reads the SHIPPED theme file and runs the REAL scanner, the REAL resolver
	 * and the REAL policy. Do not replace any of them with a fixture.
	 */
	public function test_the_shipped_ref_less_site_header_is_promotable_through_the_real_pipeline(): void {
		$markup = (string) file_get_contents( get_stylesheet_directory() . '/parts/site-header.html' );

		self::assertStringContainsString( 'wp:navigation', $markup, 'The shipped header must still contain a navigation block.' );
		self::assertStringNotContainsString( '"ref"', $markup, 'The shipped header navigation must still be ref-less; this test exists for that case.' );

		$this->create_navigation( 'probe-menu', '2026-01-01 00:00:00' );

		$references = ReferenceResolver::resolve( ReferenceScanner::scan( $markup, 'template-parts:site-header' ) );

		$navigation = array_values(
			array_filter( $references, static fn( array $reference ): bool => 'navigation' === $reference['kind'] )
		);

		self::assertCount( 1, $navigation, 'A ref-less navigation block must still produce exactly one navigation reference.' );
		self::assertNull( $navigation[0]['value'], 'A ref-less block records a null value.' );
		self::assertNotEmpty( $navigation[0]['targetHash'] ?? null, 'The export must record the fallback target hash; without it the record is refused.' );

		$outcome = NavigationPolicy::evaluate(
			'template-parts:site-header',
			'template-parts',
			'site-header',
			NavigationBlockScanner::scan( $markup ),
			$references
		);

		self::assertSame(
			array(),
			$outcome['refusals'],
			'The shipped ref-less site-header must be promotable; a refusal here blocks the Task 16 vertical slice.'
		);
	}

	/**
	 * @return array{id:int|null, hash:string|null, identity:array|null}
	 */
	private function finalizer_navigation_fallback(): array {
		return ( new PromotionFinalizer( new StateGateway() ) )->resolve_navigation_fallback();
	}

	private function finalizer_navigation_fallback_id(): int {
		$fallback = $this->finalizer_navigation_fallback();

		self::assertNotNull( $fallback['id'], 'The fallback must resolve to a published wp_navigation post.' );

		return (int) $fallback['id'];
	}

	private function finalizer_navigation_fallback_hash(): string {
		$fallback = $this->finalizer_navigation_fallback();

		self::assertNotNull( $fallback['hash'], 'The fallback must carry a content hash from the navigation provider.' );

		return (string) $fallback['hash'];
	}

	/**
	 * The content hash of a live navigation record, read through Task 2's
	 * own provider so the comparison uses its content convention.
	 */
	private function navigation_record_content_hash( int $object_id ): string {
		foreach ( ( new StateGateway() )->live_records( 'navigation' ) as $record ) {
			if ( (int) ( $record['objectId'] ?? 0 ) === $object_id ) {
				return (string) $record['contentHash'];
			}
		}

		self::fail( sprintf( 'No live navigation record exists for objectId %d.', $object_id ) );
	}

	/**
	 * One wp_navigation row with an explicit post_date, so the
	 * most-recently-published rule is exercised by real dates.
	 */
	private function create_navigation( string $name, string $date, string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_name'    => $name,
				'post_status'  => $status,
				'post_date'    => $date,
				'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
			)
		);
	}
}
