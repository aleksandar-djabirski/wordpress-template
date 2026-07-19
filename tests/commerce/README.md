# Commerce test suites

These suites exercise **real WooCommerce behaviour** and run only in the
commerce profile — never as part of the base `composer verify` / `verify:fast`
pipeline, so the base site stays green in CI with no WooCommerce installed.

Everything here is gated so a base clone carries only inert scaffolding: the
PHPUnit suite lives in its own `commerce-integration` testsuite that base
`verify`/`test:integration` never runs, and the Playwright journeys skip unless
`COMMERCE=1` is set. Nothing to remove per client.

## What's here

- **`Integration/`** — the `commerce-integration` PHPUnit suite. Boots a real
  WordPress + WooCommerce test environment (its own bootstrap loads WooCommerce
  and site-commerce and enables HPOS) and proves:
  - `Integration/SiteCommerce/PluginBootTest.php` — site-commerce takes its
    booted branch with WooCommerce active (ExampleProductRules + the commerce
    sanitize step wired, no missing-WooCommerce admin notice).
  - `Integration/Permissions/ShopManagerCapabilitiesTest.php` — the
    `client_shop_manager` role exists with its nine WooCommerce capabilities and
    still cannot install plugins, switch themes, or edit theme options.
  - `Integration/Health/CommerceSanitizeStepTest.php` — the first **runtime**
    exercise of `SiteCommerce\Health\CommerceSanitizeStep`: it seeds an order
    with PII and a stored payment token in the HPOS tables, runs the sanitize
    step, and asserts every PII column is anonymized and the payment-token
    tables are cleared.
- **`e2e/commerce-journey.spec.ts`** — the `COMMERCE=1` Playwright journeys:
  product archive → PDP (simple + variable) add-to-cart → cart quantity +
  `TESTCOUPON` → guest COD checkout → order-received → a logged-in customer's
  order history. The mobile project runs the checkout as a smoke; the desktop
  project runs the full set.

## How to run locally

1. Enable the commerce profile once (installs WooCommerce, activates
   site-commerce, configures a deterministic store, and creates the fixtures
   the suites assert against):

   ```sh
   bash scripts/enable-commerce
   ```

   Run it inside DDEV (`ddev ssh` first) or from a host with `ddev` on PATH. It
   is idempotent. **Note:** its first step `composer require`s WooCommerce,
   which edits `composer.json`/`composer.lock`. For a real commerce client you
   commit that change; for the template it stays local/ephemeral (CI throws it
   away). See `docs/adding-commerce-behaviour.md`.

2. Run the PHPUnit suite (needs the DDEV database):

   ```sh
   ddev composer test:integration:commerce
   ```

3. Run the Playwright journeys against the running store:

   ```sh
   COMMERCE=1 npm run test:e2e:commerce
   # equivalently: COMMERCE=1 npx playwright test tests/commerce/e2e
   ```

## Gates (why the base profile stays clean)

- The `commerce-integration` testsuite is wired into `phpunit.xml` but is only
  invoked by the `test:integration:commerce` Composer script (which sets
  `WP_COMMERCE_INTEGRATION=1` so `tests/bootstrap.php` loads the commerce
  bootstrap). `composer verify` / `verify:fast` / `test:integration` never touch
  it.
- The Playwright journeys `test.skip()` themselves unless `COMMERCE=1`.
- WooCommerce symbols are allowed here: `WooCommerceIsolationTest` excludes
  `tests/commerce/`.
- CI runs all of this in a dedicated `commerce-e2e` job (see
  `.github/workflows/ci.yml`) that installs WooCommerce ephemerally; the base
  jobs never install it.
