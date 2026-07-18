<?php

declare(strict_types=1);

namespace SiteCore\Contracts;

/**
 * Stable, read-only public API for testimonials content.
 *
 * This is the ONLY site-core surface (together with the rest of
 * SiteCore\Contracts) that the theme, site-integrations, and site-commerce
 * may depend on directly — see deptrac.yaml's SiteCoreContracts layer,
 * which forbids everyone else from reaching past this file into
 * SiteCore\Testimonials internals. The `testimonial` post type slug and
 * `testimonial_author` meta key are defined here (not on
 * SiteCore\Testimonials\TestimonialsProvider) precisely so this class has
 * zero dependencies of its own: TestimonialsProvider depends on this
 * contract's constants, never the other way around.
 */
final class Testimonials {

	public const POST_TYPE   = 'testimonial';
	public const META_AUTHOR = 'testimonial_author';

	private function __construct() {
		// Static-only API; never instantiated.
	}

	/**
	 * Returns the most recently published testimonials, newest first.
	 *
	 * The stable contract shape (Task 6 renders this in the
	 * `agency/reference-callout` block context, and any other future
	 * consumer must be able to rely on it too):
	 *
	 *     array{
	 *         id: int,
	 *         title: string,
	 *         content: string,
	 *         author: string,
	 *         thumbnail_id: int|null,
	 *     }
	 *
	 * @param int $limit Maximum number of testimonials to return. Default 3.
	 * @return array<int, array{id: int, title: string, content: string, author: string, thumbnail_id: int|null}>
	 */
	public static function latest( int $limit = 3 ): array {
		$posts = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => $limit,
			)
		);

		$testimonials = array();

		foreach ( $posts as $post ) {
			$post_id      = $post->ID;
			$thumbnail_id = get_post_thumbnail_id( $post );

			$testimonials[] = array(
				'id'           => $post_id,
				'title'        => $post->post_title,
				'content'      => $post->post_content,
				'author'       => (string) get_post_meta( $post_id, self::META_AUTHOR, true ),
				'thumbnail_id' => ( false === $thumbnail_id || 0 === $thumbnail_id ) ? null : $thumbnail_id,
			);
		}

		return $testimonials;
	}
}
