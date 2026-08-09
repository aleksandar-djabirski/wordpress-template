# Architecture

This starter separates code into small layers with one job each, enforced by
Deptrac (dependency direction) and the `tests/Architecture` suite (structure,
naming, and boundary rules). See `AGENTS.md` for the routing table and the
hard rules in one page; this document explains the shape those rules protect.

## Layers

```
                 ┌────────────────────┐
                 │   agency-platform   │  guardrails (roles, lockdown,
                 │   (mu-plugin)       │  security, WP-CLI) — depends on
                 └─────────┬──────────┘  nothing project-side
                           │ (no project deps)
   ┌───────────────────────┴───────────────────────┐
   │                                                │
┌──▼───────────┐   contracts only   ┌───────────────▼──────┐
│  site-theme   ├───────────────────►  SiteCore\Contracts\* │
│  (theme)      │                   │  (public API surface)│
└──────────────┘                   └───────────┬───────────┘
                                                │ implements
                        ┌───────────────────────┼───────────────────────┐
                        │                        │                       │
              ┌─────────▼─────────┐   ┌──────────▼─────────┐  (internals)│
              │ site-integrations │   │   site-commerce     │  ┌─────────▼────┐
              │ (webhooks/APIs)   │   │ (WooCommerce-only)  │  │  site-core   │
              └───────────────────┘   └──────────────────────┘  │ (business   │
                                                                  │  rules)     │
                                                                  └─────────────┘
```

`site-core`'s internals are a separate Deptrac layer from `SiteCoreContracts`
precisely so everyone else can depend on the contracts without ever being
allowed to reach past them into `SiteCore\Testimonials\*`,
`SiteCore\Leads\*`, etc.

## Dependency rules (Deptrac-enforced, see `deptrac.yaml`)

| From | May depend on |
| --- | --- |
| `SiteTheme` | `SiteCoreContracts` only |
| `SiteIntegrations` | `SiteCore`, `SiteCoreContracts` |
| `SiteCommerce` | `SiteCore`, `SiteCoreContracts` |
| `SiteCore` | `SiteCoreContracts` only |
| `AgencyPlatform` | nothing project-side |

Everything not listed is forbidden. Run `ddev composer deptrac` to check; a
violation names the offending class and the rule it broke.

## Three-category UI model

The Site Editor exposes three categories of visual structure: blocks, template
parts, and templates. Patterns provide sanctioned starting compositions.

- **Blocks** (`site-theme/blocks/`) — insertable, editable UI registered via
  `block.json`. Which blocks a client may insert is decided by
  `AgencyPlatform\Editor\BlockPolicy` from the registered block types, not a
  hand-maintained list.
- **Template parts** (`site-theme/parts/*.html`) — site chrome (header, footer)
  as block markup, declared in `theme.json.templateParts` and **editable by
  clients in the Site Editor**. Adding a part means adding
  `parts/<slug>.html` and a `templateParts` entry, not editing a PHP manifest.
- **Templates** (`site-theme/templates/*.html`) — the per-request page shell,
  also editable in the Site Editor.
- **Patterns** (`site-theme/patterns/*.php`) — sanctioned starting
  compositions, unlocked by default. `reference-landing-section.php` is the
  documented locked example.

Commerce markup overrides are the commerce profile's boundary: the theme
ships one declared block template per commerce request type, each a derived
copy of the upstream template with only the header/footer template-part
slugs rewritten. The ground truth is `tests/Architecture/commerce-template-list.php`:
six owned templates (`archive-product`, `order-confirmation`, `page-cart`,
`product-search-results`, `single-product`, `taxonomy-product_attribute`)
plus two deliberately excluded upstream slugs (`coming-soon`, `page-checkout`).
`CommerceBoundaryTest` fails whenever commerce block markup appears outside
those declared templates, and the classic `site-theme/woocommerce/` PHP
override directory is retired — a block theme has one rendering path.

## Source of truth

