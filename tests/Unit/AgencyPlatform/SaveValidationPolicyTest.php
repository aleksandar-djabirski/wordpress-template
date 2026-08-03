<?php
/**
 * Pins the set of post types the save boundary covers. Every one of these is
 * a surface a client can write through REST: post/page (the post editor),
 * wp_template and wp_template_part (the Site Editor), wp_block (synced
 * patterns) and wp_navigation (the navigation editor). Dropping one silently
 * opens a hole, so the list is asserted rather than trusted.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Editor\SaveValidation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Editor\SaveValidation
 */
final class SaveValidationPolicyTest extends TestCase {

	public function test_every_client_writable_post_type_is_validated(): void {
		self::assertSame(
			array( 'post', 'page', 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation' ),
			SaveValidation::VALIDATED_POST_TYPES
		);
	}

	public function test_the_site_editor_post_types_are_covered(): void {
		foreach ( array( 'wp_template', 'wp_template_part', 'wp_navigation' ) as $post_type ) {
			self::assertContains( $post_type, SaveValidation::VALIDATED_POST_TYPES );
		}
	}

	public function test_the_validator_is_a_public_filter_callback(): void {
		$method = new \ReflectionMethod( SaveValidation::class, 'validate_content' );

		self::assertTrue( $method->isPublic() );
		self::assertSame( 2, $method->getNumberOfParameters() );
	}
}
