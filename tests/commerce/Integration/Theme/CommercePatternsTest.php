<?php
/**
 * Proves site-commerce registers its block patterns (and their category) in the
 * commerce profile. Patterns are the sanctioned way to bring commerce blocks
 * into the theme's header and page compositions WITHOUT putting commerce
 * markup into the base theme's Git tree (CommerceBoundaryTest forbids that).
 *
 * @package Tests\Commerce\Integration
 */

declare(strict_types=1);

namespace Tests\Commerce\Integration\Theme;

use SiteCommerce\Theme\CommercePatterns;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \SiteCommerce\Theme\CommercePatterns
 */
final class CommercePatternsTest extends IntegrationTestCase {

	public function test_the_commerce_pattern_category_is_registered(): void {
		$categories = \WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered();

		self::assertArrayHasKey(
			CommercePatterns::CATEGORY,
			array_column( $categories, null, 'name' ),
			'The commerce pattern category must be registered so the patterns are findable in the inserter.'
		);
	}

	public function test_both_commerce_patterns_are_registered(): void {
		$patterns = array_column(
			\WP_Block_Patterns_Registry::get_instance()->get_all_registered(),
			null,
			'name'
		);

		foreach ( array( CommercePatterns::PATTERN_HEADER_MINI_CART, CommercePatterns::PATTERN_PRODUCT_GRID ) as $name ) {
			self::assertArrayHasKey( $name, $patterns, "The '{$name}' pattern must be registered when the commerce profile boots." );
			self::assertNotSame( '', trim( (string) $patterns[ $name ]['content'] ), "The '{$name}' pattern must have content." );
		}
	}

	public function test_every_pattern_block_is_registered(): void {
		$patterns   = array_column( \WP_Block_Patterns_Registry::get_instance()->get_all_registered(), null, 'name' );
		$registry   = \WP_Block_Type_Registry::get_instance();
		$unresolved = array();

		foreach ( array( CommercePatterns::PATTERN_HEADER_MINI_CART, CommercePatterns::PATTERN_PRODUCT_GRID ) as $name ) {
			// Recursive, not top-level: the Mini-Cart sits INSIDE a Group, and
			// the product grid nests a product template inside a query block. A
			// top-level-only walk would assert almost nothing.
			foreach ( $this->block_names( parse_blocks( (string) $patterns[ $name ]['content'] ) ) as $block_name ) {
				if ( null === $registry->get_registered( $block_name ) ) {
					$unresolved[] = $name . ' -> ' . $block_name;
				}
			}
		}

		self::assertSame( array(), $unresolved, implode( "\n", $unresolved ) );
	}

	public function test_the_header_pattern_actually_contains_the_mini_cart(): void {
		$patterns = array_column( \WP_Block_Patterns_Registry::get_instance()->get_all_registered(), null, 'name' );
		$names    = $this->block_names( parse_blocks( (string) $patterns[ CommercePatterns::PATTERN_HEADER_MINI_CART ]['content'] ) );

		$mini_cart = array_values(
			array_filter(
				$names,
				static fn( string $name ): bool => str_contains( $name, 'mini-cart' )
			)
		);

		self::assertNotSame(
			array(),
			$mini_cart,
			'The header pattern exists to place the Mini-Cart; without it the pattern is pointless.'
		);
	}

	/**
	 * Every block name in a parsed tree, flattened through inner blocks. Same
	 * traversal CommerceBlockTemplatesTest uses.
	 *
	 * @param list<array<string, mixed>> $blocks
	 * @return list<string>
	 */
	private function block_names( array $blocks ): array {
		$names = array();

		foreach ( $blocks as $block ) {
			if ( is_string( $block['blockName'] ) && '' !== $block['blockName'] ) {
				$names[] = $block['blockName'];
			}

			if ( is_array( $block['innerBlocks'] ) && array() !== $block['innerBlocks'] ) {
				$names = array_merge( $names, $this->block_names( $block['innerBlocks'] ) );
			}
		}

		return array_values( array_unique( $names ) );
	}
}
