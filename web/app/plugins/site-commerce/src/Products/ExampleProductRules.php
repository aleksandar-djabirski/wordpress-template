<?php

declare(strict_types=1);

namespace SiteCommerce\Products;

/**
 * Reference skeleton for product-level commerce rules.
 *
 * Belongs here: product pricing/eligibility rules, cart and checkout
 * behavior wired via WooCommerce hooks, order workflows — anything that
 * reacts to WooCommerce's product/cart/checkout/order lifecycle through a
 * named-method hook, the same pattern this class demonstrates.
 *
 * Must NOT go here: theme markup or templates (those belong in
 * web/app/themes/site-theme/woocommerce/), or any logic the base profile
 * needs — this class, and everything else under SiteCommerce\, only ever
 * runs once SiteCommerce\Plugin::boot() has confirmed WooCommerce is
 * active.
 */
final class ExampleProductRules {

	/**
	 * Wires this class's hooks. Called only from
	 * SiteCommerce\Plugin::boot(), which is itself only reachable once
	 * WooCommerce is confirmed active — see that class's docblock.
	 */
	public function register(): void {
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( $this, 'add_to_cart_text' ) );
	}

	/**
	 * Reference wiring point for product business rules — returns $text
	 * unchanged. This is a skeleton, not real behavior (see the spec's
	 * non-goals): replace this per-project with actual add-to-cart label
	 * logic (e.g. backorder or preorder copy), or leave it as-is and add
	 * further project-specific hooks here following the same named-method
	 * pattern.
	 */
	public function add_to_cart_text( string $text ): string {
		return $text;
	}
}
