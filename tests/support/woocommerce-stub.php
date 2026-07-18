<?php
/**
 * Minimal global-namespace WooCommerce class stand-in, used only by
 * Tests\Unit\SiteCommerce\PluginGuardTest's "WooCommerce active" case (see
 * wp-stubs.php's docblock for why class stand-ins live in their own file
 * separate from function stubs, and wp-error-stub.php for the same
 * pattern applied to WP_Error).
 *
 * Deliberately NOT required by tests/bootstrap.php: every other test in
 * this suite depends on WooCommerce staying undefined —
 * SiteCommerce\Plugin::maybe_boot()'s entire activation guard is built on
 * class_exists('WooCommerce') being false until WooCommerce is actually
 * installed. This file is required only inside the one
 * @runInSeparateProcess test that needs WooCommerce to exist, so defining
 * it here never leaks into the rest of the suite.
 *
 * This path sits outside the naming contract's fixed WooCommerce-symbol
 * allow-locations (web/app/plugins/site-commerce/, the theme's
 * woocommerce/ overrides, tests/commerce/) — same situation as
 * AgencyPlatform\Roles\ShopRole, which documents itself as a reviewed
 * exception. This file is the analogous exception for tests/support/: a
 * future tests/Architecture/woocommerce-allowlist.php should list this
 * path alongside ShopRole's.
 */

declare(strict_types=1);

if ( ! class_exists( 'WooCommerce' ) ) {
	/**
	 * Empty on purpose — SiteCommerce\Plugin only calls
	 * class_exists('WooCommerce') as its activation guard; it never calls
	 * into WooCommerce's real API from that guard path.
	 */
	class WooCommerce {}
}
