<?php

declare(strict_types=1);

namespace Tests\Unit\SiteCore;

use PHPUnit\Framework\TestCase;
use SiteCore\Contracts\Testimonials;
use SiteCore\Testimonials\TestimonialsProvider;

/**
 * @covers \SiteCore\Testimonials\TestimonialsProvider
 */
final class TestimonialsProviderRegistrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		unset( $GLOBALS['_test_registered'], $GLOBALS['_test_current_user_caps'] );
	}

	public function test_register_post_type_registers_the_testimonial_cpt_with_the_documented_shape(): void {
		( new TestimonialsProvider() )->register_post_type();

		$args = $GLOBALS['_test_registered']['post_types'][ Testimonials::POST_TYPE ] ?? null;

		self::assertIsArray( $args );
		self::assertTrue( $args['public'] );
		self::assertFalse( $args['has_archive'] );
		self::assertTrue( $args['show_in_rest'] );
		self::assertSame( array( 'title', 'editor', 'thumbnail' ), $args['supports'] );
		self::assertSame( 'dashicons-format-quote', $args['menu_icon'] );
		self::assertSame( 'Testimonials', $args['labels']['name'] );
	}

	public function test_register_meta_registers_testimonial_author_with_the_documented_shape(): void {
		( new TestimonialsProvider() )->register_meta();

		$args = $GLOBALS['_test_registered']['post_meta'][ Testimonials::POST_TYPE ][ Testimonials::META_AUTHOR ] ?? null;

		self::assertIsArray( $args );
		self::assertSame( 'string', $args['type'] );
		self::assertTrue( $args['single'] );
		self::assertTrue( $args['show_in_rest'] );
		self::assertSame( 'sanitize_text_field', $args['sanitize_callback'] );
	}

	public function test_meta_callbacks_are_named_references_not_closures(): void {
		( new TestimonialsProvider() )->register_meta();

		$args = $GLOBALS['_test_registered']['post_meta'][ Testimonials::POST_TYPE ][ Testimonials::META_AUTHOR ];

		self::assertNotInstanceOf( \Closure::class, $args['sanitize_callback'] );
		self::assertNotInstanceOf( \Closure::class, $args['auth_callback'] );
		self::assertIsArray( $args['auth_callback'] );
		self::assertSame( 'can_edit_author_meta', $args['auth_callback'][1] );
	}

	public function test_can_edit_author_meta_reflects_the_current_users_edit_posts_capability(): void {
		$provider = new TestimonialsProvider();

		$GLOBALS['_test_current_user_caps'] = array();
		self::assertFalse( $provider->can_edit_author_meta() );

		$GLOBALS['_test_current_user_caps'] = array( 'edit_posts' );
		self::assertTrue( $provider->can_edit_author_meta() );
	}
}
