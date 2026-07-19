# Agency Starter

An AI-first WordPress starter for agency work: [Bedrock](https://roots.io/bedrock/)-structured WordPress, a hybrid block/classic theme, and layered plugins with architecture tests that keep the codebase legible to both humans and coding agents.

**If you are an AI agent working in this repo, read [AGENTS.md](AGENTS.md) first.** It is the single source of agent guidance (imported by `CLAUDE.md` for Claude Code).

## What this is and how it's meant to be used (plain language)

This repository is a **starter kit you copy for every new customer project** — an empty but fully wired WordPress setup with safety rails built in, so a developer or an AI agent can build any site on it without breaking things, and mistakes get caught automatically by tests.

**Which sites and plugins does it support?** Underneath it is completely normal WordPress, so it supports any site type — service sites, marketing sites, landing pages, blogs, product sites, and WooCommerce stores (commerce is a built-in optional layer that switches on when WooCommerce is installed). Any normal plugin works too: SEO, forms, caching, backups, CRM connectors — install them like on any WordPress site. **The one deliberate exception: page builders (Elementor, Divi, …) and all-in-one purchased themes are not supported.** The template's entire value (locked editing, design in Git, automatic checks) depends on the design living in code, not in a builder's database.

**Does it come with a theme?** Yes — `site-theme`, deliberately plain. It is not a "design"; it is a skeleton: header, footer, page layouts, one example block, and a single settings file (`theme.json`) that controls all colors, fonts, and spacing. It looks bare on purpose — it is the blank canvas every customer design gets painted onto.

**How does a customer get their look?** You never create a second theme and never install a bought one on top. The workflow is always:

1. **Copy the template** for the new customer and run `scripts/rename-project`.
2. **Reshape `site-theme` in place** — it belongs to that customer's copy, so editing it directly is correct. Colors/fonts/spacing → `theme.json`. Header/footer → `parts/`. New sections the customer may edit → `blocks/` and `patterns/`.
3. **If the customer bought a theme they like, don't install it.** Use it as a *design reference*: look at its demo, then recreate that look inside `site-theme`. Installing the bought theme itself would throw away everything this template provides — the editing locks, the tests, and the guarantee that the design lives in Git and can't be broken from the admin panel.

Think of it like a house with finished wiring, plumbing, and alarm systems, where `site-theme` is its unpainted walls. A bought theme is a different prefab house — you can't bolt it onto yours, but you can look at it and paint your walls to match.

**The trade-off:** recreating a bought theme's design is more up-front work than installing it. In exchange, customers can edit text/images/products but can never break the layout, every change is tested automatically, and any AI agent working on the site knows exactly where everything goes (that is what [AGENTS.md](AGENTS.md) tells it). For an agency maintaining many sites long-term, that trade is the reason this template exists.

## Requirements

- [Docker](https://www.docker.com/) + [DDEV](https://ddev.com/) (no local PHP/Composer/MySQL needed — everything PHP-side runs inside the DDEV container)
- [Node.js](https://nodejs.org/) 20+ (the frontend toolchain runs natively on the host)

## Quickstart

### Fastest path: `scripts/setup`

```sh
ddev start
ddev ssh
bash scripts/setup   # or: ddev exec bash scripts/setup, from the host
```

This installs Composer dependencies, creates `.env` with fresh random salts, installs WordPress core, activates the theme and base-profile plugins, installs Node dependencies, builds the block editor assets, and creates a `client-editor` test user. It's idempotent — re-running it only fills in what's missing. Read `scripts/setup` itself; it's written to be read top to bottom as the canonical description of how a working install comes together.

Local-only throwaway credentials it creates: `admin` / `admin` and `client-editor` / `client-editor`. Never run this script against a shared or production environment.

### Manual path

```sh
ddev start
ddev composer install
cp .env.example .env                 # then fill in real AUTH_KEY/SALT values
ddev wp core install \
  --url=https://agency-starter.ddev.site \
  --title="Agency Starter" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.invalid
ddev wp theme activate site-theme
ddev wp plugin activate site-core site-integrations
npm ci && npm run build
```

Visit `https://agency-starter.ddev.site`.

## Profiles

The starter ships with a **base profile** (no WooCommerce) and an optional **commerce profile**.

- **Base**: `site-core`, `site-integrations`, and `site-theme` are active. `site-commerce` is present but stays a no-op (an admin notice only) until WooCommerce is active — see `SiteCommerce\Plugin::maybe_boot()`.
- **Commerce**: run **`bash scripts/enable-commerce`** — it installs WooCommerce via Composer, activates site-commerce, configures a deterministic store, and creates the test fixtures. `site-commerce` then boots its providers automatically. It is proven, not scaffolded: a `commerce-integration` PHPUnit suite and a `COMMERCE=1` Playwright journey suite exercise the real store (both run in CI's dedicated `commerce-e2e` job).
  - **Ephemeral vs committed:** the script's `composer require wpackagist-plugin/woocommerce` edits `composer.json`/`composer.lock`. For a **real commerce client you commit** that change (WooCommerce becomes a tracked, audited dependency). For the **template it stays ephemeral** — CI installs it per-run and throws it away, so the base profile never carries a committed WooCommerce dependency. Only the inert `wpackagist.org` repository entry in `composer.json` is committed. See `docs/adding-commerce-behaviour.md`.

## Project layout

```
config/                  Bedrock environment config (application.php, environments/*.php)
web/app/mu-plugins/agency-platform/   Guardrails: roles, editor lockdown, security, WP-CLI
web/app/plugins/site-core/            Business rules + SiteCore\Contracts\* public API
web/app/plugins/site-integrations/    Contract implementations that talk to the outside world
web/app/plugins/site-commerce/        WooCommerce-only behavior (activates only if Woo present)
web/app/themes/site-theme/            Hybrid theme: templates, parts, blocks, patterns, tokens
tests/                    Architecture, Unit, Integration (PHPUnit) + e2e/visual/accessibility (Playwright)
scripts/                  setup, verify, generate-block-index, check-database-overrides, sanitize-database,
                          verify-environment, rename-project
docs/                     Architecture, ownership, how-to, validation-scenario, MCP docs
ops/                      Hosting-agnostic operational contracts (backup, restore, monitoring, incidents)
.ddev/, .github/          DDEV config, CI workflows
```

## Commands

Run Composer scripts via `ddev composer <script>` (no host PHP needed); npm scripts run natively.

| Command | What it does |
| --- | --- |
| `ddev composer verify:fast` | validate, audit, phpcs, phpstan, deptrac, architecture + unit tests (no DB) |
| `ddev composer verify` | `verify:fast` + integration tests (needs the DDEV database) |
| `ddev composer test:architecture` | the 22 architecture tests (structure/dependency guardrails) |
| `ddev composer test:unit` | unit tests (no WordPress runtime) |
| `ddev composer test:integration` | integration tests against a real WordPress + DB |
| `ddev composer lint:php` / `analyse` / `deptrac` / `audit` | phpcs / phpstan (level 6) / dependency-direction check / security audit |
| `npm run build` / `npm run start` | production / watch build of block editor assets (wp-scripts) |
| `npm run lint` (`lint:js`, `lint:css`) | ESLint (blocks + parts) / Stylelint (theme CSS) |
| `npm run test:e2e` / `test:visual` / `test:accessibility` | Playwright suites against a running site |
| `bash scripts/verify` | mirrors CI: `composer verify`, `npm run lint`, `npm run build` |

## Testing — what needs what

| Suite | Needs a database? | Needs a running site? | Run with |
| --- | --- | --- | --- |
| Architecture (22 tests) | no | no | `ddev composer test:architecture` (or `composer` directly, anywhere) |
| Unit (121 tests) | no | no | `ddev composer test:unit` |
| Integration (27 tests) | yes | no (WordPress test scaffold) | `ddev composer test:integration`, or CI's `integration` job |
| e2e / accessibility | no | yes | `npm run test:e2e` / `test:accessibility` against `WP_BASE_URL` |
| Visual regression | no | yes | `npm run test:visual`; baselines are Linux-CI-authoritative — see `playwright.config.ts` |
| Commerce integration | yes | no (+ WooCommerce) | `bash scripts/enable-commerce` then `ddev composer test:integration:commerce` |
| Commerce e2e | no | yes (+ WooCommerce) | `bash scripts/enable-commerce` then `COMMERCE=1 npm run test:e2e:commerce` |

## Renaming for a new project

`scripts/rename-project` rewrites `agency-starter` → your slug and `Agency Starter` → your title across text files (Composer package name, DDEV project name, URLs, docs). Dry-run by default:

```sh
php scripts/rename-project --slug=acme-web --title="Acme Web"   # prints a diff
php scripts/rename-project --slug=acme-web --title="Acme Web" --apply
```

It deliberately does **not** rename the `agency/` block namespace (e.g. `agency/reference-callout`) or the PHP namespaces (`AgencyPlatform\`, `SiteCore\`, ...) — both are baked into existing database content and the autoloader, so renaming them is a separate, deliberate decision, never a side effect of this script.

## CI

`.github/workflows/ci.yml` runs on every push/PR: `php-qa` (`composer verify:fast`), `frontend` (lint, build, build-drift check against the committed block build output), `integration` (PHPUnit against a MariaDB service), `e2e` (DDEV + Playwright e2e/accessibility/visual), and `commerce-e2e` (the optional commerce profile: installs WooCommerce ephemerally, then runs the `commerce-integration` PHPUnit suite and the `COMMERCE=1` Playwright journeys — the base jobs never install WooCommerce). `dependency-review.yml` gates PRs on newly-introduced vulnerable dependencies. `scheduled-maintenance.yml` runs a weekly dependency audit and generated-index freshness check. `.github/dependabot.yml` groups weekly composer/npm/actions update PRs; Dependabot *security* updates are a separate GitHub repository setting.

## Documentation index

- [`docs/architecture.md`](docs/architecture.md) — layers, dependency rules, source of truth
- [`docs/ownership-rules.md`](docs/ownership-rules.md) — "I need to do X, which layer owns it?"
- [`docs/editing-strictness.md`](docs/editing-strictness.md) — the default content-only editing model and the per-project dials to tighten it
- [`docs/adding-a-block.md`](docs/adding-a-block.md), [`docs/adding-an-integration.md`](docs/adding-an-integration.md), [`docs/adding-commerce-behaviour.md`](docs/adding-commerce-behaviour.md) — how-to guides
- [`docs/validation-scenarios.md`](docs/validation-scenarios.md) — how to prove each guardrail actually fails closed
- [`docs/mcp.md`](docs/mcp.md) — optional local MCP policy
- [`ops/`](ops/) — launch checklist, backup, restore, update process, monitoring, incident recovery
