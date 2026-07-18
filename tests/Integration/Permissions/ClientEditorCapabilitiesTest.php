<?php
/**
 * Proves the `client_editor` role (AgencyPlatform\Roles\RolesProvider) and
 * every guardrail that keys off it (AgencyPlatform\Editor\EditorRestrictions,
 * AgencyPlatform\Editor\SiteEditorLockdown,
 * AgencyPlatform\Security\ApplicationPasswords) behave the way the naming
 * contract promises, against a real WordPress install rather than the
 * function stubs the `unit` suite uses.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Permissions;

use AgencyPlatform\Editor\EditorRestrictions;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\Roles\RolesProvider
 * @covers \AgencyPlatform\Editor\EditorRestrictions
 * @covers \AgencyPlatform\Editor\SiteEditorLockdown
 * @covers \AgencyPlatform\Security\ApplicationPasswords
 */
final class ClientEditorCapabilitiesTest extends IntegrationTestCase {

	/**
	 * Capabilities RolesProvider::NEVER_GRANT deliberately strips from
	 * `client_editor` (see that class), plus `edit_theme_options` — which
	 * SiteEditorLockdown additionally maps to `do_not_allow` via
	 * `map_meta_cap` for anyone who can't `manage_options`.
	 *
	 * @return list<string>
	 */
	private function never_grant_capabilities(): array {
		return array(
			'install_plugins',
			'activate_plugins',
			'switch_themes',
			'edit_files',
			'edit_plugins',
			'edit_themes',
			'edit_theme_options',
			'manage_options',
			'update_core',
			'unfiltered_html',
		);
	}

	public function test_client_editor_lacks_every_never_grant_capability(): void {
		$client_editor = $this->make_client_editor();
		wp_set_current_user( $client_editor->ID );

		foreach ( $this->never_grant_capabilities() as $capability ) {
			self::assertFalse(
				current_user_can( $capability ),
				"client_editor must not have the '{$capability}' capability."
			);
		}
	}

	/**
	 * `edit_css` (the Additional CSS / custom-CSS meta capability) is not
	 * itself in RolesProvider::NEVER_GRANT — WordPress core's own
	 * map_meta_cap() maps it to requiring `unfiltered_html` (see
	 * wp-includes/capabilities.php), which client_editor already lacks. This
	 * is a derived guarantee, not a directly-granted one, so it gets its own
	 * assertion rather than living in the never_grant_capabilities() list.
	 */
	public function test_client_editor_cannot_edit_custom_css(): void {
		$client_editor = $this->make_client_editor();
		wp_set_current_user( $client_editor->ID );

		self::assertFalse( current_user_can( 'edit_css' ) );
	}

	public function test_client_editor_retains_core_editor_capabilities(): void {
		$client_editor = $this->make_client_editor();
		wp_set_current_user( $client_editor->ID );

		$retained_capabilities = array(
			'edit_posts',
			'edit_pages',
			'publish_posts',
			'publish_pages',
			'upload_files',
			'edit_published_posts',
		);

		foreach ( $retained_capabilities as $capability ) {
			self::assertTrue(
				current_user_can( $capability ),
				"client_editor must keep the '{$capability}' capability."
			);
		}
	}

	public function test_application_passwords_are_unavailable_to_client_editor(): void {
		$client_editor = $this->make_client_editor();

		self::assertFalse( wp_is_application_passwords_available_for_user( $client_editor ) );
	}

	public function test_application_passwords_are_available_to_administrators(): void {
		$admin = $this->make_admin();

		self::assertTrue( wp_is_application_passwords_available_for_user( $admin ) );
	}

	public function test_code_editing_and_block_locking_are_disabled_for_client_editor(): void {
		$client_editor = $this->make_client_editor();
		wp_set_current_user( $client_editor->ID );

		$settings = apply_filters(
			'block_editor_settings_all',
			array(
				'codeEditingEnabled' => true,
				'canLockBlocks'      => true,
			),
			new \WP_Block_Editor_Context()
		);

		self::assertFalse( $settings['codeEditingEnabled'] );
		self::assertFalse( $settings['canLockBlocks'] );
	}

	public function test_code_editing_and_block_locking_are_unchanged_for_administrators(): void {
		$admin = $this->make_admin();
		wp_set_current_user( $admin->ID );

		$settings = apply_filters(
			'block_editor_settings_all',
			array(
				'codeEditingEnabled' => true,
				'canLockBlocks'      => true,
			),
			new \WP_Block_Editor_Context()
		);

		self::assertTrue( $settings['codeEditingEnabled'] );
		self::assertTrue( $settings['canLockBlocks'] );
	}

	public function test_client_editor_is_restricted_to_the_approved_block_allow_list(): void {
		$client_editor = $this->make_client_editor();
		wp_set_current_user( $client_editor->ID );

		$allowed_blocks = apply_filters( 'allowed_block_types_all', true, new \WP_Block_Editor_Context() );

		self::assertIsArray( $allowed_blocks );
		self::assertSame( EditorRestrictions::ALLOWED_BLOCKS, $allowed_blocks );
		self::assertContains( 'agency/reference-callout', $allowed_blocks );
		self::assertContains( 'core/paragraph', $allowed_blocks );
		self::assertNotContains( 'core/html', $allowed_blocks );
		self::assertNotContains( 'core/shortcode', $allowed_blocks );
	}

	public function test_administrators_get_unrestricted_block_types(): void {
		$admin = $this->make_admin();
		wp_set_current_user( $admin->ID );

		self::assertTrue( apply_filters( 'allowed_block_types_all', true, new \WP_Block_Editor_Context() ) );
	}

	public function test_client_editor_role_is_registered(): void {
		$role = get_role( 'client_editor' );

		self::assertNotNull( $role );
		self::assertSame( 'Client Editor', wp_roles()->role_names['client_editor'] ?? null );
	}

	/**
	 * The base profile never loads WooCommerce (see
	 * tests/Integration/bootstrap.php — site-commerce is deliberately not
	 * loaded), so AgencyPlatform\Roles\ShopRole's `class_exists('WooCommerce')`
	 * guard must skip registering `client_shop_manager` entirely. Proving
	 * that role is absent here is proof the base profile really does run
	 * isolated from commerce, not just that the guard's condition compiles.
	 */
	public function test_client_shop_manager_role_is_not_registered_in_the_base_profile(): void {
		self::assertNull( get_role( 'client_shop_manager' ) );
	}
}
