# AGENTS.md

AI-first WordPress agency starter: Bedrock + DDEV, a hybrid block/classic theme, and layered plugins (`agency-platform`, `site-core`, `site-integrations`, `site-commerce`).

## Routing table

Customer-editable UI → block (`site-theme/blocks/`)
Non-editable site chrome → part (`site-theme/parts/`)
Page shell → PHP template (`site-theme/templates/`)
Composition of blocks → pattern (`site-theme/patterns/`)
Business rule → `site-core`
External service → `site-integrations`
WooCommerce behavior → `site-commerce`
WooCommerce markup override → `site-theme/woocommerce/`

## Layer ownership

- `agency-platform` (mu-plugin): guardrails only — roles, editor/site-editor lockdown, app-password lockdown, file-mod guard, database-override detection, `wp agency *` WP-CLI commands. Never business logic, never WooCommerce, never a dependency on any other project layer.
- `site-core`: business rules plus the public `SiteCore\Contracts\*` API — the ONLY site-core namespace other layers may reference. Never renders markup, never makes network calls, never references WooCommerce or the theme.
- `site-integrations`: implementations of `SiteCore\Contracts\*` that talk outward (webhooks, APIs). The base profile's only outbound-HTTP home. Never referenced by site-core.
- `site-commerce`: WooCommerce-only behavior; activates only when WooCommerce is present (`Requires Plugins` header). Its `src/Integrations/` is the commerce profile's outbound-HTTP home. Never referenced by the base profile.
- `site-theme`: hybrid theme — root delegates hand off to `templates/`; `header.php`/`footer.php` render `parts/` via `SiteTheme\Support\Parts`. May depend only on `SiteCore\Contracts\*`; never on site-core internals, site-integrations, or site-commerce.

## Dependency direction (deptrac-enforced)

`SiteTheme → SiteCoreContracts`; `SiteIntegrations → SiteCore, SiteCoreContracts`; `SiteCommerce → SiteCore, SiteCoreContracts`; `SiteCore → SiteCoreContracts`; `AgencyPlatform → nothing project-side`. Everything else is forbidden. Check with `ddev composer deptrac`.

## Hard rules the architecture tests enforce

- No closures in `add_action`/`add_filter` anywhere in production code — named class methods only (`HookOwnershipTest`).
- `functions.php` stays ≤50 lines and only calls `ThemeBootstrap::boot()`; root template delegates stay ≤10 significant lines and register no hooks (`ThemeBootstrapTest`).
- No `components/`, `layouts/`, `inc/`, `includes/`, `helpers/`, `misc/`, `common/`, `lib/`, or `utils/` directories in the theme or any plugin (`DirectoryRulesTest`).
- Every block needs a valid `block.json`: name `agency/<folder>`, integer `apiVersion`, `file:` asset references that resolve inside the block (`BlockManifestTest`).
- WooCommerce symbols (`WooCommerce`, `WC_*`, `wc_*`, `woocommerce_*`) may only appear in `site-commerce/`, `site-theme/woocommerce/`, `tests/commerce/`, or a reviewed entry in `tests/Architecture/woocommerce-allowlist.php` (`WooCommerceIsolationTest`).
- Outbound HTTP (`wp_remote_*`, cURL, Guzzle, `file_get_contents('http...')`) only inside `site-integrations/` or `site-commerce/src/Integrations/` (`IntegrationBoundaryTest`).
- CSS colors must be design tokens (`var(--wp--preset--color--*)` / `var(--wp--custom--*)`), never raw hex/rgb (stylelint `declaration-strict-value`).
- `assets/global/` holds exactly `base.css` + `typography.css` — a genuinely new global stylesheet requires deliberately editing `GlobalAssetRulesTest`'s allow-list, not just adding the file (`GlobalAssetRulesTest`).
- `docs/generated-block-index.md` must match `php scripts/generate-block-index`'s output — run it after any block/pattern change and commit the result (`GeneratedIndexFreshnessTest`).

## Block decision order

1. A core block already does it.
2. A locked pattern (`templateLock`) composes existing blocks into the needed shape.
3. A block binding connects a core block to dynamic data.
4. A native dynamic block (`render.php`) — see `blocks/reference-callout/` as the reference implementation.
5. Only if none of the above fit: a field plugin (ACF, etc.), which needs an ADR and is never in the base profile.

## Frontend behavior order

CSS first, then native HTML/ARIA, then block-local JS declared via `block.json` (e.g. `viewScript`), then the Interactivity API for anything stateful. No global JS bundles or ad hoc `<script>` tags — see `parts/site-header/site-header.js` for the enqueued-per-part pattern.

## Commands

Run PHP/Composer commands via `ddev composer <script>`; npm runs natively.

- `ddev composer verify:fast` — validate, audit, phpcs, phpstan, deptrac, architecture + unit tests. No database. Run before every commit.
- `ddev composer verify` — `verify:fast` + `test:integration` (needs the DDEV database).
- `ddev composer test:architecture` / `test:unit` / `test:integration` / `lint:php` / `analyse` / `deptrac` / `audit` — individual steps.
- `npm run build` / `npm run start` — production/watch block build (wp-scripts).
- `npm run lint` (`lint:js` + `lint:css`) — ESLint + Stylelint.
- `npm run test:e2e` / `test:visual` / `test:accessibility` — Playwright; needs a running site (`WP_BASE_URL`, defaults to the DDEV URL).
- `scripts/setup` — full bootstrap from a fresh clone (composer install, `.env` + salts, WP core install, theme/plugin activation, `npm ci && npm run build`, client-editor test user). Run inside DDEV.
- `scripts/verify` — mirrors CI: `composer verify`, `npm run lint`, `npm run build`.
- `scripts/generate-block-index`, `scripts/check-database-overrides`, `scripts/sanitize-database`, `scripts/verify-environment`, `scripts/rename-project` — the middle three are thin wrappers around `wp agency check-overrides|sanitize|verify-env`.

## Environment safety

Environment is read via core `wp_get_environment_type()`, never `WP_ENV` directly — Bedrock sets `WP_ENVIRONMENT_TYPE` from `WP_ENV`. Lead delivery resolves to `SiteIntegrations\LeadDelivery\FakeLeadDelivery` everywhere except when the environment is `production`, `AGENCY_DISABLE_OUTBOUND_WEBHOOKS` is not `true`, and `LEAD_WEBHOOK_URL` is set — only then does `WebhookLeadDelivery` fire. Run `scripts/sanitize-database` on any database imported from production before using it locally or in staging. Never commit secrets — use environment variables (`.env`, untracked) or the host's secret store.

## Editing model

Customers hold `client_editor` or `client_shop_manager`: content only, inside the block allow-list. Structure, styles, plugins, and file editing are locked down by `agency-platform` and unavailable regardless of role.

## Verification expectations

Run `ddev composer verify:fast` before every commit; run `ddev composer test:integration` (or `verify`) when touching anything that hits the database; run the Playwright suites when UI changed. See `docs/validation-scenarios.md` for how each guardrail is meant to fail.

## Where docs live

`docs/architecture.md` (layers, dependency rules, source of truth), `docs/ownership-rules.md` (task → owning layer), `docs/adding-a-block.md`, `docs/adding-an-integration.md`, `docs/adding-commerce-behaviour.md`, `docs/validation-scenarios.md` (guardrail test scenarios), `docs/mcp.md` (MCP policy). `ops/` holds hosting-agnostic operational contracts: `backup.md`, `restore.md`, `update-process.md`, `monitoring.md`, `incident-recovery.md`.
