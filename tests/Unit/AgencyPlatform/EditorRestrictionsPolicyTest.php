<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Editor\EditorRestrictions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Editor\EditorRestrictions
 */
final class EditorRestrictionsPolicyTest extends TestCase {

	public function test_allowed_blocks_contains_no_woocommerce_entries(): void {
		foreach ( EditorRestrictions::ALLOWED_BLOCKS as $block_name ) {
			self::assertFalse(
				str_starts_with( $block_name, 'woocommerce/' ),
				"\"{$block_name}\" should not be in the base editor allow-list."
			);
		}
	}

	public function test_allowed_blocks_contains_the_reference_callout(): void {
		self::assertContains( 'agency/reference-callout', EditorRestrictions::ALLOWED_BLOCKS );
	}

	public function test_allowed_blocks_contains_core_paragraph(): void {
		self::assertContains( 'core/paragraph', EditorRestrictions::ALLOWED_BLOCKS );
	}

	public function test_allowed_blocks_excludes_shortcode(): void {
		self::assertNotContains( 'core/shortcode', EditorRestrictions::ALLOWED_BLOCKS );
	}

	public function test_privileged_users_get_the_incoming_value_back_unchanged(): void {
		$incoming = array( 'core/paragraph', 'core/heading' );

		self::assertSame(
			$incoming,
			EditorRestrictions::allowed_blocks_for( true, $incoming )
		);
	}

	public function test_privileged_users_pass_through_a_bool_incoming_value(): void {
		self::assertTrue( EditorRestrictions::allowed_blocks_for( true, true ) );
	}

	public function test_non_privileged_users_always_get_the_allow_list(): void {
		self::assertSame(
			EditorRestrictions::ALLOWED_BLOCKS,
			EditorRestrictions::allowed_blocks_for( false, true )
		);
	}

	public function test_non_privileged_users_get_the_allow_list_even_if_incoming_was_permissive(): void {
		self::assertSame(
			EditorRestrictions::ALLOWED_BLOCKS,
			EditorRestrictions::allowed_blocks_for( false, array( 'core/paragraph', 'core/shortcode' ) )
		);
	}
}
