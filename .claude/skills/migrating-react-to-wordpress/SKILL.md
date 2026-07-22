---
name: migrating-react-to-wordpress
description: Port an existing React/Vite marketing site onto this WordPress agency template — phase plan, content-ownership decisions, design-system port, and the specific guardrail collisions that cost real time the first time. Use this whenever someone says "migrate this React site to our WordPress template", "rebuild this frontend in WordPress", "port these components to Gutenberg blocks", or is mid-migration and hitting an architecture test, lint rule, block viewScript, archive URL, or CPT/rewrite problem in a client copy of this template. Also consult it before planning phases or writing the first plan for such a migration — the decisions it front-loads are expensive to reverse later.
---

# Migrating a React site onto this template

Everything below was learned executing one full migration (a React 19 + Vite +
Tailwind + GSAP marketing site → this template) and is cited to the file that
proves it. Paths are repo-relative and identical in your client copy.

Read `AGENTS.md` and `docs/adding-a-block.md` first — this skill deliberately
does **not** repeat them. It covers only what they don't say.

**The rule that made the migration work:** *no architecture test or lint rule
may be loosened.* Every time a guardrail seemed to require an edit, the
guardrail was right and the approach was wrong — and the correct fix was
always cheaper than the edit would have been. If a rule seems to need
loosening, stop and escalate.

---

## 1. Decide these three things before writing any code

### Content ownership splits three ways

Get this wrong and you pay for it in a later phase, when the fix means moving
content between a database and Git while a client is already editing.

| Storage | What lives there | Who edits it |
|---|---|---|
| CPT (database) | Case studies, articles, anything with an archive | Client, in wp-admin |
| Page copy (block attributes, database) | Headlines, body copy, CTA labels | Client, in wp-admin |
| Git data files | Content whose *count or structure* is part of the brand (e.g. a fixed five pillars, five process phases, the team, testimonials) | Developer / coding agent |

Git-owned sections render server-side from a PHP data file and carry **no block
attributes** — they are invisible in wp-admin, which is the point: there is no
"Add New" button for a client to drift the structure against.

Ask the client which bucket each content type belongs in *before* planning
phases. The split then shows up structurally in the page manifest (§5): a
section with default attributes is client-editable; a section with none is
Git-owned.

### Where Git-owned block data goes

Not a theme top-level `data/` dir — `DirectoryRulesTest` whitelists theme
top-level directories (`assets, blocks, parts, patterns, src, templates,
woocommerce`) and `data` is not on it. Co-locate it with its block:
`blocks/<slug>/<name>.php` returning a plain array (e.g.
`blocks/service-pillars/pillars.php`). Plugin-level `data/` **is** fine —
only the theme's top level is whitelisted.

Same test whitelists theme top-level *files*. There is no `front-page.php`, no
`page-<slug>.php`, no `single-<cpt>.php`. Branch inside the existing
`page.php` / `single.php` delegates and put the real markup under `templates/`.
Proof: `tests/Architecture/DirectoryRulesTest.php`, `ALLOWED_THEME_DIRS` and
`ALLOWED_THEME_FILES`.

### The design-system port

- **Brand palette → `theme.json` `settings.color.palette`.** Drop Tailwind
  entirely; two sources of truth for color cannot coexist with stylelint's
  `declaration-strict-value`.
- **An opacity/alpha ladder → `settings.custom` with `color-mix()`**, one
  *named* entry per sanctioned step:
  `"soft": "color-mix(in srgb, var(--wp--preset--color--noir) 80%, transparent)"`.
  Naming the steps makes an unsanctioned step unrepresentable — there is no
  `--wp--custom--text--73`. Same for borders.
- **Media queries, not `clamp()`**, when the target is pixel-parity with an
  existing breakpoint-based build. Tailwind's `sm/md/lg/xl` produce discrete
  jumps at 640/768/1024/1280; `clamp()` interpolates between them and will
  never match. Use `clamp()` only if you are free to redesign.
- **The component-class layer survives as theme CSS**, split across exactly the
  two allowed global stylesheets: layout primitives / helpers / buttons in
  `assets/global/base.css`, the heading/eyebrow/body/mono scale in
  `assets/global/typography.css`.
- Deliver a fixed per-breakpoint heading scale as component classes, **not**
  `theme.json` `fontSizes` presets — presets stay minimal so the client's size
  controls stay off.
