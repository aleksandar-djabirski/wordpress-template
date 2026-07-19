<?php

declare(strict_types=1);

namespace AgencyPlatform\Roles;

/**
 * Registers the `client_shop_manager` role: `client_editor`'s capabilities
 * plus a workflow-complete, explicit set of WooCommerce capabilities — only
 * when WooCommerce is active.
 *
 * This is deliberately the ONLY file in the agency-platform mu-plugin that
 * references WooCommerce, so a reviewed WooCommerce-symbol allow-list can
 * name exactly this one path. It only ever checks `class_exists('WooCommerce')`
 * and uses plain capability strings — never a `WC_*` class or constant — so
 * this file stays safe to load (and this role harmless to register with a
 * reduced capability set) even when WooCommerce is inactive.
 */
final class ShopRole {

	public const ROLE = 'client_shop_manager';

	/**
	 * The WooCommerce capabilities client_shop_manager gets on top of the
	 * client_editor baseline. This is a WORKFLOW-COMPLETE catalog + orders +
	 * coupons set: the project editing model has a shop manager edit Products,
	 * Variations, Prices, and Stock, so the product caps cover the full
	 * lifecycle — editing and deleting PUBLISHED and PRIVATE products, not just
	 * creating new ones — plus assigning catalog categories/tags (a product is
	 * uncategorizable without `assign_product_terms`), and editing an already
	 * published (active) coupon.
	 *
	 * It deliberately stays INSIDE the product/coupon/order surface: no
	 * `delete_others_*` (a shop manager can remove their own catalogue entries
	 * but not sweep away colleagues' work), and nothing store-wide-admin —
	 * `manage_options`, `edit_theme_options`, `install_plugins`, `switch_themes`
	 * are all still withheld (proven by ShopManagerCapabilitiesTest's negative
	 * assertions). `manage_woocommerce` (WooCommerce Settings access) is a
	 * documented per-project dial — see docs/editing-strictness.md.
	 *
	 * @var string[]
	 */
	private const WOOCOMMERCE_CAPABILITIES = array(
		// Store administration + reporting.
		'manage_woocommerce',
		'view_woocommerce_reports',
		// Products — full lifecycle on own and others' items, including the
		// PUBLISHED and PRIVATE states a live catalogue needs edited/removed.
		'edit_products',
		'edit_others_products',
		'edit_published_products',
		'edit_private_products',
		'read_private_products',
		'publish_products',
		'delete_products',
		'delete_published_products',
		// Product categories/tags — `assign` is required just to categorize.
		'manage_product_terms',
		'edit_product_terms',
		'assign_product_terms',
		// Orders.
		'edit_shop_orders',
		'edit_others_shop_orders',
		// Coupons — including editing an already-published (active) coupon.
		'edit_shop_coupons',
		'edit_others_shop_coupons',
		'edit_published_shop_coupons',
	);

	public function register(): void {
		// Priority 20 (RolesProvider's `init` callback runs at the default
		// 10): defensive ordering only — capabilities() reads the desired
		// client_editor set directly from RolesProvider rather than from
		// the persisted role, so correctness doesn't actually depend on it.
		add_action( 'init', array( self::class, 'register_role' ), 20 );
	}

	public static function register_role(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$capabilities = self::capabilities();
		$role         = get_role( self::ROLE );

		if ( null === $role ) {
			add_role( self::ROLE, __( 'Client Shop Manager', 'agency-platform' ), $capabilities );
			return;
		}

		RolesProvider::sync_role_capabilities( $role, $capabilities );
	}

	/**
	 * @return array<string, bool>
	 */
	public static function capabilities(): array {
		$capabilities = RolesProvider::client_editor_capabilities();

		foreach ( self::WOOCOMMERCE_CAPABILITIES as $capability ) {
			$capabilities[ $capability ] = true;
		}

		return $capabilities;
	}
}
