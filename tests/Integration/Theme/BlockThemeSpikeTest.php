<?php
/**
 * The BLOCK_THEME_PROPOSAL.md §8.3 Phase 0 gate, as far as PHPUnit can prove
 * it: WordPress recognises the theme as a block theme, the spike templates
 * resolve and render, both template parts resolve, and PHP can still register
 * the theme's custom blocks.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Theme;

use Tests\Integration\IntegrationTestCase;

final class BlockThemeSpikeTest extends IntegrationTestCase {

	public function test_wordpress_recognises_the_theme_as_a_block_theme(): void {
		self::assertTrue( wp_is_block_theme() );
		self::assertSame( 'site-theme', get_stylesheet() );
	}

	public function test_the_index_template_resolves_and_renders(): void {
		$slugs = wp_list_pluck( get_block_templates(), 'slug' );

		self::assertContains( 'index', $slugs );

		$template = get_block_template( get_stylesheet() . '//index', 'wp_template' );

		self::assertNotNull( $template );
		self::assertNotSame( '', trim( do_blocks( $template->content ) ) );
	}

	public function test_both_template_parts_resolve_and_render(): void {
		$slugs = wp_list_pluck( get_block_templates( array(), 'wp_template_part' ), 'slug' );

		self::assertContains( 'site-header', $slugs );
		self::assertContains( 'site-footer', $slugs );

		foreach ( array( 'site-header', 'site-footer' ) as $slug ) {
			$part = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template_part' );

			self::assertNotNull( $part, $slug . ' must resolve.' );
			self::assertNotSame( '', trim( do_blocks( $part->content ) ), $slug . ' must render.' );
		}
	}

	public function test_php_still_registers_the_theme_custom_blocks(): void {
		// §8.3: "Confirm PHP code can continue registering custom blocks."
		self::assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'agency/reference-callout' ) );
	}

	public function test_an_administrator_can_edit_and_save_a_template(): void {
		do_action( 'rest_api_init' );

		wp_set_current_user( $this->make_admin()->ID );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/templates/' . get_stylesheet() . '//index' );
		$request->set_param( 'content', "<!-- wp:paragraph -->\n<p>Spike edit.</p>\n<!-- /wp:paragraph -->" );

		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status() );

		$saved = get_block_template( get_stylesheet() . '//index', 'wp_template' );

		self::assertStringContainsString( 'Spike edit.', (string) $saved->content );
	}

	public function test_the_classic_path_still_serves_templates_the_spike_does_not_cover(): void {
		// locate_block_template() slices the hierarchy at the PHP template it
		// found, so a request type with no equal-or-higher block template still
		// renders classically. This is what lets the spike coexist.
		self::assertFileExists( dirname( __DIR__, 3 ) . '/web/app/themes/site-theme/templates/single.php' );
		self::assertNotContains( 'single', wp_list_pluck( get_block_templates(), 'slug' ) );
	}
}
