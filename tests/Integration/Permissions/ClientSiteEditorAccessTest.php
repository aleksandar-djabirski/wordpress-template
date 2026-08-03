<?php
/**
 * Proves the client roles can actually drive the Site Editor's data layer â€”
 * reading and writing templates, template parts, navigation and global styles
 * through the REST routes the Site Editor itself uses â€” while the forbidden
 * admin surface stays closed.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Permissions;

use AgencyPlatform\Security\AdminScreenPolicy;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\Roles\RolesProvider
 * @covers \AgencyPlatform\Security\AdminScreenPolicy
 * @covers \AgencyPlatform\Security\CapabilityPolicy
 */
final class ClientSiteEditorAccessTest extends IntegrationTestCase {

	public function set_up(): void {
		parent::set_up();

		do_action( 'rest_api_init' );
	}

	public function test_client_editor_can_read_the_template_rest_collection(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/templates' ) );

		self::assertSame( 200, $response->get_status() );
	}

	public function test_client_editor_can_read_the_template_part_rest_collection(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/template-parts' ) );

		self::assertSame( 200, $response->get_status() );
	}

	public function test_client_editor_can_create_a_navigation_record(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/navigation' );
		$request->set_param( 'title', 'Client Navigation' );
		$request->set_param( 'status', 'publish' );
		$request->set_param( 'content', '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->' );

		$response = rest_do_request( $request );

		self::assertContains( $response->get_status(), array( 200, 201 ) );
	}

	/*
	 * The two template write-back tests are deliberately NOT here. They send a
	 * REST update to `<stylesheet>//index` and `<stylesheet>//site-footer`,
	 * which resolve only after `templates/index.html` and
	 * `parts/site-footer.html` exist. Task 6 converts the theme and adds both
	 * tests at its Step 12. Before the conversion WordPress answers 404 and
	 * `wp_is_block_theme()` returns 0, so keeping them here would land Task 3
	 * on a red gate.
	 */

	public function test_client_editor_can_write_global_styles_and_the_change_persists(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$post_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();

		$request = new \WP_REST_Request( 'POST', '/wp/v2/global-styles/' . $post_id );
		$request->set_param( 'styles', array( 'color' => array( 'background' => 'var(--wp--preset--color--neutral-100)' ) ) );

		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status() );

		// Read the STORED record back, not the response echo.
		$stored = json_decode( (string) get_post( $post_id )->post_content, true );

		self::assertSame(
			'var(--wp--preset--color--neutral-100)',
			$stored['styles']['color']['background'] ?? null
		);
	}

	public function test_client_editor_can_create_and_read_back_a_navigation_record(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/navigation' );
		$request->set_param( 'title', 'Client Navigation Write' );
		$request->set_param( 'status', 'publish' );
		$request->set_param( 'content', '<!-- wp:navigation-link {"label":"Written","url":"/written/"} /-->' );

		$response = rest_do_request( $request );

		self::assertContains( $response->get_status(), array( 200, 201 ) );

		$created = get_post( (int) $response->get_data()['id'] );

		self::assertStringContainsString( 'Written', (string) $created->post_content );
	}

	public function test_client_editor_cannot_edit_themes_or_plugins_or_files(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		foreach ( array( 'switch_themes', 'install_themes', 'install_plugins', 'activate_plugins', 'edit_themes', 'edit_plugins', 'edit_files', 'manage_options' ) as $capability ) {
			self::assertFalse( current_user_can( $capability ), $capability . ' must stay denied.' );
		}
	}

	public function test_the_admin_screen_deny_list_still_names_every_forbidden_screen(): void {
		foreach ( array( 'themes.php', 'theme-install.php', 'theme-editor.php', 'plugin-install.php', 'plugin-editor.php', 'customize.php', 'widgets.php', 'nav-menus.php' ) as $screen ) {
			self::assertTrue( AdminScreenPolicy::is_denied( $screen, false ), $screen . ' must be denied.' );
		}
	}
}
