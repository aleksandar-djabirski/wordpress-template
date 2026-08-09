<?php
/**
 * The fail-closed Theme JSON adapter (plan Task 22): the ONLY file allowed
 * to name any Theme JSON internal. It probes every Core symbol it depends
 * on — class, method, function and constant forms — and refuses every
 * operation with exit 1 when the locked WordPress version stops exposing
 * that API shape. The merge path builds its result from get_data() (the
 * flattened, input-shaped view), never get_raw_data() (the origin-keyed
 * internal view that is not valid theme.json input), and the resolution
 * view is get_merged_data()->get_data() split into settings and styles —
 * the same canonical view both resolved() and
 * resolved_without_user_origin() use, so a promotion that moves a user
 * preset from the custom origin to the theme origin cannot report drift.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\ThemeJsonAdapter;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\ThemeJsonAdapter
 */
final class ThemeJsonAdapterTest extends IntegrationTestCase {

	public function test_the_adapter_supports_the_locked_wordpress_version(): void {
		$adapter = new ThemeJsonAdapter();

		self::assertSame( array(), $adapter->missing_symbols(), 'A WordPress update changed the Theme JSON API shape.' );
		self::assertTrue( $adapter->supported() );
	}

	public function test_the_probe_covers_every_declared_symbol(): void {
		$seen    = array();
		$adapter = new ThemeJsonAdapter(
			static function ( string $symbol ) use ( &$seen ): bool {
				$seen[] = $symbol;
				return true;
			}
		);

		$adapter->missing_symbols();

		self::assertContains( 'WP_Theme_JSON_Data', $seen );
		self::assertContains( 'WP_Theme_JSON_Data::update_with', $seen );
		self::assertContains( 'WP_Theme_JSON::LATEST_SCHEMA', $seen );
		self::assertContains( 'wp_get_global_settings()', $seen );
	}

	public function test_it_fails_closed_when_a_symbol_is_missing(): void {
		$adapter = new ThemeJsonAdapter(
			static fn( string $symbol ): bool => 'WP_Theme_JSON_Resolver::get_user_data' !== $symbol
		);

		self::assertFalse( $adapter->supported() );
		$this->assert_exit_code( 1, fn() => $adapter->require_support() );
	}

	/**
	 * The fail-closed contract is only useful if the operator can see WHAT
	 * is missing: the refusal message must name every missing symbol.
	 */
	public function test_the_require_support_message_names_every_missing_symbol(): void {
		$adapter = new ThemeJsonAdapter(
			static fn( string $symbol ): bool => ! in_array(
				$symbol,
				array( 'WP_Theme_JSON_Data::update_with', 'wp_get_global_styles()' ),
				true
			)
		);

		$exception = $this->assert_exit_code( 1, fn() => $adapter->require_support() );

		self::assertStringContainsString( 'WP_Theme_JSON_Data::update_with', $exception->getMessage() );
		self::assertStringContainsString( 'wp_get_global_styles()', $exception->getMessage() );
		self::assertStringContainsString( 'Global Styles promotion is refused', $exception->getMessage() );
	}

	public function test_merge_preserves_non_style_top_level_keys(): void {
		$merged = ( new ThemeJsonAdapter() )->merge_user_into_theme(
			array(
				'version'       => 3,
				'templateParts' => array(
					array(
						'name' => 'site-header',
						'area' => 'header',
					),
				),
				'settings'      => array( 'color' => array( 'custom' => true ) ),
			),
			array(
				'version' => 3,
				'styles'  => array( 'color' => array( 'background' => '#fff' ) ),
			)
		);

		self::assertSame(
			array(
				array(
					'name' => 'site-header',
					'area' => 'header',
				),
			),
			$merged['templateParts']
		);
		self::assertSame( '#fff', $merged['styles']['color']['background'] );
	}

	public function test_resolved_without_user_origin_differs_when_a_user_style_exists(): void {
		$this->save_user_global_style( array( 'styles' => array( 'color' => array( 'background' => '#101010' ) ) ) );

		$adapter = new ThemeJsonAdapter();
		$adapter->refresh_caches();

		$with    = $adapter->resolved();
		$without = $adapter->resolved_without_user_origin();

		self::assertNotSame( $with, $without );
	}

	public function test_resolved_and_resolved_without_user_origin_agree_when_the_origin_is_empty(): void {
		$adapter = new ThemeJsonAdapter();
		$adapter->refresh_caches();

		self::assertSame( $adapter->resolved(), $adapter->resolved_without_user_origin() );
	}

	public function test_a_missing_global_styles_post_is_created_not_fatal(): void {
		$this->delete_the_global_styles_post();

		self::assertIsArray( ( new ThemeJsonAdapter() )->user_origin() );
	}

	public function test_a_failed_write_is_a_hard_error(): void {
		$adapter = new ThemeJsonAdapter();
		$adapter->refresh_caches();

		$post = $adapter->user_origin_post();

		self::assertNotNull( $post, 'The fixture needs an existing Global Styles post to fail writing.' );

		wp_delete_post( (int) $post['ID'], true );

		$exception = $this->assert_exit_code(
			1,
			fn() => $adapter->write_user_origin( array( 'styles' => array( 'color' => array( 'background' => '#101010' ) ) ) )
		);

		self::assertStringContainsString( 'could not be written', $exception->getMessage() );
	}

