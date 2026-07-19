# Adding Commerce Behaviour

`site-commerce` is a WooCommerce-gated plugin: it is present in every clone
but stays a no-op until WooCommerce is active. This guide covers adding
real commerce behavior once you've enabled it.

## 1. Enable WooCommerce

The fastest path — locally or in CI — is the one script:

```sh
bash scripts/enable-commerce
```

It installs WooCommerce via Composer, activates it alongside site-commerce,
configures a deterministic store (HPOS on, classic cart/checkout, COD, free
shipping, guest checkout, store taken out of "coming soon"), and creates the
fixtures the commerce test suites assert against. It is idempotent and, like
`scripts/setup`, is written to be read top to bottom.

Under the hood it does what a real project does by hand. WooCommerce is
installed through Composer — the required path for any real project: a
Composer-managed plugin is visible to Git, `composer audit`, Dependabot, and
reproducible deploys/rollback; a manual `wp plugin install` (or a wp-admin
upload) is invisible to every one of those. The `wpackagist.org` repository the
plugin resolves from is already declared in this repo's `composer.json`:

```json
{
  "repositories": [
    {
      "type": "composer",
      "url": "https://wpackagist.org",
      "only": ["wpackagist-plugin/*", "wpackagist-theme/*"]
    }
  ]
}
```

so all that's left is the require + activate:

```sh
ddev composer require wpackagist-plugin/woocommerce
ddev wp plugin activate woocommerce site-commerce
```

**Ephemeral vs committed.** `composer require` edits `composer.json` and
`composer.lock`. For a **real commerce client** you **commit** that change —
WooCommerce becomes a tracked, audited, reproducible dependency of that client's
copy. For the **template itself** it stays **ephemeral**: CI's `commerce-e2e`
job runs `scripts/enable-commerce`, proves the commerce profile, and throws the
require away — the template must never carry a committed WooCommerce dependency
(the base CI jobs and `WooCommerceIsolationTest` prove the base profile runs
with no WooCommerce at all). The repository entry above is the only committed
piece, and it is inert without a require.

`ddev wp plugin install woocommerce --activate` works for a quick throwaway
local experiment, but never for a real project: a manually installed plugin
is untracked, unaudited, and cannot be reproduced or rolled back from Git.

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

## 5. Sanitizing commerce PII

`wp agency sanitize` (see `ops/restore.md`) is step-based and extensible via
the `agency_platform_sanitize_steps` filter. `site-commerce` registers
`SiteCommerce\Health\CommerceSanitizeStep` on that filter from `Plugin::boot()`
— so the commerce sanitize step exists **only** when WooCommerce is active. It
anonymizes both order-storage backends (classic `_billing_*`/`_shipping_*`
postmeta and HPOS `wc_orders`/`wc_order_addresses`, each guarded by a table
check), pattern-deletes `_stripe_*` metas, and clears the payment-token and
`woocommerce_sessions` tables — every statement idempotent.

Add a project-specific commerce scrub the same way: register a **named**
callable (never a closure) on `agency_platform_sanitize_steps` from a
`site-commerce` provider, keyed by a stable slug, returning the WP-CLI summary
lines. Keep every statement idempotent and guard optional tables with a
`SHOW TABLES` check. If your project installs other plugins that store PII,
give each its own step — sanitize only knows what a step teaches it.

## 6. Tests

`tests/commerce/` holds real WooCommerce-backed suites (see
`tests/commerce/README.md`). None of them run in the base
`composer verify`/`verify:fast` pipeline, so the base site stays green without
WooCommerce installed.

- **PHPUnit** (`commerce-integration` suite): proves site-commerce boots, the
  `client_shop_manager` role, and — the first runtime test of R2's SQL —
  `CommerceSanitizeStep` anonymizing a real HPOS order and clearing payment
  tokens. Needs the DDEV database:

  ```sh
  ddev composer test:integration:commerce
  ```

- **e2e** (`COMMERCE=1`): the full storefront journey — archive → PDP (simple +
  variable) → cart + `TESTCOUPON` → guest COD checkout → order history:

  ```sh
  COMMERCE=1 npm run test:e2e:commerce
  ```

CI runs both in a dedicated `commerce-e2e` job that installs WooCommerce
ephemerally; the base jobs never install it.

## Verify

```sh
ddev composer test:architecture         # WooCommerceIsolationTest, allowlist checks
ddev composer test:unit
bash scripts/enable-commerce            # once, to set up the store + fixtures
ddev composer test:integration:commerce
COMMERCE=1 npm run test:e2e:commerce
```
