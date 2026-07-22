---
name: building-on-this-template
description: Non-obvious facts about THIS WordPress agency template and about WordPress itself that cost real debugging time the first time. Consult when building or migrating any project on this template and you hit an architecture test, a lint rule, a block `viewScript`, a rewrite/CPT, an archive URL, escaped/mangled content, comments appearing, or a search returning everything. Every claim is cited to the file that proves it; it does NOT repeat AGENTS.md or docs/adding-a-block.md.
---

# Building on this template — the non-obvious parts

Everything here was learned building a full site on this template and is cited
to the file that proves it. Paths are repo-relative and identical in your copy.
This is **not** project-specific method (nothing about a particular frontend
stack) — only facts about the template and about WordPress that bite during any
build.

Read `AGENTS.md` and `docs/adding-a-block.md` first — this skill does not repeat
them.

**The rule that keeps the guardrails useful:** *no architecture test or lint
rule may be loosened.* Every time a guardrail seemed to require an edit, the
guardrail was right and the approach was wrong — and the correct fix was always
cheaper than the edit. If a rule seems to need loosening, stop and reconsider
the approach.

---

## 1. Where content and files are allowed to live

**Content ownership splits three ways**, and getting it wrong is expensive to
reverse once a client is editing:

| Storage | What lives there | Who edits it |
|---|---|---|
| CPT (database) | Anything with an archive (case studies, articles) | Client, wp-admin |
| Block attributes (database) | Page copy — headlines, body, CTA labels | Client, wp-admin |
| Git data files | Content whose *count or structure* is part of the brand (a fixed set of pillars/phases, the team) | Developer / agent |

Git-owned sections render server-side from a PHP data file and carry **no block
attributes** — invisible in wp-admin, so there is no "Add New" for a client to
drift the structure against. Decide the bucket for each content type before
planning.

**Git-owned block data** goes `blocks/<slug>/<name>.php` returning a plain array
(e.g. `blocks/service-pillars/pillars.php`), **not** a theme top-level `data/`
dir — `DirectoryRulesTest::ALLOWED_THEME_DIRS` whitelists theme top-level dirs
and `data` is not on it. A *plugin*-level `data/` is fine; only the theme's top
level is whitelisted.

**No new root theme files.** `DirectoryRulesTest::ALLOWED_THEME_FILES`
whitelists them — there is no `front-page.php`, `page-<slug>.php`, or
`single-<cpt>.php`. Branch inside the existing `page.php` / `single.php`
delegates and put markup under `templates/`.

**Asset placement** (two things `AGENTS.md` doesn't say about the
`assets/global/` two-file cap): `assets/js/` is **unconstrained** — a shared
module or vendored runtime lib lives there (`assets/js/cinq-reveal.js`,
`assets/js/vendor/`). And `GlobalAssetRulesTest::test_block_specific_css_stays_inside_its_block()`
greps every theme stylesheet for each block slug, so **a block's slug may not
appear in any CSS outside `blocks/<slug>/`** — prefix block classes with the
slug and keep the rules local.

---

## 2. Guardrail collisions (each cost discovery time once; none needs a guardrail edit)

### `composer validate --strict` fails on the very first `verify:fast`

`scripts/rename-project` rewrites the composer package name but deliberately
skips `composer.lock` (`$skip_names` includes it), so the lock's content-hash
goes stale, and `verify:fast` opens with `composer validate --strict`. A freshly
renamed, otherwise-untouched copy fails before you write a line of code. Fix,
immediately after renaming, as its own commit:

```sh
ddev composer update --lock   # re-locks the hash; no dependency version changes
```

### Editorial copy naming a WooCommerce word breaks the base profile

`WooCommerceIsolationTest` scans **string literals**, not just symbols
(`ArchitectureScanner::find_symbol_references()` strips comments/docblocks but
keeps strings; `php_files()` reads `.php` only). An article titled *"Shopify vs
WooCommerce"* in a PHP data array is a violation. **Put editorial copy in
JSON/data, not a PHP array** — the loader's docblock can safely name it, since
docblocks are stripped from the scan.

### Vendored third-party code that must not be reformatted

