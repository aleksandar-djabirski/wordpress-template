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

	/**
	 * @var array{message: string, args: array<mixed>}|null
	 */
	private static ?array $wp_die_call = null;

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

	/**
	 * @return list<array{0: string}>
	 */
	public static function legacy_design_rest_routes(): array {
		return array(
			array( '/wp/v2/widgets' ),
			array( '/wp/v2/widget-types' ),
			array( '/wp/v2/sidebars' ),
			array( '/wp/v2/menus' ),
			array( '/wp/v2/menu-items' ),
			array( '/wp/v2/menu-locations' ),
		);
	}

	/**
	 * @dataProvider legacy_design_rest_routes
	 */
	public function test_client_editor_cannot_access_legacy_design_rest_routes( string $route ): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$response = rest_do_request( new \WP_REST_Request( 'GET', $route ) );

		self::assertSame( 403, $response->get_status(), $route . ' must stay outside the Site Editor.' );
		self::assertSame( 'agency_platform_legacy_design_rest_forbidden', $response->get_data()['code'] ?? null );
	}

	public function test_client_editor_cannot_write_a_legacy_widget(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$response = rest_do_request( new \WP_REST_Request( 'POST', '/wp/v2/widgets' ) );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'agency_platform_legacy_design_rest_forbidden', $response->get_data()['code'] ?? null );
	}

	public function test_client_editor_cannot_use_customize_changeset_capability_aliases(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$capabilities = get_post_type_object( 'customize_changeset' )->cap;

		foreach ( array( 'create_posts', 'edit_posts', 'edit_others_posts', 'delete_posts', 'publish_posts', 'read_private_posts' ) as $property ) {
			self::assertFalse( current_user_can( $capabilities->{$property} ), $property . ' must map to the denied customize meta capability.' );
		}
	}

	public function test_a_direct_customize_request_is_blocked_for_client_editor(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$original_pagenow  = $GLOBALS['pagenow'] ?? null;
		self::$wp_die_call = null;
		add_filter( 'wp_die_handler', array( self::class, 'replace_wp_die_handler' ) );
		$GLOBALS['pagenow'] = 'customize.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A wp-admin request sets this global before admin_init.

		try {
			( new AdminScreenPolicy() )->block_denied_screens();
			self::fail( 'A direct customize.php request must stop at AdminScreenPolicy.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'AdminScreenPolicy blocked the request.', $exception->getMessage() );
			self::assertNotNull( self::$wp_die_call );
			self::assertSame( 403, self::$wp_die_call['args']['response'] ?? null );
		} finally {
			remove_filter( 'wp_die_handler', array( self::class, 'replace_wp_die_handler' ) );

			if ( null === $original_pagenow ) {
				unset( $GLOBALS['pagenow'] );
			} else {
				$GLOBALS['pagenow'] = $original_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the simulated wp-admin request state.
			}
		}
	}

	public function test_the_admin_screen_deny_list_still_names_every_forbidden_screen(): void {
		foreach ( array( 'themes.php', 'theme-install.php', 'theme-editor.php', 'plugin-install.php', 'plugin-editor.php', 'customize.php', 'widgets.php', 'nav-menus.php' ) as $screen ) {
			self::assertTrue( AdminScreenPolicy::is_denied( $screen, false ), $screen . ' must be denied.' );
		}
	}

	/**
	 * @param callable $handler
	 */
	public static function replace_wp_die_handler( $handler ): array {
		return array( self::class, 'capture_wp_die' );
	}

	/**
	 * @param string|\WP_Error $message
	 * @param array<mixed>      $args
	 */
	public static function capture_wp_die( $message, string $title, array $args ): void {
		self::$wp_die_call = array(
			'message' => (string) $message,
			'args'    => $args,
		);

		throw new \RuntimeException( 'AdminScreenPolicy blocked the request.' );
	}
}