- Self-host fonts (latin subset only) under `assets/fonts/`. Omit palette
  tokens nothing actually uses; they only surface pickable dead colors.

---

## 2. Phase decomposition

This shape worked; each phase got its own plan file and its own review gate,
merged as its own branch.

| Phase | Delivers |
|---|---|
| 0 | Renamed copy of the template, green `verify:fast` baseline |
| 1 | Foundation: `theme.json` tokens, fonts, the two global stylesheets, header/footer parts, the CTA button helper, visual-parity harness |
| 2a | Motion module + 2–3 simple blocks (proves the block+motion pattern end to end) |
| 2b–2d | The remaining section blocks, grouped by page |
| 2e | Page assembly: page manifest, `sync-pages`, structure lock, full-bleed rendering |
| 3a | Content model: CPT + taxonomy + a dependency-free query contract + seeding |
| 3b | Archive + listing blocks consuming that contract |
| 4 | Forms, REST route, launch checklist |

**Split the blocks phase.** It was specced as one phase and had to become five.
The Phase 1 plan ran 1214 lines and Phase 2a 501; once the pattern was
established, later plans converged to ~110 lines each. A block phase covering
15+ components is not reviewable in one pass, and one bad block pattern
replicated 15 times is expensive to unwind. Do 2–3 blocks first, get them
reviewed, then batch the rest by page.

Sequence rationale: motion and one block before many blocks; all blocks before
page assembly (assembly is a manifest edit, so late-arriving sections just get
appended and re-synced); content model before the archive that queries it.

---

## 3. Guardrail collisions

Each of these cost discovery time once. None requires editing a guardrail.

### `composer validate --strict` fails on the very first `verify:fast`

`scripts/rename-project` rewrites the composer package name but deliberately
skips `composer.lock` (`$skip_names = array('composer.lock',
'package-lock.json')`), so the lock's content-hash goes stale. `verify:fast`
starts with `composer validate --strict` (`composer.json`), so a freshly
renamed, otherwise untouched copy fails before you write a line of code.

Fix, immediately after renaming, as its own commit:

```sh
ddev composer update --lock   # re-locks the hash; no dependency version changes
```

### Editorial copy containing a WooCommerce word breaks the base profile

`WooCommerceIsolationTest` scans **string literals**, not just symbols —
`ArchitectureScanner::find_symbol_references()` strips comments and docblocks
but explicitly keeps string literals ("String literals count"). An article
titled *"Shopify vs WooCommerce"* in a PHP data array is a violation.

The scanner only reads `.php` files (`ArchitectureScanner::php_files()` filters
on extension). So: **editorial copy goes in JSON/data, not a PHP array.** In
the migration, seed content lives at
`web/app/plugins/site-core/data/insights-seed.json` and the loader's docblock
carries the warning (docblocks are stripped, so naming it there is safe).

### Vendored third-party JS that must not be reformatted

Put it in a `vendor/` subfolder **inside the block** —
`blocks/<slug>/vendor/<lib>/`. That one move makes four tools skip it with zero
config edits:

- `@wordpress/scripts`' flat eslint config has a global
  `ignores: [ '**/build/**', '**/node_modules/**', '**/vendor/**' ]`
  (`node_modules/@wordpress/scripts/config/eslint.config.cjs`), and this repo's
  `eslint.config.cjs` spreads that config, inheriting the ignore.
- `DirectoryRulesTest`'s recursive scan skips `vendor`, `node_modules`, `build`
  (`all_directories()`), so forbidden dir names *inside* the library (`lib/`,
  `utils/`) don't trip it.
- `ArchitectureScanner::php_files()` skips the same three.
- `.stylelintignore` lists `vendor/` (the migration only vendored JS, so this
  one is config-verified rather than exercised).

**It must be nested, not at the theme root**: `vendor` is not in
`ALLOWED_THEME_DIRS`, so a theme-level `vendor/` fails the whitelist.

### Asset placement

`AGENTS.md` covers the `assets/global/` two-file cap. Two things it doesn't say:

- **`assets/js/` is unconstrained.** That is where a shared motion module and
  vendored runtime libraries live (e.g. `assets/js/cinq-reveal.js`,
  `assets/js/vendor/gsap.min.js`). Only `assets/global/` is capped, and only
  PHP is forbidden under `assets/` at all.
- **Your block's slug may not appear in any CSS outside `blocks/<slug>/`.**
  `GlobalAssetRulesTest::test_block_specific_css_stays_inside_its_block()`
  greps every theme stylesheet for each block slug. Prefix block classes with
  the slug (BEM: `.hero-orbit__motif--desktop`) and keep the rules local; never
  reach into a block from a global stylesheet.

