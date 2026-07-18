# Adding Commerce Behaviour

`site-commerce` is a WooCommerce-gated plugin: it is present in every clone
but stays a no-op until WooCommerce is active. This guide covers adding
real commerce behavior once you've enabled it.

## 1. Enable WooCommerce

```sh
# Option A: Composer (add the wpackagist.org repository to composer.json first)
ddev composer require wpackagist-plugin/woocommerce

# Option B: wp-cli
ddev wp plugin install woocommerce --activate

ddev wp plugin activate site-commerce
```

`SiteCommerce\Plugin::maybe_boot()` checks `class_exists('WooCommerce')` on
`plugins_loaded`; once it's true, `boot()` wires every provider. Until then,
the plugin only shows an admin notice (`render_missing_woocommerce_notice()`).

## 2. Where behavior lives

- **`site-commerce/src/Products/`** — product-related rules (pricing,
  availability, custom fields). `ExampleProductRules` is the current
  skeleton provider; add new providers here and register them in
  `Plugin::boot()`'s `$providers` array.
- **`site-commerce/src/Integrations/`** — the commerce profile's second
  approved outbound-HTTP location (alongside `site-integrations/`). Use
  this for anything that calls a payment gateway, shipping API, or other
  commerce-specific external service.
- **`site-commerce.php`** — the plugin's bootstrap file (mirrors
  `site-core.php`/`site-integrations.php`); it declares
  `Requires Plugins: woocommerce` so WordPress itself understands the
  dependency, on top of the runtime `class_exists()` guard.

## 3. Hooks first, template overrides last

Prefer a `woocommerce_*` hook over a template override every time — a hook
keeps tracking upstream WooCommerce changes; an override silently stops.
Only fall back to `web/app/themes/site-theme/woocommerce/` when no hook
covers the change, and when you do:

1. Copy the WooCommerce core template you're overriding.
2. Add a row to the override log in `web/app/themes/site-theme/woocommerce/README.md`
   (overridden template, reason, WC template version, related tests, and
   confirmation a hook-based alternative was actually considered).
3. Keep the override as small as possible — inherit as much of the
   surrounding markup as you can.

The base profile ships with **zero** overrides deliberately; the README's
log starts empty and every future entry should be a reviewed, logged
exception, not a habit.

## 4. Isolation rules

WooCommerce symbols (`WooCommerce`, `WC_*`, `wc_*`, `woocommerce_*`) are
forbidden everywhere except:

- `web/app/plugins/site-commerce/`
- `web/app/themes/site-theme/woocommerce/`
- `tests/commerce/`
- a reviewed entry in `tests/Architecture/woocommerce-allowlist.php`, each
  with a one-line reason (see the handful of existing entries — e.g.
  `ShopRole.php`'s `class_exists('WooCommerce')` guard, which must stay
  outside `site-commerce/` because roles are `agency-platform`'s job)

`WooCommerceIsolationTest` scans the rest of the codebase and fails on any
other reference. Adding to the allowlist is a deliberate, reviewed
exception — if the code *could* live in `site-commerce/` instead, move it
there rather than allowlisting it.

## 5. Tests

- **PHPUnit**: `tests/commerce/` is reserved for WooCommerce-backed test
  suites (currently empty by design — see `tests/commerce/README.md`); it
  never runs as part of the base `composer verify`/`verify:fast` pipeline,
  so the base site stays green without WooCommerce installed. Add a new
  PHPUnit testsuite here when real commerce coverage lands.
- **e2e**: `tests/commerce/e2e/commerce-journey.spec.ts` is gated on
  `COMMERCE=1` and skips otherwise:

  ```sh
  COMMERCE=1 npx playwright test tests/commerce/e2e
  ```

## Verify

```sh
ddev composer test:architecture   # WooCommerceIsolationTest, allowlist checks
ddev composer test:unit
COMMERCE=1 npx playwright test tests/commerce/e2e
```
