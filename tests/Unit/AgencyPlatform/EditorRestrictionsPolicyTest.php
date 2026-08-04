<?php
/**
 * Proves the client block policy still holds its two non-negotiables after the
 * move from a fixed allow-list to a registered-block namespace policy: the
 * agency reference block stays insertable, and the arbitrary-markup escape
 * hatches stay denied. The exhaustive policy coverage lives in BlockPolicyTest.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Editor\BlockPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Editor\EditorRestrictions
 * @covers \AgencyPlatform\Editor\BlockPolicy
 */
final class EditorRestrictionsPolicyTest extends TestCase {

	/**
	 * @return list<string>
	 */
	private function registered(): array {
		return array( 'core/paragraph', 'core/html', 'core/shortcode', 'core/freeform', 'agency/reference-callout', 'woocommerce/mini-cart' );
	}

	public function test_client_roles_can_insert_the_reference_callout(): void {
		$allowed = BlockPolicy::resolve( false, true, $this->registered(), BlockPolicy::DEFAULT_NAMESPACES, array(), array() );

		self::assertContains( 'agency/reference-callout', (array) $allowed );
	}

	public function test_client_roles_can_insert_core_paragraph(): void {
		$allowed = BlockPolicy::resolve( false, true, $this->registered(), BlockPolicy::DEFAULT_NAMESPACES, array(), array() );

		self::assertContains( 'core/paragraph', (array) $allowed );
	}

	public function test_shortcode_and_html_blocks_are_excluded(): void {
		$allowed = BlockPolicy::resolve( false, true, $this->registered(), BlockPolicy::DEFAULT_NAMESPACES, array(), array() );

		self::assertNotContains( 'core/shortcode', (array) $allowed );
		self::assertNotContains( 'core/html', (array) $allowed );
	}

	public function test_privileged_users_get_the_incoming_value_back_unchanged(): void {
		self::assertSame(
			array( 'core/paragraph', 'core/heading' ),
			BlockPolicy::resolve( true, array( 'core/paragraph', 'core/heading' ), $this->registered(), BlockPolicy::DEFAULT_NAMESPACES, array(), array() )
		);
	}

	public function test_privileged_users_pass_through_a_bool_incoming_value(): void {
		self::assertTrue( BlockPolicy::resolve( true, true, $this->registered(), BlockPolicy::DEFAULT_NAMESPACES, array(), array() ) );
	}
}
