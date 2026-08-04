<?php
/**
 * Checks that WordPress exposes the theme's starter patterns in the live
 * inserter registries.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Theme;

use Tests\Integration\IntegrationTestCase;

final class PatternRegistrationTest extends IntegrationTestCase {

	/**
	 * @var array<string, string>
	 */
	private const STARTER_PATTERN_CATEGORIES = array(
		'agency/hero'          => 'featured',
		'agency/split-content' => 'text',
		'agency/feature-grid'  => 'featured',
		'agency/cta'           => 'call-to-action',
		'agency/content-page'  => 'pages',
	);

	public function test_pages_pattern_category_is_registered_for_the_inserter(): void {
		$category = \WP_Block_Pattern_Categories_Registry::get_instance()->get_registered( 'pages' );

		self::assertNotNull(
			$category,
			'The pages pattern category must register on init for the editor and REST inserter.'
		);
		self::assertSame( 'pages', $category['name'] );
	}

	public function test_starter_patterns_are_registered_with_their_categories(): void {
		$registry = \WP_Block_Patterns_Registry::get_instance();

		foreach ( self::STARTER_PATTERN_CATEGORIES as $slug => $category ) {
			$pattern = $registry->get_registered( $slug );

			self::assertNotNull( $pattern, $slug . ' must register as a starter pattern.' );
			self::assertSame( array( $category ), $pattern['categories'], $slug . ' must use the intended inserter category.' );
		}
	}
}
