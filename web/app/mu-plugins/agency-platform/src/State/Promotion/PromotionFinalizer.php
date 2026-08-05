<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The finalise stage of the promotion lifecycle (BLOCK_THEME_PROPOSAL.md
 * §7.8). Task 9 ships the target-side navigation fallback resolution the
 * §7.4 navigation policy judges at finalisation; the per-record finalise
 * loop that consumes it lands in Task 14.
 */
final class PromotionFinalizer {

	private readonly StateGateway $gateway;

	public function __construct( StateGateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * The deterministic target-side resolution WordPress core's navigation
	 * fallback uses: the most recently published wp_navigation post
	 * (post_type=wp_navigation, post_status=publish, orderby=date,
	 * order=DESC, posts_per_page=1 — the same query WP_Navigation_Fallback
	 * runs). The hash is read through Task 2's own navigation provider so
	 * the comparison uses that provider's content convention rather than a
	 * guess.
	 *
	 * @return array{id:int|null, hash:string|null, identity:array<string, mixed>|null}
	 */
	public function resolve_navigation_fallback(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		if ( array() === $posts ) {
			return array(
				'id'       => null,
				'hash'     => null,
				'identity' => null,
			);
		}

		// Read the hash through Task 2's own navigation provider so the comparison
		// uses that provider's content convention rather than a guess.
		foreach ( $this->gateway->live_records( 'navigation' ) as $record ) {
			if ( (int) ( $record['objectId'] ?? 0 ) === $posts[0]->ID ) {
				return array(
					'id'       => $posts[0]->ID,
					'hash'     => (string) $record['contentHash'],
					'identity' => array(
						'slug'   => $record['slug'],
						'status' => $record['status'],
					),
				);
			}
		}

		return array(
			'id'       => $posts[0]->ID,
			'hash'     => null,
			'identity' => null,
		);
	}
}