---

## 4. Block script delivery — sharing one motion module

`docs/adding-a-block.md` documents `viewScript` as a `file:` reference. Two
undocumented forms are verified working, and are what let most blocks (14 of 17
in the reference migration) share one GSAP-backed reveal module instead of
shipping a global bundle:

```jsonc
"viewScript": "cinq-reveal"                              // bare registered handle
"viewScript": [ "cinq-reveal", "file:./build/view.js" ]  // mixed array
```

Register (do not enqueue) the handle chain once in `ThemeBootstrap`, versioned
with `filemtime()` — handle names below are the reference project's; use your
own prefix:

```php
wp_register_script( 'cinq-gsap', "{$theme_uri}/assets/js/vendor/gsap.min.js", array(), $ver, true );
wp_register_script( 'cinq-gsap-scrolltrigger', …, array( 'cinq-gsap' ), $ver, true );
wp_register_script( 'cinq-reveal', "{$theme_uri}/assets/js/cinq-reveal.js", array( 'cinq-gsap-scrolltrigger' ), $ver, true );
```

Blocks pull it in via `block.json`, so GSAP loads only on pages that actually
render a reveal block. This satisfies `AGENTS.md`'s "no global JS bundles"
without duplicating the library per block.

### A `view.js` entry needs a build-script change

`scripts/build-blocks.mjs` discovers `blocks/*/index.js` and passes **only that
entry** to `wp-scripts`. A block with a `view.js` source will silently have no
`build/view.js`, and its `block.json` `file:` reference won't resolve. Add the
second entry:

```js
function runWpScripts( command, slug ) {
	const entries = [ `${ blocksRel }/${ slug }/index.js` ];

	if ( command === 'build' && existsSync( `${ blocksDir }/${ slug }/view.js` ) ) {
		entries.push( `${ blocksRel }/${ slug }/view.js` );
	}

	const result = spawnSync( process.execPath, [
		wpScriptsBin, command, ...entries,
		`--output-path=${ blocksRel }/${ slug }/build`,
	], { cwd: repoRoot, stdio: 'inherit' } );
	…
}
```

wp-scripts names output after each entry's basename, so you get `build/index.js`
+ `build/view.js` in the same per-block dir, and blocks without a `view.js` keep
byte-identical output. Keep watch mode single-entry — parallel watchers
interleave and can't be stopped cleanly.

**Lazy chunks work as-is.** webpack's default `publicPath: 'auto'` resolves
dynamic-import chunks relative to the block's own `build/`, so a heavy
dependency (Three.js) can be code-split behind an `import()` with no
`__webpack_public_path__` override. Verified by a real browser load, not just a
green build — check the chunk actually 200s before trusting it.

---

## 5. Content model and archive

### A `?page=N` query param on a page is 301'd away by core

`page` is WordPress's own post-content pagination var (the `<!--nextpage-->`
split). On a static page with no page break, `redirect_canonical()` treats
`?page=2` as a mistake and 301s to the bare permalink — every pagination link
in a custom archive breaks (verified: 301 before the fix, 200 after).

Fix by telling core the truth rather than vetoing its redirect: on the
`request` filter, drop `page` from the query vars when the request targets a
page that renders your archive block. Vetoing `redirect_canonical` instead
leaves the wrong canonical URL and the wrong `$multipage` state behind. See
`web/app/plugins/site-core/src/Insights/ArchiveRouting.php`.

The `request` filter runs before `WP_Query`, so there is no queried object yet —
resolve the target page from the query vars (by path, by id, and as the front
page).

### Rewrites and term names

- Registering a CPT or taxonomy adds rewrite rules that don't exist until a
  flush. Call `flush_rewrite_rules( false )` at the end of your seed and
  page-sync CLI commands, or `/<cpt>/<slug>/` 404s until someone saves
  permalinks by hand.
- **Term names are stored HTML-encoded.** `"Email &amp; CRM"` comes back
  encoded; a consumer that correctly calls `esc_html()` renders
  `Email &amp;amp; CRM`. Decode once at the contract boundary
  (`wp_specialchars_decode( $term->name, ENT_QUOTES )`) so every consumer can
  escape it like any other string.
- Derive per-term presentation (gradients, colors) from a Git-side map keyed by
  term slug rather than free-text meta — keeps design tokens out of the
  database and out of user input.

