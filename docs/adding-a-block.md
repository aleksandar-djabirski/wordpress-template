# Adding a Block

Before adding a block, work through `AGENTS.md`'s block decision order — a
new block is step 4 of 5 (core block → locked pattern → block binding →
native dynamic block → field plugin/ADR). This guide covers step 4, copying
`web/app/themes/site-theme/blocks/reference-callout/` as the template (its
own `README.md` has a short version of the same steps).

## 1. Copy the reference block

```sh
cp -r web/app/themes/site-theme/blocks/reference-callout \
      web/app/themes/site-theme/blocks/<your-slug>
rm -rf web/app/themes/site-theme/blocks/<your-slug>/build
```

The folder name becomes the block's slug. `BlockManifestTest` requires the
`block.json` `name` field to be exactly `agency/<folder-name>`.

## 2. Rewrite `block.json`

Required fields (`BlockManifestTest` checks these):

- `"name"`: `"agency/<your-slug>"` — must match the folder name.
- `"apiVersion"`: an integer (use `3` unless you have a reason not to).
- At least one `file:` asset reference (`render`, `editorScript`, `script`,
  `viewScript`, `style`, or `editorStyle`) resolving to a real file inside
  the block directory — no `../` escapes.
- `"textdomain"`: `"site-theme"` (the only theme text domain).

Update `title`, `category`, `icon`, `description`, and `attributes` for your
block's actual data.

## 3. Write `render.php`

Server-side render callback (WordPress provides `$attributes`, `$content`,
`$block` in scope). Conventions from `reference-callout/render.php`:

- `declare(strict_types=1);` + an `ABSPATH` guard at the top.
- Cast every attribute you read: `(string) ( $attributes['x'] ?? '' )`.
- Wrap output in `get_block_wrapper_attributes()`; escape everything
  (`esc_html()` for plain text, `wp_kses_post()` for limited HTML).
- If the block needs a `SiteCore\Contracts\*` value, guard with
  `class_exists()` so it degrades gracefully if that plugin is deactivated
  — this is also the *only* cross-layer call the theme may make
  (`SiteTheme → SiteCoreContracts`, deptrac-enforced).
- **No hooks here.** `HookOwnershipTest` forbids `add_action`/`add_filter`
  in any block `render.php` — wiring belongs in `ThemeBootstrap`.

## 4. Add `index.js` only if the block needs editor UI

`reference-callout/index.js` registers editor-side controls
(`registerBlockType`, `InspectorControls`, etc.). No custom UI needed? Keep
a minimal `editorScript` pointing at `build/index.js` anyway —
`block.json` still needs at least one `file:` reference (step 2).

## 5. Build

No build config to edit. `npm run build` runs `scripts/build-blocks.mjs`,
which auto-discovers every `blocks/*/index.js` and runs `wp-scripts build`
per block into that block's own `build/`. A new block is picked up the
moment its folder exists:

```sh
npm run build
```

Commit the `build/` output; a fresh clone must have a working editor
without running `npm install`/`npm run build` first.

To watch a single block while iterating (parallel watchers are messy, so
watch is one block at a time), pass its slug:

```sh
npm run start -- <your-slug>
```

## 6. Allow-list and index

- **Register**: nothing to do. `SiteTheme\Bootstrap\ThemeBootstrap::register_block()`
  globs `blocks/*/block.json` and registers each, so a new block folder is
  registered automatically — no edit here.
- **Allow-list**: if customers should be able to insert it, add
  `'agency/<your-slug>'` to
  `AgencyPlatform\Editor\EditorRestrictions::ALLOWED_BLOCKS` (administrators
  are unaffected either way).
- **Regenerate the index**: `php scripts/generate-block-index`.
  `GeneratedIndexFreshnessTest` byte-compares `docs/generated-block-index.md`
  against a fresh regeneration; skipping this fails
  `ddev composer test:architecture`.

## 7. Tests

- **Architecture** picks up a new block automatically —
  `BlockManifestTest`'s `@dataProvider` scans every `blocks/` directory, so
  no new architecture test is needed.
- **Unit** (optional): non-trivial render logic gets a unit test, the way
  `EditorRestrictionsPolicyTest` covers the allow-list policy.
- **e2e** (optional): a customer-insertable block gets an inserter/render
  check under `tests/e2e/` (see `tests/e2e/editor-permissions.spec.ts`).

## Verify

```sh
ddev composer test:architecture
npm run lint:js && npm run lint:css
npm run build
```
