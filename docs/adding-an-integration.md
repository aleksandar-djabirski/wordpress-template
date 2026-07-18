# Adding an Integration

An "integration" is anything that talks to an external service — a CRM, an
email API, a webhook, analytics. Copy `LeadDelivery` (`site-core/src/Contracts/LeadDelivery.php`
+ `site-integrations/src/LeadDelivery/*`) as the template; it already
demonstrates every piece below.

## 1. Define the contract in `site-core`

```php
// web/app/plugins/site-core/src/Contracts/YourThing.php
namespace SiteCore\Contracts;

interface YourThing {
    public function doTheThing( array $payload ): bool;
}
```

The contract is the **only** thing site-core exposes to other layers
(`deptrac.yaml`'s `SiteCoreContracts` layer). Keep it small, typed, and free
of any implementation detail — no HTTP, no environment checks, no
credentials here. Whatever in site-core needs the behavior (a submission
handler, a scheduled task, ...) depends on this interface only, resolved
through a filter (mirror `site_core_lead_delivery`) so site-core never
`new`s a concrete `SiteIntegrations\*` class directly — that dependency
would run the wrong way (`SiteCore → SiteIntegrations` is forbidden).

## 2. Implement it in `site-integrations`

Two implementations, same shape as `LeadDelivery`'s:

- **Fake** (`site-integrations/src/YourThing/FakeYourThing.php`): does
  nothing dangerous — logs or no-ops. This is what dev/staging/local get.
- **Real** (`WebhookYourThing.php` or similar): makes the actual outbound
  call. `site-integrations/` (or, for commerce-only integrations,
  `site-commerce/src/Integrations/`) is one of only two locations where
  `wp_remote_*`/cURL/Guzzle calls are allowed — `IntegrationBoundaryTest`
  fails anywhere else.

## 3. Resolve environment-safely

Write a resolver (mirror `LeadDeliveryResolver`) that:

1. Reads `wp_get_environment_type()` — **never** `WP_ENV` directly.
2. Returns the Fake implementation unless the environment is exactly
   `'production'`.
3. Even in production, checks an operational kill-switch constant (mirror
   `AGENCY_DISABLE_OUTBOUND_WEBHOOKS`) before returning the real
   implementation — an escape hatch for maintenance windows.
4. Returns the Fake if required configuration (a URL, an API key) is empty.
5. Never opts *out* of the Fake by mistake: an unrecognized environment
   value should fall through to Fake, not to the real implementation.

Wire the resolver against your new filter from
`SiteIntegrations\Plugin::boot()`, the same way `LeadDeliveryResolver` is
wired against `site_core_lead_delivery`.

## 4. Timeout and logging

- Set an explicit timeout on every outbound call (`WebhookLeadDelivery` uses
  5 seconds) — never rely on PHP's default.
- Log failures with `AgencyPlatform\Logging\Logger::log()` (auto-redacts
  keys that look like passwords/secrets/tokens) or a scoped `error_log()`
  call.
- **Never log the payload itself** if it may contain PII — log the error
  code/message or HTTP status only, exactly like `WebhookLeadDelivery`
  does.

## 5. Environment configuration

Add any new environment variables to `.env.example` with a comment
explaining the safe default, and read them through a small config class
(mirror `LeadWebhookConfig`) rather than calling `getenv()`/`$_ENV`
directly in the delivery class — that keeps the delivery class testable
without environment mutation.

## 6. Tests

- **Unit**: test the resolver's decision table directly (environment ×
  kill-switch × config presence → Fake or real) — see
  `tests/Unit/SiteIntegrations/LeadDeliveryResolutionTest.php`.
- **Integration**: add a case to (or mirror) `tests/Integration/Environment/EnvironmentSafetyTest.php`
  proving the filter resolves to the Fake in the `development` test
  environment and that no outbound HTTP call is attempted (that test
  short-circuits `pre_http_request` and asserts nothing was recorded).
- **Architecture**: `IntegrationBoundaryTest` and `WooCommerceIsolationTest`
  (if relevant) pick up the new files automatically — no new test needed
  unless you're adding a new allowed location.

## Verify

```sh
ddev composer test:architecture
ddev composer test:unit
ddev composer test:integration   # needs the DDEV database
```