Git owns code and the promoted baseline. The database can contain intentional
live overrides. Runtime truth is the active Git baseline plus current database
state. Agents must export and inspect current state before changing or
promoting structure and styles.

Customer copy, media choices, page compositions, navigation data, and
product/order data live in the database. Database structural overrides are
**expected**: a client editing a template, a part or Global Styles writes a
`wp_template`, `wp_template_part` or `wp_global_styles` row.
`wp agency state-diff` reports them and exits zero; `--fail-on-drift` is the
opt-in gate. (`wp agency check-overrides` remains as a compatibility alias and
prints a deprecation warning pointing at `state-diff`.)

State ownership, in one paragraph: templates and template parts are a Git
baseline plus database overrides, both promotable; Global Styles is the same
plus the client's user-origin customisation, also promotable; navigation,
synced patterns, page content, media references and fonts are database-owned
and export/diff only. The full table — the single copy — lives at
[`docs/state-reconciliation.md`](state-reconciliation.md#ownership-table).

| Thing | Owned by | Notes |
| --- | --- | --- |
| Blocks (`blocks/*/`) | Git (definition) + Database (`post_content` usage) | The block's code ships in Git; where/how it's placed on a page lives in post content |
| Products (WooCommerce) | Database (behavior in Git) | Product data lives in `wp_posts`/`wp_postmeta`; the *rules* governing it live in `site-commerce/` |
| Secrets (API keys, webhook URLs) | Environment variables / host secret store | Never Git, never the database — see `.env.example` and `AGENTS.md`'s environment-safety section |

`wp agency check-overrides` (`scripts/check-database-overrides`) **reports drift**
between the database and the Git baseline. It exits zero by default — a
database template, part or Global Styles row is a legitimate client edit under
this editing model — and exits 1 only with `--fail-on-drift`. Richer,
machine-readable reporting is `wp agency state-export` / `wp agency state-diff`,
which ship today; `check-overrides` remains as a compatibility alias and prints
a deprecation warning pointing at `state-diff`.

## Editing surfaces and their guardrails

- `AgencyPlatform\Editor\BlockPolicy` derives the insertable block set from
  registered blocks, approved namespaces, and the project filters. Its
  `ALWAYS_DENIED` list cannot be reopened by a filter.
- `AgencyPlatform\Editor\SaveValidation` re-applies the block policy at the
  REST save boundary and rejects forbidden blocks, raw HTML, registered
  shortcodes, and per-block custom CSS.
- `AgencyPlatform\Security\CapabilityPolicy` maps `edit_css` and `customize`
  to `do_not_allow` for client roles.
- `AgencyPlatform\Security\AdminScreenPolicy` blocks the legacy theme,
  plugin, Customizer, widget, menu, and settings screens while leaving
  `site-editor.php` and `font-library.php` open for the Site Editor.

## Profiles

- **Base**: `site-core`, `site-integrations`, `site-theme` active;
  `site-commerce` installed but inert (`Plugin::maybe_boot()` no-ops without
  WooCommerce). This is what CI's `php-qa`/`frontend`/`integration`/`e2e`
  jobs all run against — the base profile never requires WooCommerce.
- **Commerce**: WooCommerce active, so `site-commerce`'s providers boot and
  its `src/Integrations/` becomes a second approved outbound-HTTP location.
  See `docs/adding-commerce-behaviour.md`.

There are deliberately no further profiles. Commerce is included because
WooCommerce is the unambiguous industry standard, free, and CI-testable
without secrets. Capabilities without a unified standard — multilingual
(WPML/Polylang/TranslatePress), memberships, bookings, LMS — are per-client
plugin decisions made in the client repo, following the commerce blueprint
(enable script + deterministic config + gated tests; paid plugins installed
via Composer with license keys in `.env`, never committed). The starter is
already translation-ready: every string uses WordPress i18n functions with
phpcs-enforced text domains. A capability graduates into a template profile
only after ~3 clients converge on the same stack — extracted from real
repetition, never predicted.
