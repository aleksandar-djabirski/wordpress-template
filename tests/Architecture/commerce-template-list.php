<?php
/**
 * Canonical commerce block-template set.
 *
 * The block theme overrides a commerce template for exactly one reason: the
 * templates the commerce plugin ships reference `header` / `footer` template
 * parts, and this theme's parts are `site-header` / `site-footer`. Without an
 * override the storefront renders with no header and no footer. Each file is
 * therefore a verbatim copy of the upstream template with only those two slug
 * attributes rewritten.
 *
 * `templates` — slugs this theme owns; every one MUST exist as
 * web/app/themes/site-theme/templates/<slug>.html.
 * `excluded`  — upstream slugs this theme deliberately does NOT override,
 * each with its reason. An upstream slug in neither list fails
 * CommerceBlockTemplatesTest, which is the intended prompt to decide about it.
 *
 * @return array{templates: list<string>, excluded: array<string, string>}
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

return array(
	'templates' => array(
		'archive-product',
		'order-confirmation',
		'page-cart',
		'product-search-results',
		'single-product',
		'taxonomy-product_attribute',
	),
	'excluded'  => array(
		'coming-soon'   => 'Shipped upstream but references no template part, so there is no missing-part rewrite to justify an override.',
		'page-checkout' => 'References the checkout-header part that the commerce plugin ships and explicitly scopes with "theme":"woocommerce/woocommerce", so no part of this theme is missing. Removing that theme attribute — which the derivation would do — repoints the reference at a part this theme does not have.',
	),
);
