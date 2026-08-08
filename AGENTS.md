# AGENTS.md

## Response language
Write all prose replies in ASD-STE100 Simplified Technical English: active
voice, short sentences, one instruction per sentence, approved words only, no
synonyms, no slang or idioms. This applies to prose only
- do not change code,
code comments, commit messages, file contents, or command output.

AI-first WordPress agency starter: Bedrock + DDEV, a native block theme (full Site Editor), and layered plugins (`agency-platform`, `site-core`, `site-integrations`, `site-commerce`).

## Project lifecycle

New client: GitHub "Use this template" → `scripts/rename-project --apply` → design into `site-theme`. Store client: also `bash scripts/enable-commerce` + commit the WooCommerce require. Non-standard capabilities (multilingual, memberships, …): per-client plugin via Composer in the client repo (`docs/architecture.md#profiles`), never added to this template. Before launch: complete every item in `ops/launch-checklist.md`. Weekly: merge green Dependabot PRs.

## Routing table

Customer-editable UI → block (`site-theme/blocks/`)
Site chrome (header, footer) → template part (`site-theme/parts/*.html`)
Page shell → block template (`site-theme/templates/*.html`)
Composition of blocks → pattern (`site-theme/patterns/`)
Business rule → `site-core`
External service → `site-integrations`
WooCommerce behavior → `site-commerce`
WooCommerce markup override → `site-theme/templates/<commerce-slug>.html` (declared block template; the classic `woocommerce/` directory is retired)
State export / promotion → `agency-platform/src/State/` (never business logic)

## Layer ownership

- `agency-platform` (mu-plugin): guardrails only — roles, editor block policy, server-side save validation, admin-screen boundary, app-password lockdown, file-mod guard, database-override detection, state export/diff, promotion lifecycle, promotion backups, `wp agency *` WP-CLI commands. Never business logic, never WooCommerce, never a dependency on any other project layer.
- `site-core`: business rules plus the public `SiteCore\Contracts\*` API — the ONLY site-core namespace other layers may reference. Never renders markup, never makes network calls, never references WooCommerce or the theme.
- `site-integrations`: implementations of `SiteCore\Contracts\*` that talk outward (webhooks, APIs). The base profile's only outbound-HTTP home. Never referenced by site-core.
- `site-commerce`: WooCommerce-only behavior; activates only when WooCommerce is present (`Requires Plugins` header). Its `src/Integrations/` is the commerce profile's outbound-HTTP home. Never referenced by the base profile.
- `site-theme`: native block theme — `templates/*.html` are the only rendering path; `parts/*.html` hold the chrome and are declared in `theme.json.templateParts`. May depend only on `SiteCore\Contracts\*`; never on site-core internals, site-integrations, or site-commerce.

## Dependency direction (deptrac-enforced)

`SiteTheme → SiteCoreContracts`; `SiteIntegrations → SiteCore, SiteCoreContracts`; `SiteCommerce → SiteCore, SiteCoreContracts`; `SiteCore → SiteCoreContracts`; `AgencyPlatform → nothing project-side`. Everything else is forbidden. Check with `ddev composer deptrac`.

## Hard rules the architecture tests enforce

- No closures in `add_action`/`add_filter` anywhere in production code — named class methods only (`HookOwnershipTest`).
- `functions.php` stays ≤50 lines and only calls `ThemeBootstrap::boot()`; the theme contains no root-level PHP templates and no `templates/*.php` (`ThemeBootstrapTest`, `BlockThemeStructureTest`).
- No `components/`, `layouts/`, `inc/`, `includes/`, `helpers/`, `misc/`, `common/`, `lib/`, or `utils/` directories in the theme or any plugin (`DirectoryRulesTest`).
- Every block needs a valid `block.json`: name `agency/<folder>`, integer `apiVersion`, `file:` asset references that resolve inside the block (`BlockManifestTest`).
- WooCommerce symbols (`WooCommerce`, `WC_*`, `wc_*`, `woocommerce_*`) may only appear in `site-commerce/`, the declared commerce block templates (`site-theme/templates/<commerce-slug>.html`, enforced by `CommerceBoundaryTest`), `tests/commerce/`, or a reviewed entry in `tests/Architecture/woocommerce-allowlist.php` (`WooCommerceIsolationTest`).
- Outbound HTTP (`wp_remote_*`, cURL, Guzzle, `file_get_contents('http...')`) only inside `site-integrations/` or `site-commerce/src/Integrations/` (`IntegrationBoundaryTest`).
- CSS colors must be design tokens (`var(--wp--preset--color--*)` / `var(--wp--custom--*)`), never raw hex/rgb (stylelint `declaration-strict-value`).
- `assets/global/` holds exactly `frontend-reset.css` + `shared.css` + `editor.css`; `frontend-reset.css` is never loaded into the editor.
- Git-owned templates and parts carry no hard-coded `ref` — colours and spacing live in `theme.json` and `assets/global/shared.css` (`BlockThemeStructureTest`).
- `docs/generated-block-index.md` must match `php scripts/generate-block-index`'s output — run it after any block/pattern change and commit the result (`GeneratedIndexFreshnessTest`).

