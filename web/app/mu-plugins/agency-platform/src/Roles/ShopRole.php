<?php

declare(strict_types=1);

namespace AgencyPlatform\Roles;

/**
 * Registers the `client_shop_manager` role: `client_editor`'s capabilities
 * plus a small, explicit set of WooCommerce capabilities — only when
 * WooCommerce is active.
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
	 * @var string[]
	 */
	private const WOOCOMMERCE_CAPABILITIES = array(
		'manage_woocommerce',
		'view_woocommerce_reports',
		'edit_products',
		'edit_others_products',
		'publish_products',
		'edit_shop_orders',
		'edit_others_shop_orders',
		'edit_shop_coupons',
		'edit_others_shop_coupons',
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
