<?php
/**
 * Reviewed WooCommerce-symbol allow-list.
 *
 * WooCommerceIsolationTest forbids WooCommerce symbols (WooCommerce, WC_*,
 * wc_*, woocommerce_*) everywhere in the base profile so a site can run
 * without WooCommerce and so all commerce logic stays quarantined inside the
 * site-commerce plugin and the theme's woocommerce/ overrides. The files
 * below are the handful of deliberate, reviewed exceptions: each references a
 * WooCommerce symbol for a legitimate reason that does NOT belong in
 * site-commerce.
 *
 * Keys are repo-relative paths (forward slashes). Values are the one-line
 * reason each exception exists. To add an entry you must have a reason that
 * genuinely cannot live in site-commerce/ — otherwise move the code there.
 *
 * @return array<string, string>
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

// Keys are file paths of very different lengths, so aligning the double
// arrows to a common column would either add pages of whitespace or blow past
// the line-length limit; each entry stays on its own key => value pair.
// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

return array(
	'web/app/mu-plugins/agency-platform/src/Roles/ShopRole.php' =>
		'Registers the client_shop_manager role only when WooCommerce is active; uses class_exists( \'WooCommerce\' ) and plain capability strings, never a WC_* class, so it stays safe to load without WooCommerce.',
	'tests/support/woocommerce-stub.php' =>
		'Defines a minimal global WooCommerce class stand-in used by the one isolated PluginGuardTest case that exercises the "WooCommerce present" branch.',
	'tests/Unit/SiteCommerce/PluginGuardTest.php' =>
		'Verifies SiteCommerce\\Plugin\'s activation guard: it must name WooCommerce and the woocommerce_* filter it wires to prove the isolation model works.',
	'tests/Unit/Support/ArchitectureScannerTest.php' =>
		'Exercises the scanner\'s WooCommerce-symbol detection, so its fixtures necessarily contain the literal string WooCommerce.',
);
// phpcs:enable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
