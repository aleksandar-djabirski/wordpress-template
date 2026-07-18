# Commerce test suites

Tests here exercise real WooCommerce behavior and run only in the commerce
profile — never as part of the base `composer verify` / `verify:fast`
pipeline, since the base site must stay green in CI without WooCommerce
installed.

`SiteCommerce\Plugin`'s activation guard is covered by
`tests/Unit/SiteCommerce/PluginGuardTest.php`. No WooCommerce-backed PHPUnit
suite lives here yet — add one when real commerce coverage lands.

The e2e harness exists: `e2e/commerce-journey.spec.ts` is a `test.fixme`
placeholder gated on `COMMERCE=1`. It ships the journey outline (product
archive → PDP → add to cart → checkout validation → order) but stays
unimplemented until a real store project fills it in against a live
WooCommerce install, so it never goes green on a base install with no
WooCommerce to test against.

## How to enable

1. Install WooCommerce via Composer — `ddev composer require
   wpackagist-plugin/woocommerce` (add the `wpackagist.org` repository to
   `composer.json` first; see `docs/adding-commerce-behaviour.md` for the
   exact snippet and why Composer is the required path). `ddev wp plugin
   install woocommerce` works for a throwaway local experiment but never for
   a real project.
2. `ddev wp plugin activate site-commerce`.
3. Run the commerce Playwright specs with `COMMERCE=1` set:
   `COMMERCE=1 npx playwright test tests/commerce/e2e` (they skip
   otherwise).
