# Reference Callout (`agency/reference-callout`)

A dynamic block: heading + body copy, with an optional testimonial pulled
live from `SiteCore\Contracts\Testimonials::latest()` — site-theme's only
cross-layer call (see `deptrac.yaml`'s `SiteTheme -> SiteCoreContracts`).

Demonstrates:
- Server-side rendering (`render.php`) via `get_block_wrapper_attributes()`.
- Styling only through `var(--wp--preset--*)` / `var(--wp--custom--*)`
  tokens (`style.css`, `editor.css`) — no raw colors.
- Consuming a `SiteCore\Contracts\*` public API from the theme.
- Listing in `AgencyPlatform\Editor\EditorRestrictions::ALLOWED_BLOCKS`.

`build/index.js` (the `editorScript`) does not exist yet — a later task's
`npm run build` (`@wordpress/scripts`) produces it from a not-yet-written
`index.js` (`RichText` for `heading`/`content`, `ToggleControl` for
`showTestimonial`). Until then the block registers and renders fine on the
front end; only its editor UI is missing.

## Template for a new block

Copy this directory, rename it, update `block.json`, rewrite `render.php`,
and add its slug to `EditorRestrictions::ALLOWED_BLOCKS` if needed.
