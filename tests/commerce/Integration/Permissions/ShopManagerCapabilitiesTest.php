<?php
/**
 * Proves AgencyPlatform\Roles\ShopRole registers `client_shop_manager` with
 * exactly the intended WooCommerce capabilities and none of the "keys to the
 * kingdom" ones, when WooCommerce is active.
 *
 * The base ClientEditorCapabilitiesTest already proves this role is ABSENT in
 * the base profile (ShopRole's class_exists('WooCommerce') guard). This is its
 * commerce-profile mirror: the role exists, carries the nine shop caps on top
 * of client_editor's, and still cannot install plugins, switch themes, or edit
 * theme options.
 *
 * @package Tests\Commerce\Integration
 */

declare(strict_types=1);

namespace Tests\Commerce\Integration\Permissions;

use AgencyPlatform\Roles\ShopRole;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\Roles\ShopRole
 */
final class ShopManagerCapabilitiesTest extends IntegrationTestCase {

	/**
	 * The nine WooCommerce capabilities ShopRole grants on top of the
	 * client_editor baseline (kept in sync with ShopRole::WOOCOMMERCE_CAPABILITIES).
	 *
	 * @return list<string>
	 */
	private function expected_woocommerce_capabilities(): array {
		return array(
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
	}

	private function make_shop_manager(): \WP_User {
		$user_id = self::factory()->user->create( array( 'role' => ShopRole::ROLE ) );

		return new \WP_User( $user_id );
	}

	public function test_client_shop_manager_role_is_registered(): void {
		$role = get_role( ShopRole::ROLE );

		self::assertNotNull( $role, 'client_shop_manager must be registered when WooCommerce is active.' );
		self::assertSame(
			'Client Shop Manager',
			wp_roles()->role_names[ ShopRole::ROLE ] ?? null
		);
	}

	public function test_shop_manager_has_every_woocommerce_capability(): void {
		$shop_manager = $this->make_shop_manager();
		wp_set_current_user( $shop_manager->ID );

		foreach ( $this->expected_woocommerce_capabilities() as $capability ) {
			self::assertTrue(
				current_user_can( $capability ),
				"client_shop_manager must have the '{$capability}' capability."
			);
		}
	}

	public function test_shop_manager_retains_client_editor_content_capabilities(): void {
		$shop_manager = $this->make_shop_manager();
		wp_set_current_user( $shop_manager->ID );

		foreach ( array( 'edit_posts', 'edit_pages', 'publish_posts', 'upload_files' ) as $capability ) {
			self::assertTrue(
				current_user_can( $capability ),
				"client_shop_manager must keep the client_editor '{$capability}' capability."
			);
		}
	}

	public function test_shop_manager_cannot_reach_the_keys_to_the_kingdom(): void {
		$shop_manager = $this->make_shop_manager();
		wp_set_current_user( $shop_manager->ID );

		foreach ( array( 'install_plugins', 'switch_themes', 'edit_theme_options', 'manage_options', 'unfiltered_html' ) as $capability ) {
			self::assertFalse(
				current_user_can( $capability ),
				"client_shop_manager must NOT have the '{$capability}' capability."
			);
		}
	}
}
