# Ownership Rules

A decision tree for "I need to do X — which layer owns it, what may I touch,
and how do I verify it?" For the one-line version, see `AGENTS.md`'s routing
table. This expands each common task.

## Modify the header or footer (site chrome)

- **Owns it**: `site-theme/parts/site-header.html` or
  `site-theme/parts/site-footer.html`, plus `assets/global/shared.css` for
  the visual rules.
- **May change**: the block markup and the `.site-header` / `.site-footer`
  rules in `shared.css`.
- **Must not change**: nothing at the theme root — a block theme has no
  `header.php`/`footer.php`. Do not add an inline `style` attribute to the
  Git-owned markup.
- **Checks**: `ddev composer test:architecture`
  (`BlockThemeStructureTest`, `GlobalAssetRulesTest`),
  `ddev composer test:integration` (`BlockTemplateIntegrityTest`),
  `npm run lint:css`, `npm run test:parity`.

## Add a testimonial section to a page

- **Owns it**: content lives in the `testimonial` CPT (site-core, database);
  display goes through `agency/reference-callout` (already wired to
  `SiteCore\Contracts\Testimonials::latest()`) or a new block that consumes
  the same contract.
- **May change**: page content (add the block in the editor); `render.php`
  of a block if you need new markup for the testimonial data.
- **Must not change**: `SiteCore\Testimonials\TestimonialsProvider`
  internals from the theme — only `SiteCore\Contracts\Testimonials` is a
  legal theme dependency.
- **Checks**: `ddev composer deptrac` (catches a theme→internals reference).

## Change a product card / product listing

- **Owns it**: `site-commerce` (behavior) + `site-theme/woocommerce/`
  (markup override, last resort) or a `woocommerce_*` hook from
  `site-commerce/src/Products/`.
- **May change**: `site-commerce/src/Products/*`; a new, logged override
  under `site-theme/woocommerce/` only after ruling out a hook (see
  `docs/adding-commerce-behaviour.md`).
- **Must not change**: anything under `site-core`, `site-integrations`, or
  the base theme templates — commerce logic never leaks into the base
  profile.
- **Checks**: `ddev composer test:architecture`
  (`WooCommerceIsolationTest` fails if a `WC_*`/`wc_*`/`woocommerce_*`
  symbol appears outside the commerce boundary).

## Add a banner / promotional block

- **Owns it**: `site-theme/blocks/<new-block>/` if it's a new customer-
  editable unit, or a pattern (`site-theme/patterns/`) if it only composes
  existing blocks. See `AGENTS.md`'s block decision order.
- **Checks**: `ddev composer test:architecture` (`BlockManifestTest`),
  `npm run build`, `php scripts/generate-block-index`.

## Build a landing page

- **Owns it**: a template (`site-theme/templates/`) for the shell, a
  pattern (`site-theme/patterns/`) for the reusable composition of blocks.
  See `patterns/reference-landing-section.php` for a locked
  (`templateLock: contentOnly`) example.

## Change typography or color

- **Owns it**: `theme.json` (design tokens) — never raw CSS values.
- **May change**: `theme.json`'s `settings.typography`/`settings.color`,
  and any block/part CSS that references the resulting
  `var(--wp--preset--*)` custom property.
- **Must not do**: write a hex/rgb color literal in CSS — `npm run
  lint:css` (stylelint `declaration-strict-value`) rejects it.

## Change mobile navigation behavior

- **Owns it**: `core/navigation`'s `overlayMenu` attribute in
  `site-theme/parts/site-header.html`. There is no theme-level navigation JS
  any more. If genuinely custom interaction is needed, use a block with
  `viewScript` or the Interactivity API.
- **Checks**: `npm run lint:js`, `npm run test:accessibility` (nav toggle
  keyboard/ARIA behavior).

## Add a new external integration (webhook, API, CRM, ...)

- **Owns it**: contract in `site-core/src/Contracts/`, implementation(s) in
  `site-integrations/src/`. See `docs/adding-an-integration.md`.
- **Must not do**: call `wp_remote_*`/cURL from `site-theme`, `site-core`,
  or `agency-platform` — `IntegrationBoundaryTest` fails.

## Add a WooCommerce-only business rule

- **Owns it**: `site-commerce/src/`. See
  `docs/adding-commerce-behaviour.md`.
- **Must not do**: reference a `WC_*`/`wc_*`/`woocommerce_*` symbol
  anywhere outside `site-commerce/`, `site-theme/woocommerce/`,
  `tests/commerce/`, or a reviewed `tests/Architecture/woocommerce-allowlist.php`
  entry.

## Tighten how much customers can edit

- **Owns it**: the three `agency_platform_*` filters,
  `AdminScreenPolicy::DENIED_SCREENS`, and `RolesProvider`.
- **See**: [`editing-strictness.md`](editing-strictness.md) for the default
  editing model and the four dials (trim the block set, lock page composition
  via `template_lock`, drop page caps, tighten the admin-screen boundary).
