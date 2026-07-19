<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Health\SanitizeSteps;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Health\SanitizeSteps
 */
final class SanitizeStepsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// The wp-stubs add_filter()/apply_filters() recorder is a global; a
		// leftover registration from another test would otherwise leak into
		// SanitizeSteps::steps()'s filter pass here.
		unset( $GLOBALS['_test_filters'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_filters'] );

		parent::tearDown();
	}

	public function test_default_steps_are_ordered(): void {
		self::assertSame(
			array( 'users', 'comments', 'sessions', 'application_passwords', 'blog_public' ),
			array_keys( SanitizeSteps::steps() )
		);
	}

	public function test_default_steps_are_all_named_callables(): void {
		foreach ( SanitizeSteps::steps() as $slug => $step ) {
			self::assertIsCallable( $step, "Step '{$slug}' must be callable." );
			self::assertNotInstanceOf( \Closure::class, $step, "Step '{$slug}' must be a named callable, not a closure." );
		}
	}

	public function test_a_filter_registrant_can_append_a_step(): void {
		add_filter( 'agency_platform_sanitize_steps', array( self::class, 'append_fake_step' ) );

		$steps = SanitizeSteps::steps();

		self::assertArrayHasKey( 'fake', $steps );
		self::assertSame(
			array( 'users', 'comments', 'sessions', 'application_passwords', 'blog_public', 'fake' ),
			array_keys( $steps )
		);
	}

	/**
	 * Named filter callback (never a closure — mirrors the production
	 * named-callable rule) that appends a no-op step.
	 *
	 * @param array<string, callable> $steps
	 * @return array<string, callable>
	 */
	public static function append_fake_step( array $steps ): array {
		$steps['fake'] = array( self::class, 'fake_step' );

		return $steps;
	}

	/**
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- matches the uniform step signature; this fixture step does nothing.
	public static function fake_step( array $options ): array {
		return array( 'fake step ran.' );
	}

	public function test_sanitized_user_email_is_deterministic(): void {
		self::assertSame( 'user_42@example.invalid', SanitizeSteps::sanitized_user_email( 42 ) );
	}

	public function test_sanitized_comment_email_is_deterministic(): void {
		self::assertSame( 'comment_7@example.invalid', SanitizeSteps::sanitized_comment_email( 7 ) );
	}

	public function test_sanitized_display_name_is_deterministic(): void {
		self::assertSame( 'Sanitized User 42', SanitizeSteps::sanitized_display_name( 42 ) );
	}

	public function test_sanitized_user_nicename_is_a_unique_per_id_slug(): void {
		self::assertSame( 'sanitized-user-42', SanitizeSteps::sanitized_user_nicename( 42 ) );

		// nicename is the public author slug, which WordPress expects distinct
		// per user — different IDs must never collapse to the same slug.
		self::assertNotSame(
			SanitizeSteps::sanitized_user_nicename( 42 ),
			SanitizeSteps::sanitized_user_nicename( 43 )
		);
	}

	public function test_sanitized_comment_author_is_deterministic(): void {
		self::assertSame( 'Sanitized Commenter 7', SanitizeSteps::sanitized_comment_author( 7 ) );
	}

	public function test_user_query_args_excludes_administrators_by_default(): void {
		self::assertSame(
			array(
				'fields'       => 'all',
				'role__not_in' => array( 'administrator' ),
			),
			SanitizeSteps::user_query_args( false )
		);
	}

	public function test_user_query_args_includes_administrators_when_requested(): void {
		self::assertSame(
			array( 'fields' => 'all' ),
			SanitizeSteps::user_query_args( true )
		);
	}
}
