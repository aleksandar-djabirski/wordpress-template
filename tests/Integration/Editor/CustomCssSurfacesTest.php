<?php
/**
 * Proves every custom-CSS surface is rejected for client roles without
 * changing stored content, while administrators retain control.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Editor;

use AgencyPlatform\Editor\GlobalStylesGuard;
use AgencyPlatform\Security\AdminScreenPolicy;
use Tests\Integration\IntegrationTestCase;

final class CustomCssSurfacesTest extends IntegrationTestCase {

	public function set_up(): void {
		parent::set_up();

		\WP_Theme_JSON_Resolver::clean_cached_data();
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		parent::tear_down();

		\WP_Theme_JSON_Resolver::clean_cached_data();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stored_styles( int $post_id ): array {
		$content = json_decode( (string) get_post( $post_id )->post_content, true );

		return is_array( $content ) && is_array( $content['styles'] ?? null ) ? $content['styles'] : array();
	}

	private function global_styles_write( int $post_id, array $styles ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/wp/v2/global-styles/' . $post_id );
		$request->set_param( 'styles', $styles );

		return rest_do_request( $request );
	}

	public function test_block_instance_custom_css_is_rejected_and_post_content_is_unchanged(): void {
		$page_id  = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Original.</p><!-- /wp:paragraph -->',
			)
		);
		$original = (string) get_post( $page_id )->post_content;

		wp_set_current_user( $this->make_client_editor()->ID );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/pages/' . $page_id );
		$request->set_param( 'content', '<!-- wp:paragraph {"style":{"css":"color:red"}} --><p>x</p><!-- /wp:paragraph -->' );

		$response = rest_do_request( $request );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'agency_platform_block_custom_css', $response->as_error()->get_error_code() );
		self::assertSame( $original, get_post( $page_id )->post_content );
	}

	public function test_global_styles_root_css_is_rejected_and_the_stored_styles_are_unchanged(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$post_id = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$before  = $this->stored_styles( $post_id );

		$response = $this->global_styles_write( $post_id, array( 'css' => 'body{color:red}' ) );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'agency_platform_global_styles_custom_css', $response->as_error()->get_error_code() );
		self::assertSame( $before, $this->stored_styles( $post_id ) );

		$read = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/global-styles/' . $post_id ) );

		self::assertSame( 200, $read->get_status() );
		self::assertArrayNotHasKey( 'css', (array) ( $read->get_data()['styles'] ?? array() ) );
	}

	public function test_global_styles_block_css_is_rejected_with_its_dotted_path_and_stored_styles_are_unchanged(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$post_id = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$before  = $this->stored_styles( $post_id );

		$response = $this->global_styles_write(
			$post_id,
			array( 'blocks' => array( 'core/group' => array( 'css' => '.x{}' ) ) )
		);

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'agency_platform_global_styles_custom_css', $response->as_error()->get_error_code() );
		self::assertStringContainsString( 'styles.blocks.core/group.css', $response->as_error()->get_error_message() );
		self::assertSame( $before, $this->stored_styles( $post_id ) );
	}

	public function test_administrators_can_write_global_styles_root_and_block_css(): void {
		wp_set_current_user( $this->make_client_editor()->ID );
		$post_id = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();

		wp_set_current_user( $this->make_admin()->ID );

		$root_response = $this->global_styles_write( $post_id, array( 'css' => 'body{color:red}' ) );
		self::assertSame( 200, $root_response->get_status() );

		$block_response = $this->global_styles_write(
			$post_id,
			array( 'blocks' => array( 'core/group' => array( 'css' => '.x{}' ) ) )
		);
		self::assertSame( 200, $block_response->get_status() );
	}

	public function test_client_editor_cannot_edit_the_custom_css_post_type(): void {
		$post_type = get_post_type_object( 'custom_css' );

		self::assertNotNull( $post_type );

		wp_set_current_user( $this->make_client_editor()->ID );

		self::assertFalse( current_user_can( $post_type->cap->edit_posts ) );
		self::assertFalse( current_user_can( 'edit_css' ) );
	}

	public function test_global_styles_guard_preserves_an_existing_admin_screen_short_circuit(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$server         = new \WP_REST_Server();
		$legacy_request = new \WP_REST_Request( 'GET', '/wp/v2/widgets' );
		$admin_result   = ( new AdminScreenPolicy() )->block_legacy_design_rest_routes( null, $server, $legacy_request );

		self::assertInstanceOf( \WP_REST_Response::class, $admin_result );

		$guard_result = ( new GlobalStylesGuard() )->guard_global_styles_write(
			$admin_result,
			$server,
			new \WP_REST_Request( 'POST', '/wp/v2/global-styles/1' )
		);

		self::assertSame( $admin_result, $guard_result );
	}
}
