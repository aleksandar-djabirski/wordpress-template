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

use AgencyPlatform\State\Promotion\PromotionFinalizer;
use AgencyPlatform\State\Promotion\StateGateway;
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
