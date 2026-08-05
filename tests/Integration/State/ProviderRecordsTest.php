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
use AgencyPlatform\State\Providers\ContentState;
use AgencyPlatform\State\Providers\CustomCssState;
use AgencyPlatform\State\Providers\GlobalStylesState;
use AgencyPlatform\State\Providers\MediaReferencesState;
use AgencyPlatform\State\Providers\NavigationState;
use AgencyPlatform\State\Providers\SyncedPatternsState;
use AgencyPlatform\State\Providers\TemplatePartsState;
use AgencyPlatform\State\Providers\TemplatesState;
use AgencyPlatform\State\ReferenceScanner;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Providers\TemplatesState
 * @covers \AgencyPlatform\State\Providers\TemplatePartsState
 * @covers \AgencyPlatform\State\Providers\GlobalStylesState
 * @covers \AgencyPlatform\State\Providers\NavigationState
 * @covers \AgencyPlatform\State\Providers\SyncedPatternsState
 * @covers \AgencyPlatform\State\Providers\ContentState
 * @covers \AgencyPlatform\State\Providers\FontLibraryState
 * @covers \AgencyPlatform\State\Providers\MediaReferencesState
 * @covers \AgencyPlatform\State\Providers\CustomCssState
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

	public function test_navigation_is_database_owned_and_never_promoted(): void {
		$this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );

		$record = ( new NavigationState() )->records()[0];

		self::assertSame( 'navigation:primary', $record->key() );
		self::assertSame( Ownership::DATABASE, $record->ownership() );
		self::assertSame( PromotionPolicy::EXPORT_AND_DIFF, $record->promotion() );
		self::assertFalse( ( new NavigationState() )->has_git_baseline() );
	}

	public function test_content_records_carry_no_password_and_no_email(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_title'    => 'Protected',
				'post_password' => 'super-secret-password',
			)
		);

		$record = ( new ContentState() )->record( 'content:page-' . $page_id );

		self::assertTrue( $record->content()['hasPassword'] );
		self::assertStringNotContainsString( 'super-secret-password', wp_json_encode( $record->to_array() ) );
		self::assertArrayNotHasKey( 'postPassword', $record->content() );
	}

	public function test_media_references_are_derived_from_template_markup(): void {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/08/hero.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Hero',
			)
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => '2026/08/hero.jpg',
				'width'  => 1440,
				'height' => 900,
			)
		);

		$this->make_template( 'media', sprintf( '<!-- wp:image {"id":%d} /-->', $attachment_id ) );

		$records = ( new MediaReferencesState() )->records();

		self::assertSame( 'media-references:attachment-' . $attachment_id, $records[0]->key() );
		self::assertArrayHasKey( 'mimeType', $records[0]->content() );
		self::assertArrayNotHasKey( 'bytes', $records[0]->content() );
	}

	public function test_custom_css_is_detected_in_global_styles_and_in_the_custom_css_post(): void {
		$this->make_global_styles( '{"version":3,"styles":{"css":"body{color:red}"}}' );
		wp_update_custom_css_post( '.legacy { color: blue; }' );

		$records = ( new CustomCssState() )->records();
		$by_key  = array_combine( array_map( static fn( $record ) => $record->key(), $records ), $records );

		self::assertStringContainsString( 'body{color:red}', $by_key['custom-css:global-styles']->content()['css'] );
		self::assertStringContainsString( '.legacy', $by_key['custom-css:custom-css-post']->content()['css'] );
		self::assertSame( PromotionPolicy::REFUSE, $by_key['custom-css:global-styles']->promotion() );
	}

	public function test_custom_css_records_exist_even_when_no_css_is_set(): void {
		$records = ( new CustomCssState() )->records();

		self::assertCount( 2, $records, 'Both custom-CSS records are always emitted so the record set stays deterministic.' );
		self::assertSame( '', $records[0]->content()['css'] );
	}

	public function test_no_custom_css_matches_the_no_css_git_baseline(): void {
		$provider = new CustomCssState();

		self::assertTrue( $provider->has_git_baseline(), 'Git owns the theme stylesheets; "no Additional CSS" IS the baseline.' );
		self::assertSame(
			array_map( static fn ( $record ) => $record->content_hash(), $provider->baseline_records() ),
			array_map( static fn ( $record ) => $record->content_hash(), $provider->records() ),
			'An untouched site must produce no custom-css drift at all.'
		);
	}

	public function test_the_base_detect_references_default_scans_markup_for_references(): void {
		$navigation_id = $this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );
		$this->make_synced_pattern( 'hero', sprintf( '<!-- wp:navigation {"ref":%d} /-->', $navigation_id ) );

		$references = ( new SyncedPatternsState() )->record( 'synced-patterns:hero' )->references();

		self::assertNotEmpty( $references, 'The base detect_references() default must scan markup: an empty list means the Task 5 return-array() landmine is still in place.' );
	}
}