## Block decision order

1. A core block already does it.
2. A locked pattern (`templateLock`) composes existing blocks into the needed shape.
3. A block binding connects a core block to dynamic data.
4. A native dynamic block (`render.php`) — see `blocks/reference-callout/` as the reference implementation.
5. Only if none of the above fit: a field plugin (ACF, etc.), which needs an ADR and is never in the base profile.

## Frontend behavior order

CSS first, then native HTML/ARIA, then block-local JS declared via `block.json` (e.g. `viewScript`), then the Interactivity API for anything stateful. No global JS bundles or ad hoc `<script>` tags — see `blocks/reference-callout/block.json` for the declared-per-block pattern; native `core/navigation` already provides the responsive overlay, so no theme-level JS is needed for it.

## Commands

Run PHP/Composer commands via `ddev composer <script>`; npm runs natively.
When changing npm dependencies, regenerate the lock with `npx -y npm@10 install` — CI runs Node 22/npm 10, and locks written by newer npm majors can fail its `npm ci` sync check.

- `ddev composer verify:fast` — validate, audit, phpcs, phpstan, deptrac, architecture + unit tests. No database. Run before every commit.
- `ddev composer verify` — `verify:fast` + `test:integration` (needs the DDEV database).
- `ddev composer test:architecture` / `test:unit` / `test:integration` / `test:integration:cli` / `lint:php` / `analyse` / `deptrac` / `audit` — individual steps.
- `wp agency state-export` / `state-diff` — export and diff Site Editor state against the Git baseline; see `docs/state-reconciliation.md`.
- `wp agency promote-overrides --prepare|--seal|--finalize|--confirm|--rollback|--heartbeat` — the promotion lifecycle; see `docs/state-reconciliation.md`.
- `wp agency promotion-backups list|prune` — inspect and prune the protected promotion backups; see `docs/state-reconciliation.md`.
- `scripts/promote-overrides` — deployment-side wrapper (finalize → verify → confirm/rollback); never run on the WordPress host; see `docs/state-reconciliation.md`.
- `npm run build` / `npm run start` — production/watch block build (wp-scripts).
- `npm run lint` (`lint:js` + `lint:css`) — ESLint + Stylelint.
- `npm run test:e2e` / `test:visual` / `test:accessibility` — Playwright; needs a running site (`WP_BASE_URL`, defaults to the DDEV URL).
- `npm run test:parity` — migration + editing parity; needs a running site.
- `npm run capture:migration-baseline` — one-off pre-migration capture.
- Commerce profile (optional; WooCommerce stays OUT of the base template): `bash scripts/enable-commerce` installs WooCommerce (ephemeral `composer require` — commit it only for a real commerce client), configures a deterministic store + fixtures (native block cart/checkout, and a site-header template-part override carrying the Mini-Cart block). Then `ddev composer test:integration:commerce` (WooCommerce-backed PHPUnit, incl. the HPOS sanitize step) and `COMMERCE=1 npm run test:e2e:commerce` (storefront journeys). Neither runs in base `verify`/`test:integration`; CI's `commerce-e2e` job runs both.
- `scripts/setup` — full bootstrap from a fresh clone (composer install, `.env` + salts, WP core install, theme/plugin activation, `npm ci && npm run build`, client-editor test user). Run inside DDEV.
- `scripts/verify` — mirrors CI: `composer verify`, `npm run lint`, `npm run build`.
- `scripts/generate-block-index`, `scripts/check-database-overrides`, `scripts/sanitize-database`, `scripts/verify-environment`, `scripts/rename-project`, `scripts/enable-commerce` — the sanitize/check/verify-env three are thin wrappers around `wp agency check-overrides|sanitize|verify-env`.

