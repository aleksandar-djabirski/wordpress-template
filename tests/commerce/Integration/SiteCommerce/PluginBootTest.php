<?php
/**
 * Proves SiteCommerce\Plugin takes its BOOTED branch when WooCommerce is
 * active: ExampleProductRules and CommerceSanitizeStep are wired, and the
 * "WooCommerce is missing" admin notice is NOT registered.
 *
 * The unit-level PluginGuardTest already checks both branches against function
 * stubs; this integration test is the proof the booted branch actually fires
 * against a real WordPress + WooCommerce load, from the real `plugins_loaded`
 * hook the commerce bootstrap runs through.
 *
 * @package Tests\Commerce\Integration
 */

declare(strict_types=1);

namespace Tests\Commerce\Integration\SiteCommerce;

use AgencyPlatform\Health\SanitizeSteps;
use SiteCommerce\Health\CommerceSanitizeStep;
use SiteCommerce\Plugin;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \SiteCommerce\Plugin
 * @covers \SiteCommerce\Products\ExampleProductRules
 * @covers \SiteCommerce\Health\CommerceSanitizeStep
 */
final class PluginBootTest extends IntegrationTestCase {

	public function test_woocommerce_is_actually_active(): void {
		self::assertTrue(
			class_exists( 'WooCommerce' ),
			'The commerce integration bootstrap must load WooCommerce; without it every assertion below is meaningless.'
		);
	}

	public function test_example_product_rules_filter_is_registered(): void {
		self::assertNotFalse(
			has_filter( 'woocommerce_product_single_add_to_cart_text' ),
			'ExampleProductRules must wire its add-to-cart-text filter once site-commerce boots.'
		);
	}

	public function test_commerce_sanitize_step_is_appended_to_the_registry(): void {
		$steps = SanitizeSteps::steps();

		self::assertArrayHasKey(
			'commerce',
			$steps,
			'CommerceSanitizeStep must append itself to the sanitize registry when WooCommerce is active.'
		);
		self::assertSame(
			array( CommerceSanitizeStep::class, 'sanitize_commerce' ),
			$steps['commerce'],
			'The commerce step must be the named CommerceSanitizeStep callable, never a closure.'
		);
	}

	public function test_missing_woocommerce_admin_notice_is_not_registered(): void {
		self::assertFalse(
			has_action( 'admin_notices', array( Plugin::class, 'render_missing_woocommerce_notice' ) ),
			'With WooCommerce active, site-commerce must not register its missing-WooCommerce admin notice.'
		);
	}
}
