<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Health\DatabaseOverrideCheck;
use PHPUnit\Framework\TestCase;

// This test builds `post_content` fixtures with plain json_encode() rather
// than wp_json_encode(): these are pure unit tests that run without
// WordPress loaded (see tests/support/wp-stubs.php), and wp_json_encode()
// isn't among the intentionally-minimal stubs there.
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode

/**
 * @covers \AgencyPlatform\Health\DatabaseOverrideCheck
 */
final class DatabaseOverrideClassifyTest extends TestCase {

	public function test_published_template_is_an_override(): void {
		$result = DatabaseOverrideCheck::classify(
			array(
				array(
					'post_type'   => 'wp_template',
					'post_name'   => 'single',
					'post_status' => 'publish',
				),
			)
		);

		self::assertCount( 1, $result['overrides'] );
		self::assertSame( 'single', $result['overrides'][0]['post_name'] );
		self::assertSame( array(), $result['expected'] );
		self::assertSame( array(), $result['synced_patterns'] );
	}

	public function test_published_template_part_is_an_override(): void {
		$result = DatabaseOverrideCheck::classify(
			array(
				array(
					'post_type'   => 'wp_template_part',
					'post_name'   => 'header',
					'post_status' => 'publish',
				),
			)
		);

		self::assertCount( 1, $result['overrides'] );
		self::assertSame( 'header', $result['overrides'][0]['post_name'] );
	}

	public function test_non_published_template_is_not_an_override(): void {
		$result = DatabaseOverrideCheck::classify(
			array(
				array(
					'post_type'   => 'wp_template',
					'post_name'   => 'single',
					'post_status' => 'draft',
				),
			)
		);

		self::assertSame( array(), $result['overrides'] );
		self::assertSame( array(), $result['expected'] );
		self::assertSame( array(), $result['synced_patterns'] );
	}

	public function test_plain_global_styles_record_is_expected(): void {
		$result = DatabaseOverrideCheck::classify(
			array(
				array(
					'post_type'    => 'wp_global_styles',
					'post_name'    => 'wp-global-styles-site-theme',
					'post_status'  => 'publish',
					'post_content' => json_encode(
						array(
							'version'                     => 2,
							'isGlobalStylesUserThemeJSON' => true,
						)
					),
				),
			)
		);

		self::assertSame( array(), $result['overrides'] );
		self::assertCount( 1, $result['expected'] );
	}

	public function test_global_styles_record_without_content_is_expected(): void {
		$result = DatabaseOverrideCheck::classify(
			array(
				array(
					'post_type'   => 'wp_global_styles',
					'post_name'   => 'wp-global-styles-site-theme',
					'post_status' => 'publish',
				),
			)
		);

		self::assertSame( array(), $result['overrides'] );
		self::assertCount( 1, $result['expected'] );
	}

	public function test_global_styles_with_custom_css_is_an_override(): void {
		$result = DatabaseOverrideCheck::classify(
			array(
				array(
					'post_type'    => 'wp_global_styles',
					'post_name'    => 'wp-global-styles-site-theme',
					'post_status'  => 'publish',
					'post_content' => json_encode(
						array(
							'version'                     => 2,
							'isGlobalStylesUserThemeJSON' => true,
							'styles'                      => array(
								'css' => 'body { color: red; }',
							),
						)
					),
				),
			)
		);

		self::assertCount( 1, $result['overrides'] );
		self::assertSame( array(), $result['expected'] );
	}

	public function test_global_styles_with_extra_custom_keys_is_an_override(): void {
		$result = DatabaseOverrideCheck::classify(
			array(
				array(
					'post_type'    => 'wp_global_styles',
					'post_name'    => 'wp-global-styles-site-theme',
					'post_status'  => 'publish',
					'post_content' => json_encode(
						array(
							'version'                     => 2,
							'isGlobalStylesUserThemeJSON' => true,
							'settings'                    => array(
								'color' => array( 'custom' => false ),
							),
						)
					),
				),
			)
		);

		self::assertCount( 1, $result['overrides'] );
	}

	public function test_global_styles_with_nested_all_blank_leaves_is_expected(): void {
		// A nested array whose every leaf is blank (empty string/null/empty
		// array, at any depth) carries no real customization, even though
		// the array itself isn't literally `[]`.
		$result = DatabaseOverrideCheck::classify(
			array(
				array(
					'post_type'    => 'wp_global_styles',
					'post_name'    => 'wp-global-styles-site-theme',
					'post_status'  => 'publish',
					'post_content' => json_encode(
						array(
							'version'                     => 2,
							'isGlobalStylesUserThemeJSON' => true,
							'settings'                    => array(
								'typography' => array(
									'fontSize' => '',
									'nested'   => array(
										'also' => null,
									),
								),
							),
						)
					),
				),
			)
		);

		self::assertSame( array(), $result['overrides'] );
		self::assertCount( 1, $result['expected'] );
	}

	public function test_global_styles_with_nested_real_value_is_an_override(): void {
		// Same shape as above, but one leaf actually carries a value —
		// that's a real customization and must not be masked by the
		// all-blank-leaves exemption.
		$result = DatabaseOverrideCheck::classify(
			array(
				array(
					'post_type'    => 'wp_global_styles',
					'post_name'    => 'wp-global-styles-site-theme',
					'post_status'  => 'publish',
					'post_content' => json_encode(
						array(
							'version'                     => 2,
							'isGlobalStylesUserThemeJSON' => true,
							'settings'                    => array(
								'typography' => array(
									'fontSize' => '16px',
									'nested'   => array(
										'also' => null,
									),
								),
							),
						)
					),
				),
			)
		);

		self::assertCount( 1, $result['overrides'] );
		self::assertSame( array(), $result['expected'] );
	}

	public function test_global_styles_with_malformed_content_is_an_override(): void {
		// Non-JSON/unparseable post_content is unexpected for this post
		// type; classify() treats it conservatively as a customization so
		// it surfaces for human review rather than being silently ignored.
		$result = DatabaseOverrideCheck::classify(
			array(
				array(
					'post_type'    => 'wp_global_styles',
					'post_name'    => 'wp-global-styles-site-theme',
					'post_status'  => 'publish',
					'post_content' => 'not valid json {{{',
				),
			)
		);

		self::assertCount( 1, $result['overrides'] );
		self::assertSame( array(), $result['expected'] );
	}

	public function test_wp_block_is_reported_as_synced_pattern_not_override(): void {
		$result = DatabaseOverrideCheck::classify(
			array(
				array(
					'post_type'   => 'wp_block',
					'post_name'   => 'featured-callout',
					'post_status' => 'publish',
				),
			)
		);

		self::assertCount( 1, $result['synced_patterns'] );
		self::assertSame( array(), $result['overrides'] );
		self::assertSame( array(), $result['expected'] );
	}

	public function test_unknown_post_types_are_ignored(): void {
		$result = DatabaseOverrideCheck::classify(
			array(
				array(
					'post_type'   => 'post',
					'post_name'   => 'hello-world',
					'post_status' => 'publish',
				),
				array(
					'post_type'   => 'page',
					'post_name'   => 'about',
					'post_status' => 'publish',
				),
			)
		);

		self::assertSame( array(), $result['overrides'] );
		self::assertSame( array(), $result['expected'] );
		self::assertSame( array(), $result['synced_patterns'] );
	}

	public function test_an_empty_record_set_yields_empty_buckets(): void {
		$result = DatabaseOverrideCheck::classify( array() );

		self::assertSame(
			array(
				'overrides'       => array(),
				'expected'        => array(),
				'synced_patterns' => array(),
			),
			$result
		);
	}
}
