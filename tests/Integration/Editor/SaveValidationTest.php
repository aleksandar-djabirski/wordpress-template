<?php
/**
 * Proves the save boundary against a real WordPress + REST stack. The editor
 * allow-list is a UI convenience; this is the security boundary, so every
 * rejection class BLOCK_THEME_PROPOSAL.md §9.3 names is exercised through an
 * actual REST request rather than through the policy functions alone.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Editor;

use Tests\Integration\IntegrationTestCase;

final class SaveValidationTest extends IntegrationTestCase {

	private const ORIGINAL = '<!-- wp:paragraph --><p>Original content.</p><!-- /wp:paragraph -->';

	public function set_up(): void {
		parent::set_up();

		do_action( 'rest_api_init' );
		add_shortcode( 'agency_test_shortcode', '__return_empty_string' );
	}

	public function tear_down(): void {
		remove_shortcode( 'agency_test_shortcode' );

		parent::tear_down();
	}

	private function make_page(): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => self::ORIGINAL,
			)
		);
	}

	private function save_as_client( int $page_id, string $content ): \WP_REST_Response {
		return $this->save_as_client_route( '/wp/v2/pages/' . $page_id, $content );
	}

	private function save_as_client_route( string $route, string $content ): \WP_REST_Response {
		wp_set_current_user( $this->make_client_editor()->ID );

		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_param( 'content', $content );

		return rest_do_request( $request );
	}

	private function assert_client_rejection( string $content, string $error_code ): void {
		$response = $this->save_as_client( $this->make_page(), $content );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( $error_code, $response->as_error()->get_error_code() );
	}

	public function test_a_forbidden_block_is_rejected_with_a_named_rest_error(): void {
		$page_id  = $this->make_page();
		$response = $this->save_as_client( $page_id, '<!-- wp:html --><div>raw</div><!-- /wp:html -->' );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'agency_platform_forbidden_block', $response->as_error()->get_error_code() );
		self::assertStringContainsString( 'core/html', $response->as_error()->get_error_message() );
	}

	public function test_a_rejected_save_leaves_the_original_content_intact(): void {
		$page_id = $this->make_page();

		$this->save_as_client( $page_id, '<!-- wp:html --><div>raw</div><!-- /wp:html -->' );

		// Rejection, never silent stripping: destroying a client's content
		// without telling them is worse than refusing the save.
		self::assertSame( self::ORIGINAL, get_post( $page_id )->post_content );
	}

	public function test_a_nested_forbidden_block_is_rejected(): void {
		$this->assert_client_rejection(
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:html --><div>x</div><!-- /wp:html --></div><!-- /wp:group -->',
			'agency_platform_forbidden_block'
		);
	}

	public function test_the_freeform_block_is_rejected(): void {
		$this->assert_client_rejection(
			'<!-- wp:freeform -->Classic content<!-- /wp:freeform -->',
			'agency_platform_forbidden_block'
		);
	}

	public function test_an_unregistered_block_is_rejected(): void {
		$this->assert_client_rejection( '<!-- wp:acme/thing /-->', 'agency_platform_forbidden_block' );
	}

	public function test_raw_html_is_rejected(): void {
		$this->assert_client_rejection( '<script>alert(1)</script>', 'agency_platform_raw_html' );
	}

	public function test_whitespace_only_null_name_separators_are_allowed(): void {
		$page_id  = $this->make_page();
		$response = $this->save_as_client( $page_id, "<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->" );

		self::assertSame( 200, $response->get_status() );
	}

	public function test_a_registered_shortcode_is_rejected_anywhere_in_content(): void {
		$this->assert_client_rejection(
			'<!-- wp:paragraph --><p>Call [agency_test_shortcode] now</p><!-- /wp:paragraph -->',
			'agency_platform_shortcode'
		);
	}

	public function test_unregistered_bracket_text_is_allowed(): void {
		$page_id  = $this->make_page();
		$response = $this->save_as_client( $page_id, '<!-- wp:paragraph --><p>Rates are [subject to change]</p><!-- /wp:paragraph -->' );

		self::assertSame( 200, $response->get_status() );
	}

	public function test_an_escaped_shortcode_is_allowed(): void {
		$page_id  = $this->make_page();
		$response = $this->save_as_client( $page_id, '<!-- wp:paragraph --><p>See [[agency_test_shortcode]]</p><!-- /wp:paragraph -->' );

		self::assertSame( 200, $response->get_status() );
	}

	public function test_block_instance_custom_css_is_rejected(): void {
		$this->assert_client_rejection(
			'<!-- wp:paragraph {"style":{"css":"color:red"}} --><p>x</p><!-- /wp:paragraph -->',
			'agency_platform_block_custom_css'
		);
	}

	public function test_a_valid_nested_block_tree_is_allowed(): void {
		$page_id  = $this->make_page();
		$response = $this->save_as_client( $page_id, '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Fine</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );

		self::assertSame( 200, $response->get_status() );
	}

	public function test_administrators_can_save_a_forbidden_block(): void {
		wp_set_current_user( $this->make_admin()->ID );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/pages/' . $this->make_page() );
		$request->set_param( 'content', '<!-- wp:html --><div>raw</div><!-- /wp:html -->' );

		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status() );
	}

	public function test_site_editor_rejects_the_four_save_violation_classes_for_templates_and_parts(): void {
		$cases = array(
			array( '<!-- wp:html --><div>raw</div><!-- /wp:html -->', 'agency_platform_forbidden_block' ),
			array( '<script>alert(1)</script>', 'agency_platform_raw_html' ),
			array( '<!-- wp:paragraph --><p>Call [agency_test_shortcode] now</p><!-- /wp:paragraph -->', 'agency_platform_shortcode' ),
			array( '<!-- wp:paragraph {"style":{"css":"color:red"}} --><p>x</p><!-- /wp:paragraph -->', 'agency_platform_block_custom_css' ),
		);

		$routes = array(
			'/wp/v2/templates/' . get_stylesheet() . '//page',
			'/wp/v2/template-parts/' . get_stylesheet() . '//site-header',
		);

		foreach ( $routes as $route ) {
			foreach ( $cases as $case ) {
				$response = $this->save_as_client_route( $route, $case[0] );

				self::assertSame( 403, $response->get_status(), $route . ' must reject ' . $case[1] . '.' );
				self::assertSame( $case[1], $response->as_error()->get_error_code(), $route . ' must name ' . $case[1] . '.' );
			}
		}
	}

	public function test_a_shortcode_is_rejected_for_navigation_saves(): void {
		$navigation_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
			)
		);

		$response = $this->save_as_client_route(
			'/wp/v2/navigation/' . $navigation_id,
			'<!-- wp:navigation-link {"label":"[agency_test_shortcode]","url":"/"} /-->'
		);

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'agency_platform_shortcode', $response->as_error()->get_error_code() );
	}

	public function test_the_editor_context_is_derived_from_the_saved_post_type(): void {
		$block = register_block_type(
			'acme/site-only',
			array(
				'render_callback' => '__return_empty_string',
			)
		);
		self::assertInstanceOf( \WP_Block_Type::class, $block );

		$filter = static function ( array $blocks, \WP_Block_Editor_Context $context ): array {
			return 'core/edit-site' === $context->name ? array( 'acme/site-only' ) : $blocks;
		};

		add_filter( 'agency_platform_allowed_blocks', $filter, 10, 2 );

		try {
			$template_response = $this->save_as_client_route(
				'/wp/v2/templates/' . get_stylesheet() . '//page',
				'<!-- wp:acme/site-only /-->'
			);

			self::assertSame( 200, $template_response->get_status() );

			$page_response = $this->save_as_client( $this->make_page(), '<!-- wp:acme/site-only /-->' );

			self::assertSame( 403, $page_response->get_status() );
			self::assertSame( 'agency_platform_forbidden_block', $page_response->as_error()->get_error_code() );
		} finally {
			remove_filter( 'agency_platform_allowed_blocks', $filter, 10 );
			unregister_block_type( 'acme/site-only' );
		}
	}
}
