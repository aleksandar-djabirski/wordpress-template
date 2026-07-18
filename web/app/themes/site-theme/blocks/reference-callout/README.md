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

`build/index.js` (the `editorScript`) is produced by the root `npm run
build` script (`scripts/build-blocks.mjs`), which auto-discovers every
`blocks/*/index.js` and runs `wp-scripts build` per block in entry-point
form with that block's own `--output-path=<slug>/build` — rather than
`--webpack-src-dir`/`--webpack-copy-php`, so nothing shadow-copies
`render.php` into a second build-owned location. The build output
(`build/index.js` + `build/index.asset.php`) is committed to git — see
`.gitignore`'s Task 7 note — so a fresh clone has a working block editor
without running `npm install`/`npm run build` first.

## Template for a new block

Copy this directory, rename it, update `block.json`, rewrite `render.php`,
and add its slug to `EditorRestrictions::ALLOWED_BLOCKS` if needed.
