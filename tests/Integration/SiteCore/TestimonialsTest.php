<?php
/**
 * Proves the `testimonial` custom post type, its `testimonial_author` meta
 * field, and SiteCore\Contracts\Testimonials::latest() behave the way the
 * naming contract promises against a real WordPress database, rather than
 * the $GLOBALS-stubbed `get_posts()`/`get_post_meta()` the `unit` suite
 * uses (see tests/Unit/SiteCore/TestimonialsContractShapeTest.php for that
 * pure-shape coverage).
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\SiteCore;

use SiteCore\Contracts\Testimonials;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \SiteCore\Testimonials\TestimonialsProvider
 * @covers \SiteCore\Contracts\Testimonials
 */
final class TestimonialsTest extends IntegrationTestCase {

	public function test_the_testimonial_post_type_and_author_meta_are_registered(): void {
		self::assertTrue( post_type_exists( Testimonials::POST_TYPE ) );
		self::assertTrue( registered_meta_key_exists( 'post', Testimonials::META_AUTHOR, Testimonials::POST_TYPE ) );
	}

	public function test_latest_returns_only_published_testimonials_newest_first_in_the_documented_shape(): void {
		$older_id = self::factory()->post->create(
			array(
				'post_type'    => Testimonials::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Solid partner from day one',
				'post_content' => 'They understood our brief immediately.',
				'post_date'    => '2024-01-01 09:00:00',
				'meta_input'   => array( Testimonials::META_AUTHOR => 'Ada Lovelace' ),
			)
		);
		$newer_id = self::factory()->post->create(
			array(
				'post_type'    => Testimonials::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Fantastic agency to work with',
				'post_content' => 'They shipped everything on time and it shows.',
				'post_date'    => '2024-02-01 09:00:00',
				'meta_input'   => array( Testimonials::META_AUTHOR => 'Grace Hopper' ),
			)
		);
		self::factory()->post->create(
			array(
				'post_type'    => Testimonials::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => 'Unpublished draft testimonial',
				'post_content' => 'Should never be returned by latest().',
				'post_date'    => '2024-03-01 09:00:00',
				'meta_input'   => array( Testimonials::META_AUTHOR => 'Draft Author' ),
			)
		);

		self::assertSame(
			array(
				array(
					'id'           => $newer_id,
					'title'        => 'Fantastic agency to work with',
					'content'      => 'They shipped everything on time and it shows.',
					'author'       => 'Grace Hopper',
					'thumbnail_id' => null,
				),
				array(
					'id'           => $older_id,
					'title'        => 'Solid partner from day one',
					'content'      => 'They understood our brief immediately.',
					'author'       => 'Ada Lovelace',
					'thumbnail_id' => null,
				),
			),
			Testimonials::latest( 5 )
		);
	}

	public function test_latest_respects_the_limit_argument(): void {
		self::factory()->post->create(
			array(
				'post_type'   => Testimonials::POST_TYPE,
				'post_status' => 'publish',
				'post_date'   => '2024-01-01 09:00:00',
				'meta_input'  => array( Testimonials::META_AUTHOR => 'First Author' ),
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => Testimonials::POST_TYPE,
				'post_status' => 'publish',
				'post_date'   => '2024-02-01 09:00:00',
				'meta_input'  => array( Testimonials::META_AUTHOR => 'Second Author' ),
			)
		);

		self::assertCount( 1, Testimonials::latest( 1 ) );
	}
}