	/**
	 * The shipped-artefact guard: the REAL theme.json the release depends on
	 * must merge a user origin, keep its templateParts, validate, and round
	 * the merged document through WP_Theme_JSON without loss. A fixture that
	 * was shaped to satisfy the rule would never catch this file.
	 */
	public function test_the_shipped_theme_json_merges_validates_and_stays_stable(): void {
		$raw = file_get_contents( dirname( __DIR__, 3 ) . '/web/app/themes/site-theme/theme.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the shipped on-disk artefact under test; the WP_Filesystem credentials context does not exist here.

		self::assertIsString( $raw, 'The shipped theme.json must be readable by the shipped-artefact test.' );

		$theme_json = json_decode( $raw, true );

		self::assertIsArray( $theme_json );

		$adapter = new ThemeJsonAdapter();

		// The exported user origin carries no version key (the provider
		// strips it); the merge must supply LATEST_SCHEMA itself.
		$merged = $adapter->merge_user_into_theme( $theme_json, array( 'styles' => array( 'color' => array( 'background' => '#101010' ) ) ) );

		$adapter->validate_theme_json( $merged );

		self::assertSame( '#101010', $merged['styles']['color']['background'] );
		self::assertSame(
			$theme_json['templateParts'],
			$merged['templateParts'],
			'Losing templateParts would un-register the theme\'s parts and break the Site Editor.'
		);
		self::assertSame( $theme_json['version'], $merged['version'] );
		self::assertTrue(
			array_is_list( $merged['settings']['color']['palette'] ),
			'get_raw_data() keys every preset node by origin; only the flattened get_data() view is valid theme.json input.'
		);
		self::assertTrue(
			array_is_list( $merged['settings']['spacing']['spacingSizes'] ),
			'The spacing sizes must flatten the same way; an origin-keyed map mis-registers every preset on re-read.'
		);
	}

	/**
	 * The stability guard in action: a palette whose entries are not
	 * preset-shaped (a string where an object belongs) is a document
	 * WP_Theme_JSON cannot represent losslessly — parsing it throws, and
	 * validate must convert that into a refusal, never let the raw error
	 * escape.
	 */
	public function test_validate_theme_json_rejects_a_document_wp_theme_json_cannot_represent(): void {
		$adapter = new ThemeJsonAdapter();

		$exception = $this->assert_exit_code(
			1,
			fn() => $adapter->validate_theme_json(
				array(
					'version'  => 3,
					'settings' => array( 'color' => array( 'palette' => array( 'nope' ) ) ),
				)
			)
		);

		self::assertStringContainsString( 'cannot be parsed', $exception->getMessage() );
	}

	/**
	 * The write path round-trips through the REAL resolver: after a write
	 * and a cache refresh, the resolved output must actually contain the
	 * written style — a write that only touched the database row without
	 * being re-readable would silently lose customer intent.
	 */
	public function test_a_written_user_origin_is_visible_to_the_resolver(): void {
		$this->save_user_global_style( array( 'styles' => array( 'color' => array( 'background' => '#101010' ) ) ) );

		$adapter = new ThemeJsonAdapter();
		$adapter->refresh_caches();

		self::assertSame( '#101010', $adapter->resolved()['styles']['color']['background'] );
	}

	/**
	 * reset_user_origin() writes the empty origin back — version plus the
	 * isGlobalStylesUserThemeJSON marker WordPress requires — so the
	 * resolved output returns to the theme's own value.
	 */
	public function test_reset_user_origin_returns_resolved_output_to_the_theme_value(): void {
		$this->save_user_global_style( array( 'styles' => array( 'color' => array( 'background' => '#101010' ) ) ) );

		$adapter = new ThemeJsonAdapter();
		$adapter->refresh_caches();
		$adapter->reset_user_origin();
		$adapter->refresh_caches();

		self::assertSame( 'var(--wp--preset--color--base)', $adapter->resolved()['styles']['color']['background'] );
	}

	/**
	 * Writes a user origin through the REAL adapter — the same path the
	 * strategy and the finalizer use — so the fixtures and the production
	 * write path cannot drift apart.
	 *
	 * wp-phpunit runs with no current user, so core's on-demand post
	 * creation cannot attach the wp_theme term: wp_insert_post() applies
	 * tax_input only when current_user_can( $taxonomy_obj->cap->assign_terms ),
	 * which is false for user 0. Without the term the resolver's name-field
	 * tax_query cannot find the row. SeedsStateFixtures attaches the term
	 * explicitly for the same reason.
	 */
	private function save_user_global_style( array $content ): void {
		$adapter = new ThemeJsonAdapter();
		$adapter->refresh_caches();
		$adapter->write_user_origin( $content );

		$post = $adapter->user_origin_post();

		self::assertNotNull( $post, 'The fixture needs a Global Styles post to attach the theme term to.' );

		wp_set_object_terms( (int) $post['ID'], get_stylesheet(), 'wp_theme' );

		$adapter->refresh_caches();
	}

	private function delete_the_global_styles_post(): void {
		$adapter = new ThemeJsonAdapter();
		$adapter->refresh_caches();

		$post = $adapter->user_origin_post();

		self::assertNotNull( $post, 'The fixture needs an existing Global Styles post to delete.' );

		wp_delete_post( (int) $post['ID'], true );

		$adapter->refresh_caches();
	}

	/**
	 * Asserts that the operation refuses with exactly the expected exit code,
	 * and returns the exception so the caller can also assert the message.
	 * Fails when no exception is thrown, when a non-PromotionException
	 * escapes, or when the code differs.
	 *
	 * @param callable():void $operation
	 */
	private function assert_exit_code( int $expected, callable $operation ): PromotionException {
		try {
			$operation();
		} catch ( PromotionException $exception ) {
			self::assertSame(
				$expected,
				$exception->exit_code(),
				sprintf( 'Expected exit code %d, got %d: %s', $expected, $exception->exit_code(), $exception->getMessage() )
			);

			return $exception;
		}

		self::fail( sprintf( 'Expected a PromotionException with exit code %d, but no exception was thrown.', $expected ) );
	}
}