### Per-page structure lock is Git, not the editor

If the brief is "sections are fixed, copy is editable", **do not rely on
`templateLock: contentOnly`.** Verified against the classic editor: the setting
arrives (`getTemplateLock()` returns `contentOnly`) but is not translated into
structural enforcement for existing dynamic-block page content —
`getBlockEditingMode()` stays `default` and
`canRemoveBlock` / `canMoveBlock` / `canInsertBlockType` stay permissive.

The working arrangement:

1. A Git-owned manifest: per route, the ordered list of section blocks plus
   default attributes for the editable ones.
2. An idempotent reconcile command (`wp <ns> sync-pages`) run on deploy: adds
   missing sections, drops sections no longer in the manifest, fixes order, and
   **preserves the attributes and inner content of sections that survive**, so
   client copy edits are never clobbered.
3. Keep the `block_editor_settings_all` filter anyway as declared intent and a
   forward-compatible hook.

Match manifest sections to existing blocks by block name, first-come-first-
served, ignoring `blockName === null` freeform/whitespace blocks. Keep the
reconcile core a pure array→array function so it unit-tests without WordPress;
the `parse_blocks` / `serialize_block` / `wp_update_post` wrappers stay thin.

The manifest and the command belong in `site-core`, not the theme and not
`agency-platform`: they are business rules, and writing `post_content` that
contains `agency/*` block-name **strings** is not a code dependency on the
theme, so deptrac is satisfied.

This also makes late phases cheap: a section that arrives in Phase 4 is appended
to the manifest and re-synced. Never stub a not-yet-built section into page
content.

---

## 6. Proving parity before the pages exist

- **Seed a block-preview page.** An idempotent `wp eval-file` script that
  maintains one published page (e.g. `/_block-preview/`) containing every block
  built so far, filled with representative copy, lets Playwright structure-test
  block markup and computed styles from Phase 2a onward — long before page
  assembly. Look the page up by slug and update in place so re-runs don't
  duplicate. The *script* is committed; the page it creates is environment
  content.
  **Gotcha:** no `declare(strict_types=1)` in a `wp eval-file` script —
  `eval()` makes it a fatal error.
- **Emulate `prefers-reduced-motion: reduce` in visual specs.** The reveal
  module then sets the final state immediately instead of animating on scroll,
  which removes the timing flake *and* proves blocks render content rather than
  blank under reduced motion. Assert exact computed values (surface colors,
  alpha tiers, letter-spacing) rather than screenshots wherever you can.
- **Keep the React-vs-WP pixel-diff harness separate from committed
  screenshot baselines.** Both sites must be captured on the *same* machine or
  font rendering differs; the committed Playwright baselines are
  Linux-CI-authoritative. Never commit baselines or diff PNGs generated on a
  developer machine — regenerate through CI.
- The template's own committed visual baselines become stale the moment you
  restyle the theme. Plan for regenerating them in CI as a launch task; don't
  discover it in Phase 4.

---

## 7. Environment

**Universal:** run `ddev composer verify:fast` before every commit, and
`npm run lint:css` after any CSS change. Both are cheap and catch the
guardrail collisions above at the point you caused them.

**Windows host + DDEV-in-WSL — these are host-specific, ignore them on
Linux/macOS.** `AGENTS.md` says "npm runs natively"; that is not true on this
setup.

- PHP, Composer and WP-CLI exist **only inside DDEV, which runs in WSL**:
  `wsl -e bash -lc "cd /mnt/c/Users/<you>/Projects/<repo> && ddev composer verify:fast"`
- **npm must also run under WSL.** An `npm ci` performed under WSL leaves
  POSIX-only symlink shims in `node_modules/.bin` (no `.cmd`/`.ps1`), so native
  Windows npm cannot execute them.
- **Playwright is the exception — run it from the Windows host.** Its browsers
  fail under WSL for missing system libraries; from Windows, invoke the CLI
  directly since the `.bin` shim is unusable:
  `node node_modules/@playwright/test/cli.js test tests/visual/…`
- **Commit from Git Bash on Windows** — WSL git has no identity configured.
- Mixed Windows/WSL git access causes EOL churn on the template dotfiles
  (`.editorconfig`, `.gitattributes`, …). `git config core.autocrlf false`
  helps; when they still show as modified, `git checkout -- .` them. Never
  commit them — the diff is noise and it pollutes every review.
