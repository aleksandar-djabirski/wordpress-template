<?php
/**
 * BLOCK_THEME_PROPOSAL.md §7.1: the three Git-backed providers read the
 * active theme's rows through the wp_theme taxonomy (field => slug — the
 * tax_query default of term_id would silently match nothing), normalise
 * their content through the same serialiser the Git baseline reader uses
 * (so a healthy site can never report phantom drift), and emit the
 * references the bundle hash depends on. Created in Task 8, extended in
 * Task 9.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\Providers\GlobalStylesState;
use AgencyPlatform\State\Providers\TemplatePartsState;
use AgencyPlatform\State\Providers\TemplatesState;
use AgencyPlatform\State\ReferenceScanner;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Providers\TemplatesState
 * @covers \AgencyPlatform\State\Providers\TemplatePartsState
 * @covers \AgencyPlatform\State\Providers\GlobalStylesState
 */
final class ProviderRecordsTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	public function test_a_template_override_becomes_a_promotable_record(): void {
		$fixture = "<!-- wp:paragraph -->\r\n<p>Live edit</p>\r\n<!-- /wp:paragraph -->";

		$this->make_template( 'page', $fixture );

		$records = ( new TemplatesState() )->records();

		self::assertCount( 1, $records );
		self::assertSame( 'templates:page', $records[0]->key() );
		self::assertSame( Ownership::GIT_BASELINE_PLUS_DB, $records[0]->ownership() );
		self::assertSame( PromotionPolicy::PROMOTABLE, $records[0]->promotion() );
		self::assertStringNotContainsString( "\r", $records[0]->content()['markup'] );
		self::assertSame( array( 'markup' ), array_keys( $records[0]->content() ) );
		self::assertSame( serialize_blocks( parse_blocks( str_replace( "\r\n", "\n", $fixture ) ) ), $records[0]->content()['markup'], 'The exported markup must be byte-identical to a parse/serialise round-trip of the LF-normalised fixture — the provider and the Git baseline reader both normalise line endings BEFORE parsing, so a healthy site can never report phantom drift.' );
	}

	public function test_a_template_records_its_navigation_reference(): void {
		$this->make_template( 'home', '<!-- wp:navigation {"ref":31} /-->' );

		$references = ( new TemplatesState() )->record( 'templates:home' )->references();

		self::assertSame( ReferenceScanner::KIND_NAVIGATION, $references[0]['kind'] );
		self::assertSame( 31, $references[0]['value'] );
	}

	public function test_a_template_part_override_becomes_a_promotable_record(): void {
		$fixture = "<!-- wp:paragraph -->\r\n<p>Part edit</p>\r\n<!-- /wp:paragraph -->";

		$this->make_part( 'header', $fixture );

		$records = ( new TemplatePartsState() )->records();

		self::assertCount( 1, $records );
		self::assertSame( 'template-parts:header', $records[0]->key() );
		self::assertSame( Ownership::GIT_BASELINE_PLUS_DB, $records[0]->ownership() );
		self::assertSame( PromotionPolicy::PROMOTABLE, $records[0]->promotion() );
		self::assertStringNotContainsString( "\r", $records[0]->content()['markup'] );
		self::assertSame( array( 'markup' ), array_keys( $records[0]->content() ) );
		self::assertSame( serialize_blocks( parse_blocks( str_replace( "\r\n", "\n", $fixture ) ) ), $records[0]->content()['markup'], 'A template part must normalise exactly like a template — the baseline reader treats the two identically.' );
	}

	public function test_a_template_part_records_its_navigation_reference(): void {
		$this->make_part( 'footer', '<!-- wp:navigation {"ref":17} /-->' );

		$references = ( new TemplatePartsState() )->record( 'template-parts:footer' )->references();

		self::assertSame( ReferenceScanner::KIND_NAVIGATION, $references[0]['kind'] );
		self::assertSame( 17, $references[0]['value'] );
	}

	public function test_an_uncustomised_global_styles_row_matches_its_empty_baseline(): void {
		$this->make_global_styles( '{"version":3,"isGlobalStylesUserThemeJSON":true}' );

		$provider = new GlobalStylesState();

		self::assertSame( array(), $provider->records()[0]->content() );
		self::assertSame( $provider->baseline_records()[0]->content_hash(), $provider->records()[0]->content_hash() );
	}

	public function test_a_customised_global_styles_row_differs_from_its_baseline(): void {
		$this->make_global_styles( '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"color":{"background":"var(--wp--preset--color--base)"}}}' );

		$provider = new GlobalStylesState();

		self::assertNotSame( $provider->baseline_records()[0]->content_hash(), $provider->records()[0]->content_hash() );
	}

	public function test_global_styles_strips_custom_css_so_it_is_not_both_promotable_and_forbidden(): void {
		$this->make_global_styles( '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"css":"body{color:red}","blocks":{"core/group":{"css":"padding:0"}}}}' );

		$provider = new GlobalStylesState();

		self::assertSame( array(), $provider->records()[0]->content(), 'CSS-only edits belong to the custom-css provider; Global Styles must read as unchanged.' );
		self::assertSame( $provider->baseline_records()[0]->content_hash(), $provider->records()[0]->content_hash() );
	}

	public function test_global_styles_is_the_user_origin_record_named_active(): void {
		$this->make_global_styles( '{"version":3,"isGlobalStylesUserThemeJSON":true}' );

		$provider = new GlobalStylesState();
		$baseline = $provider->baseline_records();

		self::assertSame( 'global-styles:active', $provider->records()[0]->key() );
		self::assertSame( 'active', $provider->records()[0]->slug() );
		self::assertSame( Ownership::GIT_BASELINE_PLUS_DB_USER_ORIGIN, $provider->records()[0]->ownership() );
		self::assertSame( PromotionPolicy::PROMOTABLE, $provider->records()[0]->promotion() );
		self::assertSame( 'global-styles:active', $baseline[0]->key() );
		self::assertNull( $baseline[0]->object_id() );
		self::assertSame( 'baseline', $baseline[0]->status() );
		self::assertSame( array(), $baseline[0]->content() );
	}

	public function test_global_styles_records_a_font_file_reference(): void {
		$this->make_global_styles( '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"typography":{"fontFamily":"assets/fonts/example.woff2"}}}' );

		$references = ( new GlobalStylesState() )->records()[0]->references();

		self::assertCount( 1, $references );
		self::assertSame( ReferenceScanner::KIND_FONT_FILE, $references[0]['kind'] );
		self::assertSame( 'assets/fonts/example.woff2', $references[0]['value'] );
		self::assertSame( ReferenceScanner::RESOLUTION_ENVIRONMENT, $references[0]['resolution'] );
	}

	public function test_the_theme_taxonomy_query_uses_the_stylesheet_slug(): void {
		// A wp_template row belonging to ANOTHER theme must never be exported.
		$foreign_id = self::factory()->post->create(
			array(
				'post_type'   => 'wp_template',
				'post_name'   => 'page',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $foreign_id, 'some-other-theme', 'wp_theme' );

		self::assertSame( array(), ( new TemplatesState() )->records(), 'Without field => slug the tax_query matches nothing at all; with it, only the active theme matches.' );

		$this->make_template( 'page', '<!-- wp:paragraph --><p>Ours</p><!-- /wp:paragraph -->' );

		self::assertSame( array( 'templates:page' ), array_map( static fn ( $record ) => $record->key(), ( new TemplatesState() )->records() ) );
	}
}