Put it in a `vendor/` subfolder **inside the block**: `blocks/<slug>/vendor/`.
That one move makes the tooling skip it with zero config edits — the
`@wordpress/scripts` eslint config globally ignores `**/vendor/**`
(`node_modules/@wordpress/scripts/config/eslint.config.cjs`, spread by this
repo's `eslint.config.cjs`), `DirectoryRulesTest::all_directories()` skips
`vendor`/`node_modules`/`build` (so forbidden dir names *inside* the library
don't trip it), and `ArchitectureScanner::php_files()` skips the same three.
**Must be nested, not at the theme root** — `vendor` is not in
`ALLOWED_THEME_DIRS`, so a theme-level `vendor/` fails the whitelist.

---

## 3. Block script delivery — sharing one module, not a global bundle

`docs/adding-a-block.md` documents `viewScript` as a `file:` reference. Two
undocumented forms are verified working and let many blocks share one runtime
module instead of shipping a global bundle:

```jsonc
"viewScript": "cinq-reveal"                              // bare registered handle
"viewScript": [ "cinq-reveal", "file:./build/view.js" ]  // mixed array
```

Register (do not enqueue) the handle chain once in `ThemeBootstrap`, versioned
with `filemtime()`; blocks pull it in via `block.json`, so the dependency loads
only on pages that render such a block — satisfying `AGENTS.md`'s "no global JS
bundles" without duplicating the library per block.

**A `view.js` entry needs a build-script change.** `scripts/build-blocks.mjs`
discovers `blocks/*/index.js` and passes **only that entry** to `wp-scripts`, so
a block with a `view.js` source silently gets no `build/view.js`. Add the second
entry (pass both to `wp-scripts build` under the same `--output-path`); blocks
without a `view.js` keep byte-identical output. Keep watch mode single-entry.

**Lazy chunks work as-is** — webpack's default `publicPath: 'auto'` resolves
dynamic-import chunks relative to the block's own `build/`, so a heavy dependency
can be code-split behind an `import()` with no `__webpack_public_path__`
override. Verify by a real browser load, not just a green build — check the
chunk actually 200s.

---

## 4. WordPress behaviours that bite (independent of any project)

### Block-editor content gets un-slashed on write — escapes vanish

`wp_insert_post()` / `wp_update_post()` run `wp_unslash()` on everything handed
to them. If you serialize block attributes yourself (`serialize_block(s)`) and
hand the result to those functions, every `\uXXXX` / backslash escape the
serializer just wrote loses its backslash on the DB write, and the block comment
renders as literal `u003cp…` text on the front end. **`wp_slash()` the post
array before insert/update.** Reference: cinq-wp `PageCommands`
(`fix: slash page content so block attribute escapes survive the DB write`).

### Core rewrites `url()` inside inlined block-style data URIs

`_wp_normalize_relative_css_links()` (run over inlined block stylesheets)
short-circuits on `#`, `/`, `http:`, `data:` — but **not `%`**. So a
`filter='url(%23noise)'` reference inside an SVG data URI gets rewritten to a
relative theme path, the filter resolves to nothing, and an feTurbulence grain
`<rect>` falls back to its **default black fill** (a flat rectangle behind your
content). Fix: percent-encode the parentheses (`url%28%23noise%29`) so there is
no literal `url(` for the regex; the data URI still decodes correctly. Reference:
cinq-wp `blocks/hero-orbit/style.css`.

### Site search matches block delimiters

WordPress searches `post_content` with `LIKE`, and block-based content contains
`<!-- wp:paragraph -->` etc. So a search for `rag` matches every post (it is a
substring of "pa**rag**raph"); likewise `image`, `heading`, `quote`, `group`, and
your own block names. For an archive query, restrict search to title + excerpt
via `WP_Query`'s native `search_columns` var
(`['post_title','post_excerpt']`) — scoped to that one query, no hand-built SQL,
site-wide search untouched. Reference: cinq-wp `Contracts\Insights`.

### `?page=N` on a page is 301'd away by core

`page` is WordPress's own post-content pagination var (`<!--nextpage-->`). On a
static page with no page break, `redirect_canonical()` treats `?page=2` as a
mistake and 301s to the bare permalink — every custom-archive pagination link
breaks. Fix by dropping `page` from the query vars on the `request` filter when
the request targets a page that renders your archive (the filter runs before
`WP_Query`, so resolve the page from query vars, not the queried object). This
also silently breaks `#`-anchored links — see the anchor note below. Reference:
cinq-wp `Insights\ArchiveRouting`.

### Comments render by default and warn if the theme has no `comments.php`

Fresh posts are `comment_status: open`, and a `single` template that calls
`comments_template()` on a theme shipping no `comments.php` prints a core
deprecation notice above an unstyled comment form. If the site has no comments,
remove them properly — withdraw `comments`/`trackbacks` post-type support, force
`comments_open`/`pings_open` false, resolve the `default_comment_status` option
to `closed` (via `pre_option_*`, so a DB restore can't reopen it), unhook the
admin Comments menu/column, and drop the `comments_template()` call. Do **not**
add a `comments.php` to silence the notice. Reference: cinq-wp
`Discussion\CommentsDisabled`.

### Rewrites and term names

- Registering a CPT or taxonomy adds rewrite rules that don't exist until a
  flush — call `flush_rewrite_rules( false )` at the end of your seed / page-sync
  CLI commands, or `/<cpt>/<slug>/` 404s until someone saves permalinks by hand.
- **Term names are stored HTML-encoded.** `"Email &amp; CRM"` comes back
  encoded; a consumer that correctly `esc_html()`s it renders `Email &amp;amp;
  CRM`. Decode once at the boundary (`wp_specialchars_decode( $name, ENT_QUOTES
  )`).
- Derive per-term presentation (gradients, colors) from a Git-side map keyed by
  term slug, not free-text meta — keeps design tokens out of the database and out
  of user input.

### The block allow-list should be post-type aware

`EditorRestrictions::ALLOWED_BLOCKS` is one flat list returned regardless of post
type, so page-section blocks can be inserted into an article, where they render
nonsensically. Vary the returned set by `WP_Block_Editor_Context->post`'s post
type: page-composition blocks for `page`, content primitives for `post`/CPTs.
Keep it in `agency-platform` (it is one policy — "what may a customer insert, and
where"), keep administrators unaffected, and **extend**
`EditorRestrictionsPolicyTest`, never weaken it. Reference: cinq-wp
`EditorRestrictions`.

### Per-page structure lock is Git, not the editor

If the brief is "sections fixed, copy editable", **do not rely on `templateLock:
contentOnly`.** Verified against the classic editor: the setting arrives
(`getTemplateLock()` → `contentOnly`) but is not translated into structural
enforcement for existing dynamic-block page content — `getBlockEditingMode()`
stays `default` and `canRemoveBlock`/`canMoveBlock`/`canInsertBlockType` stay
permissive. The working arrangement is a Git manifest of the ordered sections
per route + an idempotent `wp <ns> sync-pages` reconcile command run on deploy
(add missing, drop removed, fix order, **preserve surviving sections'
attributes** so client copy edits are never clobbered). Keep the reconcile core
a pure array→array function so it unit-tests without WordPress. The manifest and
command belong in `site-core` (business rules; `post_content` block-name
*strings* are not a theme code-dependency, so deptrac is satisfied). Reference:
cinq-wp `Pages\PageManifest` / `PageSync` / `Cli\PageCommands`.

---

## 5. A couple of testing facts

- **Seed a block-preview page** — an idempotent `wp eval-file` script that keeps
  one published page containing every block built so far lets Playwright
  structure-test block markup and computed styles before real pages exist. The
  script is committed; the page it creates is environment content. **Gotcha:** no
  `declare(strict_types=1)` in a `wp eval-file` script — `eval()` makes it a
  fatal.
- **`#`-anchored links land at the top when a scroll-animation library runs on
  load.** ScrollTrigger-style libraries call `window.scrollTo(0,0)` while
  measuring on first refresh and restore a position cached *before* the browser's
  fragment jump, so `/page/#section` lands at `scrollY 0`. Re-apply the fragment
  target (`scrollIntoView()`, respecting `scroll-margin-top`) after the first
  refresh, skipped if the reader scrolled during load.
- **Committed Playwright visual baselines are Linux-CI-authoritative**
  (`playwright.config.ts`) and go stale the moment you restyle. Regenerate them
  through CI as a launch task — never commit baselines generated on a dev
  machine; browser font rendering differs.

---

## 6. Environment (Windows host + DDEV-in-WSL — host-specific; ignore on Linux/macOS)

`AGENTS.md` says "npm runs natively"; that is **not true** on this setup.

- PHP, Composer and WP-CLI exist only inside DDEV, which runs in WSL:
  `wsl -e bash -lc "cd /mnt/c/Users/<you>/Projects/<repo> && ddev composer verify:fast"`.
- **npm must also run under WSL** — an `npm ci` under WSL leaves POSIX-only
  symlink shims in `node_modules/.bin` (no `.cmd`), so native Windows npm can't
  execute them.
- **Playwright is the exception — run it from the Windows host**, since its
  browsers fail under WSL for missing system libs and the `.bin` shim is
  unusable: `node node_modules/@playwright/test/cli.js test tests/…`.
- **Commit from Git Bash on Windows** — WSL git has no identity configured.
- Mixed Windows/WSL git access causes EOL churn on the template dotfiles.
  `git config core.autocrlf false` helps; when they still show modified, stage
  only the files you actually changed (never `git checkout -- .` with an
  uncommitted edit present — it reverts your work along with the noise).
- The docroot on `/mnt/c` is served over a 9p mount; every page load and every
  test run pays a multi-second filesystem tax. Moving the project onto WSL ext4
  (or enabling DDEV Mutagen) removes it. Local-only — production never runs on
  9p.
