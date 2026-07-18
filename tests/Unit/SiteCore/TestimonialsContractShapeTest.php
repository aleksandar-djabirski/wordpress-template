<?php

declare(strict_types=1);

namespace Tests\Unit\SiteCore;

use PHPUnit\Framework\TestCase;
use SiteCore\Contracts\Testimonials;

/**
 * @covers \SiteCore\Contracts\Testimonials
 */
final class TestimonialsContractShapeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		unset( $GLOBALS['_test_posts'], $GLOBALS['_test_post_meta'], $GLOBALS['_test_thumbnails'] );
	}

	public function test_returns_an_empty_array_when_there_are_no_testimonials(): void {
		self::assertSame( array(), Testimonials::latest() );
	}

	public function test_maps_a_stubbed_post_to_the_documented_contract_shape(): void {
		$GLOBALS['_test_posts']      = array(
			(object) array(
				'ID'           => 7,
				'post_title'   => 'Fantastic agency to work with',
				'post_content' => 'They shipped everything on time and it shows.',
			),
		);
		$GLOBALS['_test_post_meta']  = array(
			7 => array( 'testimonial_author' => 'Jane Doe' ),
		);
		$GLOBALS['_test_thumbnails'] = array( 7 => 42 );

		self::assertSame(
			array(
				array(
					'id'           => 7,
					'title'        => 'Fantastic agency to work with',
					'content'      => 'They shipped everything on time and it shows.',
					'author'       => 'Jane Doe',
					'thumbnail_id' => 42,
				),
			),
			Testimonials::latest()
		);
	}

	public function test_a_testimonial_without_a_thumbnail_maps_thumbnail_id_to_null(): void {
		$GLOBALS['_test_posts']     = array(
			(object) array(
				'ID'           => 9,
				'post_title'   => 'Great results',
				'post_content' => 'Would recommend.',
			),
		);
		$GLOBALS['_test_post_meta'] = array(
			9 => array( 'testimonial_author' => 'John Roe' ),
		);
		// Deliberately no $GLOBALS['_test_thumbnails'] entry for post 9:
		// get_post_thumbnail_id() stub returns '' (matching real
		// WordPress), same as for a post with no featured image.

		$testimonials = Testimonials::latest();

		self::assertNull( $testimonials[0]['thumbnail_id'] );
	}

	public function test_a_numeric_string_thumbnail_id_is_cast_to_int(): void {
		$GLOBALS['_test_posts']     = array(
			(object) array(
				'ID'           => 11,
				'post_title'   => 'Numeric-string thumbnail edge case',
				'post_content' => 'Covers get_post_thumbnail_id() returning a numeric string.',
			),
		);
		$GLOBALS['_test_post_meta'] = array(
			11 => array( 'testimonial_author' => 'Sam Smith' ),
		);
		// A numeric string (rather than an int) reproduces how meta-backed
		// WordPress values often arrive in practice.
		$GLOBALS['_test_thumbnails'] = array( 11 => '42' );

		$testimonials = Testimonials::latest();

		self::assertSame( 42, $testimonials[0]['thumbnail_id'] );
		self::assertIsInt( $testimonials[0]['thumbnail_id'] );
	}
}
