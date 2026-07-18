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

## Two-category UI model

Everything a site visitor sees is either a **block** or a **part**, plus one
special-cased third location for WooCommerce:

- **Blocks** (`site-theme/blocks/`) — customer-editable content. Registered
  via `block.json`, insertable/editable in the block editor, subject to
  `EditorRestrictions::ALLOWED_BLOCKS` for non-admin users.
- **Parts** (`site-theme/parts/`) — non-editable site chrome (header, footer)
  rendered through `SiteTheme\Support\Parts::render()`, outside the block
  editor's reach entirely. Adding a part means editing
  `Parts::MANIFEST`, not the block editor.
- **`site-theme/woocommerce/`** — WooCommerce template overrides. Not a UI
  category so much as an escape hatch: `wc_locate_template()` prefers a
  theme file over WooCommerce's own, so this directory can silently
  shadow core Woo markup. It is empty by design (see
  `woocommerce/README.md`'s override log) — a `woocommerce_*` hook must be
  ruled out first, and any override must be logged there.

Page shells (`templates/`) and block *compositions* (`patterns/`) are
one level up from both: a template lays out where parts/content go for a
given request type, a pattern is a canned arrangement of blocks (optionally
`templateLock`-ed, as in `patterns/reference-landing-section.php`).

## Source of truth

| Thing | Owned by | Notes |
| --- | --- | --- |
| Templates (`templates/*.php`) | Git | `ThemeBootstrapTest` requires a thin root delegate per template |
| Parts (`parts/*/`) | Git | Rendered via `Parts::render()`; never editable in wp-admin |
| Blocks (`blocks/*/`) | Git (definition) + Database (`post_content` usage) | The block's code ships in Git; where/how it's placed on a page lives in post content |
| Design tokens | Git (`theme.json`) | `AgencyPlatform\Health\DatabaseOverrideCheck` flags a DB `wp_global_styles` row with real CSS/customizations as an override of this file |
| Content (pages, posts, testimonials) | Database | Authored by editors; not versioned |
| Products (WooCommerce) | Database (behavior in Git) | Product data lives in `wp_posts`/`wp_postmeta`; the *rules* governing it live in `site-commerce/` |
| Secrets (API keys, webhook URLs) | Environment variables / host secret store | Never Git, never the database — see `.env.example` and `AGENTS.md`'s environment-safety section |

`wp agency check-overrides` (`scripts/check-database-overrides`) detects when
a published `wp_template`/`wp_template_part` row or a customized
`wp_global_styles` row exists in the database, shadowing the Git-owned
files above.

## Profiles

- **Base**: `site-core`, `site-integrations`, `site-theme` active;
  `site-commerce` installed but inert (`Plugin::maybe_boot()` no-ops without
  WooCommerce). This is what CI's `php-qa`/`frontend`/`integration`/`e2e`
  jobs all run against — the base profile never requires WooCommerce.
- **Commerce**: WooCommerce active, so `site-commerce`'s providers boot and
  its `src/Integrations/` becomes a second approved outbound-HTTP location.
  See `docs/adding-commerce-behaviour.md`.
