# Commerce test suites

Tests here exercise real WooCommerce behavior and run only in the commerce
profile — never as part of the base `composer verify` / `verify:fast`
pipeline, since the base site must stay green in CI without WooCommerce
installed.

## Current status

Empty by design. This is a skeleton (Task 5): `SiteCommerce\Plugin` has an
activation guard and a unit test
(`tests/Unit/SiteCommerce/PluginGuardTest.php`) covering it, but no
WooCommerce-backed test suite exists yet.

## How to enable

1. Install WooCommerce, either:
   - `composer require wpackagist-plugin/woocommerce` — requires adding the
     `wpackagist.org` Composer repository to `composer.json`'s
     `repositories` first, or
   - install it manually via wp-cli or the wp-admin Plugins screen.
2. Playwright commerce specs: set the `COMMERCE=1` environment variable when
   running the suite (wired up in Task 10 — not present yet).
3. PHPUnit integration coverage for commerce gets its own dedicated test
   suite in a later task; none exists yet.

Until WooCommerce is installed, `SiteCommerce\Plugin::maybe_boot()` stays in
its no-op admin-notice state and nothing here runs.
