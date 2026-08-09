<?php
/**
 * Proves AgencyPlatform\Security\CapabilityPolicy denies the two meta
 * capabilities that granting `edit_theme_options` would otherwise open up:
 * `edit_css` (Additional CSS / per-block custom CSS) and `customize` (the
 * Customizer, which core maps through edit_theme_options).
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Security\CapabilityPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Security\CapabilityPolicy
 */
final class CapabilityPolicyTest extends TestCase {

	public function test_edit_css_is_denied_for_client_roles(): void {
		self::assertSame(
			array( 'do_not_allow' ),
			CapabilityPolicy::map( array( 'unfiltered_html' ), 'edit_css', false )
		);
	}

	public function test_customize_is_denied_for_client_roles(): void {
		self::assertSame(
			array( 'do_not_allow' ),
			CapabilityPolicy::map( array( 'edit_theme_options' ), 'customize', false )
		);
	}

	public function test_privileged_users_keep_the_incoming_mapping(): void {
		self::assertSame(
			array( 'unfiltered_html' ),
			CapabilityPolicy::map( array( 'unfiltered_html' ), 'edit_css', true )
		);
		self::assertSame(
			array( 'edit_theme_options' ),
			CapabilityPolicy::map( array( 'edit_theme_options' ), 'customize', true )
		);
	}

	public function test_unrelated_capabilities_are_untouched_for_everyone(): void {
		self::assertSame(
			array( 'edit_theme_options' ),
			CapabilityPolicy::map( array( 'edit_theme_options' ), 'edit_theme_options', false )
		);
		self::assertSame(
			array( 'edit_posts' ),
			CapabilityPolicy::map( array( 'edit_posts' ), 'edit_post', false )
		);
	}

	public function test_denied_meta_caps_constant_is_exactly_the_two_documented_capabilities(): void {
		self::assertSame( array( 'edit_css', 'customize' ), CapabilityPolicy::DENIED_META_CAPS );
	}
}
