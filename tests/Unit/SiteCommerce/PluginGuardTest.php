<?php

declare(strict_types=1);

namespace Tests\Unit\SiteCommerce;

use PHPUnit\Framework\TestCase;
use SiteCommerce\Plugin;

/**
 * Locks down site-commerce's whole isolation model: WooCommerce absent
 * must stay a safe no-op (only the admin notice wired up, nothing that
 * touches WooCommerce), and WooCommerce present must wire the real
 * providers. This is the one place that "safe no-op deactivation path"
 * requirement is machine-checked rather than eyeballed in review.
 *
 * @covers \SiteCommerce\Plugin
 * @covers \SiteCommerce\Products\ExampleProductRules
 */
final class PluginGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		unset( $GLOBALS['_test_filters'] );
	}

	public function test_maybe_boot_registers_only_the_admin_notice_when_woocommerce_is_absent(): void {
		self::assertFalse(
			class_exists( 'WooCommerce', false ),
			'This test must run before anything defines WooCommerce, or the guard it exercises is meaningless.'
		);

		Plugin::maybe_boot();

		$notice_hook = $GLOBALS['_test_filters']['admin_notices'][10][0]['callback'] ?? null;

		self::assertNotNull( $notice_hook, 'Expected the missing-WooCommerce admin notice to be registered on admin_notices.' );
		self::assertIsArray( $notice_hook, 'Expected a [Plugin::class, \'method\'] callable, not a Closure.' );
		self::assertNotInstanceOf( \Closure::class, $notice_hook );
		self::assertSame( array( Plugin::class, 'render_missing_woocommerce_notice' ), $notice_hook );

		self::assertArrayNotHasKey(
			'woocommerce_product_single_add_to_cart_text',
			$GLOBALS['_test_filters'] ?? array(),
			'ExampleProductRules must not be wired while WooCommerce is absent.'
		);
	}

	/**
	 * Defining the global WooCommerce class is irreversible for the rest of
	 * the PHP process (classes, like the AGENCY_DISABLE_OUTBOUND_WEBHOOKS
	 * constant in SiteIntegrations\LeadDeliveryResolutionTest, can't be
	 * undefined once set) — every other test in this suite depends on
	 * WooCommerce staying undefined, so this one case runs isolated.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_boot_wires_example_product_rules_when_woocommerce_is_present(): void {
		require_once dirname( __DIR__, 2 ) . '/support/woocommerce-stub.php';

		self::assertTrue( class_exists( 'WooCommerce', false ) );

		Plugin::maybe_boot();

		$product_rule_hook = $GLOBALS['_test_filters']['woocommerce_product_single_add_to_cart_text'][10][0]['callback'] ?? null;

		self::assertNotNull( $product_rule_hook, 'Expected ExampleProductRules to wire its filter once WooCommerce is present.' );
		self::assertNotInstanceOf( \Closure::class, $product_rule_hook );

		self::assertArrayNotHasKey(
			'admin_notices',
			$GLOBALS['_test_filters'] ?? array(),
			'The missing-WooCommerce admin notice must not register once WooCommerce is present.'
		);
	}
}
