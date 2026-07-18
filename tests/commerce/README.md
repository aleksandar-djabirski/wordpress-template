# Commerce test suites

Tests here exercise real WooCommerce behavior and run only in the commerce
profile — never as part of the base `composer verify` / `verify:fast`
pipeline, since the base site must stay green in CI without WooCommerce
installed.

Empty by design (Task 5 skeleton): `SiteCommerce\Plugin`'s activation guard
is covered by `tests/Unit/SiteCommerce/PluginGuardTest.php`, but no
WooCommerce-backed suite lives here yet.

## How to enable

1. Install WooCommerce — `composer require wpackagist-plugin/woocommerce`
   (add the `wpackagist.org` repository to `composer.json` first) or
   install manually via wp-cli/wp-admin.
2. Playwright commerce specs: set `COMMERCE=1` when running the suite
   (Task 10 wires this up — not present yet).
3. PHPUnit integration coverage gets its own suite in a later task; none
   exists yet.
