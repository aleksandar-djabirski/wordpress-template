<?php

declare(strict_types=1);

namespace SiteCommerce\Theme;

/**
 * Registers the commerce profile's block patterns.
 *
 * The base theme's templates, parts, and patterns stay commerce-free, so a
 * site with no store installed never shows a broken block
 * (tests/Architecture/CommerceBoundaryTest enforces that). Patterns are the
 * sanctioned way back in: they exist only while this plugin is booted, and a
 * client composes them into a template or part through the Site Editor.
 *
 * `header-mini-cart` is the supported way to put a cart in the site header.
 * It is a composition, not a theme file, because the header part is shared
 * with every non-commerce project built from this starter.
 */
final class CommercePatterns {

	public const CATEGORY = 'site-commerce';

	public const PATTERN_HEADER_MINI_CART = 'site-commerce/header-mini-cart';

	public const PATTERN_PRODUCT_GRID = 'site-commerce/product-grid';

	/**
	 * Wires pattern registration. Called only from SiteCommerce\Plugin::boot()
	 * (the store plugin is confirmed active).
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'register_patterns' ), 11 );
	}

	/**
	 * Priority 11 on `init` so the theme's own pattern registration and the
	 * store plugin's block registration have both already run.
	 */
	public static function register_patterns(): void {
		register_block_pattern_category(
			self::CATEGORY,
			array( 'label' => __( 'Commerce', 'site-commerce' ) )
		);

		register_block_pattern(
			self::PATTERN_HEADER_MINI_CART,
			array(
				'title'      => __( 'Header with Mini-Cart', 'site-commerce' ),
				'categories' => array( self::CATEGORY ),
				'blockTypes' => array( 'core/template-part/header' ),
				'content'    => self::pattern_content( 'header-mini-cart' ),
			)
		);

		register_block_pattern(
			self::PATTERN_PRODUCT_GRID,
			array(
				'title'      => __( 'Product grid', 'site-commerce' ),
				'categories' => array( self::CATEGORY ),
				'content'    => self::pattern_content( 'product-grid' ),
			)
		);
	}

	/**
	 * Reads a pattern body from this plugin's patterns/ directory.
	 *
	 * The markup lives in files rather than PHP string literals for two
	 * reasons: it is generated from upstream composition (so it is reviewable
	 * as markup and re-derivable on upgrade), and it keeps commerce block names
	 * out of PHP source entirely.
	 */
	private static function pattern_content( string $slug ): string {
		$path = dirname( __DIR__, 2 ) . '/patterns/' . $slug . '.html';

		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled plugin asset from disk, not a remote resource.
		$content = file_get_contents( $path );

		return false === $content ? '' : $content;
	}
}
