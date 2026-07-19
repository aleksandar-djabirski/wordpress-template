<?php

declare(strict_types=1);

namespace SiteCommerce\Theme;

/**
 * Declares WooCommerce theme support on behalf of the active theme, but only
 * while the commerce profile is booted (WooCommerce active).
 *
 * The base site-theme deliberately ships WITHOUT `add_theme_support('woocommerce')`
 * — the base profile has no WooCommerce, so declaring support there would be
 * meaningless (and would pull a WooCommerce string into the theme, which the
 * isolation boundary forbids). Without theme support, WooCommerce falls back to
 * its "unsupported theme" content-injection shim, which renders single products
 * by filtering `the_content` — fragile, and not how a real store should render.
 *
 * Declaring support here (from the WooCommerce-only plugin, on `after_setup_theme`
 * so it lands before WooCommerce's own template loader reads it on `init`) makes
 * WooCommerce use its real template hierarchy: the single-product / archive
 * templates, the add-to-cart form, and the product-gallery features. This is the
 * supported, reliable rendering path the commerce e2e journeys drive, and it
 * keeps the base theme and base profile untouched — the support only exists once
 * SiteCommerce\Plugin::boot() has confirmed WooCommerce is active.
 */
final class ThemeSupport {

	/**
	 * Wires the theme-support declaration. Called only from
	 * SiteCommerce\Plugin::boot() (WooCommerce confirmed active).
	 */
	public function register(): void {
		add_action( 'after_setup_theme', array( self::class, 'declare_support' ) );
	}

	/**
	 * Adds WooCommerce theme support plus the three product-gallery features
	 * (zoom, lightbox, slider) a stock WooCommerce single-product page expects.
	 */
	public static function declare_support(): void {
		add_theme_support( 'woocommerce' );
		add_theme_support( 'wc-product-gallery-zoom' );
		add_theme_support( 'wc-product-gallery-lightbox' );
		add_theme_support( 'wc-product-gallery-slider' );
	}
}