## Environment safety

Environment is read via core `wp_get_environment_type()`, never `WP_ENV` directly — Bedrock sets `WP_ENVIRONMENT_TYPE` from `WP_ENV`. Lead delivery resolves to `SiteIntegrations\LeadDelivery\FakeLeadDelivery` everywhere except when the environment is `production`, `AGENCY_DISABLE_OUTBOUND_WEBHOOKS` is not `true`, and `LEAD_WEBHOOK_URL` is set — only then does `WebhookLeadDelivery` fire. `AgencyPlatform\Security\MailGuard` suppresses outbound `wp_mail()` email outside production (via `pre_wp_mail`, returning `false`; a plugin calling an SMTP/API directly bypasses `wp_mail()` and is out of scope) unless `AGENCY_ALLOW_OUTBOUND_EMAIL` is defined true (a deliberate opt-out surface for a safe test mailbox; `verify-env` warns but does not fail on it). Run `scripts/sanitize-database` on any database imported from production before using it locally or in staging — it is a step-based, idempotent **baseline** scrub (users: email/URL/display name/author slug/profile meta; comments: email/URL/author/IP/agent; sessions; application passwords; `blog_public`), extensible via the `agency_platform_sanitize_steps` filter (site-commerce adds WooCommerce order + registered-customer PII scrubbing when Woo is active), with a `--include-admins` flag. It covers this starter's core (and, with commerce active, known WooCommerce) PII only — audit third-party plugins for their own PII tables before sharing any dump (see `ops/launch-checklist.md`). Never commit secrets — use environment variables (`.env`, untracked) or the host's secret store. State bundles and promotion manifests are signed with the HMAC keyring `AGENCY_PROMOTION_HMAC_KEYS` (a JSON keyring) plus `AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID` (`.env` carries both commented out, lines 62-63); a missing keyring is a hard failure — every export/prepare/seal/finalize exits 1, with no WordPress-salt fallback.

## Editing model

Customers hold `client_editor` or `client_shop_manager`. Both have **full visual Site Editor control within the approved block system**: templates, template parts, navigation, Global Styles, and page composition are all editable. They cannot switch or install themes, install or activate plugins, edit theme/plugin files, use the code editor, insert `core/html`, `core/shortcode` or `core/freeform`, or edit Additional CSS — `edit_css` and `customize` are mapped to `do_not_allow`, and `AgencyPlatform\Security\AdminScreenPolicy` refuses `themes.php`, `theme-editor.php`, `plugin-editor.php`, `customize.php`, `widgets.php` and `nav-menus.php` outright. The block set is derived from the REGISTERED blocks filtered to approved namespaces (`core/`, `agency/`, `woocommerce/`), extensible through `agency_platform_allowed_block_namespaces`, `agency_platform_allowed_blocks` and `agency_platform_disallowed_blocks`. That policy is re-applied server side on save (`rest_pre_insert_*`), which also rejects content-level shortcodes and per-block custom CSS. How strict editing is per project is a dial — see `docs/editing-strictness.md`, recorded at launch per `ops/launch-checklist.md`.

## Verification expectations

Run `ddev composer verify:fast` before every commit; run `ddev composer test:integration` (or `verify`) when touching anything that hits the database; run the Playwright suites when UI changed. See `docs/validation-scenarios.md` for how each guardrail is meant to fail.

## Where docs live

`docs/architecture.md` (layers, dependency rules, source of truth), `docs/ownership-rules.md` (task → owning layer), `docs/editing-strictness.md` (per-project editing-lockdown dials), `docs/adding-a-block.md`, `docs/adding-an-integration.md`, `docs/adding-commerce-behaviour.md`, `docs/validation-scenarios.md` (guardrail test scenarios), `docs/state-reconciliation.md` (state export, promotion, rollback, recovery), `docs/block-theme-migration-baseline.md` (the Phase 0 record), `docs/mcp.md` (MCP policy). `ops/` holds hosting-agnostic operational contracts: `launch-checklist.md`, `backup.md`, `restore.md`, `update-process.md`, `monitoring.md`, `incident-recovery.md`.
