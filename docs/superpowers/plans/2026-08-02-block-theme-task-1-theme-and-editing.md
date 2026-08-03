# Block Theme Conversion & Client Site Editor (Release 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert `web/app/themes/site-theme` from a classic/hybrid PHP theme to a native WordPress block theme and give the `client_editor` / `client_shop_manager` roles full visual Site Editor control within the approved block system, with server-side save enforcement, an explicit admin-screen boundary, and two independent visual parity gates.

**Architecture:** The theme drops every classic file (root delegates, `header.php`/`footer.php`, `templates/*.php`, `parts/<name>/<name>.php`, `SiteTheme\Support\Parts`) and gains `templates/*.html` + `parts/*.html` + `theme.json.templateParts`. Header/footer visuals move from part-local CSS into a shared, editor-loaded stylesheet so the editor canvas and the frontend render identically. `agency-platform` deletes `SiteEditorLockdown`, grants `edit_theme_options`, denies `edit_css` and `customize` through `map_meta_cap`, replaces the fixed block allow-list with a registered-block namespace policy, adds server-side REST save validation, and enforces an explicit admin-screen deny list. Two Playwright parity suites (migration parity against immutable pre-migration baselines; editing parity between the Site Editor canvas and the frontend) gate the result.

**Tech Stack:** WordPress 7.0.2 (pinned by `composer.lock` via `roots/wordpress ^7.0`), PHP 8.3, Bedrock, DDEV, Composer scripts (`verify:fast`, `verify`), PHPUnit 9.6 (`architecture` / `unit` / `integration` / `commerce-integration` suites), `@wordpress/scripts` 33, Playwright 1.61, stylelint, ESLint, deptrac, PHPStan level 6, phpcs `WordPress-Extra`.

## Global Constraints

These apply to **every** task. They are copied from `BLOCK_THEME_PROPOSAL.md` §4 and from `AGENTS.md`.

- `web/app/themes/site-theme/functions.php` stays at most 50 lines and registers no hooks and contains no closures.
- Production hooks use named class methods, never closures (`tests/Architecture/HookOwnershipTest.php`).
- No `components/`, `layouts/`, `inc/`, `includes/`, `helpers/`, `misc/`, `common/`, `lib/`, `utils/` directory anywhere in the theme or any plugin.
- Every custom block keeps a valid `block.json`: name `agency/<folder>`, integer `apiVersion`, `file:` references that resolve inside the block directory.
- The theme may depend only on `SiteCore\Contracts\*`. `deptrac.yaml` declares `AgencyPlatform: []` — **any new class under `web/app/mu-plugins/agency-platform/src/` may depend on no project layer at all** (WordPress core and PHP only).
- Outbound HTTP stays inside `site-integrations/` and `site-commerce/src/Integrations/`.
- CSS colours in repository stylesheets must be `var(--wp--preset--color--*)` or `var(--wp--custom--*)`; raw hex/rgb fails stylelint's `scale-unlimited/declaration-strict-value`. (Colours a client picks in Global Styles land in the database as generated inline CSS — that is expected and is not governed by this rule.)
- CSS class names must satisfy stylelint's `selector-class-pattern`: `^[a-z0-9]+(-[a-z0-9]+)*(__[a-z0-9]+(-[a-z0-9]+)*)?(--[a-z0-9]+(-[a-z0-9]+)*)?$` (BEM, at most one element and one modifier segment).
- WooCommerce symbols (`WooCommerce`, `WC_*`, `wc_*`, `woocommerce_*`) may appear only in `site-commerce/`, `site-theme/woocommerce/`, `tests/commerce/`, or a reviewed entry in `tests/Architecture/woocommerce-allowlist.php`.
- `docs/generated-block-index.md` must byte-match `php scripts/generate-block-index` output (`tests/Architecture/GeneratedIndexFreshnessTest.php`).
- `web/app/themes/site-theme/assets/global/` holds exactly the files listed in `GlobalAssetRulesTest::ALLOWED_GLOBAL_CSS`; adding a file requires editing that constant deliberately.
- New PHP under `agency-platform` uses the `agency-platform` i18n text domain; theme PHP uses `site-theme` (`phpcs.xml`).
- PHP is run through DDEV: `ddev composer <script>`. npm runs natively. `ddev composer verify:fast` gates every commit.
- When npm dependencies change, regenerate the lock with `npx -y npm@10 install` (CI runs Node 22 / npm 10 and enforces `npm ci` lock sync).
- Do not add Elementor or any page builder, do not add an editing-mode switch, do not keep a parallel classic template path, do not add outbound HTTP to `agency-platform`, do not commit secrets or customer state.
- The final theme has exactly one rendering path.
- Visual screenshots are **Linux-CI-authoritative**. Never generate or commit a baseline PNG from Windows/macOS. Baselines are produced by the documented CI capture flow and committed from the uploaded artifact. Both image gates keep `workflow_dispatch` for maintainers **and** carry a narrow agent-runnable push trigger on the `ci-capture/**` branch namespace, so an agent session with Git push access can start either capture without a workflow-dispatch tool. Neither trigger commits a PNG automatically.

### Commit-gate policy

No commit in this plan lands with a gate that the plan already knows is red. Each commit **declares** the gates it is responsible for, and every declared gate must pass before that commit is made:

- Run `ddev composer verify:fast` immediately before **every** commit. Also run the default declared gates: `ddev composer verify` plus `npm run lint` for any commit touching JS or CSS.
- A commit that changes rendered markup also declares `npm run test:e2e` and `npm run test:accessibility`.
- A commit that changes rendered pixels also declares `npm run test:visual`.
- Exactly **six** commits use the narrow browser-gate exception: the Task 5 spike commit, Task 6 conversion commits 1, 2 and 3, the Task 7 commit, and the Task 8 commit. The exception starts at `spike: prove native block theme and editor styles`, covers only the time while the existing browser and visual assertions still name classic markup, and ends only when Task 9 commits the rewritten browser and visual suites. No other commit may omit a required browser gate.
- The exception covers **only** `npm run test:e2e` and `npm run test:visual`. It does **not** cover `npm run test:accessibility`, which asserts no classic selector and stays green across the conversion. From Task 7 onward every commit that changes rendered markup must run `npm run test:accessibility` and it must pass. Accessibility is the one browser gate that can still catch a landmark or contrast regression while the other two are blind, so it is never waived.
- Unit 1 owns all edits to `web/app/mu-plugins/agency-platform/src/Plugin.php` and `web/app/mu-plugins/agency-platform/src/Cli/AgencyCommands.php` in this release. Units 2 and 3 may change `Plugin.php` only by adding their one sequenced provider-registration line at the position this plan leaves for it. They must not reformat, reorder, or otherwise edit either file.
- Unit 1 owns the Release 1 capability-matrix change in `tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php`. Unit 4A retains ownership of all other commerce test and profile work.
- Unit 1 documentation is limited to `AGENTS.md`, `docs/architecture.md`, `docs/editing-strictness.md`, `docs/ownership-rules.md`, `docs/adding-a-block.md`, `docs/validation-scenarios.md`, `docs/generated-block-index.md`, and `docs/block-theme-migration-baseline.md`. Unit 0 owns the `README.md` repair. Unit 4B owns `README.md`, `ops/**`, the state runbook, proof records, and the final documentation and operations sweep.
- Before the branch is offered for review, every suite in the Definition of done must be green.

## Decisions this plan fixes (the spec leaves these open)

1. **The Appearance menu is removed, not repointed.** `AdminScreenPolicy` removes the whole `themes.php` top-level menu for non-`manage_options` users and adds a single top-level **Design** entry that links straight to `site-editor.php`. This keeps `#adminmenu li#menu-appearance` absent, so the two existing assertions (`tests/e2e/editor-permissions.spec.ts` and `tests/commerce/e2e/shop-manager-admin.spec.ts`) stay true with no edit, and it removes Themes / Customize / Menus / Widgets from the client's menu in one move.
2. **`font-library.php` is allowed.** It is gated on `edit_theme_options`, it is the Site Editor's own font surface, and §5.4/§7.6 keep Font Library records database-owned. It is not in the deny list.
3. **Save validation is enforced on `rest_pre_insert_{$post_type}` only.** Every block-editor and Site Editor save goes through REST in WordPress 7.0, and only that filter can return a `WP_Error` that becomes a clean REST error. Programmatic `wp_insert_post()` (WP-CLI, importers, tests) is deliberately not gated — that is agency-side tooling, not client input. This is documented in `docs/editing-strictness.md`.
4. **`woocommerce` is in the default allowed-namespace list unconditionally.** When WooCommerce is absent no `woocommerce/*` block is registered, so the resolved list is identical; gating on `class_exists( 'WooCommerce' )` would put a WooCommerce symbol into `agency-platform` for zero benefit and would need a `woocommerce-allowlist.php` entry.
5. **Hand-authored block markup carries no inline `style` attribute.** Static-save blocks (`core/group`, `core/heading`, `core/paragraph`, `core/columns`, `core/buttons`) serialise their `style` attribute into the saved HTML; getting those strings wrong by hand produces "unexpected or invalid content" in the editor. All colour/spacing/border for Git-authored templates and parts therefore lives in `theme.json` and in `assets/global/shared.css`, keyed off `className`. Blocks a client adds later may carry inline styles — that is database state, not a Git file.
6. **Migration parity covers only pages that exist before and after the migration** — `/` and `/sample-page/`. The Phase 1 demo page is new, so it has no "old" counterpart; it is covered by editing parity instead.
7. **`reference-landing-section` stays `templateLock: contentOnly`.** It is the repository's documented example of a locked pattern and its purpose is to demonstrate locking. The five new patterns are unlocked. `tests/e2e/locked-pattern.spec.ts` therefore needs no change.
8. **Parity determinism** comes from pinning the browser job to `ubuntu-24.04`, installing `fonts-dejavu-core`, and injecting a fixed `DejaVu Sans` / `DejaVu Serif` font stack into both sides of every parity comparison. No font binary is committed, so no licence review is required.
9. **Migration baselines are compared with `pixelmatch`/`pngjs`, never `toHaveScreenshot()`**, so `--update-snapshots` can never regenerate them.
10. **Only ONE layer emits each semantic element.** `render_block_core_template_part()` (`web/wp/wp-includes/blocks/template-part.php:170-181`) always wraps the part's content in a tag — `tagName` when set, otherwise the area's `area_tag`, which for the `header`/`footer` areas is `<header>`/`<footer>`. If the part FILE also opened a `core/group` with `tagName`, every page would ship nested `<header><header>`, which is invalid landmark structure and fails accessibility review. So: the **template** carries `{"tagName":"header","area":"header","className":"site-header"}` on the `core/template-part` block, and the **part file** opens a plain `core/group` with `className:"site-header__inner"`. Same for the footer. Selector consequence: `header.site-header` exists on the frontend and in a *page/template* canvas; when a client edits the part on its own (`site-editor.php?p=/wp_template_part/...`) only `.site-header__inner` is present, and the part-only tests target that.
11. **Header and footer flex layout lives in `assets/global/shared.css`, not in a block `layout` attribute.** A `core/group` with `layout:{type:flex}` makes WordPress generate a `.wp-container-core-group-is-layout-*` rule whose specificity fights our own stylesheet and whose exact class name is unstable. Because `shared.css` is loaded into the editor canvas as well as the frontend, plain CSS on `.site-header__inner` / `.site-footer__inner` renders identically in both and stays fully under our control. This is the same reasoning as Decision 5.
12. **Global Styles writes need their own guard.** `WP_REST_Global_Styles_Controller` extends `WP_REST_Posts_Controller` but overrides `prepare_item_for_database()` (`class-wp-rest-global-styles-controller.php:238`) and applies **no** `rest_pre_insert_wp_global_styles` filter — verified, the file contains no `apply_filters()` call at all. So `SaveValidation` cannot see a Global Styles write. `GlobalStylesGuard` hooks `rest_pre_dispatch` (`class-wp-rest-server.php:1079`, returning non-null short-circuits the request) and rejects root `styles.css`, per-block `styles.blocks.*.css`, per-element `styles.elements.*.css`, and variation CSS for client roles.
13. **The Phase 0 spike is a real, separate commit.** `locate_block_template()` (`web/wp/wp-includes/block-template.php:62-92`) keeps a PHP template found by `locate_template()` as a fallback and only considers block templates of **equal or higher** specificity, and `wp_enable_block_templates()` (`theme-templates.php:132-141`) has already added `block-templates` support to this theme because it ships a `theme.json`. So `templates/index.html` alone changes only the requests whose hierarchy bottoms out at `index` — every `page.php`/`single.php`/`archive.php`/`search.php`/`404.php` request still renders classically. The spike genuinely coexists, exactly as §8.2 says, and it ships as its own commit with its own §8.3 gate.
14. **Navigation resolution is "most recently published", not "exactly one".** `WP_Navigation_Fallback::get_most_recently_published_navigation()` queries `wp_navigation` with `orderby => date`, `order => DESC`, `posts_per_page => 1` and returns the first non-empty result. Seeding is therefore made deterministic by creating or updating one record named `Primary`, not by asserting no other record can exist.
15. **Every commit passes its own gates.** No commit in this plan lands with a gate the plan already knows is red. That is what drives the task split: CSS first (frontend unchanged), then conversion **together with** the seeding and browser-spec updates it invalidates, then the classic-path deletion (a no-op for rendering, because equal-specificity block templates already win).

---

## Task 1: Phase 0 baseline record and classic-path inventory

**Files:**
- Create: `docs/block-theme-migration-baseline.md`

**Interfaces:**
- Consumes: nothing.
- Produces: `docs/block-theme-migration-baseline.md` — the recorded Phase 0 evidence every later task's "was this already broken?" question resolves against.

- [ ] **Step 1: Verify the orchestrator-created task worktree**

```bash
git branch --show-current
git status --porcelain
git merge-base --is-ancestor feat/block-theme-fse-migration HEAD
```

Expected: the current branch is `feat/bt-task-1-theme-and-editing`, status is empty, and the ancestry check exits 0. Unit 0 in the tracking plan creates and pushes the integration branch before this task starts. Do not create branches from a dirty main checkout inside this plan.

- [ ] **Step 2: Confirm Unit 0 repaired the stray paste in README.md**

Confirm the first playbook item reads:

```
1. **New client** → click **"Use this template"** on GitHub …
```

Unit 0 owns this separate repair. If the fragment `Projects\wordpress-template\BLOCK_THEME_PROPOSAL.md` is still present, STOP and return the failed readiness check to the orchestrator. Do not repair or commit it from the Task 1 branch.

- [ ] **Step 3: Run every Phase 0 baseline command and capture its output**

Run each of these and keep the full output in a scratch file outside the repository — do not commit raw logs:

```bash
ddev composer verify:fast
ddev composer verify
npm ci
npm run lint
npm run build
npm run test:e2e
npm run test:visual
npm run test:accessibility
```

If a command already fails on `main`, record the failure verbatim. Do not fix unrelated failures and do not hide them.

- [ ] **Step 4: Write the baseline record**

Create `docs/block-theme-migration-baseline.md` with exactly these sections:

1. `# Block theme migration — Phase 0 baseline`, plus the commit SHA the baseline was taken at.
2. `## Baseline command results` — a table with columns `Command | Result | Notes`, one row per command from Step 3.
3. `## Classic rendering path inventory` — the exact file list Phase 3 deletes:
   - Root delegates: `404.php`, `archive.php`, `index.php`, `page.php`, `search.php`, `single.php`
   - Root chrome: `header.php`, `footer.php`
   - PHP templates: `templates/404.php`, `templates/archive.php`, `templates/index.php`, `templates/page.php`, `templates/search.php`, `templates/single.php`
   - PHP parts: `parts/site-header/site-header.php`, `parts/site-header/site-header.css`, `parts/site-header/site-header.js`, `parts/site-footer/site-footer.php`, `parts/site-footer/site-footer.css`
   - Support class: `src/Support/Parts.php`
   - Global CSS being replaced: `assets/global/base.css`, `assets/global/typography.css`
4. `## Patterns and custom blocks` — `patterns/reference-landing-section.php`; block `agency/reference-callout` at `blocks/reference-callout/` (`block.json`, `index.js`, `build/index.js`, `render.php`, `style.css`, `editor.css`, `README.md`).
5. `## Tests coupled to the classic path` — for each entry, the specific assertion that breaks:
   - `tests/Architecture/DirectoryRulesTest.php` — `ALLOWED_THEME_FILES` lists the six root delegates plus `header.php`/`footer.php`.
   - `tests/Architecture/ThemeBootstrapTest.php` — `test_each_template_has_a_thin_root_delegate`, `test_every_root_delegate_maps_to_a_template`.
   - `tests/Architecture/GlobalAssetRulesTest.php` — `ALLOWED_GLOBAL_CSS = array( 'base.css', 'typography.css' )`, `test_part_assets_are_named_after_their_part`.
   - `tests/Unit/SiteTheme/PartsTest.php` — the whole file (only validates `SiteTheme\Support\Parts`).
   - `tests/e2e/smoke.spec.ts` — `header.site-header`, `.site-header__site-title`, `footer.site-footer`, `main#site-main`, `#site-header-nav`, `.site-header__toggle`, `.is-open`.
   - `tests/visual/__screenshots__/chromium-desktop/home-desktop.png` and `.../chromium-mobile/home-mobile.png` — must be regenerated after conversion.
   - `tests/support/BlockIndexGenerator.php` — `files_containing()` filters to `.php`, so `.html` templates are invisible to the generated index.
6. `## Role, lockdown and database-override tests` —
   - `tests/Integration/Permissions/ClientEditorCapabilitiesTest.php` (`never_grant_capabilities()` includes `edit_theme_options`; `test_client_editor_is_restricted_to_the_approved_block_allow_list` asserts `assertSame( EditorRestrictions::ALLOWED_BLOCKS, ... )`; `test_code_editing_and_block_locking_are_disabled_for_client_editor` asserts `canLockBlocks === false`).
   - `tests/Unit/AgencyPlatform/EditorRestrictionsPolicyTest.php` (all eight methods key off `EditorRestrictions::ALLOWED_BLOCKS`).
   - `tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php` (`test_shop_manager_cannot_reach_the_keys_to_the_kingdom` includes `edit_theme_options`).
   - `tests/Unit/AgencyPlatform/DatabaseOverrideClassifyTest.php` (13 methods; unchanged by this plan — a later engagement task replaces the detection internals).
7. `## Commerce-specific tests (§8.1)` — inventory them, and change none of them. For each, state whether this plan touches it:
   - `tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php` — **touched** (Task 3 Step 13: `edit_theme_options` moves from the negative list to a positive Site Editor inheritance test, per §11.9).
   - `tests/commerce/Integration/Health/CommerceSanitizeStepTest.php` — untouched.
   - `tests/commerce/Integration/SiteCommerce/PluginBootTest.php` — untouched.
   - `tests/commerce/Integration/bootstrap.php` — untouched.
   - `tests/commerce/e2e/commerce-journey.spec.ts` — untouched. Note that it drives the CLASSIC storefront cart/checkout markup that `scripts/enable-commerce` seeds; converting that to native Cart/Checkout blocks is the commerce track's job, not this plan's.
   - `tests/commerce/e2e/shop-manager-admin.spec.ts` — untouched, and deliberately so: `AdminScreenPolicy` removes the whole Appearance menu, so its `expectNoAdminMenu( page, 'menu-appearance' )` assertion stays true (Decision 1).
   - `tests/Unit/SiteCommerce/CommerceSanitizeStepTest.php` and `tests/Unit/SiteCommerce/PluginGuardTest.php` — untouched.
   - `tests/commerce/README.md` — untouched.
8. `## Setup and CI assumptions that break` —
   - `scripts/setup` step 9/9 runs `wp menu create "Primary"`, `wp menu item add-custom primary ...`, `wp menu item add-post primary ...`, `wp menu location assign primary primary`. A block theme registers no nav-menu locations, so `wp menu location assign` fails.
   - `scripts/setup`'s refusal message claims it creates "pretty permalinks", but no `wp rewrite structure` call exists in the script.
   - `.github/workflows/ci.yml`'s `e2e` job has the same "Create primary navigation menu" step.

- [ ] **Step 5: Commit**

```bash
ddev composer verify:fast
git add docs/block-theme-migration-baseline.md
git commit -m "test: capture block-theme migration expectations"
```

---

## Task 2: Parity harness and immutable pre-migration baselines

This task must land **before** any `templates/*.html` exists: the moment `templates/index.html` is committed, `wp_is_block_theme()` becomes true and WordPress stops using the classic hierarchy, so the old frontend can no longer be photographed.

**Files:**
- Create: `tests/parity/helpers/parity.ts`
- Create: `tests/parity/migration-baseline.capture.spec.ts`
- Create: `tests/parity/__migration_baselines__/metadata.json`
- Create: `tests/Architecture/MigrationBaselineGuardTest.php`
- Modify: `playwright.config.ts`
- Modify: `package.json`, `package-lock.json`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `tests/parity/helpers/parity.ts` exporting `ParityPage`, `MIGRATION_PARITY_PAGES`, `EDITING_PARITY_PAGES`, `PARITY_FONT_CSS`, `MIGRATION_BASELINE_DIR`, `applyParityFonts()`, `captureFrontend()`, `compareToBaseline()`.
  - Playwright projects `parity-desktop` (1440×900) and `parity-mobile` (390×844).
  - npm scripts `test:parity` and `capture:migration-baseline`.

- [ ] **Step 1: Add the pixel-comparison dependencies**

```bash
npm install --save-dev pixelmatch@^6.0.0 pngjs@^7.0.0 @types/pngjs@^6.0.5
npx -y npm@10 install
```

`pixelmatch` and `pngjs` are required because migration baselines must **not** be Playwright snapshots — a Playwright snapshot is regenerable with `--update-snapshots`, which would let a regression be papered over.

- [ ] **Step 2: Write the parity helper**

Create `tests/parity/helpers/parity.ts`:

```ts
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import type { Frame, Page } from '@playwright/test';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';

export type ParityPage = {
	/** File-safe identifier; also the baseline PNG basename. */
	name: string;
	/** Site-relative path, always with a leading slash. */
	path: string;
	/** Maximum allowed differing-pixel ratio, 0..1. */
	maxDiffRatio: number;
	/** Selectors painted over on BOTH sides before comparison. */
	maskSelectors: string[];
	/** Maximum per-edge bounding-box delta for header/footer geometry. */
	maxEdgeDeltaPx: number;
};

export const MIGRATION_BASELINE_DIR = resolve( __dirname, '..', '__migration_baselines__' );

/**
 * Both sides of every parity comparison are forced onto one libre font that
 * ships with the pinned ubuntu-24.04 runner (fonts-dejavu-core). No font
 * binary is committed, so no licence review is required, and font fallback
 * differences can never produce a false parity failure.
 */
export const PARITY_FONT_CSS = `
	*, *::before, *::after {
		font-family: "DejaVu Sans", sans-serif !important;
		font-synthesis: none !important;
	}
	code, pre, kbd, samp {
		font-family: "DejaVu Sans Mono", monospace !important;
	}
`;

export const MIGRATION_PARITY_PAGES: ParityPage[] = [
	{ name: 'home', path: '/', maxDiffRatio: 0.05, maskSelectors: [], maxEdgeDeltaPx: 8 },
	{ name: 'sample-page', path: '/sample-page/', maxDiffRatio: 0.05, maskSelectors: [], maxEdgeDeltaPx: 8 },
];

export const EDITING_PARITY_PAGES: ParityPage[] = [
	{ name: 'demo', path: '/demo/', maxDiffRatio: 0.05, maskSelectors: [], maxEdgeDeltaPx: 8 },
	{ name: 'sample-page', path: '/sample-page/', maxDiffRatio: 0.05, maskSelectors: [], maxEdgeDeltaPx: 8 },
];

export async function applyParityFonts( target: Page | Frame ): Promise< void > {
	await target.addStyleTag( { content: PARITY_FONT_CSS } );
}

export async function captureFrontend(
	page: Page,
	path: string,
	maskSelectors: string[]
): Promise< Buffer > {
	await page.goto( path, { waitUntil: 'networkidle' } );
	await applyParityFonts( page );
	await page.evaluate( () => document.fonts.ready );
	return page.screenshot( {
		fullPage: true,
		animations: 'disabled',
		caret: 'hide',
		mask: maskSelectors.map( ( selector ) => page.locator( selector ) ),
	} );
}

export function compareToBaseline(
	actual: Buffer,
	baselinePath: string,
	maxDiffRatio: number,
	diffOutPath: string
): { diffRatio: number } {
	const expectedPng = PNG.sync.read( readFileSync( baselinePath ) );
	const actualPng = PNG.sync.read( actual );

	const width = Math.min( expectedPng.width, actualPng.width );
	const height = Math.min( expectedPng.height, actualPng.height );
	const diff = new PNG( { width, height } );

	const differing = pixelmatch(
		cropTo( expectedPng, width, height ).data,
		cropTo( actualPng, width, height ).data,
		diff.data,
		width,
		height,
		{ threshold: 0.2 }
	);

	// A size mismatch counts as difference, so a taller or shorter page cannot
	// pass by comparing only the overlapping region.
	const maxArea = Math.max(
		expectedPng.width * expectedPng.height,
		actualPng.width * actualPng.height
	);
	const diffRatio = ( differing + ( maxArea - width * height ) ) / maxArea;

	if ( diffRatio > maxDiffRatio ) {
		mkdirSync( dirname( diffOutPath ), { recursive: true } );
		writeFileSync( diffOutPath, PNG.sync.write( diff ) );
	}

	return { diffRatio };
}

function cropTo( source: PNG, width: number, height: number ): PNG {
	if ( source.width === width && source.height === height ) {
		return source;
	}
	const cropped = new PNG( { width, height } );
	PNG.bitblt( source, cropped, 0, 0, width, height, 0, 0 );
	return cropped;
}
```

- [ ] **Step 3: Write the capture spec**

Create `tests/parity/migration-baseline.capture.spec.ts`:

```ts
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from '@playwright/test';
import { MIGRATION_BASELINE_DIR, MIGRATION_PARITY_PAGES, captureFrontend } from './helpers/parity';

/**
 * Writes the IMMUTABLE pre-migration baselines. Deliberately writes PNGs with
 * fs.writeFileSync instead of toHaveScreenshot(), so `--update-snapshots` can
 * never regenerate them: regenerating a migration baseline requires running
 * this spec explicitly with CAPTURE_MIGRATION_BASELINE=1 against the classic
 * theme, which no longer exists after the migration lands.
 */
test.describe( 'migration baseline capture', () => {
	test.skip(
		process.env.CAPTURE_MIGRATION_BASELINE !== '1',
		'capture-only spec — set CAPTURE_MIGRATION_BASELINE=1 to run'
	);

	for ( const parityPage of MIGRATION_PARITY_PAGES ) {
		test( `capture ${ parityPage.name }`, async ( { page }, testInfo ) => {
			const buffer = await captureFrontend( page, parityPage.path, parityPage.maskSelectors );
			const dir = join( MIGRATION_BASELINE_DIR, testInfo.project.name );
			mkdirSync( dir, { recursive: true } );
			writeFileSync( join( dir, `${ parityPage.name }.png` ), buffer );
		} );
	}
} );
```

- [ ] **Step 4: Write the metadata sidecar**

Create `tests/parity/__migration_baselines__/metadata.json`. The `TO-BE-FILLED-BY-CAPTURE-RUN` values are replaced in Step 9:

```json
{
  "capturedAtCommit": "TO-BE-FILLED-BY-CAPTURE-RUN",
  "capturedAtUtc": "TO-BE-FILLED-BY-CAPTURE-RUN",
  "runnerImage": "ubuntu-24.04",
  "browser": "chromium",
  "browserVersion": "TO-BE-FILLED-BY-CAPTURE-RUN",
  "playwrightVersion": "TO-BE-FILLED-BY-CAPTURE-RUN",
  "fontStack": "DejaVu Sans",
  "fontPackage": "fonts-dejavu-core",
  "contentFixtures": [
    "WordPress core install defaults: Sample Page (slug sample-page), Hello world! post",
    "One classic nav menu assigned to the primary location (scripts/setup step 9)"
  ],
  "projects": {
    "parity-desktop": { "viewport": { "width": 1440, "height": 900 }, "deviceScaleFactor": 1 },
    "parity-mobile": { "viewport": { "width": 390, "height": 844 }, "deviceScaleFactor": 1 }
  },
  "pages": [
    { "name": "home", "path": "/", "maxDiffRatio": 0.05, "maskSelectors": [] },
    { "name": "sample-page", "path": "/sample-page/", "maxDiffRatio": 0.05, "maskSelectors": [] }
  ]
}
```

- [ ] **Step 5: Add the Playwright parity projects**

In `playwright.config.ts`, replace the `projects` array with the block below. `testDir`, `snapshotPathTemplate`, `use`, and `expect` stay exactly as they are.

```ts
	projects: [
		{
			name: 'chromium-desktop',
			testIgnore: /[\\/]parity[\\/]/,
			use: { ...devices[ 'Desktop Chrome' ], viewport: { width: 1280, height: 800 } },
		},
		{
			name: 'chromium-mobile',
			testIgnore: /[\\/]parity[\\/]/,
			use: { ...devices[ 'Pixel 7' ] },
		},
		// Parity projects use the exact viewports BLOCK_THEME_PROPOSAL.md §9.6
		// mandates, and run ONLY the parity specs.
		{
			name: 'parity-desktop',
			testMatch: /[\\/]parity[\\/].*\.spec\.ts$/,
			use: {
				...devices[ 'Desktop Chrome' ],
				viewport: { width: 1440, height: 900 },
				deviceScaleFactor: 1,
			},
		},
		{
			name: 'parity-mobile',
			testMatch: /[\\/]parity[\\/].*\.spec\.ts$/,
			use: {
				...devices[ 'Desktop Chrome' ],
				viewport: { width: 390, height: 844 },
				deviceScaleFactor: 1,
				hasTouch: true,
			},
		},
	],
```

- [ ] **Step 6: Add the npm scripts**

In `package.json`, add to `scripts`:

```json
"test:parity": "playwright test tests/parity --project=parity-desktop --project=parity-mobile",
"capture:migration-baseline": "playwright test tests/parity/migration-baseline.capture.spec.ts --project=parity-desktop --project=parity-mobile"
```

- [ ] **Step 7: Wire the capture flow into CI as a job that nothing gates**

The capture must be runnable on a commit where the classic frontend still exists. It therefore cannot live in the `e2e` job: that job declares `needs: [php-qa, frontend]`, so a single red PHP check anywhere in the repository would make the baselines uncapturable. It gets its own job with **no `needs:`**.

Both image gates must be startable by a maintainer **and** by an agent session that has Git push access but no workflow-dispatch tool. So each gate keeps its `workflow_dispatch` input and gains a narrow push trigger on a dedicated, single-purpose branch namespace. A capture branch is disposable: it is pushed, it produces an artifact, and it is deleted. It is never merged.

In `.github/workflows/ci.yml`:

0. Widen the push trigger to the capture namespace, and nothing else:

```yaml
on:
  push:
    branches:
      - main
      - 'ci-capture/**'
  pull_request:
  workflow_dispatch:
```

1. Add a second `workflow_dispatch` input beside `update_visual_snapshots`:

```yaml
      capture_migration_baselines:
        description: >-
          Capture the IMMUTABLE pre-migration parity baselines
          (tests/parity/__migration_baselines__) against the classic theme and
          upload them as the `migration-baselines` artifact. Run this ONCE,
          against a commit where the classic theme still renders. Nothing is
          committed automatically.
        type: boolean
        default: false
```

2. Change the `e2e` job's `runs-on: ubuntu-latest` to `runs-on: ubuntu-24.04`, and add a font step immediately after "Set up DDEV":

```yaml
      - name: Install deterministic parity fonts
        run: sudo apt-get update && sudo apt-get install -y fonts-dejavu-core
```

3. Add a new job at the end of the file. It deliberately declares **no `needs:`** and only ever runs on the manual trigger, so it costs nothing on push/pull_request and cannot be blocked by an unrelated failing check:

```yaml
  # 6. One-shot capture of the IMMUTABLE pre-migration parity baselines.
  #    Manual trigger only, and deliberately gated on NOTHING: the baselines
  #    photograph a frontend that stops existing after the block-theme
  #    conversion, so an unrelated red check in php-qa or frontend must never
  #    be able to prevent the capture. Pinned to the same runner image and the
  #    same font package as the e2e job, because a parity baseline is only
  #    comparable against a run with identical font rendering.
  migration-baseline-capture:
    name: Capture migration parity baselines (manual or ci-capture ref)
    if: >-
      (github.event_name == 'workflow_dispatch' && inputs.capture_migration_baselines == true)
      || github.ref == 'refs/heads/ci-capture/migration-baselines'
    runs-on: ubuntu-24.04
    steps:
      - name: Checkout
        uses: actions/checkout@v7

      - name: Set up DDEV
        uses: ddev/github-action-setup-ddev@v1

      - name: Install deterministic parity fonts
        run: sudo apt-get update && sudo apt-get install -y fonts-dejavu-core

      - name: Start DDEV
        run: ddev start

      - name: Create .env for CI
        run: |
          cp .env.example .env
          sed -i 's/^WP_ENV=.*/WP_ENV=development/' .env
          sed -i 's#^WP_HOME=.*#WP_HOME=https://agency-starter.ddev.site#' .env

      - name: Install PHP dependencies (inside DDEV)
        run: ddev composer install --no-interaction --prefer-dist

      - name: Install WordPress core
        run: |
          ddev wp core install \
            --url=https://agency-starter.ddev.site \
            --title="Agency Starter Baseline Capture" \
            --admin_user=admin \
            --admin_password=admin \
            --admin_email=admin@example.invalid \
            --skip-email

      - name: Activate theme and base-profile plugins
        run: |
          ddev wp theme activate site-theme
          ddev wp plugin activate site-core site-integrations

      - name: Seed the site exactly as scripts/setup does today
        run: |
          ddev wp menu create "Primary"
          ddev wp menu item add-custom primary "Home" https://agency-starter.ddev.site/
          ddev wp menu item add-post primary "$(ddev wp post list --post_type=page --name=sample-page --field=ID)"
          ddev wp menu location assign primary primary
          ddev wp rewrite structure '/%postname%/' --hard

      - name: Set up Node 22
        uses: actions/setup-node@v7
        with:
          node-version: '22'
          cache: npm

      - name: Install Node dependencies
        run: npm ci

      - name: Install Playwright browser (Chromium)
        run: npx playwright install --with-deps chromium

      - name: Capture the baselines
        env:
          WP_BASE_URL: https://agency-starter.ddev.site
          CAPTURE_MIGRATION_BASELINE: '1'
        run: npm run capture:migration-baseline

      - name: Record the capture environment
        run: |
          {
            echo "capturedAtCommit=${GITHUB_SHA}"
            echo "capturedAtUtc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
            echo "playwrightVersion=$(npx playwright --version)"
            echo "browserVersion=$(node -e 'const { chromium } = require("playwright"); chromium.launch().then(async b => { process.stdout.write(b.version()); await b.close(); })')"
          } > tests/parity/__migration_baselines__/capture-environment.txt
          cat tests/parity/__migration_baselines__/capture-environment.txt

      - name: Upload migration parity baselines
        uses: actions/upload-artifact@v7
        with:
          name: migration-baselines
          path: tests/parity/__migration_baselines__
          if-no-files-found: error
```

The `browserVersion` command launches the installed Chromium build and records its real version. Do not leave `capture-environment.txt` in the repository. Step 9 copies its values into `metadata.json` and deletes it.

4. Give the **visual-baseline** gate the same dual trigger. In the `e2e` job, the two regeneration steps gain the capture ref and the two normal-path steps exclude it. Change all four `if:` expressions:

```yaml
      - name: Regenerate visual baselines (manual or ci-capture ref)
        if: >-
          (github.event_name == 'workflow_dispatch' && inputs.update_visual_snapshots == true)
          || github.ref == 'refs/heads/ci-capture/visual-baselines'

      - name: Upload regenerated visual baselines
        if: >-
          (github.event_name == 'workflow_dispatch' && inputs.update_visual_snapshots == true)
          || github.ref == 'refs/heads/ci-capture/visual-baselines'

      - name: Run visual regression suite
        if: >-
          !((github.event_name == 'workflow_dispatch' && inputs.update_visual_snapshots == true)
          || github.ref == 'refs/heads/ci-capture/visual-baselines')
          && steps.visual_baselines.outputs.exists == 'true'

      - name: Skip visual regression (no committed baselines yet)
        if: >-
          !((github.event_name == 'workflow_dispatch' && inputs.update_visual_snapshots == true)
          || github.ref == 'refs/heads/ci-capture/visual-baselines')
          && steps.visual_baselines.outputs.exists == 'false'
```

Keep the existing three-path comment block above those steps and extend it to name the `ci-capture/visual-baselines` ref, so the reason each path exists stays readable.

5. Keep the migration-capture push cheap. `migration-baseline-capture` deliberately has no `needs:`, so it cannot be blocked; the two DDEV browser jobs, however, would burn a full WordPress boot for nothing on that ref. Add to **both** the `e2e` job and the `commerce-e2e` job:

```yaml
    if: github.ref != 'refs/heads/ci-capture/migration-baselines'
```

Do not add that guard to `php-qa`, `frontend`, or `integration`: they are cheap, they are real signal on the exact commit being photographed, and `migration-baseline-capture` does not depend on them.

6. Do not widen the trigger any further. `ci-capture/**` is the only added push namespace, no capture branch is ever merged, and no job commits a PNG. A capture branch is deleted with `git push origin --delete <ref>` once its artifact is downloaded and accepted.

- [ ] **Step 8: Commit the harness (green: no guard test yet)**

Run first, and require green:

```bash
ddev composer verify:fast
npm run lint
```

Expected: PASS. Nothing added so far can fail a gate — the parity specs are skipped without `CAPTURE_MIGRATION_BASELINE=1`, and the guard test does not exist yet. That ordering is deliberate: the guard test asserts the baselines are on disk, so writing it before the capture would commit a known-red architecture suite and, worse, would make `php-qa` red and block every other job.

```bash
ddev composer verify:fast
git add tests/parity playwright.config.ts package.json package-lock.json .github/workflows/ci.yml
git commit -m "test: add the migration parity harness and capture job"
git push -u origin feat/bt-task-1-theme-and-editing
```

- [ ] **Step 9: ORCHESTRATOR GATE — capture the baselines in CI and commit them**

The orchestrator performs this gate itself. It starts the run, downloads the artifact, and opens every PNG. Do not start the block-theme conversion until this gate passes. If the session can neither start the run nor inspect the PNG files, stop and report that exact blocker.

1. Start the capture on the task commit by **either** path, whichever the session actually has:
   - **Workflow dispatch** — the GitHub UI's **Run workflow** on the task branch with `capture_migration_baselines = true`, or the REST equivalent `POST /repos/{owner}/{repo}/actions/workflows/ci.yml/dispatches` with `{"ref":"<branch>","inputs":{"capture_migration_baselines":"true"}}` and a token carrying the `workflow` scope.
   - **Agent push trigger** — push the exact commit to the capture ref: `git push origin HEAD:refs/heads/ci-capture/migration-baselines`. This needs only Git push access. Delete the ref after the artifact is accepted.

   Either path runs `migration-baseline-capture` on that commit and nothing else that matters.
2. Download the `migration-baselines` artifact (`GET /repos/{owner}/{repo}/actions/runs/{run_id}/artifacts`, then the `archive_download_url` zip).
3. Unpack it over `tests/parity/__migration_baselines__/`.
4. Open `capture-environment.txt`, copy `capturedAtCommit`, `capturedAtUtc`, `playwrightVersion`, and `browserVersion` into `metadata.json`, then **delete `capture-environment.txt`**.
5. Confirm `metadata.json` contains no remaining `TO-BE-FILLED-BY-CAPTURE-RUN` value and that four PNGs exist (`parity-desktop/home.png`, `parity-desktop/sample-page.png`, `parity-mobile/home.png`, `parity-mobile/sample-page.png`).
6. Look at all four by eye. They must show the classic header, the classic navigation and the classic footer — if any shows a block-theme render, the capture ran on the wrong commit and must be redone.

```bash
ddev composer verify:fast
git add tests/parity/__migration_baselines__
git commit -m "test: record the immutable pre-migration parity baselines"
```

- [ ] **Step 10: Write the guard test**

Now that the baselines exist, add the test that stops anything regenerating them. Create `tests/Architecture/MigrationBaselineGuardTest.php`:

```php
<?php
/**
 * Protects the IMMUTABLE migration-parity baselines. These PNGs photograph a
 * frontend that no longer exists once the classic theme is deleted, so nothing
 * in the repository may be able to silently regenerate them — in particular,
 * `playwright test --update-snapshots` must have no path to them.
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\FormatsArchitectureFailures;

require_once dirname( __DIR__ ) . '/support/FormatsArchitectureFailures.php';

final class MigrationBaselineGuardTest extends TestCase {

	use FormatsArchitectureFailures;

	private const BASELINE_RELATIVE = 'tests/parity/__migration_baselines__';
	private const DEFAULT_MAX_DIFF_RATIO = 0.05;

	/**
	 * @return array<string, mixed>
	 */
	private function metadata(): array {
		$path = $this->repo_root() . '/' . self::BASELINE_RELATIVE . '/metadata.json';

		self::assertFileExists( $path, 'Migration baselines must carry a metadata.json sidecar.' );

		$decoded = json_decode( $this->read( $path ), true );

		self::assertIsArray( $decoded, 'metadata.json must be a JSON object.' );

		return $decoded;
	}

	public function test_metadata_records_every_reproducibility_field(): void {
		$metadata = $this->metadata();

		$required = array(
			'capturedAtCommit',
			'capturedAtUtc',
			'runnerImage',
			'browser',
			'browserVersion',
			'playwrightVersion',
			'fontStack',
			'contentFixtures',
			'projects',
			'pages',
		);

		foreach ( $required as $key ) {
			self::assertArrayHasKey(
				$key,
				$metadata,
				$this->architecture_failure(
					'Migration baseline metadata is missing a reproducibility field',
					self::BASELINE_RELATIVE . '/metadata.json',
					'A baseline that does not record the browser, viewport, fixtures, fonts and commit it was taken at cannot be trusted or reproduced.',
					'Add the "' . $key . '" key with the value the capture run actually used.'
				)
			);

			self::assertNotSame(
				'TO-BE-FILLED-BY-CAPTURE-RUN',
				$metadata[ $key ] ?? null,
				'metadata.json still holds a capture-run placeholder for "' . $key . '".'
			);
		}
	}

	public function test_every_declared_baseline_png_exists(): void {
		$metadata = $this->metadata();
		$root     = $this->repo_root() . '/' . self::BASELINE_RELATIVE;

		foreach ( array_keys( (array) $metadata['projects'] ) as $project ) {
			foreach ( (array) $metadata['pages'] as $page ) {
				$name = (string) ( $page['name'] ?? '' );

				self::assertFileExists(
					$root . '/' . $project . '/' . $name . '.png',
					$this->architecture_failure(
						'Declared migration baseline is missing on disk',
						self::BASELINE_RELATIVE . '/' . $project . '/' . $name . '.png',
						'metadata.json declares this baseline, so the parity suite will try to compare against it.',
						'Re-run the capture workflow against the pre-migration commit and commit the uploaded artifact.'
					)
				);
			}
		}
	}

	public function test_no_page_can_raise_the_five_percent_limit(): void {
		$helper = $this->read( $this->repo_root() . '/tests/parity/helpers/parity.ts' );

		// Every ParityPage literal is one { ... } block. The master specification
		// sets five percent as the maximum, not a default that pages may raise.
		preg_match_all( '/\{[^{}]*maxDiffRatio:[^{}]*\}/', $helper, $entries );

		foreach ( $entries[0] as $entry ) {
			if ( 1 !== preg_match( '/maxDiffRatio:\s*([0-9.]+)/', $entry, $ratio ) ) {
				continue;
			}

			self::assertLessThanOrEqual(
				self::DEFAULT_MAX_DIFF_RATIO,
				(float) $ratio[1],
				$this->architecture_failure(
					'A parity page raises its threshold above ' . self::DEFAULT_MAX_DIFF_RATIO,
					'tests/parity/helpers/parity.ts',
					'The release gate permits no page above five percent.',
					'Correct the rendering or mask only a proven nondeterministic region: ' . $entry
				)
			);
		}

		$this->addToAssertionCount( 1 );
	}

	public function test_no_spec_can_regenerate_a_migration_baseline(): void {
		foreach ( $this->spec_files() as $file ) {
			$contents  = $this->read( $file );
			$is_parity = str_contains( str_replace( '\\', '/', $file ), '/tests/parity/' );

			if ( ! $is_parity && ! str_contains( $contents, '__migration_baselines__' ) ) {
				continue;
			}

			self::assertStringNotContainsString(
				'toHaveScreenshot',
				$contents,
				$this->architecture_failure(
					'A parity/baseline spec uses toHaveScreenshot()',
					$this->to_relative( $file ),
					'toHaveScreenshot() baselines are regenerated by `--update-snapshots`, which would let an agent overwrite the pre-migration evidence and make a regression pass.',
					'Compare with pixelmatch through compareToBaseline() in tests/parity/helpers/parity.ts instead.'
				)
			);
		}

		$this->addToAssertionCount( 1 );
	}

	public function test_baselines_live_outside_the_playwright_snapshot_tree(): void {
		$config = $this->read( $this->repo_root() . '/playwright.config.ts' );

		self::assertStringContainsString(
			'__screenshots__',
			$config,
			'playwright.config.ts must keep resolving snapshots under __screenshots__, away from __migration_baselines__.'
		);

		self::assertStringNotContainsString(
			'__migration_baselines__',
			$config,
			'playwright.config.ts must not teach Playwright how to resolve migration baselines as snapshots.'
		);

		self::assertDirectoryDoesNotExist(
			$this->repo_root() . '/tests/visual/__screenshots__/__migration_baselines__',
			'Migration baselines must never live under the Playwright snapshot directory.'
		);
	}

	/**
	 * @return list<string>
	 */
	private function spec_files(): array {
		$root = $this->repo_root() . '/tests';
		$out  = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( $item instanceof \SplFileInfo && $item->isFile() && str_ends_with( $item->getFilename(), '.ts' ) ) {
				$out[] = $item->getPathname();
			}
		}

		sort( $out );

		return $out;
	}

	private function read( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- static source inspection in the architecture suite; no WordPress runtime here.
		$source = file_get_contents( $file );

		return false === $source ? '' : $source;
	}
}
```

- [ ] **Step 11: Prove the guard test really fails when a baseline goes missing**

The guard test cannot be written before the PNGs exist without committing a red gate, so demonstrate the failure without committing it:

```bash
$source = 'tests/parity/__migration_baselines__/parity-desktop/home.png'
$temporary = Join-Path $env:TEMP 'bt-task-1-home.png'
if ( -not (Test-Path -LiteralPath $source) -or (Test-Path -LiteralPath $temporary) ) { throw 'The proof-file paths are not in the expected state.' }
Move-Item -LiteralPath $source -Destination $temporary -ErrorAction Stop
ddev composer test:architecture
```

Expected: FAIL — `MigrationBaselineGuardTest::test_every_declared_baseline_png_exists` reports "Declared migration baseline is missing on disk" naming `tests/parity/__migration_baselines__/parity-desktop/home.png`.

Then restore it and confirm the suite goes green:

```bash
$source = Join-Path $env:TEMP 'bt-task-1-home.png'
$destination = 'tests/parity/__migration_baselines__/parity-desktop/home.png'
if ( -not (Test-Path -LiteralPath $source) -or (Test-Path -LiteralPath $destination) ) { throw 'The proof-file paths are not in the expected state.' }
Move-Item -LiteralPath $source -Destination $destination -ErrorAction Stop
ddev composer test:architecture
```

Expected: PASS, including every `MigrationBaselineGuardTest` method.

Repeat the same trick once for the placeholder check: temporarily set `capturedAtCommit` back to `TO-BE-FILLED-BY-CAPTURE-RUN`, confirm `test_metadata_records_every_reproducibility_field` fails, then restore the real value.

- [ ] **Step 12: Commit the guard**

```bash
ddev composer verify:fast
```

Expected: PASS.

```bash
ddev composer verify:fast
git add tests/Architecture/MigrationBaselineGuardTest.php
git commit -m "test: protect the migration baselines from regeneration"
```

---
## Task 3: Client capability policy — Site Editor in, Customizer and Additional CSS out

**Execution order correction:** Run Task 5 immediately after Task 2's baseline guard commit and before this task, even though Task 5 is written later in this document. Task 5 creates the block-theme environment that this task's required template and template-part REST tests need. Run Task 3, then Task 4, then resume at Task 6. This is the only task-number reordering in Unit 1. A required test must execute in its environment. It must not skip while that environment is absent.

Background facts verified against the installed WordPress 7.0.2 tree, so the executor does not have to rediscover them:

- `wp-includes/capabilities.php` maps `customize` → `edit_theme_options`. Granting `edit_theme_options` therefore also grants `customize`.
- `wp-admin/themes.php:12` allows access when the user has **either** `switch_themes` **or** `edit_theme_options`. Granting `edit_theme_options` opens the Themes screen.
- `wp-admin/widgets.php:15` and `wp-admin/nav-menus.php:23` are gated on `edit_theme_options` alone.
- `wp-admin/site-editor.php:14` is gated on `edit_theme_options`.
- `wp-admin/menu.php:207` sets `$appearance_capability = current_user_can( 'switch_themes' ) ? 'switch_themes' : 'edit_theme_options'`, so the whole Appearance menu appears for a client once `edit_theme_options` is granted.
- `wp-includes/capabilities.php` maps `edit_css` to `unfiltered_html`; `wp-includes/block-editor.php:662` sets `$editor_settings['canEditCSS'] = current_user_can( 'edit_css' )`, which is what hides the Global Styles "Additional CSS" panel.
- `wp_template`, `wp_template_part`, `wp_global_styles` and `wp_navigation` all map their post-type capabilities to `edit_theme_options` (`wp-includes/post.php`).

**Files:**
- Modify: `web/app/mu-plugins/agency-platform/src/Roles/RolesProvider.php`
- Modify: `web/app/mu-plugins/agency-platform/src/Roles/ShopRole.php` (docblock only)
- Create: `web/app/mu-plugins/agency-platform/src/Security/CapabilityPolicy.php`
- Create: `web/app/mu-plugins/agency-platform/src/Security/AdminScreenPolicy.php`
- Delete: `web/app/mu-plugins/agency-platform/src/Editor/SiteEditorLockdown.php`
- Modify: `web/app/mu-plugins/agency-platform/src/Plugin.php`
- Test: `tests/Unit/AgencyPlatform/CapabilityPolicyTest.php` (create)
- Test: `tests/Unit/AgencyPlatform/AdminScreenPolicyTest.php` (create)
- Test: `tests/Integration/Permissions/ClientEditorCapabilitiesTest.php` (modify)
- Test: `tests/Integration/Permissions/ClientSiteEditorAccessTest.php` (create)
- Test: `tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php` (modify)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `AgencyPlatform\Security\CapabilityPolicy` with
    `public const DENIED_META_CAPS = array( 'edit_css', 'customize' );`
    `public function register(): void`
    `public function deny_client_meta_caps( array $required_capabilities, string $capability, int $user_id, array $args ): array`
    `public static function map( array $required_capabilities, string $capability, bool $user_is_privileged ): array`
  - `AgencyPlatform\Security\AdminScreenPolicy` with
    `public const DENIED_SCREENS = array( ... );`
    `public const SITE_EDITOR_SCREEN = 'site-editor.php';`
    `public function register(): void`
    `public function block_denied_screens(): void`
    `public function replace_appearance_menu(): void`
    `public static function is_denied( string $pagenow, bool $user_is_privileged ): bool`
  - `AgencyPlatform\Roles\RolesProvider::client_editor_capabilities()` now returns an array containing `'edit_theme_options' => true` and no `'edit_css'`/`'unfiltered_html'` key.
  - The final client capability matrix later tasks and Task 4 of the wider engagement assert against: `edit_theme_options = true`, `edit_css = false`, `unfiltered_html = false`, `customize = false`, `manage_options = false`, `switch_themes = false`, `install_plugins = false`, `activate_plugins = false`, `edit_themes = false`, `edit_plugins = false`, `edit_files = false`, `update_core = false`.

- [ ] **Step 1: Write the failing unit test for the meta-capability policy**

Create `tests/Unit/AgencyPlatform/CapabilityPolicyTest.php`:

```php
<?php
/**
 * Proves AgencyPlatform\Security\CapabilityPolicy denies the two meta
 * capabilities that granting `edit_theme_options` would otherwise open up:
 * `edit_css` (Additional CSS / per-block custom CSS) and `customize` (the
 * Customizer, which core maps through edit_theme_options).
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Security\CapabilityPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Security\CapabilityPolicy
 */
final class CapabilityPolicyTest extends TestCase {

	public function test_edit_css_is_denied_for_client_roles(): void {
		self::assertSame(
			array( 'do_not_allow' ),
			CapabilityPolicy::map( array( 'unfiltered_html' ), 'edit_css', false )
		);
	}

	public function test_customize_is_denied_for_client_roles(): void {
		self::assertSame(
			array( 'do_not_allow' ),
			CapabilityPolicy::map( array( 'edit_theme_options' ), 'customize', false )
		);
	}

	public function test_privileged_users_keep_the_incoming_mapping(): void {
		self::assertSame(
			array( 'unfiltered_html' ),
			CapabilityPolicy::map( array( 'unfiltered_html' ), 'edit_css', true )
		);
		self::assertSame(
			array( 'edit_theme_options' ),
			CapabilityPolicy::map( array( 'edit_theme_options' ), 'customize', true )
		);
	}

	public function test_unrelated_capabilities_are_untouched_for_everyone(): void {
		self::assertSame(
			array( 'edit_theme_options' ),
			CapabilityPolicy::map( array( 'edit_theme_options' ), 'edit_theme_options', false )
		);
		self::assertSame(
			array( 'edit_posts' ),
			CapabilityPolicy::map( array( 'edit_posts' ), 'edit_post', false )
		);
	}

	public function test_denied_meta_caps_constant_is_exactly_the_two_documented_capabilities(): void {
		self::assertSame( array( 'edit_css', 'customize' ), CapabilityPolicy::DENIED_META_CAPS );
	}
}
```

Add the recursion guard's own integration test to `tests/Integration/Permissions/ClientEditorCapabilitiesTest.php` (it needs a real `map_meta_cap` chain, so it cannot live in the unit suite):

```php
	/**
	 * CapabilityPolicy's map_meta_cap callback must return BEFORE it calls
	 * user_can(), because user_can() re-enters map_meta_cap. Without the early
	 * return, every capability check on the site recurses. A blown stack shows
	 * up as a fatal, not a failed assertion, so these three calls completing at
	 * all is the assertion that matters.
	 */
	public function test_the_meta_cap_policy_does_not_recurse(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		self::assertFalse( current_user_can( 'edit_css' ) );
		self::assertFalse( current_user_can( 'customize' ) );
		self::assertTrue( current_user_can( 'edit_posts' ) );
		self::assertTrue( current_user_can( 'edit_theme_options' ) );
		self::assertFalse( current_user_can( 'manage_options' ) );
	}

	public function test_the_meta_cap_policy_does_not_recurse_for_administrators(): void {
		wp_set_current_user( $this->make_admin()->ID );

		self::assertTrue( current_user_can( 'edit_css' ) );
		self::assertTrue( current_user_can( 'customize' ) );
		self::assertTrue( current_user_can( 'manage_options' ) );
	}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev composer test:unit`
Expected: FAIL with `Error: Class "AgencyPlatform\Security\CapabilityPolicy" not found`.

- [ ] **Step 3: Implement CapabilityPolicy**

Create `web/app/mu-plugins/agency-platform/src/Security/CapabilityPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace AgencyPlatform\Security;

/**
 * Denies the two meta capabilities that granting `edit_theme_options` to a
 * client role would otherwise hand over as a side effect.
 *
 * `customize`: WordPress core maps it straight to `edit_theme_options`
 * (wp-includes/capabilities.php), so the Customizer would open the moment the
 * Site Editor did. Clients get the Site Editor, never the Customizer — there
 * is exactly one design surface.
 *
 * `edit_css`: core maps it to `unfiltered_html`, which client roles already
 * lack, so this denial is belt-and-braces rather than the sole barrier. It
 * matters because it is enforced at the MAPPING level: a plugin that grants
 * `unfiltered_html` to an editor role cannot accidentally re-open Additional
 * CSS, per-block custom CSS, or the `custom_css` post type (which maps all of
 * its own capabilities to `edit_css`).
 *
 * Privileged users (anyone who can `manage_options`) are never affected.
 */
final class CapabilityPolicy {

	/**
	 * @var string[]
	 */
	public const DENIED_META_CAPS = array( 'edit_css', 'customize' );

	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'deny_client_meta_caps' ), 10, 4 );
	}

	/**
	 * @param string[]          $required_capabilities
	 * @param array<int, mixed> $args
	 * @return string[]
	 */
	public function deny_client_meta_caps( array $required_capabilities, string $capability, int $user_id, array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $args is required to match WordPress's `map_meta_cap` filter signature.
		// EARLY RETURN BEFORE user_can(). This callback runs on EVERY
		// map_meta_cap call, and user_can() itself calls map_meta_cap again —
		// so calling it unconditionally would re-enter this method for every
		// capability check on the site, once per nesting level. Checking the
		// cheap, WordPress-free condition first means user_can() runs only for
		// the two capabilities in DENIED_META_CAPS, and the nested call for
		// 'manage_options' returns here immediately because 'manage_options'
		// is not in that list. Recursion therefore terminates at depth 1.
		if ( ! in_array( $capability, self::DENIED_META_CAPS, true ) ) {
			return $required_capabilities;
		}

		return self::map( $required_capabilities, $capability, user_can( $user_id, 'manage_options' ) );
	}

	/**
	 * Pure policy decision, so it is directly unit-testable without a
	 * WordPress runtime.
	 *
	 * @param string[] $required_capabilities
	 * @return string[]
	 */
	public static function map( array $required_capabilities, string $capability, bool $user_is_privileged ): array {
		if ( $user_is_privileged ) {
			return $required_capabilities;
		}

		if ( ! in_array( $capability, self::DENIED_META_CAPS, true ) ) {
			return $required_capabilities;
		}

		return array( 'do_not_allow' );
	}
}
```

- [ ] **Step 4: Run the unit test and watch it pass**

Run: `ddev composer test:unit`
Expected: PASS.

- [ ] **Step 5: Write the failing unit test for the admin-screen boundary**

Create `tests/Unit/AgencyPlatform/AdminScreenPolicyTest.php`:

```php
<?php
/**
 * Proves AgencyPlatform\Security\AdminScreenPolicy's deny/allow boundary is
 * exactly the one BLOCK_THEME_PROPOSAL.md §9.2 specifies: the Site Editor is
 * reachable, every other theme/customizer/file-editor screen is not.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Security\AdminScreenPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Security\AdminScreenPolicy
 */
final class AdminScreenPolicyTest extends TestCase {

	/**
	 * @return list<array{0: string}>
	 */
	public static function denied_screens(): array {
		return array(
			array( 'themes.php' ),
			array( 'theme-install.php' ),
			array( 'theme-editor.php' ),
			array( 'plugin-install.php' ),
			array( 'plugin-editor.php' ),
			array( 'customize.php' ),
			array( 'widgets.php' ),
			array( 'nav-menus.php' ),
			array( 'options-general.php' ),
			array( 'options-writing.php' ),
			array( 'options-reading.php' ),
			array( 'options-discussion.php' ),
			array( 'options-media.php' ),
			array( 'options-permalink.php' ),
			array( 'options.php' ),
		);
	}

	/**
	 * @dataProvider denied_screens
	 */
	public function test_client_roles_are_denied_every_forbidden_screen( string $pagenow ): void {
		self::assertTrue( AdminScreenPolicy::is_denied( $pagenow, false ) );
	}

	/**
	 * @dataProvider denied_screens
	 */
	public function test_privileged_users_are_denied_nothing( string $pagenow ): void {
		self::assertFalse( AdminScreenPolicy::is_denied( $pagenow, true ) );
	}

	public function test_the_site_editor_is_allowed_for_client_roles(): void {
		self::assertFalse( AdminScreenPolicy::is_denied( 'site-editor.php', false ) );
	}

	public function test_the_font_library_is_allowed_for_client_roles(): void {
		// Font Library access is part of the Site Editor capability model
		// (BLOCK_THEME_PROPOSAL.md §7.6 keeps font records database-owned), so
		// it is deliberately NOT on the deny list.
		self::assertFalse( AdminScreenPolicy::is_denied( 'font-library.php', false ) );
	}

	public function test_ordinary_content_screens_are_allowed_for_client_roles(): void {
		foreach ( array( 'index.php', 'edit.php', 'post.php', 'post-new.php', 'upload.php', 'profile.php' ) as $pagenow ) {
			self::assertFalse( AdminScreenPolicy::is_denied( $pagenow, false ), $pagenow . ' must stay reachable.' );
		}
	}

	public function test_denied_screens_constant_covers_the_whole_spec_deny_list(): void {
		foreach ( array( 'themes.php', 'theme-install.php', 'theme-editor.php', 'plugin-install.php', 'plugin-editor.php', 'customize.php', 'widgets.php', 'nav-menus.php' ) as $screen ) {
			self::assertContains( $screen, AdminScreenPolicy::DENIED_SCREENS );
		}

		self::assertNotContains( AdminScreenPolicy::SITE_EDITOR_SCREEN, AdminScreenPolicy::DENIED_SCREENS );
	}
}
```

- [ ] **Step 6: Run it and watch it fail**

Run: `ddev composer test:unit`
Expected: FAIL with `Error: Class "AgencyPlatform\Security\AdminScreenPolicy" not found`.

- [ ] **Step 7: Implement AdminScreenPolicy**

Create `web/app/mu-plugins/agency-platform/src/Security/AdminScreenPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace AgencyPlatform\Security;

/**
 * The explicit admin-screen boundary that makes granting `edit_theme_options`
 * safe.
 *
 * `edit_theme_options` is the capability the Site Editor needs, but core also
 * gates themes.php, widgets.php and nav-menus.php behind it, and maps
 * `customize` through it. Hiding menu items is cosmetic, so the real barrier
 * here is a wp_die() on `admin_init` for a fixed deny list; the menu surgery
 * below only stops clients being shown doors that would slam in their face.
 *
 * ALLOW (§9.2): site-editor.php and its REST endpoints (templates, template
 * parts, navigation, global styles), plus font-library.php, which is the Site
 * Editor's own font surface.
 *
 * DENY: themes.php, theme-install.php, theme-editor.php, plugin-install.php,
 * plugin-editor.php, customize.php, widgets.php, nav-menus.php, and the
 * unrelated settings screens. The settings screens are already gated on
 * `manage_options` by core; listing them keeps the boundary explicit rather
 * than implied.
 *
 * REST is deliberately untouched: the Site Editor is a REST client, and core's
 * own `edit_theme_options` gate on those routes is the correct check.
 */
final class AdminScreenPolicy {

	public const SITE_EDITOR_SCREEN = 'site-editor.php';

	/**
	 * @var string[]
	 */
	public const DENIED_SCREENS = array(
		'themes.php',
		'theme-install.php',
		'theme-editor.php',
		'plugin-install.php',
		'plugin-editor.php',
		'customize.php',
		'widgets.php',
		'nav-menus.php',
		'options-general.php',
		'options-writing.php',
		'options-reading.php',
		'options-discussion.php',
		'options-media.php',
		'options-permalink.php',
		'options.php',
	);

	public function register(): void {
		add_action( 'admin_init', array( $this, 'block_denied_screens' ) );
		add_action( 'admin_menu', array( $this, 'replace_appearance_menu' ), 999 );
	}

	/**
	 * Hard boundary. Runs on `admin_init`, which every wp-admin screen fires
	 * through wp-admin/admin.php before the screen's own capability check.
	 */
	public function block_denied_screens(): void {
		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';

		if ( ! self::is_denied( $pagenow, current_user_can( 'manage_options' ) ) ) {
			return;
		}

		wp_die(
			'<h1>' . esc_html__( 'You need a higher level of permission.', 'agency-platform' ) . '</h1>' .
			'<p>' . esc_html__( 'This screen is not part of the editing model for your role. Design changes belong in the Site Editor.', 'agency-platform' ) . '</p>',
			403
		);
	}

	/**
	 * Removes the Appearance menu (whose top-level target is themes.php, a
	 * denied screen) and replaces it with one Design entry that links straight
	 * to the Site Editor. Passing an existing admin file as the menu slug makes
	 * WordPress link to that file, so the empty callback is never invoked.
	 */
	public function replace_appearance_menu(): void {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		remove_menu_page( 'themes.php' );

		add_menu_page(
			__( 'Design', 'agency-platform' ),
			__( 'Design', 'agency-platform' ),
			'edit_theme_options',
			self::SITE_EDITOR_SCREEN,
			'',
			'dashicons-admin-appearance',
			60
		);
	}

	/**
	 * Pure policy decision, so it is directly unit-testable without a
	 * WordPress runtime.
	 */
	public static function is_denied( string $pagenow, bool $user_is_privileged ): bool {
		if ( $user_is_privileged ) {
			return false;
		}

		return in_array( $pagenow, self::DENIED_SCREENS, true );
	}
}
```

- [ ] **Step 8: Run the unit tests and watch them pass**

Run: `ddev composer test:unit`
Expected: PASS.

- [ ] **Step 9: Grant `edit_theme_options` in RolesProvider**

In `web/app/mu-plugins/agency-platform/src/Roles/RolesProvider.php`:

1. Remove `'edit_theme_options',` from the `NEVER_GRANT` array.
2. Add a new constant beneath `NEVER_GRANT`:

```php
	/**
	 * Capabilities this role must be granted EXPLICITLY, because core's
	 * `editor` role does not carry them. Removing a capability from
	 * NEVER_GRANT is not enough on its own.
	 *
	 * `edit_theme_options` is what opens the Site Editor (and the wp_template,
	 * wp_template_part, wp_global_styles and wp_navigation post types, which
	 * all map their capabilities to it). The screens it would otherwise expose
	 * are closed by AgencyPlatform\Security\AdminScreenPolicy, and the
	 * `customize` / `edit_css` meta capabilities it would otherwise imply are
	 * denied by AgencyPlatform\Security\CapabilityPolicy.
	 *
	 * @var string[]
	 */
	private const ALWAYS_GRANT = array(
		'edit_theme_options',
	);
```

3. In `client_editor_capabilities()`, after the `NEVER_GRANT` loop, add:

```php
		foreach ( self::ALWAYS_GRANT as $capability ) {
			$capabilities[ $capability ] = true;
		}
```

4. Update the class docblock's first paragraph to read: "Registers the `client_editor` role: everything a WordPress core `editor` can do, minus `unfiltered_html` and every "keys to the kingdom" capability in NEVER_GRANT, plus every capability in ALWAYS_GRANT (the Site Editor's `edit_theme_options`)."

- [ ] **Step 10: Remove SiteEditorLockdown and wire the new providers**

1. Delete `web/app/mu-plugins/agency-platform/src/Editor/SiteEditorLockdown.php`.
2. In `web/app/mu-plugins/agency-platform/src/Plugin.php`, remove the `use AgencyPlatform\Editor\SiteEditorLockdown;` import and the `new SiteEditorLockdown(),` entry; add `use AgencyPlatform\Security\AdminScreenPolicy;` and `use AgencyPlatform\Security\CapabilityPolicy;`. The provider list becomes:

```php
		$providers = array(
			new EnvironmentIndicator(),
			new RolesProvider(),
			new ShopRole(),
			new CapabilityPolicy(),
			new AdminScreenPolicy(),
			new EditorRestrictions(),
			new ApplicationPasswords(),
			new FileModGuard(),
			new MailGuard(),
			new AgencyCommands(),
		);
```

(Task 4 inserts `new SaveValidation(),` immediately after `new EditorRestrictions(),`.)

3. In `web/app/mu-plugins/agency-platform/src/Roles/ShopRole.php`, update the `WOOCOMMERCE_CAPABILITIES` docblock sentence that reads "`manage_options`, `edit_theme_options`, `install_plugins`, `switch_themes` are all still withheld" to: "`manage_options`, `install_plugins`, `switch_themes` are all still withheld; `edit_theme_options` IS inherited from `client_editor` so a shop manager gets the same Site Editor access, bounded by `AgencyPlatform\Security\AdminScreenPolicy` (proven by ShopManagerCapabilitiesTest)."

- [ ] **Step 11: Update the base integration capability test**

In `tests/Integration/Permissions/ClientEditorCapabilitiesTest.php`:

1. Delete `'edit_theme_options',` from `never_grant_capabilities()`.
2. Update the class docblock and the `@covers` list: replace `\AgencyPlatform\Editor\SiteEditorLockdown` with `\AgencyPlatform\Security\CapabilityPolicy` and `\AgencyPlatform\Security\AdminScreenPolicy`, and rewrite the `never_grant_capabilities()` docblock so it no longer mentions `SiteEditorLockdown`.
3. Rewrite `test_client_editor_cannot_edit_custom_css()` so it names the new enforcement point:

```php
	/**
	 * `edit_css` gates Additional CSS, per-block custom CSS, and the
	 * `custom_css` post type. Core maps it to `unfiltered_html` (which
	 * client_editor lacks); AgencyPlatform\Security\CapabilityPolicy
	 * additionally maps it to `do_not_allow`, so the denial survives a plugin
	 * granting the underlying capability.
	 */
	public function test_client_editor_cannot_edit_custom_css(): void {
		$client_editor = $this->make_client_editor();
		wp_set_current_user( $client_editor->ID );

		self::assertFalse( current_user_can( 'edit_css' ) );

		$client_editor->add_cap( 'unfiltered_html' );
		wp_set_current_user( $client_editor->ID );

		self::assertFalse(
			current_user_can( 'edit_css' ),
			'CapabilityPolicy must keep edit_css denied even when unfiltered_html is granted directly.'
		);
	}
```

4. Add the positive/negative capability matrix test:

```php
	public function test_client_editor_capability_matrix(): void {
		$client_editor = $this->make_client_editor();
		wp_set_current_user( $client_editor->ID );

		self::assertTrue( current_user_can( 'edit_theme_options' ) );
		self::assertFalse( current_user_can( 'edit_css' ) );
		self::assertFalse( current_user_can( 'unfiltered_html' ) );
		self::assertFalse( current_user_can( 'customize' ) );
	}
```

5. Leave `test_code_editing_and_block_locking_are_disabled_for_client_editor()` and `test_client_editor_is_restricted_to_the_approved_block_allow_list()` **untouched** here. Both assert current, still-true behaviour, and Task 4 replaces them in the same commit that changes the production code. Writing the `canLockBlocks = true` assertion in this task would commit a knowingly red integration suite, which this plan never does.

- [ ] **Step 12: Write the Site Editor access integration test**

Create `tests/Integration/Permissions/ClientSiteEditorAccessTest.php`:

```php
<?php
/**
 * Proves the client roles can actually drive the Site Editor's data layer —
 * reading and writing templates, template parts, navigation and global styles
 * through the REST routes the Site Editor itself uses — while the forbidden
 * admin surface stays closed.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Permissions;

use AgencyPlatform\Security\AdminScreenPolicy;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\Roles\RolesProvider
 * @covers \AgencyPlatform\Security\AdminScreenPolicy
 * @covers \AgencyPlatform\Security\CapabilityPolicy
 */
final class ClientSiteEditorAccessTest extends IntegrationTestCase {

	public function set_up(): void {
		parent::set_up();

		do_action( 'rest_api_init' );
	}

	public function test_client_editor_can_read_the_template_rest_collection(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/templates' ) );

		self::assertSame( 200, $response->get_status() );
	}

	public function test_client_editor_can_read_the_template_part_rest_collection(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/template-parts' ) );

		self::assertSame( 200, $response->get_status() );
	}

	public function test_client_editor_can_create_a_navigation_record(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/navigation' );
		$request->set_param( 'title', 'Client Navigation' );
		$request->set_param( 'status', 'publish' );
		$request->set_param( 'content', '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->' );

		$response = rest_do_request( $request );

		self::assertContains( $response->get_status(), array( 200, 201 ) );
	}

	/**
	 * Reading a collection proves nothing about editing. These two write, then
	 * READ BACK, because the whole point of Release 1 is that a client's Site
	 * Editor save actually persists.
	 *
	 * These two required tests have no skip path.
	 */
	public function test_client_editor_can_create_and_read_back_a_template(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$id      = get_stylesheet() . '//index';
		$content = "<!-- wp:paragraph -->\n<p>Client template write.</p>\n<!-- /wp:paragraph -->";

		$request = new \WP_REST_Request( 'POST', '/wp/v2/templates/' . $id );
		$request->set_param( 'content', $content );

		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertStringContainsString(
			'Client template write.',
			(string) get_block_template( $id, 'wp_template' )->content
		);
	}

	public function test_client_editor_can_create_and_read_back_a_template_part(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$id      = get_stylesheet() . '//site-footer';
		$content = "<!-- wp:paragraph -->\n<p>Client footer write.</p>\n<!-- /wp:paragraph -->";

		$request = new \WP_REST_Request( 'POST', '/wp/v2/template-parts/' . $id );
		$request->set_param( 'content', $content );

		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertStringContainsString(
			'Client footer write.',
			(string) get_block_template( $id, 'wp_template_part' )->content
		);
	}

	public function test_client_editor_can_write_global_styles_and_the_change_persists(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$post_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();

		$request = new \WP_REST_Request( 'POST', '/wp/v2/global-styles/' . $post_id );
		$request->set_param( 'styles', array( 'color' => array( 'background' => 'var(--wp--preset--color--neutral-100)' ) ) );

		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status() );

		// Read the STORED record back, not the response echo.
		$stored = json_decode( (string) get_post( $post_id )->post_content, true );

		self::assertSame(
			'var(--wp--preset--color--neutral-100)',
			$stored['styles']['color']['background'] ?? null
		);
	}

	public function test_client_editor_can_create_and_read_back_a_navigation_record(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/navigation' );
		$request->set_param( 'title', 'Client Navigation Write' );
		$request->set_param( 'status', 'publish' );
		$request->set_param( 'content', '<!-- wp:navigation-link {"label":"Written","url":"/written/"} /-->' );

		$response = rest_do_request( $request );

		self::assertContains( $response->get_status(), array( 200, 201 ) );

		$created = get_post( (int) $response->get_data()['id'] );

		self::assertStringContainsString( 'Written', (string) $created->post_content );
	}

	public function test_client_editor_cannot_edit_themes_or_plugins_or_files(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		foreach ( array( 'switch_themes', 'install_themes', 'install_plugins', 'activate_plugins', 'edit_themes', 'edit_plugins', 'edit_files', 'manage_options' ) as $capability ) {
			self::assertFalse( current_user_can( $capability ), $capability . ' must stay denied.' );
		}
	}

	public function test_the_admin_screen_deny_list_still_names_every_forbidden_screen(): void {
		foreach ( array( 'themes.php', 'theme-install.php', 'theme-editor.php', 'plugin-install.php', 'plugin-editor.php', 'customize.php', 'widgets.php', 'nav-menus.php' ) as $screen ) {
			self::assertTrue( AdminScreenPolicy::is_denied( $screen, false ), $screen . ' must be denied.' );
		}
	}
}
```

**ORCHESTRATOR CORRECTION, 2026-08-03 — ordering defect.** The two write-back
methods above, `test_client_editor_can_create_and_read_back_a_template` and
`test_client_editor_can_create_and_read_back_a_template_part`, are **NOT added in
Task 3**. Task 6 Step 12 adds them.

The original text of this step claimed "Task 5 runs before this task and creates
the required block-theme environment." That claim is false: the execution order
is Task 3, then Task 4, then Task 5. Both methods send a REST **update** to
`<stylesheet>//index` and `<stylesheet>//site-footer`. Those identifiers resolve
only once `templates/index.html` and `parts/site-footer.html` exist, which
happens in Task 5's spike and Task 6's conversion. Before then WordPress returns
404, `wp_is_block_theme()` returns `0`, and Task 3's own Step 14 gate is red.
That contradicts this plan's commit-gate policy, which forbids landing a commit
on a gate the plan already knows is red.

Proven on 2026-08-03: with the classic theme still in place, both methods failed
with a 404, and the rest of Task 3 passed — unit 160 tests / 352 assertions,
integration 39 tests / 112 assertions with only these two red, deptrac 0
violations.

So Task 3 creates `ClientSiteEditorAccessTest.php` WITHOUT those two methods.
Everything else in the file stays. The collection-read, navigation-create, and
Global Styles tests all pass against the classic theme and are kept here,
because they prove capability wiring rather than block-template resolution.

- [ ] **Step 13: Update the commerce capability test**

In `tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php`:

1. In `test_shop_manager_cannot_reach_the_keys_to_the_kingdom()`, delete `'edit_theme_options',` from the array so it reads `array( 'install_plugins', 'switch_themes', 'manage_options', 'unfiltered_html' )`.
2. Add:

```php
	/**
	 * client_shop_manager derives from client_editor, so it inherits the Site
	 * Editor capability. The screens edit_theme_options would otherwise expose
	 * are closed by AgencyPlatform\Security\AdminScreenPolicy, not by
	 * withholding the capability.
	 */
	public function test_shop_manager_inherits_site_editor_access(): void {
		$shop_manager = $this->make_shop_manager();
		wp_set_current_user( $shop_manager->ID );

		self::assertTrue( current_user_can( 'edit_theme_options' ) );
		self::assertFalse( current_user_can( 'edit_css' ) );
		self::assertFalse( current_user_can( 'customize' ) );
	}
```

3. Update the class docblock's last sentence: "…and still cannot install plugins or switch themes, and can reach the Site Editor but not the Themes, Customizer, Widgets or file-editor screens."

This file is nominally owned by the commerce track, but §11.9 explicitly assigns "Update `ShopRole` documentation and tests to reflect inherited Site Editor access" to this task, and the Release 1 gate requires the commerce suite green.

- [ ] **Step 14: Run the full PHP verification**

Run: `ddev composer verify`
Expected: PASS, with no known red. If anything fails, fix it here — do not carry a red gate into the commit.

Also run the commerce suite, because this task edits a commerce test:

```bash
ddev exec bash scripts/enable-commerce
ddev composer test:integration:commerce
```

Expected: PASS.

Then restore the base profile before any non-commerce verification or commit. This test uses an ephemeral Composer change only. First confirm the task worktree was clean for these files before the command. Then deactivate the profile, restore only the known ephemeral Composer files, and reinstall the base dependency set:

```powershell
$before = git diff --name-only HEAD -- composer.json composer.lock
if ( $before ) { throw 'The worktree has pre-existing Composer changes. Stop and report them.' }
ddev wp plugin deactivate woocommerce
git restore --source=HEAD -- composer.json composer.lock
ddev composer install --no-interaction --prefer-dist
ddev composer verify:fast
```

Expected: the base profile is restored, `git diff -- composer.json composer.lock` is empty, and `verify:fast` passes. Do not carry WooCommerce installation state into the next task.

- [ ] **Step 15: Commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/Roles/RolesProvider.php web/app/mu-plugins/agency-platform/src/Roles/ShopRole.php web/app/mu-plugins/agency-platform/src/Security/CapabilityPolicy.php web/app/mu-plugins/agency-platform/src/Security/AdminScreenPolicy.php web/app/mu-plugins/agency-platform/src/Editor/SiteEditorLockdown.php web/app/mu-plugins/agency-platform/src/Plugin.php tests/Unit/AgencyPlatform/CapabilityPolicyTest.php tests/Unit/AgencyPlatform/AdminScreenPolicyTest.php tests/Integration/Permissions/ClientEditorCapabilitiesTest.php tests/Integration/Permissions/ClientSiteEditorAccessTest.php tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php
git commit -m "feat: add client Site Editor capability policy"
```

---

## Task 4: Registered-block policy and server-side save validation

> **ORCHESTRATOR CORRECTION, 2026-08-03 — EXECUTION ORDER. Run Task 5 BEFORE
> this task.**
>
> This task's Step 10 requires block templates on disk. Its own text says "Task 5
> has already put block templates on disk" and adds an execution correction
> repeating that a missing block-theme environment is a failing task gate. Task 3
> Step 12 made the same assumption. Both are right about the dependency and wrong
> about the order: the numbering says Task 5 comes third, and it does not.
>
> Task 5 settles it. Its own `Interfaces` block states "Consumes: nothing from
> Tasks 3–4." Task 5 depends on neither task, and both depend on Task 5's
> `templates/index.html`, `parts/site-header.html`, and `parts/site-footer.html`.
> So the real dependency order is Task 1, Task 2, **Task 5**, Task 3, Task 4,
> Task 6.
>
> Proven on 2026-08-03: with the classic theme still active, Luna stopped before
> Step 1 and reported that `templates/index.html` was absent and
> `wp_is_block_theme()` returned false, exactly as this brief required instead of
> writing tests that could not pass.
>
> Task 3 already shipped at `1ae633e` before this was understood. That is safe
> and needs no rework: its two block-dependent tests were deferred to Task 6,
> which still creates the files they need. Do not move them again.
>
> **Second correction, same task.** Step 11 says
> `ClientEditorCapabilitiesTest::test_code_editing_is_disabled_and_block_locking_is_enabled_for_client_editor`
> is written here and that `canLockBlocks` becomes `true`. The repository still
> carries the older `test_code_editing_and_block_locking_are_disabled_for_client_editor`,
> which asserts `canLockBlocks === false`. This task must REPLACE that older test
> rather than leave both. Two tests asserting opposite values cannot both pass.

Background facts verified against WordPress 7.0.2:

- `rest_pre_insert_{$post_type}` is applied by `WP_REST_Posts_Controller::prepare_item_for_database()` (line 1531) **and** by `WP_REST_Templates_Controller::prepare_item_for_database()` (line 661). Returning a `WP_Error` from it aborts the request with that error. This is the only save filter that can reject cleanly, which is why it is the enforcement point (see Decision 3).
- `wp-includes/block-supports/custom-css.php` (new in 7.0) stores per-block custom CSS in the block instance's `attrs.style.css`, and core **strips** it on `content_save_pre` at priority 8 for users without `edit_css`. Our validator runs earlier (`rest_pre_insert_*` happens before `wp_insert_post()`), so it sees the raw value and **rejects** instead of silently stripping.
- `get_shortcode_regex()` matches only registered shortcode tags. Capture group 1 is the optional leading `[` that marks an escaped `[[tag]]`; capture group 2 is the tag name.

**Files:**
- Create: `web/app/mu-plugins/agency-platform/src/Editor/BlockPolicy.php`
- Create: `web/app/mu-plugins/agency-platform/src/Editor/SaveValidation.php`
- Create: `web/app/mu-plugins/agency-platform/src/Editor/GlobalStylesGuard.php`
- Modify: `web/app/mu-plugins/agency-platform/src/Editor/EditorRestrictions.php`
- Modify: `web/app/mu-plugins/agency-platform/src/Plugin.php`
- Test: `tests/Unit/AgencyPlatform/BlockPolicyTest.php` (create)
- Test: `tests/Unit/AgencyPlatform/SaveValidationPolicyTest.php` (create)
- Test: `tests/Unit/AgencyPlatform/GlobalStylesGuardTest.php` (create)
- Test: `tests/Unit/AgencyPlatform/EditorRestrictionsPolicyTest.php` (rewrite)
- Test: `tests/Integration/Editor/SaveValidationTest.php` (create)
- Test: `tests/Integration/Editor/CustomCssSurfacesTest.php` (create)
- Test: `tests/Integration/Permissions/ClientEditorCapabilitiesTest.php` (modify)

**Interfaces:**
- Consumes: `AgencyPlatform\Security\CapabilityPolicy` and `AgencyPlatform\Security\AdminScreenPolicy` from Task 3 (only as neighbours in `Plugin::boot()`).
- Produces:
  - `AgencyPlatform\Editor\BlockPolicy`
    `public const DEFAULT_NAMESPACES = array( 'core', 'agency', 'woocommerce' );`
    `public const ALWAYS_DENIED = array( 'core/html', 'core/shortcode', 'core/freeform' );`
    `public static function resolve( bool $is_privileged, bool|array $incoming, array $registered_block_names, array $allowed_namespaces, array $extra_allowed, array $extra_denied ): bool|array`
    `public static function forbidden_blocks( array $parsed_blocks, array $allowed_block_names ): array` returning `list<array{block: string, path: string}>`
    `public static function raw_html_violations( array $parsed_blocks ): array` returning `list<string>`
    `public static function block_css_violations( array $parsed_blocks ): array` returning `list<array{block: string, css: string}>`
    `public static function shortcode_violations( string $content, string $shortcode_regex ): array` returning `list<string>`
  - `AgencyPlatform\Editor\SaveValidation`
    `public const VALIDATED_POST_TYPES = array( 'post', 'page', 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation' );`
    `public function register(): void`
    `public function register_save_filters(): void`
    `public function validate_content( $prepared, \WP_REST_Request $request )`
  - Filters: `agency_platform_allowed_block_namespaces`, `agency_platform_allowed_blocks`, `agency_platform_disallowed_blocks`.

- [ ] **Step 1: Write the failing unit test for the block policy**

Create `tests/Unit/AgencyPlatform/BlockPolicyTest.php`. Cover, at minimum:

```php
<?php
/**
 * Proves AgencyPlatform\Editor\BlockPolicy resolves the client block set from
 * the REGISTERED blocks (not a hand-maintained list), keeps html/shortcode/
 * freeform permanently denied, and detects every save-time violation class
 * BLOCK_THEME_PROPOSAL.md §9.3 names.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Editor\BlockPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Editor\BlockPolicy
 */
final class BlockPolicyTest extends TestCase {

	/**
	 * @return list<string>
	 */
	private function registered(): array {
		return array(
			'core/paragraph',
			'core/group',
			'core/navigation',
			'core/template-part',
			'core/html',
			'core/shortcode',
			'core/freeform',
			'agency/reference-callout',
			'woocommerce/mini-cart',
			'acme/tracking-pixel',
		);
	}

	private function resolve( bool $privileged, $incoming ) {
		return BlockPolicy::resolve(
			$privileged,
			$incoming,
			$this->registered(),
			BlockPolicy::DEFAULT_NAMESPACES,
			array(),
			array()
		);
	}

	public function test_privileged_users_get_the_incoming_value_back_unchanged(): void {
		self::assertTrue( $this->resolve( true, true ) );
		self::assertSame( array( 'core/paragraph' ), $this->resolve( true, array( 'core/paragraph' ) ) );
	}

	public function test_client_roles_get_every_registered_block_in_an_approved_namespace(): void {
		$allowed = $this->resolve( false, true );

		self::assertIsArray( $allowed );
		self::assertContains( 'core/paragraph', $allowed );
		self::assertContains( 'core/group', $allowed );
		self::assertContains( 'core/navigation', $allowed );
		self::assertContains( 'core/template-part', $allowed );
		self::assertContains( 'agency/reference-callout', $allowed );
		self::assertContains( 'woocommerce/mini-cart', $allowed );
	}

	public function test_unapproved_namespaces_are_excluded(): void {
		self::assertNotContains( 'acme/tracking-pixel', (array) $this->resolve( false, true ) );
	}

	public function test_html_shortcode_and_freeform_are_always_denied(): void {
		$allowed = (array) $this->resolve( false, true );

		self::assertNotContains( 'core/html', $allowed );
		self::assertNotContains( 'core/shortcode', $allowed );
		self::assertNotContains( 'core/freeform', $allowed );
	}

	public function test_an_incoming_array_restriction_is_intersected_not_replaced(): void {
		$allowed = $this->resolve( false, array( 'core/paragraph', 'core/html', 'acme/tracking-pixel' ) );

		self::assertSame( array( 'core/paragraph' ), array_values( (array) $allowed ) );
	}

	public function test_an_incoming_false_restriction_is_preserved(): void {
		// `false` means another filter already decided this context gets no
		// blocks at all. Turning that into an allow-list would widen access.
		self::assertFalse( $this->resolve( false, false ) );
		self::assertFalse( $this->resolve( true, false ) );
	}

	public function test_extra_allowed_blocks_are_added_even_outside_the_namespace_set(): void {
		$allowed = BlockPolicy::resolve(
			false,
			true,
			$this->registered(),
			BlockPolicy::DEFAULT_NAMESPACES,
			array( 'acme/tracking-pixel' ),
			array()
		);

		self::assertContains( 'acme/tracking-pixel', (array) $allowed );
	}

	public function test_extra_denied_blocks_are_removed(): void {
		$allowed = BlockPolicy::resolve(
			false,
			true,
			$this->registered(),
			BlockPolicy::DEFAULT_NAMESPACES,
			array(),
			array( 'core/navigation' )
		);

		self::assertNotContains( 'core/navigation', (array) $allowed );
	}

	public function test_extra_allowed_cannot_re_enable_a_permanently_denied_block(): void {
		$allowed = BlockPolicy::resolve(
			false,
			true,
			$this->registered(),
			BlockPolicy::DEFAULT_NAMESPACES,
			array( 'core/html', 'core/shortcode', 'core/freeform' ),
			array()
		);

		self::assertNotContains( 'core/html', (array) $allowed );
		self::assertNotContains( 'core/shortcode', (array) $allowed );
		self::assertNotContains( 'core/freeform', (array) $allowed );
	}

	public function test_forbidden_blocks_are_found_recursively(): void {
		$parsed = array(
			array(
				'blockName' => 'core/group',
				'attrs'     => array(),
				'innerBlocks' => array(
					array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '' ),
					array( 'blockName' => 'core/html', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '' ),
				),
				'innerHTML' => '',
			),
		);

		$violations = BlockPolicy::forbidden_blocks( $parsed, array( 'core/group', 'core/paragraph' ) );

		self::assertCount( 1, $violations );
		self::assertSame( 'core/html', $violations[0]['block'] );
		self::assertSame( 'core/group > core/html', $violations[0]['path'] );
	}

	public function test_null_name_blocks_with_real_markup_are_raw_html_violations(): void {
		$parsed = array(
			array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => "\n\n" ),
			array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<script>alert(1)</script>' ),
		);

		$violations = BlockPolicy::raw_html_violations( $parsed );

		self::assertCount( 1, $violations );
		self::assertStringContainsString( '<script>', $violations[0] );
	}

	public function test_block_instance_custom_css_is_detected_recursively(): void {
		$parsed = array(
			array(
				'blockName'   => 'core/group',
				'attrs'       => array(),
				'innerBlocks' => array(
					array(
						'blockName'   => 'core/paragraph',
						'attrs'       => array( 'style' => array( 'css' => 'color:red' ) ),
						'innerBlocks' => array(),
						'innerHTML'   => '',
					),
				),
				'innerHTML'   => '',
			),
		);

		$violations = BlockPolicy::block_css_violations( $parsed );

		self::assertCount( 1, $violations );
		self::assertSame( 'core/paragraph', $violations[0]['block'] );
	}

	public function test_registered_shortcode_tags_are_detected_anywhere_in_content(): void {
		// The regex shape WordPress's get_shortcode_regex() produces for the
		// registered tags "gallery" and "contact-form".
		$regex = '\[(\[?)(gallery|contact\-form)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)';

		$violations = BlockPolicy::shortcode_violations(
			'<!-- wp:paragraph --><p>Call us [contact-form] today</p><!-- /wp:paragraph -->',
			$regex
		);

		self::assertSame( array( 'contact-form' ), $violations );
	}

	public function test_escaped_shortcodes_and_ordinary_bracket_text_are_not_violations(): void {
		$regex = '\[(\[?)(gallery|contact\-form)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)';

		self::assertSame( array(), BlockPolicy::shortcode_violations( '<p>See [[gallery]] for the escape syntax.</p>', $regex ) );
		self::assertSame( array(), BlockPolicy::shortcode_violations( '<p>Rates are [subject to change] this year.</p>', $regex ) );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev composer test:unit`
Expected: FAIL with `Error: Class "AgencyPlatform\Editor\BlockPolicy" not found`.

- [ ] **Step 3: Implement BlockPolicy**

Create `web/app/mu-plugins/agency-platform/src/Editor/BlockPolicy.php`. Every method is pure PHP with no WordPress calls, so the whole policy is unit-testable and `deptrac`'s `AgencyPlatform: []` rule stays satisfied.

```php
<?php

declare(strict_types=1);

namespace AgencyPlatform\Editor;

/**
 * The client editing policy, expressed as pure functions.
 *
 * The editor allow-list is DERIVED from the blocks actually registered on the
 * site, filtered to approved namespaces — not a hand-maintained list that goes
 * stale the moment a core release ships a new block. That matters for a block
 * theme: the Site Editor needs core's template, query, navigation and post
 * blocks, and a fixed list would quietly break it.
 *
 * The allow-list is a UI convenience, NOT a security boundary. The same policy
 * is re-applied server side on save by AgencyPlatform\Editor\SaveValidation,
 * because a direct REST request can post whatever markup it likes.
 */
final class BlockPolicy {

	/**
	 * @var string[]
	 */
	public const DEFAULT_NAMESPACES = array( 'core', 'agency', 'woocommerce' );

	/**
	 * Never insertable and never savable by a client, whatever any filter
	 * says. `core/html` and `core/shortcode` are arbitrary-markup escape
	 * hatches; `core/freeform` (the Classic block) stores raw HTML directly in
	 * post content, which is the same hole through a different door.
	 *
	 * `woocommerce` is listed in DEFAULT_NAMESPACES unconditionally: when
	 * WooCommerce is absent no woocommerce/* block is registered, so the
	 * resolved set is identical, and gating on it would put a WooCommerce
	 * symbol inside agency-platform for no benefit.
	 *
	 * @var string[]
	 */
	public const ALWAYS_DENIED = array( 'core/html', 'core/shortcode', 'core/freeform' );

	/**
	 * @param bool|string[] $incoming               Whatever WordPress/other filters passed in.
	 * @param string[]      $registered_block_names Every currently registered block name.
	 * @param string[]      $allowed_namespaces     Namespace prefixes without the slash.
	 * @param string[]      $extra_allowed          Explicitly allowed block names.
	 * @param string[]      $extra_denied           Explicitly denied block names.
	 * @return bool|string[]
	 */
	public static function resolve(
		bool $is_privileged,
		$incoming,
		array $registered_block_names,
		array $allowed_namespaces,
		array $extra_allowed,
		array $extra_denied
	) {
		if ( $is_privileged ) {
			return $incoming;
		}

		// An incoming `false` means another filter has already decided this
		// context gets NO blocks at all. Widening that to an allow-list would
		// be a privilege escalation dressed up as a policy, so `false` is
		// preserved untouched. Only `true` (no restriction yet) and an array
		// (a narrower list to intersect with) are ours to act on.
		if ( false === $incoming ) {
			return false;
		}

		$allowed = array();

		foreach ( array_merge( $registered_block_names, $extra_allowed ) as $block_name ) {
			if ( in_array( $block_name, $extra_allowed, true ) ) {
				$allowed[] = $block_name;
				continue;
			}

			if ( in_array( self::namespace_of( $block_name ), $allowed_namespaces, true ) ) {
				$allowed[] = $block_name;
			}
		}

		$allowed = array_diff( $allowed, $extra_denied, self::ALWAYS_DENIED );

		if ( is_array( $incoming ) ) {
			$allowed = array_intersect( $allowed, $incoming );
		}

		$allowed = array_values( array_unique( $allowed ) );
		sort( $allowed );

		return $allowed;
	}

	/**
	 * @param array<int, array<string, mixed>> $parsed_blocks      Output of parse_blocks().
	 * @param string[]                         $allowed_block_names
	 * @return list<array{block: string, path: string}>
	 */
	public static function forbidden_blocks( array $parsed_blocks, array $allowed_block_names ): array {
		$violations = array();

		foreach ( $parsed_blocks as $block ) {
			$name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';

			if ( '' === $name ) {
				// Handled by raw_html_violations(); a null-name block is never
				// a "forbidden block", it is raw markup.
				continue;
			}

			if ( ! in_array( $name, $allowed_block_names, true ) ) {
				$violations[] = array( 'block' => $name, 'path' => $name );
			}

			foreach ( self::forbidden_blocks( (array) ( $block['innerBlocks'] ?? array() ), $allowed_block_names ) as $nested ) {
				$violations[] = array( 'block' => $nested['block'], 'path' => $name . ' > ' . $nested['path'] );
			}
		}

		return $violations;
	}

	/**
	 * @param array<int, array<string, mixed>> $parsed_blocks
	 * @return list<string>
	 */
	public static function raw_html_violations( array $parsed_blocks ): array {
		$violations = array();

		foreach ( $parsed_blocks as $block ) {
			$name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
			$html = (string) ( $block['innerHTML'] ?? '' );

			if ( '' === $name && '' !== trim( $html ) ) {
				$violations[] = trim( $html );
			}

			foreach ( self::raw_html_violations( (array) ( $block['innerBlocks'] ?? array() ) ) as $nested ) {
				$violations[] = $nested;
			}
		}

		return $violations;
	}

	/**
	 * Per-block custom CSS lives in the block instance's `style.css` attribute
	 * (WordPress 7.0's customCSS block support). Core strips it on save for
	 * users without `edit_css`; this detects it earlier so the save is
	 * REJECTED with a clear error instead of silently losing content.
	 *
	 * @param array<int, array<string, mixed>> $parsed_blocks
	 * @return list<array{block: string, css: string}>
	 */
	public static function block_css_violations( array $parsed_blocks ): array {
		$violations = array();

		foreach ( $parsed_blocks as $block ) {
			$css = $block['attrs']['style']['css'] ?? null;

			if ( is_string( $css ) && '' !== trim( $css ) ) {
				$violations[] = array(
					'block' => is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '(unnamed)',
					'css'   => trim( $css ),
				);
			}

			foreach ( self::block_css_violations( (array) ( $block['innerBlocks'] ?? array() ) ) as $nested ) {
				$violations[] = $nested;
			}
		}

		return $violations;
	}

	/**
	 * Shortcodes run on the WHOLE post content, so a registered tag typed into
	 * a Paragraph block executes without any core/shortcode block being
	 * involved. $shortcode_regex must come from get_shortcode_regex(), which
	 * only matches REGISTERED tags — ordinary bracket text is never a match,
	 * and `[[tag]]` is the documented escape form (capture group 1 is the
	 * leading `[`).
	 *
	 * @return list<string>
	 */
	public static function shortcode_violations( string $content, string $shortcode_regex ): array {
		if ( '' === $shortcode_regex ) {
			return array();
		}

		$matches = array();
		$found   = preg_match_all( '/' . $shortcode_regex . '/', $content, $matches );

		if ( ! is_int( $found ) || 0 === $found ) {
			return array();
		}

		$violations = array();

		foreach ( (array) ( $matches[2] ?? array() ) as $index => $tag ) {
			if ( '[' === ( $matches[1][ $index ] ?? '' ) ) {
				continue;
			}

			$violations[] = (string) $tag;
		}

		return array_values( array_unique( $violations ) );
	}

	/**
	 * Finds custom CSS anywhere in a Global Styles `styles` tree.
	 *
	 * WordPress stores Global Styles CSS in four places inside one payload:
	 * the root (`styles.css`), per block type (`styles.blocks.<name>.css`),
	 * per element (`styles.elements.<name>.css`) and inside style variations
	 * (`styles.variations.<name>.…css`). Core sanitises them away for users
	 * without `edit_css` (class-wp-theme-json.php:3718), but silently — so a
	 * client's CSS vanishes with no explanation. This finds every occurrence
	 * so the write can be REFUSED with a message instead.
	 *
	 * Returns dotted paths, e.g. `styles.css`, `styles.blocks.core/group.css`.
	 *
	 * @param array<string, mixed> $styles The `styles` node of a global-styles payload.
	 * @return list<string>
	 */
	public static function global_styles_css_violations( array $styles, string $path = 'styles' ): array {
		$violations = array();

		foreach ( $styles as $key => $value ) {
			$child = $path . '.' . (string) $key;

			if ( 'css' === $key ) {
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$violations[] = $child;
				}

				continue;
			}

			if ( is_array( $value ) ) {
				foreach ( self::global_styles_css_violations( $value, $child ) as $nested ) {
					$violations[] = $nested;
				}
			}
		}

		return $violations;
	}

	private static function namespace_of( string $block_name ): string {
		$separator = strpos( $block_name, '/' );

		return false === $separator ? '' : substr( $block_name, 0, $separator );
	}
}
```

Add the matching unit tests to `tests/Unit/AgencyPlatform/BlockPolicyTest.php`:

```php
	public function test_global_styles_root_custom_css_is_detected(): void {
		self::assertSame(
			array( 'styles.css' ),
			BlockPolicy::global_styles_css_violations( array( 'css' => 'body{color:red}' ) )
		);
	}

	public function test_global_styles_block_and_element_custom_css_is_detected(): void {
		$styles = array(
			'blocks'   => array( 'core/group' => array( 'css' => '.x{}' ) ),
			'elements' => array( 'link' => array( 'css' => 'a{}' ) ),
		);

		self::assertSame(
			array( 'styles.blocks.core/group.css', 'styles.elements.link.css' ),
			BlockPolicy::global_styles_css_violations( $styles )
		);
	}

	public function test_global_styles_variation_custom_css_is_detected(): void {
		$styles = array( 'variations' => array( 'section-a' => array( 'blocks' => array( 'core/group' => array( 'css' => '.y{}' ) ) ) ) );

		self::assertSame(
			array( 'styles.variations.section-a.blocks.core/group.css' ),
			BlockPolicy::global_styles_css_violations( $styles )
		);
	}

	public function test_global_styles_without_custom_css_is_clean(): void {
		$styles = array(
			'color'      => array( 'background' => 'var(--wp--preset--color--base)' ),
			'blocks'     => array( 'core/group' => array( 'spacing' => array( 'padding' => array( 'top' => '1rem' ) ) ) ),
			'typography' => array( 'fontSize' => '1rem' ),
		);

		self::assertSame( array(), BlockPolicy::global_styles_css_violations( $styles ) );
	}

	public function test_an_empty_css_string_is_not_a_violation(): void {
		self::assertSame( array(), BlockPolicy::global_styles_css_violations( array( 'css' => '   ' ) ) );
	}
```

- [ ] **Step 4: Run the unit test and watch it pass**

Run: `ddev composer test:unit -- --filter BlockPolicyTest`
Expected: PASS.

- [ ] **Step 5: Rewrite EditorRestrictions on top of BlockPolicy**

Replace the body of `web/app/mu-plugins/agency-platform/src/Editor/EditorRestrictions.php`. `ALLOWED_BLOCKS` is deleted; the class keeps its name and its two filters so every doc and test reference stays valid.

```php
<?php

declare(strict_types=1);

namespace AgencyPlatform\Editor;

/**
 * Applies AgencyPlatform\Editor\BlockPolicy to the block editor and the Site
 * Editor for anyone who can't `manage_options`.
 *
 * The policy is derived from the registered block types rather than a fixed
 * list, so the Site Editor keeps every core template/query/navigation block it
 * needs, and a newly registered core block does not silently disappear from
 * the inserter. Projects extend it through three filters instead of editing
 * this file:
 *
 *   agency_platform_allowed_block_namespaces — array<string> of namespace
 *       prefixes (no slash). Default: BlockPolicy::DEFAULT_NAMESPACES.
 *   agency_platform_allowed_blocks — array<string> of extra block names to
 *       allow regardless of namespace.
 *   agency_platform_disallowed_blocks — array<string> of block names to deny.
 *
 * Each filter receives the current value plus the WP_Block_Editor_Context.
 * BlockPolicy::ALWAYS_DENIED wins over all three.
 */
final class EditorRestrictions {

	public function register(): void {
		add_filter( 'allowed_block_types_all', array( $this, 'filter_allowed_block_types' ), 10, 2 );
		add_filter( 'block_editor_settings_all', array( $this, 'filter_block_editor_settings' ), 10, 2 );
	}

	/**
	 * @param bool|string[] $allowed_block_types
	 * @return bool|string[]
	 */
	public function filter_allowed_block_types( $allowed_block_types, \WP_Block_Editor_Context $context ) {
		return BlockPolicy::resolve(
			current_user_can( 'manage_options' ),
			$allowed_block_types,
			self::registered_block_names(),
			self::allowed_namespaces( $context ),
			self::extra_allowed_blocks( $context ),
			self::extra_denied_blocks( $context )
		);
	}

	/**
	 * @return string[]
	 */
	public static function registered_block_names(): array {
		return array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() );
	}

	/**
	 * @return string[]
	 */
	public static function allowed_namespaces( \WP_Block_Editor_Context $context ): array {
		/**
		 * Filters the block namespaces a client role may use.
		 *
		 * @param string[]                 $namespaces Namespace prefixes without the slash.
		 * @param \WP_Block_Editor_Context $context    The current editor context.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'agency_platform_allowed_block_namespaces', BlockPolicy::DEFAULT_NAMESPACES, $context ) ) ) );
	}

	/**
	 * @return string[]
	 */
	public static function extra_allowed_blocks( \WP_Block_Editor_Context $context ): array {
		/**
		 * Filters block names allowed for client roles regardless of namespace.
		 *
		 * @param string[]                 $blocks  Block names.
		 * @param \WP_Block_Editor_Context $context The current editor context.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'agency_platform_allowed_blocks', array(), $context ) ) ) );
	}

	/**
	 * @return string[]
	 */
	public static function extra_denied_blocks( \WP_Block_Editor_Context $context ): array {
		/**
		 * Filters block names denied to client roles.
		 *
		 * @param string[]                 $blocks  Block names.
		 * @param \WP_Block_Editor_Context $context The current editor context.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'agency_platform_disallowed_blocks', array(), $context ) ) ) );
	}

	/**
	 * @param array<string, mixed> $editor_settings
	 * @return array<string, mixed>
	 */
	public function filter_block_editor_settings( array $editor_settings, \WP_Block_Editor_Context $context ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $context is required to match WordPress's `block_editor_settings_all` filter signature.
		if ( current_user_can( 'manage_options' ) ) {
			return $editor_settings;
		}

		// Clients may lock their OWN blocks (§5.2); they may never open the
		// code editor, which would bypass the block policy entirely.
		$editor_settings['canLockBlocks']      = true;
		$editor_settings['codeEditingEnabled'] = false;

		return $editor_settings;
	}
}
```

- [ ] **Step 6: Rewrite the EditorRestrictions unit test**

Replace `tests/Unit/AgencyPlatform/EditorRestrictionsPolicyTest.php` entirely. Its eight existing methods all key off the deleted `ALLOWED_BLOCKS` constant. The replacement tests only what `EditorRestrictions` still owns as pure logic — the class's WordPress-facing methods now need `WP_Block_Type_Registry` and `apply_filters`, so keep the file focused:

```php
<?php
/**
 * Proves the client block policy still holds its two non-negotiables after the
 * move from a fixed allow-list to a registered-block namespace policy: the
 * agency reference block stays insertable, and the arbitrary-markup escape
 * hatches stay denied. The exhaustive policy coverage lives in BlockPolicyTest.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Editor\BlockPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Editor\EditorRestrictions
 * @covers \AgencyPlatform\Editor\BlockPolicy
 */
final class EditorRestrictionsPolicyTest extends TestCase {

	/**
	 * @return list<string>
	 */
	private function registered(): array {
		return array( 'core/paragraph', 'core/html', 'core/shortcode', 'core/freeform', 'agency/reference-callout', 'woocommerce/mini-cart' );
	}

	public function test_client_roles_can_insert_the_reference_callout(): void {
		$allowed = BlockPolicy::resolve( false, true, $this->registered(), BlockPolicy::DEFAULT_NAMESPACES, array(), array() );

		self::assertContains( 'agency/reference-callout', (array) $allowed );
	}

	public function test_client_roles_can_insert_core_paragraph(): void {
		$allowed = BlockPolicy::resolve( false, true, $this->registered(), BlockPolicy::DEFAULT_NAMESPACES, array(), array() );

		self::assertContains( 'core/paragraph', (array) $allowed );
	}

	public function test_shortcode_and_html_blocks_are_excluded(): void {
		$allowed = BlockPolicy::resolve( false, true, $this->registered(), BlockPolicy::DEFAULT_NAMESPACES, array(), array() );

		self::assertNotContains( 'core/shortcode', (array) $allowed );
		self::assertNotContains( 'core/html', (array) $allowed );
	}

	public function test_privileged_users_get_the_incoming_value_back_unchanged(): void {
		self::assertSame(
			array( 'core/paragraph', 'core/heading' ),
			BlockPolicy::resolve( true, array( 'core/paragraph', 'core/heading' ), $this->registered(), BlockPolicy::DEFAULT_NAMESPACES, array(), array() )
		);
	}

	public function test_privileged_users_pass_through_a_bool_incoming_value(): void {
		self::assertTrue( BlockPolicy::resolve( true, true, $this->registered(), BlockPolicy::DEFAULT_NAMESPACES, array(), array() ) );
	}
}
```

Note for the executor: `docs/generated-block-index.md`'s Tests column lists this file because it contains the string `reference-callout`; the rewrite keeps that string, so the generated index does not change here.

- [ ] **Step 7: Write the save-validation policy unit test and the SaveValidation class**

Create `tests/Unit/AgencyPlatform/SaveValidationPolicyTest.php`:

```php
<?php
/**
 * Pins the set of post types the save boundary covers. Every one of these is
 * a surface a client can write through REST: post/page (the post editor),
 * wp_template and wp_template_part (the Site Editor), wp_block (synced
 * patterns) and wp_navigation (the navigation editor). Dropping one silently
 * opens a hole, so the list is asserted rather than trusted.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Editor\SaveValidation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Editor\SaveValidation
 */
final class SaveValidationPolicyTest extends TestCase {

	public function test_every_client_writable_post_type_is_validated(): void {
		self::assertSame(
			array( 'post', 'page', 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation' ),
			SaveValidation::VALIDATED_POST_TYPES
		);
	}

	public function test_the_site_editor_post_types_are_covered(): void {
		foreach ( array( 'wp_template', 'wp_template_part', 'wp_navigation' ) as $post_type ) {
			self::assertContains( $post_type, SaveValidation::VALIDATED_POST_TYPES );
		}
	}

	public function test_the_validator_is_a_public_filter_callback(): void {
		$method = new \ReflectionMethod( SaveValidation::class, 'validate_content' );

		self::assertTrue( $method->isPublic() );
		self::assertSame( 2, $method->getNumberOfParameters() );
	}
}
```

Then create `web/app/mu-plugins/agency-platform/src/Editor/SaveValidation.php`:

```php
<?php

declare(strict_types=1);

namespace AgencyPlatform\Editor;

/**
 * The security boundary the editor allow-list is NOT.
 *
 * `allowed_block_types_all` shapes the inserter; it does nothing about a
 * direct REST request, a pasted block tree, or an automated client. This class
 * re-applies the FULL resolved block policy on save and REJECTS the request
 * with a REST error rather than silently stripping content — stripping
 * destroys a client's work without telling them, and is reserved for an
 * explicit sanitisation command.
 *
 * Enforcement point: `rest_pre_insert_{$post_type}`, which both
 * WP_REST_Posts_Controller and WP_REST_Templates_Controller apply before
 * wp_insert_post() runs, and which can return a WP_Error. Every block-editor
 * and Site Editor save goes through it. Programmatic wp_insert_post() calls
 * (WP-CLI, importers, migrations) are deliberately NOT gated: that is agency
 * tooling, not client input.
 *
 * Four rejection classes, per BLOCK_THEME_PROPOSAL.md §9.3:
 *   1. Any block outside the resolved policy — recursively, not just html and
 *      shortcode. core/freeform is included via BlockPolicy::ALWAYS_DENIED.
 *   2. Non-whitespace raw HTML, i.e. a parse_blocks() block with a null name.
 *   3. Any REGISTERED shortcode tag anywhere in the content, because
 *      do_shortcode() runs on the whole post content and does not care which
 *      block the tag sits in.
 *   4. Per-block custom CSS (the block instance `style.css` attribute).
 */
final class SaveValidation {

	/**
	 * @var string[]
	 */
	public const VALIDATED_POST_TYPES = array(
		'post',
		'page',
		'wp_template',
		'wp_template_part',
		'wp_block',
		'wp_navigation',
	);

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_save_filters' ) );
	}

	public function register_save_filters(): void {
		foreach ( self::VALIDATED_POST_TYPES as $post_type ) {
			add_filter( "rest_pre_insert_{$post_type}", array( $this, 'validate_content' ), 10, 2 );
		}
	}

	/**
	 * @param mixed $prepared The prepared post object (stdClass) or WP_Error.
	 * @return mixed
	 */
	public function validate_content( $prepared, \WP_REST_Request $request ) {
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return $prepared;
		}

		$content = $request->get_param( 'content' );

		if ( is_array( $content ) ) {
			$content = $content['raw'] ?? '';
		}

		$content = (string) $content;

		if ( '' === $content ) {
			return $prepared;
		}

		$parsed  = parse_blocks( $content );
		$context = self::editor_context( $prepared, $request );

		// The FULL resolved policy for this user in THIS editor context —
		// including whatever the three agency_platform_* filters decide for it.
		// Re-deriving it here (rather than re-using a cached post-editor list)
		// is what makes the boundary match the editor a project has actually
		// configured, and lets a filter that widens the Site Editor without
		// widening the post editor stay honest on save.
		$allowed = BlockPolicy::resolve(
			false,
			apply_filters( 'allowed_block_types_all', true, $context ),
			EditorRestrictions::registered_block_names(),
			EditorRestrictions::allowed_namespaces( $context ),
			EditorRestrictions::extra_allowed_blocks( $context ),
			EditorRestrictions::extra_denied_blocks( $context )
		);

		if ( false === $allowed ) {
			return new \WP_Error(
				'agency_platform_no_blocks_allowed',
				__( 'Block editing is disabled for your role in this editor.', 'agency-platform' ),
				array( 'status' => 403 )
			);
		}

		$forbidden = BlockPolicy::forbidden_blocks( $parsed, (array) $allowed );

		if ( array() !== $forbidden ) {
			return new \WP_Error(
				'agency_platform_forbidden_block',
				sprintf(
					/* translators: 1: block name, 2: block path within the content. */
					__( 'The block "%1$s" is not allowed for your role (found at %2$s). Remove it and save again.', 'agency-platform' ),
					$forbidden[0]['block'],
					$forbidden[0]['path']
				),
				array( 'status' => 403, 'violations' => $forbidden )
			);
		}

		$raw_html = BlockPolicy::raw_html_violations( $parsed );

		if ( array() !== $raw_html ) {
			return new \WP_Error(
				'agency_platform_raw_html',
				__( 'Raw HTML is not allowed for your role. Rebuild this content with blocks and save again.', 'agency-platform' ),
				array( 'status' => 403, 'violations' => $raw_html )
			);
		}

		$shortcodes = BlockPolicy::shortcode_violations( $content, get_shortcode_regex() );

		if ( array() !== $shortcodes ) {
			return new \WP_Error(
				'agency_platform_shortcode',
				sprintf(
					/* translators: %s: shortcode tag. */
					__( 'The shortcode [%s] is not allowed for your role. Remove it and save again.', 'agency-platform' ),
					$shortcodes[0]
				),
				array( 'status' => 403, 'violations' => $shortcodes )
			);
		}

		$block_css = BlockPolicy::block_css_violations( $parsed );

		if ( array() !== $block_css ) {
			return new \WP_Error(
				'agency_platform_block_custom_css',
				sprintf(
					/* translators: %s: block name. */
					__( 'Custom CSS on the "%s" block is not allowed for your role. Use the design controls instead.', 'agency-platform' ),
					$block_css[0]['block']
				),
				array( 'status' => 403, 'violations' => $block_css )
			);
		}

		return $prepared;
	}

	/**
	 * Builds the editor context this save actually came from.
	 *
	 * Hard-coding `core/edit-site` would apply the Site Editor's resolved
	 * policy to a page saved from the post editor, so a project that widens one
	 * editor through the agency_platform_* filters would silently widen the
	 * other. `wp_template`, `wp_template_part` and `wp_navigation` are only
	 * ever written by the Site Editor; `post`, `page` and `wp_block` are the
	 * post editor's surfaces.
	 *
	 * @param object $prepared The prepared post object from the REST controller.
	 */
	public static function editor_context( object $prepared, \WP_REST_Request $request ): \WP_Block_Editor_Context {
		$post_type = isset( $prepared->post_type ) ? (string) $prepared->post_type : (string) $request->get_param( 'type' );
		$settings  = array(
			'name' => in_array( $post_type, self::SITE_EDITOR_POST_TYPES, true ) ? 'core/edit-site' : 'core/edit-post',
		);

		$post_id = isset( $prepared->ID ) ? (int) $prepared->ID : (int) $request->get_param( 'id' );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( $post instanceof \WP_Post ) {
			$settings['post'] = $post;
		}

		return new \WP_Block_Editor_Context( $settings );
	}
}
```

Add the companion constant beside `VALIDATED_POST_TYPES`:

```php
	/**
	 * The subset of VALIDATED_POST_TYPES the Site Editor owns. Everything else
	 * in that list is a post-editor surface.
	 *
	 * @var string[]
	 */
	public const SITE_EDITOR_POST_TYPES = array( 'wp_template', 'wp_template_part', 'wp_navigation' );
```

- [ ] **Step 8: Guard Global Styles writes**

`SaveValidation` cannot see a Global Styles write. Verified: `WP_REST_Global_Styles_Controller` extends `WP_REST_Posts_Controller` but **overrides** `prepare_item_for_database()` (`web/wp/wp-includes/rest-api/endpoints/class-wp-rest-global-styles-controller.php:238`), and the whole file contains **no** `apply_filters()` call — so `rest_pre_insert_wp_global_styles` is never applied. Core's own handling (`class-wp-theme-json.php:3718`) sanitises `css` away for users without `edit_css`, but silently: the client's CSS disappears with no message, which is exactly the behaviour §9.3 forbids.

The guard therefore hooks `rest_pre_dispatch` (`web/wp/wp-includes/rest-api/class-wp-rest-server.php:1079` — returning a non-`null` value short-circuits the request and the value becomes the response).

Create `web/app/mu-plugins/agency-platform/src/Editor/GlobalStylesGuard.php`:

```php
<?php

declare(strict_types=1);

namespace AgencyPlatform\Editor;

/**
 * Refuses custom CSS in a Global Styles write from a client role.
 *
 * This is a SEPARATE class from SaveValidation because Global Styles has a
 * separate save path: WP_REST_Global_Styles_Controller overrides
 * prepare_item_for_database() and applies no rest_pre_insert_* filter, so the
 * post-type save boundary never sees the request. rest_pre_dispatch is the
 * earliest hook that does, and returning a WP_Error from it short-circuits the
 * request with that error.
 *
 * It covers three of the four custom-CSS surfaces BLOCK_THEME_PROPOSAL.md
 * §9.3 names — Global Styles root CSS, block-type CSS and element/variation
 * CSS. The fourth (an individual block instance's `style.css` attribute) lives
 * in post content and belongs to SaveValidation. The Customizer's `custom_css`
 * post type needs no guard: WordPress maps every one of its post-type
 * capabilities to `edit_css` (wp-includes/post.php:209-212), which
 * AgencyPlatform\Security\CapabilityPolicy denies outright.
 *
 * Rejecting rather than stripping is the point: core already strips, and a
 * client whose CSS silently vanishes files a bug against the agency.
 */
final class GlobalStylesGuard {

	public const ROUTE_PREFIX = '/wp/v2/global-styles/';

	/**
	 * @var string[]
	 */
	public const WRITE_METHODS = array( 'POST', 'PUT', 'PATCH' );

	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'guard_global_styles_write' ), 10, 3 );
	}

	/**
	 * @param mixed $result Response to short-circuit with, or null to continue.
	 * @return mixed
	 */
	public function guard_global_styles_write( $result, \WP_REST_Server $server, \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $server is required to match WordPress's `rest_pre_dispatch` filter signature.
		if ( null !== $result ) {
			return $result;
		}

		if ( ! self::is_global_styles_write( (string) $request->get_route(), (string) $request->get_method() ) ) {
			return $result;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return $result;
		}

		$styles     = $request->get_param( 'styles' );
		$violations = is_array( $styles ) ? BlockPolicy::global_styles_css_violations( $styles ) : array();

		if ( array() === $violations ) {
			return $result;
		}

		return new \WP_Error(
			'agency_platform_global_styles_custom_css',
			sprintf(
				/* translators: %s: dotted path to the offending custom CSS, e.g. styles.blocks.core/group.css */
				__( 'Custom CSS is not allowed for your role (found at %s). Use the design controls instead.', 'agency-platform' ),
				$violations[0]
			),
			array( 'status' => 403, 'violations' => $violations )
		);
	}

	/**
	 * Pure route/method test, so it is unit-testable without a REST server.
	 */
	public static function is_global_styles_write( string $route, string $method ): bool {
		if ( ! str_starts_with( $route, self::ROUTE_PREFIX ) ) {
			return false;
		}

		return in_array( strtoupper( $method ), self::WRITE_METHODS, true );
	}
}
```

Add `tests/Unit/AgencyPlatform/GlobalStylesGuardTest.php` covering the pure route test:

```php
<?php
/**
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Editor\GlobalStylesGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Editor\GlobalStylesGuard
 */
final class GlobalStylesGuardTest extends TestCase {

	public function test_a_global_styles_write_is_guarded(): void {
		foreach ( array( 'POST', 'PUT', 'PATCH', 'post' ) as $method ) {
			self::assertTrue( GlobalStylesGuard::is_global_styles_write( '/wp/v2/global-styles/12', $method ) );
		}
	}

	public function test_a_global_styles_read_is_not_guarded(): void {
		foreach ( array( 'GET', 'HEAD', 'OPTIONS' ) as $method ) {
			self::assertFalse( GlobalStylesGuard::is_global_styles_write( '/wp/v2/global-styles/12', $method ) );
		}
	}

	public function test_other_routes_are_not_guarded(): void {
		self::assertFalse( GlobalStylesGuard::is_global_styles_write( '/wp/v2/pages/12', 'POST' ) );
		self::assertFalse( GlobalStylesGuard::is_global_styles_write( '/wp/v2/templates', 'POST' ) );
	}
}
```

- [ ] **Step 9: Wire SaveValidation and GlobalStylesGuard into Plugin**

In `web/app/mu-plugins/agency-platform/src/Plugin.php`, add `use AgencyPlatform\Editor\GlobalStylesGuard;` and `use AgencyPlatform\Editor\SaveValidation;`, then insert both entries immediately after `new EditorRestrictions(),`:

```php
			new EditorRestrictions(),
			new SaveValidation(),
			new GlobalStylesGuard(),
```

- [ ] **Step 10: Write the save-validation integration test**

Create `tests/Integration/Editor/SaveValidationTest.php`. Build it on this shape — the first two cases are written out in full so the harness is unambiguous, and the remaining cases follow the same pattern:

```php
<?php
/**
 * Proves the save boundary against a real WordPress + REST stack. The editor
 * allow-list is a UI convenience; this is the security boundary, so every
 * rejection class BLOCK_THEME_PROPOSAL.md §9.3 names is exercised through an
 * actual REST request rather than through the policy functions alone.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Editor;

use Tests\Integration\IntegrationTestCase;

final class SaveValidationTest extends IntegrationTestCase {

	private const ORIGINAL = '<!-- wp:paragraph --><p>Original content.</p><!-- /wp:paragraph -->';

	public function set_up(): void {
		parent::set_up();

		do_action( 'rest_api_init' );
		add_shortcode( 'agency_test_shortcode', '__return_empty_string' );
	}

	public function tear_down(): void {
		remove_shortcode( 'agency_test_shortcode' );

		parent::tear_down();
	}

	private function make_page(): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => self::ORIGINAL,
			)
		);
	}

	private function save_as_client( int $page_id, string $content ): \WP_REST_Response {
		wp_set_current_user( $this->make_client_editor()->ID );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/pages/' . $page_id );
		$request->set_param( 'content', $content );

		return rest_do_request( $request );
	}

	public function test_a_forbidden_block_is_rejected_with_a_named_rest_error(): void {
		$page_id  = $this->make_page();
		$response = $this->save_as_client( $page_id, '<!-- wp:html --><div>raw</div><!-- /wp:html -->' );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( 'agency_platform_forbidden_block', $response->as_error()->get_error_code() );
		self::assertStringContainsString( 'core/html', $response->as_error()->get_error_message() );
	}

	public function test_a_rejected_save_leaves_the_original_content_intact(): void {
		$page_id = $this->make_page();

		$this->save_as_client( $page_id, '<!-- wp:html --><div>raw</div><!-- /wp:html -->' );

		// Rejection, never silent stripping: destroying a client's content
		// without telling them is worse than refusing the save.
		self::assertSame( self::ORIGINAL, get_post( $page_id )->post_content );
	}
}
```

Add one test per remaining case, each following `save_as_client()` + status/code assertions:



| Content posted as `client_editor` | Expected status | Expected error code |
|---|---|---|
| `<!-- wp:group --><div class="wp-block-group"><!-- wp:html --><div>x</div><!-- /wp:html --></div><!-- /wp:group -->` (nested — proves recursion) | 403 | `agency_platform_forbidden_block` |
| `<!-- wp:freeform -->Classic content<!-- /wp:freeform -->` | 403 | `agency_platform_forbidden_block` |
| `<!-- wp:acme/thing /-->` (unregistered block) | 403 | `agency_platform_forbidden_block` |
| `<script>alert(1)</script>` (null-name block, real markup) | 403 | `agency_platform_raw_html` |
| `<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->` (whitespace-only null-name separators) | 200 | — |
| `<!-- wp:paragraph --><p>Call [agency_test_shortcode] now</p><!-- /wp:paragraph -->` | 403 | `agency_platform_shortcode` |
| `<!-- wp:paragraph --><p>Rates are [subject to change]</p><!-- /wp:paragraph -->` (unregistered bracket text) | 200 | — |
| `<!-- wp:paragraph --><p>See [[agency_test_shortcode]]</p><!-- /wp:paragraph -->` (escaped form) | 200 | — |
| `<!-- wp:paragraph {"style":{"css":"color:red"}} --><p>x</p><!-- /wp:paragraph -->` | 403 | `agency_platform_block_custom_css` |
| `<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Fine</p><!-- /wp:paragraph --></div><!-- /wp:group -->` | 200 | — |

Plus three cases that need a different harness:

- **Administrator control.** The `core/html` payload posted as `$this->make_admin()` returns 200. This proves the rule is role-scoped rather than a blanket block, and that the test itself is capable of a passing save.
- **Site Editor context.** Apply the same four rejection classes to `POST /wp/v2/templates/site-theme//page` and `POST /wp/v2/template-parts/site-theme//site-header`. Task 5 has already put block templates on disk. These required cases have no skip path. The task verification must fail if the block-theme environment is unavailable.
**Execution correction for the preceding Site Editor context case:** Task 5 has already created the block-theme files before this task runs. Do not use a conditional bypass. Those four required template and part REST cases must execute here. A missing block-theme environment is a failing task gate.

- **Content-level shortcode with an unloaded post type.** `POST /wp/v2/navigation/<id>` with the shortcode payload returns 403 `agency_platform_shortcode`, proving `VALIDATED_POST_TYPES` really is wired for every entry, not just `page`.
- **Editor context is derived, not assumed.** Register `add_filter( 'agency_platform_allowed_blocks', fn( array $blocks, \WP_Block_Editor_Context $context ): array => 'core/edit-site' === $context->name ? array( 'acme/site-only' ) : $blocks, 10, 2 )`, register a dummy `acme/site-only` block type, then post it to `/wp/v2/templates/site-theme//page` (must succeed, 200) and to `/wp/v2/pages/<id>` (must be rejected, 403 `agency_platform_forbidden_block`). This proves `SaveValidation::editor_context()` builds the real context instead of hard-coding `core/edit-site`. Remove the filter and unregister the block in `tear_down()`.

**All four custom-CSS surfaces (§9.3, §11.13).** Add `tests/Integration/Editor/CustomCssSurfacesTest.php` covering every one, each asserting both the rejection AND that the stored data is unchanged afterwards:

| Surface | Request | Expected |
|---|---|---|
| 1. Block-instance `style.css` | `POST /wp/v2/pages/<id>` with `<!-- wp:paragraph {"style":{"css":"color:red"}} -->` | 403 `agency_platform_block_custom_css`; `get_post( $id )->post_content` unchanged |
| 2. Global Styles root CSS | `POST /wp/v2/global-styles/<id>` with `styles => array( 'css' => 'body{color:red}' )` | 403 `agency_platform_global_styles_custom_css`; re-reading `/wp/v2/global-styles/<id>` shows no `css` |
| 3. Global Styles block-type CSS | `POST /wp/v2/global-styles/<id>` with `styles => array( 'blocks' => array( 'core/group' => array( 'css' => '.x{}' ) ) )` | 403 `agency_platform_global_styles_custom_css` naming `styles.blocks.core/group.css`; stored styles unchanged |
| 4. Customizer `custom_css` post type | `current_user_can( get_post_type_object( 'custom_css' )->cap->edit_posts )` and `current_user_can( 'edit_css' )` as `client_editor` | both `false` — core maps every `custom_css` capability to `edit_css` (`wp-includes/post.php:209-212`), which `CapabilityPolicy` denies |

Get the Global Styles post id with `\WP_Theme_JSON_Resolver::get_user_global_styles_post_id()`, and take a snapshot of the stored `styles` before each rejected write so the "unchanged" assertion compares against a real value rather than an empty array. Add an administrator control for surfaces 2 and 3: the same payload posted as `$this->make_admin()` returns 200, which proves the guard is role-scoped and that the test can produce a passing write at all.

Also add to `tests/Integration/Permissions/ClientEditorCapabilitiesTest.php`, replacing `test_client_editor_is_restricted_to_the_approved_block_allow_list()`:

```php
	public function test_client_editor_gets_a_registered_block_policy_not_a_fixed_list(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$allowed = apply_filters( 'allowed_block_types_all', true, new \WP_Block_Editor_Context() );

		self::assertIsArray( $allowed );
		self::assertContains( 'agency/reference-callout', $allowed );
		self::assertContains( 'core/paragraph', $allowed );
		self::assertContains( 'core/template-part', $allowed );
		self::assertContains( 'core/navigation', $allowed );
		self::assertNotContains( 'core/html', $allowed );
		self::assertNotContains( 'core/shortcode', $allowed );
		self::assertNotContains( 'core/freeform', $allowed );
	}

	public function test_the_allowed_block_namespace_filter_is_honoured(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		add_filter( 'agency_platform_allowed_block_namespaces', static fn(): array => array( 'agency' ) );

		$allowed = apply_filters( 'allowed_block_types_all', true, new \WP_Block_Editor_Context() );

		self::assertContains( 'agency/reference-callout', (array) $allowed );
		self::assertNotContains( 'core/paragraph', (array) $allowed );
	}
```

(The closure above lives in a TEST file; `HookOwnershipTest` only scans production code under the plugin/theme roots, so it is allowed there.)

- [ ] **Step 11: Run the full PHP verification**

Run: `ddev composer verify`
Expected: PASS, with no known red. `ClientEditorCapabilitiesTest::test_code_editing_is_disabled_and_block_locking_is_enabled_for_client_editor` is written in this task (Task 3 deliberately does **not** write it), so `canLockBlocks = true` and its assertion land together.

- [ ] **Step 12: Commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform tests/Unit/AgencyPlatform tests/Integration
git commit -m "feat: enforce the resolved block policy on save"
```

---

## Task 5: Phase 0 spike and gate

§8.2 requires a minimum block-theme spike, and §8.3 requires its gate to pass before Phase 1. It is a real, separate commit, and it really does coexist with the classic files, for two reasons verified in the installed core tree:

- `wp_enable_block_templates()` (`web/wp/wp-includes/theme-templates.php:132-141`) adds `block-templates` support whenever `wp_is_block_theme() || wp_theme_has_theme_json()`. This theme already ships a `theme.json`, so the support flag is **already** on before this task starts.
- `locate_block_template()` (`web/wp/wp-includes/block-template.php:62-92`) first asks `locate_template()` for a PHP template, and when it finds one it **slices the hierarchy at that template** so only block templates of equal or higher specificity are considered. A page request finds `page.php`, so with only `templates/index.html` on disk no block template is in the sliced list and the classic `page.php` still renders.

The spike therefore changes exactly one thing: requests whose hierarchy bottoms out at `index` — on a fresh install, the posts-index front page `/`. Everything else keeps rendering classically until the conversion task.

**Declared gates for this commit:** `ddev composer verify` and the §8.3 checklist below. The Playwright suites are **not** gates for this commit and are expected to fail on `/`: the spike deliberately re-renders the home page. That is the whole point of a spike, §8.3's gate list does not include them, and Task 9 re-establishes them.

**Files:**
- Create: `web/app/themes/site-theme/templates/index.html`
- Create: `web/app/themes/site-theme/templates/page.html` (TEMPORARY spike version — replaced in Task 6)
- Create: `web/app/themes/site-theme/parts/site-header.html`
- Create: `web/app/themes/site-theme/parts/site-footer.html`
- Create: `web/app/themes/site-theme/assets/global/editor.css`
- Modify: `web/app/themes/site-theme/theme.json`
- Modify: `web/app/themes/site-theme/src/Bootstrap/ThemeBootstrap.php`
- Modify: `tests/Architecture/GlobalAssetRulesTest.php` (allow-list only)
- Create: `tests/Integration/Theme/BlockThemeSpikeTest.php`
- Modify: `docs/block-theme-migration-baseline.md` (Phase 0 findings)

**Interfaces:**
- Consumes: nothing from Tasks 3–4.
- Produces: the two template parts and `theme.json.templateParts`, which Task 6 builds the full template set on. `templates/page.html` here is a throwaway two-block file; Task 6 replaces its contents.

- [ ] **Step 1: Add the two template parts**

Create `web/app/themes/site-theme/parts/site-header.html`. Only **one** layer may emit the `<header>` element: `render_block_core_template_part()` (`web/wp/wp-includes/blocks/template-part.php:170-181`) always wraps the part's content in `tagName`, or in the area's `area_tag` (`header` for the `header` area) when `tagName` is absent. So the template's `core/template-part` block owns the semantic tag and the `.site-header` class, and the part file opens a plain inner group.

```html
<!-- wp:group {"className":"site-header__inner"} -->
<div class="wp-block-group site-header__inner">
<!-- wp:group {"className":"site-header__branding"} -->
<div class="wp-block-group site-header__branding">
<!-- wp:site-logo /-->
<!-- wp:site-title {"level":0} /-->
<!-- wp:site-tagline /-->
</div>
<!-- /wp:group -->
<!-- wp:navigation {"overlayMenu":"mobile","className":"site-header__nav"} /-->
<!-- wp:buttons {"className":"site-header__actions"} -->
<div class="wp-block-buttons site-header__actions">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Contact</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
```

Site Logo, Site Title, Site Tagline, Navigation, Group and Buttons are all present, as §8.2 requires.

Create `web/app/themes/site-theme/parts/site-footer.html`:

```html
<!-- wp:group {"className":"site-footer__inner"} -->
<div class="wp-block-group site-footer__inner">
<!-- wp:paragraph {"className":"site-footer__copyright"} -->
<p class="site-footer__copyright">© Agency Starter. All rights reserved.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
```

- [ ] **Step 2: Add the two spike templates**

Create `web/app/themes/site-theme/templates/index.html`:

```html
<!-- wp:template-part {"slug":"site-header","tagName":"header","area":"header","className":"site-header"} /-->

<!-- wp:group {"tagName":"main","anchor":"site-main","layout":{"type":"constrained"}} -->
<main class="wp-block-group" id="site-main">
<!-- wp:query {"queryId":0,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":true}} -->
<div class="wp-block-query">
<!-- wp:post-template -->
<!-- wp:post-title {"level":2,"isLink":true} /-->
<!-- wp:post-excerpt /-->
<!-- /wp:post-template -->

<!-- wp:query-no-results -->
<!-- wp:paragraph -->
<p>Nothing found.</p>
<!-- /wp:paragraph -->
<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"site-footer","tagName":"footer","area":"footer","className":"site-footer"} /-->
```

Create the TEMPORARY `web/app/themes/site-theme/templates/page.html` §8.2 asks for. Task 6 replaces its contents; it exists here only so an administrator has a second template to open and save during the gate:

```html
<!-- wp:template-part {"slug":"site-header","tagName":"header","area":"header","className":"site-header"} /-->

<!-- wp:group {"tagName":"main","anchor":"site-main","layout":{"type":"constrained"}} -->
<main class="wp-block-group" id="site-main">
<!-- wp:post-title {"level":1} /-->
<!-- wp:post-content {"layout":{"type":"constrained"}} /-->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"site-footer","tagName":"footer","area":"footer","className":"site-footer"} /-->
```

Note the consequence, and record it in the Phase 0 findings: adding `templates/page.html` makes block rendering win for page requests too, because `page` has equal specificity to the `page.php` the hierarchy found. That is expected and is what makes the gate's "administrator can edit and save the template" check meaningful.

- [ ] **Step 3: Register the parts in theme.json**

Add a top-level `templateParts` array immediately after `"version": 3,` in `web/app/themes/site-theme/theme.json`:

```json
  "templateParts": [
    { "name": "site-header", "title": "Site Header", "area": "header" },
    { "name": "site-footer", "title": "Site Footer", "area": "footer" }
  ],
```

Change nothing else in `theme.json` yet — the settings flips belong to Task 6.

- [ ] **Step 4: Add the minimal editor-safe stylesheet**

Create `web/app/themes/site-theme/assets/global/editor.css`. WordPress prefixes every selector in an `add_editor_style()` sheet with `.editor-styles-wrapper`, so write them unscoped:

```css
/*
 * Editor-only canvas corrections. WordPress scopes every selector in this file
 * to .editor-styles-wrapper automatically (see add_editor_style()), so write
 * them unscoped. Nothing here may be needed on the frontend — if a rule is,
 * it belongs in a stylesheet the frontend also loads.
 */

body {
	background-color: var(--wp--preset--color--base);
}

/* The canvas renders a template part inline in the post flow rather than as a
   document region, so its child margins can collapse out and shift the header
   or footer a few pixels against the frontend. A new block formatting context
   keeps the later editing-parity geometry check honest. */
.wp-block-template-part {
	display: flow-root;
}
```

In `tests/Architecture/GlobalAssetRulesTest.php`, extend `ALLOWED_GLOBAL_CSS` to `array( 'base.css', 'typography.css', 'editor.css' )` and rename the test to `test_global_stylesheet_directory_holds_only_the_allowed_files()`. Task 6 narrows the list to the final three-file split.

- [ ] **Step 5: Teach ThemeBootstrap to load editor styles**

In `web/app/themes/site-theme/src/Bootstrap/ThemeBootstrap.php`, add the constant and the two `setup()` lines. Change nothing else: `Parts`, the part asset loop and `register_nav_menus()` all stay, because the classic path is still live for most requests.

```php
	/**
	 * Stylesheets loaded into the block editor canvas. The frontend reset is
	 * deliberately absent — a document-level reset fights the canvas's own
	 * layout. GlobalAssetRulesTest asserts that exclusion once the CSS split
	 * lands.
	 *
	 * @var string[]
	 */
	public const EDITOR_STYLESHEETS = array(
		'assets/global/editor.css',
	);
```

and inside `setup()`, after the existing `add_theme_support( 'editor-styles' );`:

```php
		add_editor_style( self::EDITOR_STYLESHEETS );
```

- [ ] **Step 6: Write the Phase 0 gate test**

Create `tests/Integration/Theme/BlockThemeSpikeTest.php`. It encodes the §8.3 gate items a test can prove; the two that need a browser are Step 8's manual checks.

```php
<?php
/**
 * The BLOCK_THEME_PROPOSAL.md §8.3 Phase 0 gate, as far as PHPUnit can prove
 * it: WordPress recognises the theme as a block theme, the spike templates
 * resolve and render, both template parts resolve, and PHP can still register
 * the theme's custom blocks.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Theme;

use Tests\Integration\IntegrationTestCase;

final class BlockThemeSpikeTest extends IntegrationTestCase {

	public function test_wordpress_recognises_the_theme_as_a_block_theme(): void {
		self::assertTrue( wp_is_block_theme() );
		self::assertSame( 'site-theme', get_stylesheet() );
	}

	public function test_the_index_template_resolves_and_renders(): void {
		$slugs = wp_list_pluck( get_block_templates(), 'slug' );

		self::assertContains( 'index', $slugs );

		$template = get_block_template( get_stylesheet() . '//index', 'wp_template' );

		self::assertNotNull( $template );
		self::assertNotSame( '', trim( do_blocks( $template->content ) ) );
	}

	public function test_both_template_parts_resolve_and_render(): void {
		$slugs = wp_list_pluck( get_block_templates( array(), 'wp_template_part' ), 'slug' );

		self::assertContains( 'site-header', $slugs );
		self::assertContains( 'site-footer', $slugs );

		foreach ( array( 'site-header', 'site-footer' ) as $slug ) {
			$part = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template_part' );

			self::assertNotNull( $part, $slug . ' must resolve.' );
			self::assertNotSame( '', trim( do_blocks( $part->content ) ), $slug . ' must render.' );
		}
	}

	public function test_php_still_registers_the_theme_custom_blocks(): void {
		// §8.3: "Confirm PHP code can continue registering custom blocks."
		self::assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'agency/reference-callout' ) );
	}

	public function test_an_administrator_can_edit_and_save_a_template(): void {
		do_action( 'rest_api_init' );

		wp_set_current_user( $this->make_admin()->ID );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/templates/' . get_stylesheet() . '//index' );
		$request->set_param( 'content', "<!-- wp:paragraph -->\n<p>Spike edit.</p>\n<!-- /wp:paragraph -->" );

		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status() );

		$saved = get_block_template( get_stylesheet() . '//index', 'wp_template' );

		self::assertStringContainsString( 'Spike edit.', (string) $saved->content );
	}

	public function test_the_classic_path_still_serves_templates_the_spike_does_not_cover(): void {
		// locate_block_template() slices the hierarchy at the PHP template it
		// found, so a request type with no equal-or-higher block template still
		// renders classically. This is what lets the spike coexist.
		self::assertFileExists( dirname( __DIR__, 3 ) . '/web/app/themes/site-theme/templates/single.php' );
		self::assertNotContains( 'single', wp_list_pluck( get_block_templates(), 'slug' ) );
	}
}
```

- [ ] **Step 7: Run the PHP gate**

```bash
ddev composer verify
```

Expected: PASS, including `BlockThemeSpikeTest`. `deptrac` must stay clean — the spike adds no cross-layer reference.

- [ ] **Step 8: Run the §8.3 orchestrator browser gate**

```powershell
$proofRoot = 'C:\tmp\bt-task-1-spike-proof'
$expectedRoot = [IO.Path]::GetFullPath( $proofRoot )
if ( Test-Path -LiteralPath $expectedRoot ) { throw 'The disposable proof worktree already exists. Stop and inspect it; do not delete it from this checkout.' }
git worktree add --detach $expectedRoot HEAD
if ( (Resolve-Path -LiteralPath $expectedRoot).Path -ne $expectedRoot ) { throw 'The proof worktree path is not exact.' }
ddev stop
Push-Location $expectedRoot
ddev config --project-name=bt-task-1-spike-proof --project-type=wordpress --docroot=web/wp --create-docroot=false
ddev start
ddev composer install --no-interaction --prefer-dist
ddev wp core install --url=https://bt-task-1-spike-proof.ddev.site --title='Block theme spike proof' --admin_user=admin --admin_password=admin --admin_email=admin@example.invalid --skip-email
ddev wp theme activate site-theme
ddev wp plugin activate site-core site-integrations
ddev wp rewrite structure '/%postname%/' --hard
$navigation = '<!-- wp:navigation-link {"label":"Home","url":"https://bt-task-1-spike-proof.ddev.site/","kind":"custom","isTopLevelLink":true} /-->'
ddev wp post create --post_type=wp_navigation --post_status=publish --post_title=Primary --post_content=$navigation --porcelain
```

This is a temporary bootstrap seed. It creates one ref-less `wp_navigation` record and pretty permalinks before the browser gate. Task 8 remains the owner of the tracked `scripts/setup` and CI bootstrap correction. Never run `ddev delete` in the task or main checkout. After recording the gate, run `ddev stop`, `Pop-Location`, remove the proof worktree with `git worktree remove $expectedRoot`, and confirm `git worktree list` no longer names it.

Then use browser automation as `admin` / `admin` and confirm every item below. Save the result in the unit evidence. No separate human action is required when the orchestrator can control the browser.

1. `https://bt-task-1-spike-proof.ddev.site/` renders through `templates/index.html`: the block header (site logo area, title, tagline, navigation, Contact button) and the block footer are visible.
2. `https://bt-task-1-spike-proof.ddev.site/sample-page/` renders through `templates/page.html`.
3. `https://bt-task-1-spike-proof.ddev.site/hello-world/` still renders through the **classic** `templates/single.php` — the classic header markup (`#site-header-nav`) is present. This is the coexistence proof.
4. `/wp/wp-admin/site-editor.php?p=/template` lists the templates; opening `index` and `page` shows no "unexpected or invalid content" warning on any block.
5. Editing and saving `index` in the Site Editor succeeds, and the change appears on `/`.
6. Both template parts appear under Patterns → Template Parts, in the Header and Footer areas.

- [ ] **Step 9: Record the Phase 0 findings**

Append to `docs/block-theme-migration-baseline.md`:

- a `## Phase 0 spike findings` section stating which request types the spike moved to block rendering (`/` and page requests) and which stayed classic, with the `locate_block_template()` slicing rule as the reason;
- a `## Intentional content differences` section recording that the block footer drops the classic `gmdate( 'Y' )` year and the `get_bloginfo( 'name' )` interpolation, because no core block produces either — the copyright line becomes client-editable static text;
- the required test replacements confirmed by this task: `GlobalAssetRulesTest::ALLOWED_GLOBAL_CSS` widened, and the list of tests Task 6 and Task 7 must replace.

- [ ] **Step 10: Commit the spike**

```bash
ddev composer verify:fast
git add web/app/themes/site-theme tests/Architecture/GlobalAssetRulesTest.php tests/Integration/Theme/BlockThemeSpikeTest.php docs/block-theme-migration-baseline.md
git commit -m "spike: prove native block theme and editor styles"
```

---

## Task 6: Convert the theme to a native block theme and delete the classic path

Task 5's spike already put `parts/site-header.html`, `parts/site-footer.html`, `templates/index.html`, a throwaway `templates/page.html`, `assets/global/editor.css` and `theme.json.templateParts` in place, and proved the §8.3 gate. This task finishes the job: the remaining four templates, the real `page.html`, the three-file CSS split, the `theme.json` settings flips, the block-theme `ThemeBootstrap`, the architecture-test replacements, and the deletion of the classic path.

**Ordering constraints, all verified in the installed core tree:**

- `locate_block_template()` slices the template hierarchy at the PHP template `locate_template()` found, and only considers block templates of **equal or higher** specificity. `page.html` has equal specificity to `page.php`, `single.html` to `single.php`, and so on — so the moment all six block templates exist, the block path wins for **every** request and the classic files become unreachable dead code. Deleting them afterwards is a rendering no-op, which is why Step 8's deletion is safe and why it gets its own commit.
- `WP_Theme::__construct()` only tolerates a missing root `index.php` once the theme is a block theme, so `templates/index.html` must exist before `index.php` is deleted — Task 5 already guaranteed that.

**Commits and their declared gates (see the commit-gate policy in Global Constraints):**

| Commit | Contents | Declared gates |
|---|---|---|
| `feat: migrate global styles and editor assets` | Steps 5–6: the three-file CSS split and the `ThemeBootstrap` stylesheet constants | `ddev composer verify`, `npm run lint`; the frontend is byte-for-byte unchanged, so `npm run test:visual` must ALSO still pass |
| `feat: convert theme templates and parts to block markup` | Steps 1–4 and 7, plus Steps 9–16: the templates, the parts, `theme.json`, and every architecture/integration test replacement | `ddev composer verify` |
| `chore: remove classic theme path and stale tests` | Step 8 and Step 17 | `ddev composer verify` |

The Playwright suites are **not** gates for the second and third commits: the specs still assert classic markup, and Task 9 rewrites them. That window is declared, bounded to two commits, and closed before the branch is offered for review.

**Files:**
- Create: `web/app/themes/site-theme/templates/{404,archive,search,single}.html`
- Modify: `web/app/themes/site-theme/templates/{index,page}.html` (Task 5 created both; `page.html` gets its real contents here)
- Modify: `web/app/themes/site-theme/parts/{site-header,site-footer}.html` (Task 5 created both)
- Create: `web/app/themes/site-theme/assets/global/{frontend-reset,shared}.css`
- Modify: `web/app/themes/site-theme/assets/global/editor.css` (Task 5 created it)
- Delete: `web/app/themes/site-theme/{404,archive,index,page,search,single,header,footer}.php`
- Delete: `web/app/themes/site-theme/templates/{404,archive,index,page,search,single}.php`
- Delete: `web/app/themes/site-theme/parts/site-header/` and `parts/site-footer/` (all five files)
- Delete: `web/app/themes/site-theme/src/Support/Parts.php`
- Delete: `web/app/themes/site-theme/assets/global/{base,typography}.css`
- Delete: `tests/Unit/SiteTheme/PartsTest.php`
- Modify: `web/app/themes/site-theme/src/Bootstrap/ThemeBootstrap.php`
- Modify: `web/app/themes/site-theme/theme.json`
- Modify: `web/app/themes/site-theme/functions.php` (docblock only)
- Modify: `web/app/themes/site-theme/style.css` (theme header Description)
- Modify: `tests/Architecture/DirectoryRulesTest.php`
- Modify: `tests/Architecture/ThemeBootstrapTest.php`
- Modify: `tests/Architecture/GlobalAssetRulesTest.php`
- Modify: `tests/Architecture/WooCommerceIsolationTest.php` (docblock and failure wording only)
- Create: `tests/Architecture/BlockThemeStructureTest.php`
- Create: `tests/Integration/Theme/BlockTemplateIntegrityTest.php`
- Modify: `tests/support/BlockIndexGenerator.php`
- Modify: `package.json` (`lint:js`), `eslint.config.cjs`
- Modify: `docs/generated-block-index.md` (regenerated)

**Interfaces:**
- Consumes: nothing from Tasks 3–4.
- Produces:
  - Block templates and parts on disk at the exact paths above (later engagement tasks promote database overrides onto these files, and the commerce track adds `templates/single-product.html` and friends beside them).
  - `SiteTheme\Bootstrap\ThemeBootstrap::FRONTEND_STYLESHEETS` — `array<string, string>` mapping enqueue handle → theme-relative path.
  - `SiteTheme\Bootstrap\ThemeBootstrap::EDITOR_STYLESHEETS` — `string[]` of theme-relative paths passed to `add_editor_style()`.
  - `theme.json` `templateParts` entries `site-header` (area `header`) and `site-footer` (area `footer`).
  - Stable frontend hooks for the browser suites: `main#site-main`, `header.site-header`, `footer.site-footer`, `.site-header__branding`, `.wp-block-navigation`.
  - **Extension contract for the commerce track and client projects:** `templates/` and `parts/` are open sets. `BlockThemeStructureTest` asserts the base-profile templates exist and that *every* file matches `FILENAME_PATTERN` (`^[a-z0-9]+(?:[-_][a-z0-9]+)*\.html$`) and lives flat in the directory; it never enumerates a closed list, so adding `templates/single-product.html`, `templates/taxonomy-product_cat.html`, `templates/page-checkout.html` and the rest needs **no edit to any Task 1 test**. `BlockTemplateIntegrityTest` is profile-scoped through the explicit `FOREIGN_PROFILE_NAMESPACES = array( 'woocommerce' )` constant: a file is skipped only when it references a namespace on that named list that has no registered blocks in the current run, so a commerce template never reddens the base `integration` suite while a typo like `cor/paragraph` still fails loudly. The commerce track adds its own `tests/commerce/Integration/` test running the same assertions with WooCommerce loaded.
  - `tests/support/BlockIndexGenerator.php` indexes `.html` templates as well as `.php` patterns (Step 13) — **a hard prerequisite for the commerce track**: without it, a WooCommerce block used inside `templates/single-product.html` is invisible to `docs/generated-block-index.md` and `GeneratedIndexFreshnessTest` silently under-reports.

- [ ] **Step 1: Write the failing block-theme structure test**

Create `tests/Architecture/BlockThemeStructureTest.php`:

```php
<?php
/**
 * Enforces the native block theme's file contract: HTML templates and parts
 * only, flat parts/, every referenced part present and declared in theme.json,
 * and no environment-specific record IDs baked into Git-owned markup.
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\FormatsArchitectureFailures;

require_once dirname( __DIR__ ) . '/support/FormatsArchitectureFailures.php';

final class BlockThemeStructureTest extends TestCase {

	use FormatsArchitectureFailures;

	/**
	 * The BASE-PROFILE templates: the request types the theme must keep
	 * covering after the conversion, one block template each, matching the
	 * classic hierarchy it replaces.
	 *
	 * This is a MINIMUM, never a closed set. The commerce profile adds its own
	 * block templates beside these (single-product, archive-product,
	 * taxonomy-product_cat, page-cart, page-checkout, order-confirmation and
	 * friends), and a client project adds its own. Every test below therefore
	 * checks "each of these exists" and "every file here matches the template
	 * naming and format contract" — never "these and nothing else".
	 *
	 * @var string[]
	 */
	private const BASE_PROFILE_TEMPLATES = array( '404', 'archive', 'index', 'page', 'search', 'single' );

	/**
	 * Template and part filenames must be lowercase, hyphen-or-underscore
	 * separated, and .html. Underscores are permitted because WordPress's own
	 * template hierarchy uses them for taxonomy templates
	 * (taxonomy-product_cat.html).
	 */
	private const FILENAME_PATTERN = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*\.html$/';

	/**
	 * @var string[]
	 */
	private const REQUIRED_PARTS = array( 'site-footer', 'site-header' );

	/**
	 * Git-owned templates and parts must not hard-code a database record id.
	 * A `ref` binds a Navigation or synced-pattern row that exists only in one
	 * environment; promoting or deploying that file elsewhere silently points
	 * at the wrong record (or nothing). Add a slug here only with a written
	 * review note.
	 *
	 * @var string[]
	 */
	private const HARDCODED_REF_ALLOWLIST = array();

	private function theme(): string {
		return $this->repo_root() . '/web/app/themes/site-theme';
	}

	public function test_the_theme_is_a_block_theme(): void {
		self::assertFileExists(
			$this->theme() . '/templates/index.html',
			$this->architecture_failure(
				'templates/index.html is missing',
				'web/app/themes/site-theme/templates/index.html',
				'templates/index.html is the file WordPress uses to decide a theme is a block theme (WP_Theme::is_block_theme()); without it the Site Editor and every block template are unavailable.',
				'Add templates/index.html with the posts-index block markup.'
			)
		);
	}

	public function test_every_base_profile_template_exists_as_html(): void {
		foreach ( self::BASE_PROFILE_TEMPLATES as $slug ) {
			self::assertFileExists(
				$this->theme() . '/templates/' . $slug . '.html',
				$this->architecture_failure(
					'Required base-profile block template is missing',
					'web/app/themes/site-theme/templates/' . $slug . '.html',
					'Each classic template the theme used to ship needs a block equivalent, or that request type falls back to index.html and loses its behaviour.',
					'Add templates/' . $slug . '.html with block markup only.'
				)
			);
		}
	}

	/**
	 * Deliberately an ALLOW-PATTERN, not an enumeration: additional templates
	 * (the commerce profile's product/cart/checkout templates, a client
	 * project's own) are expected and must not need an edit here.
	 */
	public function test_every_template_file_matches_the_naming_and_format_contract(): void {
		foreach ( $this->entries( $this->theme() . '/templates' ) as $entry ) {
			self::assertDirectoryDoesNotExist(
				$this->theme() . '/templates/' . $entry,
				$this->architecture_failure(
					'Nested directory under templates/',
					'web/app/themes/site-theme/templates/' . $entry,
					'WordPress resolves block templates as flat templates/<slug>.html files; a nested directory is never loaded. In particular, WooCommerce block overrides belong at templates/single-product.html, NOT templates/woocommerce/single-product.html.',
					'Move the markup to templates/<slug>.html and delete the directory.'
				)
			);

			self::assertMatchesRegularExpression(
				self::FILENAME_PATTERN,
				$entry,
				$this->architecture_failure(
					'Template filename breaks the block-template contract',
					'web/app/themes/site-theme/templates/' . $entry,
					'A block theme has exactly one rendering path, and WordPress matches templates by lowercase slug filename; a PHP file here would resurrect the classic path the migration removed, and a mixed-case or oddly named file is silently never matched.',
					'Rename to a lowercase hyphen/underscore-separated .html file, or delete it.'
				)
			);
		}
	}

	public function test_parts_directory_is_flat_and_html_only(): void {
		$parts = $this->theme() . '/parts';

		foreach ( $this->entries( $parts ) as $entry ) {
			self::assertFileExists( $parts . '/' . $entry );

			self::assertDirectoryDoesNotExist(
				$parts . '/' . $entry,
				$this->architecture_failure(
					'Nested directory under parts/',
					'web/app/themes/site-theme/parts/' . $entry,
					'WordPress resolves template parts as flat parts/<slug>.html files; a nested directory is never loaded.',
					'Move the markup into parts/' . $entry . '.html and delete the directory.'
				)
			);

			self::assertMatchesRegularExpression(
				self::FILENAME_PATTERN,
				$entry,
				$this->architecture_failure(
					'Part filename breaks the template-part contract',
					'web/app/themes/site-theme/parts/' . $entry,
					'Template parts are lowercase-slug HTML block markup; PHP or CSS here belongs to the removed classic part convention.',
					'Move styles into assets/global/shared.css and markup into parts/<slug>.html.'
				)
			);
		}

		foreach ( self::REQUIRED_PARTS as $slug ) {
			self::assertFileExists( $parts . '/' . $slug . '.html' );
		}
	}

	public function test_every_referenced_template_part_exists_and_is_declared(): void {
		$declared = $this->declared_template_parts();

		foreach ( $this->markup_files() as $file ) {
			preg_match_all( '/"slug"\s*:\s*"([a-z0-9-]+)"/', $this->read( $file ), $matches );

			foreach ( $matches[1] as $slug ) {
				self::assertFileExists(
					$this->theme() . '/parts/' . $slug . '.html',
					$this->architecture_failure(
						'Referenced template part does not exist',
						$this->to_relative( $file ),
						'A template-part reference with no file renders nothing and gives the client an empty, unfixable area in the Site Editor.',
						'Add parts/' . $slug . '.html, or correct the slug in this file.'
					)
				);

				self::assertContains(
					$slug,
					$declared,
					$this->architecture_failure(
						'Template part is not declared in theme.json',
						'web/app/themes/site-theme/theme.json',
						'The Site Editor lists and names template parts from theme.json.templateParts; an undeclared part is unnamed and unassigned to an area.',
						'Add { "name": "' . $slug . '", "title": "...", "area": "..." } to theme.json templateParts.'
					)
				);
			}
		}
	}

	public function test_every_declared_template_part_has_a_file(): void {
		foreach ( $this->declared_template_parts() as $slug ) {
			self::assertFileExists( $this->theme() . '/parts/' . $slug . '.html' );
		}
	}

	public function test_no_hardcoded_database_refs_in_git_owned_markup(): void {
		foreach ( $this->markup_files() as $file ) {
			$slug = pathinfo( $file, PATHINFO_FILENAME );

			if ( in_array( $slug, self::HARDCODED_REF_ALLOWLIST, true ) ) {
				continue;
			}

			self::assertDoesNotMatchRegularExpression(
				'/"ref"\s*:\s*\d+/',
				$this->read( $file ),
				$this->architecture_failure(
					'Hard-coded database record id in a Git-owned template',
					$this->to_relative( $file ),
					'A "ref" binds a Navigation or synced-pattern row that exists only in the environment it was authored in; deploying or promoting this file elsewhere points it at the wrong record or nothing at all.',
					'Use a ref-less block (core/navigation resolves the site\'s navigation at render time), or add this slug to HARDCODED_REF_ALLOWLIST with a review note.'
				)
			);
		}
	}

	public function test_no_classic_php_remains_under_templates_or_parts(): void {
		foreach ( array( '/templates', '/parts' ) as $directory ) {
			foreach ( $this->entries( $this->theme() . $directory ) as $entry ) {
				self::assertStringEndsNotWith( '.php', $entry, $this->to_relative( $this->theme() . $directory . '/' . $entry ) . ' must not exist in a block theme.' );
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function declared_template_parts(): array {
		$decoded = json_decode( $this->read( $this->theme() . '/theme.json' ), true );

		self::assertIsArray( $decoded, 'theme.json must be valid JSON.' );

		$slugs = array();

		foreach ( (array) ( $decoded['templateParts'] ?? array() ) as $part ) {
			$slugs[] = (string) ( $part['name'] ?? '' );
		}

		sort( $slugs );

		return $slugs;
	}

	/**
	 * @return list<string>
	 */
	private function markup_files(): array {
		$files = array();

		foreach ( array( '/templates', '/parts' ) as $directory ) {
			foreach ( $this->entries( $this->theme() . $directory ) as $entry ) {
				$files[] = $this->theme() . $directory . '/' . $entry;
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * @return list<string>
	 */
	private function entries( string $dir ): array {
		$entries = is_dir( $dir ) ? scandir( $dir ) : false;

		if ( false === $entries ) {
			return array();
		}

		return array_values(
			array_filter( $entries, static fn( string $entry ): bool => '.' !== $entry && '..' !== $entry )
		);
	}

	private function read( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- static source inspection in the architecture suite; no WordPress runtime here.
		$source = file_get_contents( $file );

		return false === $source ? '' : $source;
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev composer test:architecture`
Expected: FAIL — `BlockThemeStructureTest::test_the_theme_is_a_block_theme` reports "templates/index.html is missing".

- [ ] **Step 3: Finalise the template parts**

Task 5 already created both files with the correct single-semantic-wrapper shape: the `core/template-part` block in the template owns the `<header>`/`<footer>` element and the `.site-header`/`.site-footer` class, and the part file opens a plain `core/group` with `.site-header__inner` / `.site-footer__inner`. Do **not** add a `tagName` to the part files' own groups — `render_block_core_template_part()` already wraps the content, so a second `tagName` produces nested `<header><header>`, an invalid landmark structure that fails the accessibility suite.

Remove the placeholder Contact button the spike added, so the header matches the classic header's content. `web/app/themes/site-theme/parts/site-header.html` becomes:

```html
<!-- wp:group {"className":"site-header__inner"} -->
<div class="wp-block-group site-header__inner">
<!-- wp:group {"className":"site-header__branding"} -->
<div class="wp-block-group site-header__branding">
<!-- wp:site-title {"level":0} /-->
<!-- wp:site-tagline /-->
</div>
<!-- /wp:group -->
<!-- wp:navigation {"overlayMenu":"mobile","className":"site-header__nav"} /-->
</div>
<!-- /wp:group -->
```

`web/app/themes/site-theme/parts/site-footer.html` stays exactly as Task 5 wrote it:

```html
<!-- wp:group {"className":"site-footer__inner"} -->
<div class="wp-block-group site-footer__inner">
<!-- wp:paragraph {"className":"site-footer__copyright"} -->
<p class="site-footer__copyright">© Agency Starter. All rights reserved.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
```

The `core/site-logo` block the spike included is dropped here: the classic header rendered no logo, so keeping it would show an empty logo placeholder and cost migration parity. A project that wants one adds it in the Site Editor.

The footer deliberately carries no navigation: the classic `parts/site-footer/site-footer.php` called `wp_nav_menu( theme_location => 'footer', fallback_cb => false )` and no menu was ever assigned to that location, so the classic footer rendered the copyright line alone. Reproducing that keeps migration parity honest.

The classic copyright line printed `gmdate( 'Y' )` and `get_bloginfo( 'name' )`. No core block produces the current year, so the year is dropped and the site name becomes editable static text. Task 5 Step 9 already recorded this under `## Intentional content differences`.

- [ ] **Step 4: Write the block templates**

Create `web/app/themes/site-theme/templates/index.html`:

```html
<!-- wp:template-part {"slug":"site-header","tagName":"header","area":"header","className":"site-header"} /-->

<!-- wp:group {"tagName":"main","anchor":"site-main","layout":{"type":"constrained"}} -->
<main class="wp-block-group" id="site-main">
<!-- wp:query {"queryId":0,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":true}} -->
<div class="wp-block-query">
<!-- wp:post-template -->
<!-- wp:post-title {"level":2,"isLink":true} /-->
<!-- wp:post-excerpt /-->
<!-- /wp:post-template -->

<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"space-between"}} -->
<!-- wp:query-pagination-previous /-->
<!-- wp:query-pagination-numbers /-->
<!-- wp:query-pagination-next /-->
<!-- /wp:query-pagination -->

<!-- wp:query-no-results -->
<!-- wp:paragraph -->
<p>Nothing found.</p>
<!-- /wp:paragraph -->
<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"site-footer","tagName":"footer","area":"footer","className":"site-footer"} /-->
```

Create `web/app/themes/site-theme/templates/page.html`:

```html
<!-- wp:template-part {"slug":"site-header","tagName":"header","area":"header","className":"site-header"} /-->

<!-- wp:group {"tagName":"main","anchor":"site-main","layout":{"type":"constrained"}} -->
<main class="wp-block-group" id="site-main">
<!-- wp:post-title {"level":1} /-->
<!-- wp:post-content {"layout":{"type":"constrained"}} /-->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"site-footer","tagName":"footer","area":"footer","className":"site-footer"} /-->
```

Create `web/app/themes/site-theme/templates/single.html`:

```html
<!-- wp:template-part {"slug":"site-header","tagName":"header","area":"header","className":"site-header"} /-->

<!-- wp:group {"tagName":"main","anchor":"site-main","layout":{"type":"constrained"}} -->
<main class="wp-block-group" id="site-main">
<!-- wp:post-title {"level":1} /-->
<!-- wp:post-content {"layout":{"type":"constrained"}} /-->

<!-- wp:comments -->
<div class="wp-block-comments">
<!-- wp:comments-title /-->
<!-- wp:comment-template -->
<!-- wp:comment-author-name /-->
<!-- wp:comment-content /-->
<!-- /wp:comment-template -->
<!-- wp:post-comments-form /-->
</div>
<!-- /wp:comments -->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"site-footer","tagName":"footer","area":"footer","className":"site-footer"} /-->
```

Create `web/app/themes/site-theme/templates/archive.html`:

```html
<!-- wp:template-part {"slug":"site-header","tagName":"header","area":"header","className":"site-header"} /-->

<!-- wp:group {"tagName":"main","anchor":"site-main","layout":{"type":"constrained"}} -->
<main class="wp-block-group" id="site-main">
<!-- wp:query-title {"type":"archive","level":1} /-->
<!-- wp:term-description /-->

<!-- wp:query {"queryId":0,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":true}} -->
<div class="wp-block-query">
<!-- wp:post-template -->
<!-- wp:post-title {"level":2,"isLink":true} /-->
<!-- wp:post-excerpt /-->
<!-- /wp:post-template -->

<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"space-between"}} -->
<!-- wp:query-pagination-previous /-->
<!-- wp:query-pagination-numbers /-->
<!-- wp:query-pagination-next /-->
<!-- /wp:query-pagination -->

<!-- wp:query-no-results -->
<!-- wp:paragraph -->
<p>Nothing found.</p>
<!-- /wp:paragraph -->
<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"site-footer","tagName":"footer","area":"footer","className":"site-footer"} /-->
```

Create `web/app/themes/site-theme/templates/search.html`: identical to `archive.html`, except the two blocks above the query become

```html
<!-- wp:query-title {"type":"search","level":1} /-->
```

and the `query-no-results` body becomes

```html
<!-- wp:query-no-results -->
<!-- wp:paragraph -->
<p>Nothing found. Try a different search.</p>
<!-- /wp:paragraph -->
<!-- wp:search {"label":"Search","showLabel":false,"buttonText":"Search"} /-->
<!-- /wp:query-no-results -->
```

Create `web/app/themes/site-theme/templates/404.html`:

```html
<!-- wp:template-part {"slug":"site-header","tagName":"header","area":"header","className":"site-header"} /-->

<!-- wp:group {"tagName":"main","anchor":"site-main","layout":{"type":"constrained"}} -->
<main class="wp-block-group" id="site-main">
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Nothing here</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>The page you were looking for could not be found. Try a search instead.</p>
<!-- /wp:paragraph -->

<!-- wp:search {"label":"Search","showLabel":false,"buttonText":"Search"} /-->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"site-footer","tagName":"footer","area":"footer","className":"site-footer"} /-->
```

`main#site-main` is deliberate: WordPress's block-theme skip link (`_block_template_add_skip_link()` in `wp-includes/block-template.php`) reuses the first `<main>` element's existing `id` when it has one, so the injected skip link keeps pointing at `#site-main` exactly as the classic `header.php` did.

- [ ] **Step 5: Split the global CSS**

Delete `assets/global/base.css` and `assets/global/typography.css`. Create the three replacements.

`web/app/themes/site-theme/assets/global/frontend-reset.css` — frontend only, never loaded into the editor (the editor canvas is not a document and these rules would fight core's canvas styles):

```css
/*
 * Frontend-only reset and accessibility helpers. NEVER passed to
 * add_editor_style() — see SiteTheme\Bootstrap\ThemeBootstrap::EDITOR_STYLESHEETS
 * and GlobalAssetRulesTest. Colors come exclusively from theme.json design
 * tokens (var(--wp--preset--*)); no raw values here.
 */

*,
*::before,
*::after {
	box-sizing: border-box;
}

body {
	margin: 0;
}

img,
video {
	max-width: 100%;
	height: auto;
}

/* Visually hidden, but available to assistive tech. clip-path replaces the
   deprecated clip property; inset(50%) collapses the box to a point. */
.screen-reader-text {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}

/* WordPress injects the block-theme skip link as
   <a class="skip-link screen-reader-text" ...> and points it at the first
   <main> element's id, which every template sets to site-main. Revealing it on
   focus is the theme's job. */
.screen-reader-text:focus {
	position: fixed;
	top: var(--wp--preset--spacing--10);
	left: var(--wp--preset--spacing--10);
	z-index: 100000;
	width: auto;
	height: auto;
	padding: var(--wp--preset--spacing--10) var(--wp--preset--spacing--20);
	clip-path: none;
	background-color: var(--wp--preset--color--base);
	color: var(--wp--preset--color--neutral-900);
}

:focus-visible {
	outline: 2px solid var(--wp--preset--color--accent);
	outline-offset: 2px;
}
```

`web/app/themes/site-theme/assets/global/shared.css` — the editor-safe design system, loaded on the frontend AND in the editor canvas, so what the client edits is what the visitor sees:

```css
/*
 * Editor-safe design-system classes. Loaded on the frontend by
 * ThemeBootstrap::enqueue_assets() and into the editor canvas by
 * add_editor_style() — that shared load is what makes the Site Editor canvas
 * match the frontend.
 *
 * Colors, spacing and typography come exclusively from theme.json design
 * tokens. Git-authored templates and parts carry NO inline style attributes
 * (hand-writing a block's serialized style output is how "unexpected or
 * invalid content" warnings happen); everything visual lives here, keyed off
 * the className the block markup sets.
 */

body {
	font-family: var(--wp--preset--font-family--sans);
	line-height: 1.6;
}

h1,
h2,
h3,
h4,
h5,
h6 {
	font-family: var(--wp--preset--font-family--sans);
	line-height: 1.2;
}

/* .site-header is the <header> the core/template-part block emits (the
   template sets tagName + className); .site-header__inner is the group inside
   the part file. Only one of the two emits the landmark element. */
.site-header {
	display: block;
	padding: var(--wp--preset--spacing--20) var(--wp--preset--spacing--30);
	background-color: var(--wp--preset--color--base);
	border-bottom: 1px solid var(--wp--preset--color--neutral-300);
}

/* Flex lives here rather than in a block `layout` attribute: a layout
   attribute makes WordPress generate a .wp-container-core-group-is-layout-*
   rule whose class name is unstable and whose specificity fights this file.
   shared.css loads in the editor canvas too, so plain CSS renders identically
   on both sides and stays under our control. */
.site-header__inner {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--wp--preset--spacing--20);
}

.site-header__branding {
	display: flex;
	flex-direction: column;
}

.site-header__branding .wp-block-site-title {
	margin: 0;
	font-size: var(--wp--preset--font-size--large);
	font-weight: 650;
}

.site-header__branding .wp-block-site-title a {
	color: var(--wp--preset--color--neutral-900);
	text-decoration: none;
}

.site-header__branding .wp-block-site-tagline {
	margin: 0;
	font-size: var(--wp--preset--font-size--small);
	color: var(--wp--preset--color--neutral-700);
}

.site-header__nav a {
	color: var(--wp--preset--color--neutral-900);
	text-decoration: none;
}

.site-footer {
	display: block;
	padding: var(--wp--preset--spacing--30);
	background-color: var(--wp--preset--color--primary-dark);
	color: var(--wp--preset--color--base);
}

.site-footer__inner {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--wp--preset--spacing--20);
}

.site-footer__copyright {
	margin: 0;
	font-size: var(--wp--preset--font-size--small);
}
```

`web/app/themes/site-theme/assets/global/editor.css` — editor-only corrections. WordPress prefixes every selector in an `add_editor_style()` sheet with `.editor-styles-wrapper`, so write selectors unscoped here:

```css
/*
 * Editor-only canvas corrections. WordPress scopes every selector in this file
 * to .editor-styles-wrapper automatically (see add_editor_style()), so write
 * them unscoped. Nothing here may be needed on the frontend — if a rule is,
 * it belongs in shared.css.
 */

body {
	background-color: var(--wp--preset--color--base);
}

/* The canvas renders a template part inline in the post flow rather than as a
   document region, so its child margins can collapse out and shift the header
   or footer a few pixels against the frontend. A new block formatting context
   keeps the editing-parity geometry check honest. */
.wp-block-template-part {
	display: flow-root;
}
```

- [ ] **Step 6: Rewrite ThemeBootstrap**

Task 5 already added `EDITOR_STYLESHEETS` (holding `assets/global/editor.css` alone) and the `add_editor_style()` call.

**This step is split across two of this task's three commits, because the classic header and footer are still rendering when commit 1 is made and they still need their part CSS enqueued:**

- **In commit 1**, apply everything below EXCEPT the removal of the `Parts::MANIFEST` asset loop and `register_nav_menus()`. Keep both, verbatim, at the end of `enqueue_assets()` / `setup()`. The frontend then loads `frontend-reset.css`, `shared.css`, and the two part stylesheets exactly as before, so `npm run test:visual` still passes.
- **In commit 3** (the deletion commit), delete the `Parts` import, the asset loop and `register_nav_menus()`, leaving the file exactly as written below. By then the block templates own every request, so the part stylesheets and menu locations are unreachable.

Also split the `shared.css` content the same way: commit 1's `shared.css` carries only the typography rules moved out of `typography.css`; the `.site-header*` / `.site-footer*` block rules from Step 5 land in **commit 2**, alongside the deletion of `parts/site-header/site-header.css`, `parts/site-header/site-header.js` and `parts/site-footer/site-footer.css` — dead assets the moment the block parts render, and a source of duplicate `.site-header` rules if left behind.

The final state of `web/app/themes/site-theme/src/Bootstrap/ThemeBootstrap.php`:

```php
<?php

declare(strict_types=1);

namespace SiteTheme\Bootstrap;

/**
 * Site Theme's single wiring point. functions.php stays a thin ≤50-line shell
 * (an ABSPATH guard plus one boot() call); every setup step lives here as its
 * own named method, never a closure — see the "no closures in
 * add_action/add_filter" rule enforced by HookOwnershipTest.
 *
 * This is a NATIVE BLOCK THEME: templates/*.html and parts/*.html are the only
 * rendering path. WordPress recognises that from the file layout alone
 * (templates/index.html), so there is deliberately no
 * add_theme_support( 'block-templates' ) call here.
 *
 * Most classic theme supports are redundant: core's _add_default_theme_supports()
 * (wp-includes/theme.php, hooked to after_setup_theme at priority 1) already
 * adds post-thumbnails, responsive-embeds, editor-styles, html5 and
 * automatic-feed-links for every block theme. `editor-styles` is re-declared
 * below only because add_editor_style() depends on it; nav-menu locations are
 * gone because the native Navigation block replaces wp_nav_menu().
 *
 * Pattern registration is deliberately NOT wired here: WordPress auto-registers
 * every *.php file under patterns/ from its own header comment (see
 * wp-includes/theme.php's _register_theme_block_patterns()).
 */
final class ThemeBootstrap {

	/**
	 * Frontend stylesheets, in load order: enqueue handle => theme-relative
	 * path. Each sheet depends on the one before it.
	 *
	 * @var array<string, string>
	 */
	public const FRONTEND_STYLESHEETS = array(
		'site-theme-frontend-reset' => 'assets/global/frontend-reset.css',
		'site-theme-shared'         => 'assets/global/shared.css',
	);

	/**
	 * Stylesheets loaded into the block editor canvas. frontend-reset.css is
	 * deliberately absent: its document-level reset would fight the canvas's
	 * own layout. GlobalAssetRulesTest asserts that exclusion.
	 *
	 * @var string[]
	 */
	public const EDITOR_STYLESHEETS = array(
		'assets/global/shared.css',
		'assets/global/editor.css',
	);

	public static function boot(): void {
		add_action( 'after_setup_theme', array( self::class, 'setup' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * add_editor_style() requires the `editor-styles` support flag, and both
	 * belong on after_setup_theme — WordPress's documented hook for theme
	 * support flags.
	 */
	public static function setup(): void {
		add_theme_support( 'editor-styles' );
		add_editor_style( self::EDITOR_STYLESHEETS );
	}

	/**
	 * Enqueues the theme's global frontend CSS. Every handle is versioned by
	 * filemtime() rather than a static theme version string, so a deploy always
	 * busts caches without a manual version bump.
	 */
	public static function enqueue_assets(): void {
		$theme_dir    = get_template_directory();
		$theme_uri    = get_template_directory_uri();
		$dependencies = array();

		foreach ( self::FRONTEND_STYLESHEETS as $handle => $relative_path ) {
			wp_enqueue_style(
				$handle,
				"{$theme_uri}/{$relative_path}",
				$dependencies,
				(string) filemtime( "{$theme_dir}/{$relative_path}" )
			);

			$dependencies = array( $handle );
		}
	}

	/**
	 * Registers every dynamic block under blocks/ from its block.json —
	 * zero-config: dropping a new blocks/<slug>/block.json in place registers
	 * it, no edit here required. Mirrors scripts/build-blocks.mjs's
	 * auto-discovery so adding a block is a folder-only operation on both the
	 * PHP and build sides.
	 */
	public static function register_block(): void {
		$manifests = glob( get_template_directory() . '/blocks/*/block.json' );

		foreach ( ( false === $manifests ? array() : $manifests ) as $manifest ) {
			register_block_type( dirname( $manifest ) );
		}
	}
}
```

- [ ] **Step 7: Update theme.json**

In `web/app/themes/site-theme/theme.json`:

1. `settings.appearanceTools`: `false` → `true`.
2. `settings.color.custom`: `false` → `true`.
3. `settings.color.customGradient`: `false` → `true`.
4. `settings.typography.customFontSize`: `false` → `true`.
5. `settings.spacing.custom`: `false` → `true`.
6. Leave `color.defaultPalette` and `spacing.defaultSpacingSizes` at `false` — the agency palette and spacing scale stay the starting point; clients now add on top of it rather than being restricted to it.
7. Add a top-level `templateParts` array immediately after `version`:

```json
  "templateParts": [
    { "name": "site-header", "title": "Site Header", "area": "header" },
    { "name": "site-footer", "title": "Site Footer", "area": "footer" }
  ],
```

Do **not** add a `customTemplates` entry: the six templates are all core hierarchy templates, which need no declaration.

- [ ] **Step 8: Delete the classic path**

```bash
cd web/app/themes/site-theme
git rm 404.php archive.php index.php page.php search.php single.php header.php footer.php
git rm templates/404.php templates/archive.php templates/index.php templates/page.php templates/search.php templates/single.php
git rm -r parts/site-header parts/site-footer
git rm src/Support/Parts.php
git rm assets/global/base.css assets/global/typography.css
cd ../../../..
git rm tests/Unit/SiteTheme/PartsTest.php
```

Then update the two prose files:

- `web/app/themes/site-theme/functions.php`: change the docblock sentence "every real setup step lives in \SiteTheme\Bootstrap\ThemeBootstrap" to also state that this is a native block theme whose templates live in `templates/*.html`. Do not add lines that push the file past 50.
- `web/app/themes/site-theme/style.css`: replace the `Description:` header value with `The native block theme for the agency-starter Bedrock project — HTML block templates and parts, theme.json for design tokens, blocks and patterns for editable UI. Full Site Editor control for client roles within the approved block system. No page builder, no classic fallback.` Keep the maintainer note about not writing a literal `*` followed by `/`.

- [ ] **Step 9: Update DirectoryRulesTest**

In `tests/Architecture/DirectoryRulesTest.php`:

1. Replace `ALLOWED_THEME_FILES` with:

```php
	private const ALLOWED_THEME_FILES = array(
		'style.css',
		'functions.php',
		'theme.json',
		'screenshot.png',
		'README.md',
	);
```

2. Update the file-header docblock: "a hybrid theme keeps a small, fixed set of top-level folders" becomes "a block theme keeps a small, fixed set of top-level folders".
3. In `test_theme_top_level_files_are_on_the_whitelist()`, replace the `$why` argument with: `A block theme has exactly one rendering path — templates/*.html and parts/*.html. Any root-level PHP other than functions.php is a classic-hierarchy file WordPress would silently start honouring again.` and the `$where` argument with: `Move markup into templates/ or parts/ as block HTML, and behaviour into src/Bootstrap/ThemeBootstrap.php; delete build/editor cruft.`
4. Add:

```php
	public function test_no_php_files_live_under_theme_templates_or_parts(): void {
		foreach ( array( 'templates', 'parts' ) as $directory ) {
			$root = $this->repo_root() . '/web/app/themes/site-theme/' . $directory;

			foreach ( $this->all_php_files( $root ) as $file ) {
				self::fail(
					$this->architecture_failure(
						'PHP file under the block theme\'s ' . $directory . '/ directory',
						$this->to_relative( $file ),
						'templates/ and parts/ hold block markup only; a PHP file here is a leftover of the deleted classic rendering path.',
						'Convert the markup to a .html block template or part, or delete the file.'
					)
				);
			}
		}

		$this->addToAssertionCount( 1 );
	}

	public function test_template_parts_are_direct_html_files_under_parts(): void {
		$parts = $this->repo_root() . '/web/app/themes/site-theme/parts';

		foreach ( $this->top_level_directories( $parts ) as $name ) {
			self::fail(
				$this->architecture_failure(
					'Nested directory under the theme\'s parts/',
					$this->to_relative( $parts ) . '/' . $name,
					'WordPress resolves template parts as flat parts/<slug>.html files; a nested directory is never loaded.',
					'Move the markup into parts/' . $name . '.html and its styles into assets/global/shared.css.'
				)
			);
		}

		foreach ( $this->top_level_files( $parts ) as $name ) {
			self::assertStringEndsWith(
				'.html',
				$name,
				$this->architecture_failure(
					'Non-HTML file directly under the theme\'s parts/',
					$this->to_relative( $parts ) . '/' . $name,
					'Template parts are HTML block markup; PHP, CSS or JS here belongs to the removed classic part convention.',
					'Move styles into assets/global/shared.css and behaviour into a block\'s viewScript.'
				)
			);
		}

		$this->addToAssertionCount( 1 );
	}
```

- [ ] **Step 10: Update ThemeBootstrapTest**

In `tests/Architecture/ThemeBootstrapTest.php`:

1. Keep `FUNCTIONS_MAX_LINES`, `test_functions_php_is_a_thin_shell()`, `test_functions_php_registers_no_hooks_and_has_no_closures()`, and the private helpers `registers_hooks`, `contains_closure`, `significant_line_count`, `code_without_comments`, `php_in_directory`, `root_php_files`, `read`.
2. Delete `DELEGATE_MAX_SIGNIFICANT_LINES`, `test_each_template_has_a_thin_root_delegate()`, `test_every_root_delegate_maps_to_a_template()`, `requires_template()`, and `template_names()`.
3. Update the file-header docblock: the "thin shell" contract now covers `functions.php` and `ThemeBootstrap` only; markup lives in `templates/*.html`.
4. Add:

```php
	private const CLASSIC_ROOT_TEMPLATES = array(
		'index.php',
		'page.php',
		'single.php',
		'archive.php',
		'search.php',
		'404.php',
		'header.php',
		'footer.php',
	);

	/**
	 * A second rendering path is the failure mode this whole migration exists
	 * to remove: any of these left in the theme would let WordPress fall back
	 * to classic rendering for some request type, so the site the client edits
	 * in the Site Editor and the site a visitor sees could silently diverge.
	 *
	 * @var string[]
	 */
	private const CLASSIC_TEMPLATE_CALLS = array(
		'get_header',
		'get_footer',
		'get_template_part',
		'wp_nav_menu',
		'register_nav_menus',
	);

	public function test_the_theme_declares_itself_a_block_theme(): void {
		self::assertFileExists(
			$this->theme() . '/templates/index.html',
			$this->architecture_failure(
				'templates/index.html is missing',
				'web/app/themes/site-theme/templates/index.html',
				'WP_Theme::is_block_theme() keys off this exact file; without it WordPress falls back to the classic hierarchy and the Site Editor is unavailable.',
				'Add templates/index.html with the posts-index block markup.'
			)
		);
	}

	public function test_no_classic_root_template_files_remain(): void {
		foreach ( self::CLASSIC_ROOT_TEMPLATES as $name ) {
			self::assertFileDoesNotExist(
				$this->theme() . '/' . $name,
				$this->architecture_failure(
					'Classic root template survives in a block theme',
					'web/app/themes/site-theme/' . $name,
					'WordPress still honours root-level hierarchy files for some request types, so leaving one creates a second rendering path the Site Editor cannot see.',
					'Delete this file; its markup belongs in templates/*.html or parts/*.html.'
				)
			);
		}
	}

	public function test_the_theme_has_no_second_rendering_path(): void {
		self::assertFileDoesNotExist(
			$this->theme() . '/src/Support/Parts.php',
			'SiteTheme\\Support\\Parts belonged to the classic part convention and must be deleted.'
		);

		foreach ( $this->all_theme_php_files() as $file ) {
			foreach ( self::CLASSIC_TEMPLATE_CALLS as $call ) {
				self::assertDoesNotMatchRegularExpression(
					'/\b' . preg_quote( $call, '/' ) . '\s*\(/',
					$this->code_without_comments( $file ),
					$this->architecture_failure(
						'Classic template function called in a block theme',
						$this->to_relative( $file ),
						$call . '() belongs to the classic rendering path this theme no longer has; calling it reintroduces markup the Site Editor cannot edit.',
						'Express this with a block: core/template-part for chrome, core/navigation for menus.'
					)
				);
			}
		}
	}

	public function test_theme_bootstrap_owns_the_setup_hooks(): void {
		$source = $this->read( $this->theme() . '/src/Bootstrap/ThemeBootstrap.php' );

		foreach ( array( 'after_setup_theme', 'wp_enqueue_scripts', 'init' ) as $hook ) {
			self::assertStringContainsString(
				"'" . $hook . "'",
				$source,
				'ThemeBootstrap::boot() must own the ' . $hook . ' registration.'
			);
		}
	}
```

Add the `all_theme_php_files()` helper, which recursively lists `*.php` under the theme, skipping `vendor`, `node_modules` and `build`.

- [ ] **Step 11: Update GlobalAssetRulesTest**

In `tests/Architecture/GlobalAssetRulesTest.php`:

1. `ALLOWED_GLOBAL_CSS` becomes `array( 'frontend-reset.css', 'shared.css', 'editor.css' )`, and rename `test_global_stylesheet_directory_holds_only_base_and_typography()` to `test_global_stylesheet_directory_holds_only_the_three_split_files()`.
2. Delete `test_part_assets_are_named_after_their_part()` entirely (the `Parts::assets()` convention it enforced no longer exists).
3. Update the file-header docblock accordingly.
4. Add:

```php
	public function test_frontend_reset_css_is_never_registered_as_an_editor_style(): void {
		self::assertNotContains(
			'assets/global/frontend-reset.css',
			\SiteTheme\Bootstrap\ThemeBootstrap::EDITOR_STYLESHEETS,
			$this->architecture_failure(
				'Frontend reset CSS is loaded into the editor canvas',
				'web/app/themes/site-theme/src/Bootstrap/ThemeBootstrap.php',
				'frontend-reset.css is a document-level reset; inside the editor canvas it fights core\'s own canvas layout and makes the editor stop matching the frontend.',
				'Keep frontend-reset.css in FRONTEND_STYLESHEETS only; put editor-safe rules in assets/global/shared.css.'
			)
		);
	}

	public function test_every_declared_global_stylesheet_exists_on_disk(): void {
		$declared = array_merge(
			array_values( \SiteTheme\Bootstrap\ThemeBootstrap::FRONTEND_STYLESHEETS ),
			\SiteTheme\Bootstrap\ThemeBootstrap::EDITOR_STYLESHEETS
		);

		foreach ( array_unique( $declared ) as $relative_path ) {
			self::assertStringStartsWith( 'assets/global/', $relative_path );
			self::assertFileExists( $this->theme() . '/' . $relative_path );
		}
	}

	public function test_the_shared_stylesheet_is_loaded_on_both_sides(): void {
		self::assertContains( 'assets/global/shared.css', array_values( \SiteTheme\Bootstrap\ThemeBootstrap::FRONTEND_STYLESHEETS ) );
		self::assertContains( 'assets/global/shared.css', \SiteTheme\Bootstrap\ThemeBootstrap::EDITOR_STYLESHEETS );
	}
```

- [ ] **Step 12: Write the block-template integration test**

Delete `tests/Integration/Theme/BlockThemeSpikeTest.php` in this step: every assertion it made is subsumed below, and its `test_the_classic_path_still_serves_templates_the_spike_does_not_cover()` is deliberately false once all six templates exist.


Create `tests/Integration/Theme/BlockTemplateIntegrityTest.php`:

```php
<?php
/**
 * Parses and renders every block template and part with WordPress loaded, so a
 * typo in hand-authored block markup fails a test rather than silently
 * rendering an empty region on a client's site.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Theme;

use Tests\Integration\IntegrationTestCase;

final class BlockTemplateIntegrityTest extends IntegrationTestCase {

	/**
	 * Block namespaces that belong to an OPTIONAL profile this suite may not
	 * load. Only these may cause a template or part to be skipped, and only
	 * when the namespace really has no registered blocks in the current run.
	 * Anything else that is unregistered is a bug, not a profile.
	 *
	 * Adding an entry here is a deliberate act: it silences a whole file, so it
	 * needs the same review a woocommerce-allowlist.php entry does.
	 *
	 * @var string[]
	 */
	private const FOREIGN_PROFILE_NAMESPACES = array( 'woocommerce' );

	private function theme(): string {
		return dirname( __DIR__, 3 ) . '/web/app/themes/site-theme';
	}

	/**
	 * Every template and part on disk, including files that belong to a
	 * profile this suite does not load.
	 *
	 * @return list<string>
	 */
	private function all_markup_files(): array {
		$files = array();

		foreach ( array( '/templates', '/parts' ) as $directory ) {
			$found = glob( $this->theme() . $directory . '/*.html' );
			$files = array_merge( $files, false === $found ? array() : $found );
		}

		sort( $files );

		return $files;
	}

	/**
	 * PROFILE SCOPING (§11.12: "every referenced block is registered FOR THE
	 * TESTED PROFILE").
	 *
	 * The base `integration` suite never loads WooCommerce, so a commerce
	 * profile's templates/single-product.html legitimately references blocks
	 * that are not registered here. Failing on those would make the base suite
	 * red the moment the commerce track lands its templates.
	 *
	 * The rule is deliberately NARROW: a file is skipped only when it
	 * references a namespace on the EXPLICIT, named FOREIGN_PROFILE_NAMESPACES
	 * list AND that namespace has no registered blocks in this run. A
	 * mis-spelled `cor/paragraph` therefore does not skip the file — `cor` is
	 * not a known profile namespace, so the file stays in scope and
	 * test_every_referenced_block_is_registered_for_this_profile() fails on it,
	 * which is exactly what should happen.
	 *
	 * @return list<string>
	 */
	private function markup_files(): array {
		$inactive = array_values( array_diff( self::FOREIGN_PROFILE_NAMESPACES, $this->active_namespaces() ) );

		if ( array() === $inactive ) {
			return $this->all_markup_files();
		}

		$files = array();

		foreach ( $this->all_markup_files() as $file ) {
			$foreign = false;

			foreach ( $this->flatten( parse_blocks( $this->read( $file ) ) ) as $block ) {
				if ( ! is_string( $block['blockName'] ) ) {
					continue;
				}

				$separator = strpos( $block['blockName'], '/' );
				$namespace = false === $separator ? '' : substr( $block['blockName'], 0, $separator );

				if ( in_array( $namespace, $inactive, true ) ) {
					$foreign = true;
					break;
				}
			}

			if ( ! $foreign ) {
				$files[] = $file;
			}
		}

		return $files;
	}

	/**
	 * @return list<string> Block namespaces with at least one registered block.
	 */
	private function active_namespaces(): array {
		$namespaces = array();

		foreach ( array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ) as $block_name ) {
			$separator = strpos( (string) $block_name, '/' );

			if ( false !== $separator ) {
				$namespaces[] = substr( (string) $block_name, 0, $separator );
			}
		}

		return array_values( array_unique( $namespaces ) );
	}

	private function read( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a theme file from disk in an integration test.
		$source = file_get_contents( $file );

		return false === $source ? '' : $source;
	}

	public function test_wordpress_recognises_the_theme_as_a_block_theme(): void {
		self::assertTrue( wp_is_block_theme() );
		self::assertSame( 'site-theme', get_stylesheet() );
	}

	public function test_every_template_and_part_parses_into_named_blocks_only(): void {
		// Profile-independent: raw markup outside any block is wrong in every
		// profile, so this one scans EVERY file on disk.
		foreach ( $this->all_markup_files() as $file ) {
			foreach ( $this->flatten( parse_blocks( $this->read( $file ) ) ) as $block ) {
				if ( null !== $block['blockName'] ) {
					continue;
				}

				self::assertSame(
					'',
					trim( (string) $block['innerHTML'] ),
					basename( $file ) . ' contains raw markup outside any block: ' . trim( (string) $block['innerHTML'] )
				);
			}
		}
	}

	/**
	 * §11.12: "confirm every referenced block is registered FOR THE TESTED
	 * PROFILE". markup_files() drops files whose blocks belong to a profile
	 * this suite does not load (see its docblock); every remaining block must
	 * be registered here.
	 */
	public function test_every_referenced_block_is_registered_for_this_profile(): void {
		$registry = \WP_Block_Type_Registry::get_instance();

		self::assertNotEmpty( $this->markup_files(), 'At least the base-profile templates must be in scope for this profile.' );

		foreach ( $this->markup_files() as $file ) {
			foreach ( $this->flatten( parse_blocks( $this->read( $file ) ) ) as $block ) {
				if ( null === $block['blockName'] ) {
					continue;
				}

				self::assertTrue(
					$registry->is_registered( $block['blockName'] ),
					basename( $file ) . ' references the unregistered block ' . $block['blockName'] . '.'
				);
			}
		}
	}

	public function test_every_referenced_template_part_file_exists(): void {
		// Profile-independent: a missing part file is broken in every profile.
		foreach ( $this->all_markup_files() as $file ) {
			foreach ( $this->flatten( parse_blocks( $this->read( $file ) ) ) as $block ) {
				if ( 'core/template-part' !== $block['blockName'] ) {
					continue;
				}

				$slug = (string) ( $block['attrs']['slug'] ?? '' );

				self::assertFileExists( $this->theme() . '/parts/' . $slug . '.html' );
			}
		}
	}

	/**
	 * A MINIMUM set, never a closed one — the commerce profile and client
	 * projects add their own templates, and this test must not need an edit
	 * when they do.
	 */
	public function test_core_resolves_a_block_template_for_every_base_profile_slug(): void {
		$slugs = wp_list_pluck( get_block_templates(), 'slug' );

		foreach ( array( '404', 'archive', 'index', 'page', 'search', 'single' ) as $slug ) {
			self::assertContains( $slug, $slugs );
		}
	}

	public function test_core_resolves_both_template_parts(): void {
		$slugs = wp_list_pluck( get_block_templates( array(), 'wp_template_part' ), 'slug' );

		self::assertContains( 'site-header', $slugs );
		self::assertContains( 'site-footer', $slugs );
	}

	public function test_every_template_and_part_renders_without_a_fatal(): void {
		foreach ( $this->markup_files() as $file ) {
			$rendered = do_blocks( $this->read( $file ) );

			self::assertIsString( $rendered, basename( $file ) . ' must render to a string.' );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return list<array<string, mixed>>
	 */
	private function flatten( array $blocks ): array {
		$flat = array();

		foreach ( $blocks as $block ) {
			$flat[] = $block;

			foreach ( $this->flatten( (array) ( $block['innerBlocks'] ?? array() ) ) as $nested ) {
				$flat[] = $nested;
			}
		}

		return $flat;
	}
}
```

**ORCHESTRATOR CORRECTION, 2026-08-03 — deferred from Task 3.** Also add these
two methods to `tests/Integration/Permissions/ClientSiteEditorAccessTest.php`
now. Task 3 Step 12 created that file but deliberately left these two out,
because before the conversion they returned 404. See the correction note in
Task 3 Step 12 for the evidence.

They belong here and not earlier because both send a REST **update** to a
file-backed identifier. `<stylesheet>//index` resolves only once
`templates/index.html` exists, and `<stylesheet>//site-footer` only once
`parts/site-footer.html` exists. Both exist after this task's conversion.

```php
	/**
	 * Reading a collection proves nothing about editing. These two write, then
	 * READ BACK, because the whole point of Release 1 is that a client's Site
	 * Editor save actually persists.
	 *
	 * These two required tests have no skip path.
	 */
	public function test_client_editor_can_create_and_read_back_a_template(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$id      = get_stylesheet() . '//index';
		$content = "<!-- wp:paragraph -->\n<p>Client template write.</p>\n<!-- /wp:paragraph -->";

		$request = new \WP_REST_Request( 'POST', '/wp/v2/templates/' . $id );
		$request->set_param( 'content', $content );

		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertStringContainsString(
			'Client template write.',
			(string) get_block_template( $id, 'wp_template' )->content
		);
	}

	public function test_client_editor_can_create_and_read_back_a_template_part(): void {
		wp_set_current_user( $this->make_client_editor()->ID );

		$id      = get_stylesheet() . '//site-footer';
		$content = "<!-- wp:paragraph -->\n<p>Client footer write.</p>\n<!-- /wp:paragraph -->";

		$request = new \WP_REST_Request( 'POST', '/wp/v2/template-parts/' . $id );
		$request->set_param( 'content', $content );

		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertStringContainsString(
			'Client footer write.',
			(string) get_block_template( $id, 'wp_template_part' )->content
		);
	}
```

Confirm `wp_is_block_theme()` returns `1` before you run these. If it returns
`0`, the conversion is incomplete and these tests will fail with a 404 for that
reason, not because the capability wiring is wrong.

- [ ] **Step 13: Teach the block index generator about HTML templates (HARD GATE for the commerce track)**

In `tests/support/BlockIndexGenerator.php`, `files_containing()` currently skips every file whose extension is not `php`, which makes block usage inside `.html` templates invisible: the generated index's Templates column would stay empty forever, and `GeneratedIndexFreshnessTest` would happily pass while under-reporting. The commerce track's WooCommerce block templates depend on this being fixed first. Change that guard to accept both:

```php
			$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

			if ( 'php' !== $extension && 'html' !== $extension ) {
				continue;
			}
```

Update the method's docblock to say it scans PHP patterns and HTML block templates.

Then prove it, so the fix cannot silently regress:

```bash
ddev exec php -r '$g = require "tests/support/BlockIndexGenerator.php";'
(rg -c 'html' tests/support/BlockIndexGenerator.php)
```

and add a temporary `<!-- wp:agency/reference-callout /-->` line to `templates/page.html`, run `ddev exec php scripts/generate-block-index`, confirm the Templates column now names `web/app/themes/site-theme/templates/page.html`, then remove the temporary line and regenerate again. Commit only the final, clean index.

- [ ] **Step 14: Make the WooCommerce isolation wording independent of the classic override directory**

`tests/Architecture/WooCommerceIsolationTest.php` states, in its file docblock and in its failure message, that WooCommerce symbols may live in "the theme's `woocommerce/` overrides". Under a block theme, WooCommerce overrides are block templates directly under `templates/` (`templates/single-product.html` and friends) — §11.8 forbids `templates/woocommerce/*` — and the commerce track may delete `site-theme/woocommerce/` entirely. Reword both so the sentence stays true either way, without asserting that the directory does or does not exist:

- File docblock, second sentence: "WooCommerce symbols (WooCommerce, WC_*, wc_*, woocommerce_*) may only appear inside the site-commerce plugin and the commerce-owned theme override locations listed in `tests/Architecture/woocommerce-allowlist.php`; every other location is scanned."
- Failure message `$why`: "The base profile must run without WooCommerce; commerce PHP belongs only in site-commerce and the commerce-owned theme override locations."
- Failure message `$where`: unchanged (it already points at `site-commerce/` or the allow-list).

Do **not** change `PATTERNS`, the scanned-path set, or `woocommerce-allowlist.php`'s entries — the commerce track owns those decisions. This step is wording only, so the test keeps passing whichever way the commerce track resolves the directory.

- [ ] **Step 15: Retarget the JS lint paths**

1. In `package.json`, change `lint:js` to `wp-scripts lint-js web/app/themes/site-theme/blocks` — `parts/` now holds HTML only.
2. In `eslint.config.cjs`, delete the `web/app/themes/site-theme/parts/**/*.js` override block (the `no-var` exception existed for the deleted `site-header.js`). The file then exports the spread `@wordpress/scripts` config unchanged; keep a one-line comment recording why the override was removed.

- [ ] **Step 16: Regenerate the block index and run the PHP suites**

```bash
ddev exec php scripts/generate-block-index
ddev composer verify
npm run lint
npm run build
```

Expected: `verify` PASS (architecture, unit, integration all green), `npm run lint` PASS, `npm run build` produces no diff under `blocks/`.

- [ ] **Step 17: Hand off the real Site Editor verification to Task 9**

Automated parsing cannot catch a mismatch between a static-save block's serialized HTML and what the block would actually save. Task 8 must first make the block-navigation and permalink bootstrap work. Run this verification in Task 9 after the fresh-install proof. Do not delay either Task 6 conversion commit for a known-broken setup command.

1. Run `ddev exec bash scripts/setup` after Task 8 has committed the block-navigation and permalink bootstrap correction. A required Site Editor check must not rely on a setup command that is known to fail.
2. Log in as `admin` / `admin` and open `/wp/wp-admin/site-editor.php?p=/template`.
3. Open each of the six templates and both parts in turn.
4. Confirm **no** block shows "This block contains unexpected or invalid content".
5. If one does, click "Attempt block recovery", save, then export the corrected markup (Site Editor → Options → Export) and replace the offending file's contents with the exported version. Re-run Step 16.

- [ ] **Step 18: Commit, in three parts**

Do the work in the order the table at the top of this task gives, and run each commit's declared gates **before** making it. Never stage the deletions with the conversion: reviewing "these six files replace those fourteen" is far harder than reviewing them separately, and §16 asks for exactly this split.

Commit 1 — after Steps 5 and 6 only. The frontend is byte-for-byte unchanged (the same rules, redistributed across three files), so the visual suite must still pass:

```bash
ddev composer verify:fast && ddev composer verify && npm run lint && npm run test:visual
git add web/app/themes/site-theme/assets/global web/app/themes/site-theme/src/Bootstrap/ThemeBootstrap.php tests/Architecture/GlobalAssetRulesTest.php
git commit -m "feat: migrate global styles and editor assets"
```

Commit 2 — after Steps 1–4, 7 and 9–16:

```bash
ddev composer verify:fast && ddev composer verify && npm run lint
git add web/app/themes/site-theme/templates web/app/themes/site-theme/parts web/app/themes/site-theme/theme.json \
        tests/Architecture tests/Integration tests/support/BlockIndexGenerator.php \
        package.json eslint.config.cjs docs/generated-block-index.md
git commit -m "feat: convert theme templates and parts to block markup"
```

Commit 3 — after Step 8 only. Task 9 owns the final real-Site-Editor check after Task 8 makes setup usable:

```bash
ddev composer verify:fast && ddev composer verify
git add -A web/app/themes/site-theme tests/Unit/SiteTheme tests/Architecture
git commit -m "chore: remove classic theme path and stale tests"
```

---

## Task 7: Patterns, the demonstration page, and the dynamic block's editor preview

**Files:**
- Create: `web/app/themes/site-theme/patterns/hero.php`
- Create: `web/app/themes/site-theme/patterns/split-content.php`
- Create: `web/app/themes/site-theme/patterns/feature-grid.php`
- Create: `web/app/themes/site-theme/patterns/cta.php`
- Create: `web/app/themes/site-theme/patterns/content-page.php`
- Create: `tests/fixtures/demo-page.html`
- Create: `tests/fixtures/demo-media.png`
- Modify: `web/app/themes/site-theme/blocks/reference-callout/index.js`
- Modify: `web/app/themes/site-theme/blocks/reference-callout/editor.css`
- Modify: `web/app/themes/site-theme/blocks/reference-callout/README.md`
- Modify: `web/app/themes/site-theme/blocks/reference-callout/build/index.js` (rebuilt output, committed)
- Modify: `web/app/themes/site-theme/blocks/reference-callout/build/index.asset.php` (rebuilt output, committed — `npm run build` rewrites its `version` hash whenever `index.js` changes, and both build files are tracked, so committing one without the other leaves the build drifted)
- Modify: `docs/generated-block-index.md` (regenerated)

**Interfaces:**
- Consumes: `templates/page.html` and the two parts from Task 5.
- Produces:
  - Five unlocked patterns registered under the slugs `agency/hero`, `agency/split-content`, `agency/feature-grid`, `agency/cta`, `agency/content-page`.
  - `tests/fixtures/demo-page.html` — block markup for the Phase 1 demonstration page, containing the placeholder tokens `{{MEDIA_ID}}` and `{{MEDIA_URL}}` that Task 8's `scripts/setup` substitutes. The raw fixture is deliberately NOT valid block markup — `"id":{{MEDIA_ID}}` is invalid JSON inside a block attribute — so nothing may parse it before substitution.
  - `tests/fixtures/demo-media.png` — a deterministic 1200×675 solid `#1a3c34` PNG imported as the demo page's media.
  - The demo page at `/demo/` (created by Task 8), which the editing-parity suite in Task 10 photographs.

- [ ] **Step 1: Write the five patterns**

Each file follows the shape of the existing `patterns/reference-landing-section.php`: a PHP header comment WordPress auto-registers from, `declare(strict_types=1);`, an ABSPATH guard, then block markup echoed through `esc_html__()` for every visible string. None of them sets `templateLock` — §11.6 makes locking the exception, and the editing posture gives clients full control inside the approved block system. `reference-landing-section.php` keeps its `templateLock: contentOnly`: it is the repository's documented example of a locked composition, and `tests/e2e/locked-pattern.spec.ts` asserts exactly that.

`web/app/themes/site-theme/patterns/hero.php`:

```php
<?php
/**
 * Title: Hero
 * Slug: agency/hero
 * Categories: featured
 * Inserter: yes
 * Description: A full-width introduction — headline, supporting sentence, and a primary call-to-action button.
 *
 * Unlocked on purpose: the editing posture gives client roles full control
 * within the approved block system, so a pattern is a sanctioned STARTING
 * composition, not a cage. Lock a pattern only when its internal semantics
 * require it (see patterns/reference-landing-section.php).
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!-- wp:group {"className":"is-style-hero","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-hero">

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading"><?php echo esc_html__( 'Ship client sites without losing the design to a database', 'site-theme' ); ?></h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Design tokens, editor guardrails, and dynamic blocks that keep working together — from the first commit to the client\'s hundredth edit.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#"><?php echo esc_html__( 'Start a project', 'site-theme' ); ?></a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->

</div>
<!-- /wp:group -->
```

`patterns/split-content.php` — header `Title: Split Content`, `Slug: agency/split-content`, `Categories: text`, description "A two-column section: a heading and body copy beside supporting copy." Body:

```php
?>
<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'Built to be handed over', 'site-theme' ); ?></h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Every layout decision lives in Git, so a new developer — or a coding agent — can read the site instead of guessing at it.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Clients still get real visual control: templates, header, footer, navigation, colours and typography are all editable in the Site Editor.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p><?php echo esc_html__( 'What they cannot reach is the code editor, raw HTML, shortcodes, or Additional CSS — the four ways a site normally becomes unmaintainable.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
```

`web/app/themes/site-theme/patterns/feature-grid.php` — same PHP preamble as `hero.php` (docblock header, `declare(strict_types=1);`, ABSPATH guard), with header fields `Title: Feature Grid`, `Slug: agency/feature-grid`, `Categories: featured`, `Description: A three-column grid of short feature cards.` Body:

```php
?>
<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php echo esc_html__( 'Tokens, not hex codes', 'site-theme' ); ?></h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Colours, spacing and the type scale live in theme.json and stay consistent on every page.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php echo esc_html__( 'Guardrails, not lockouts', 'site-theme' ); ?></h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Clients edit the whole site visually; the code editor, raw HTML and shortcodes stay closed.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php echo esc_html__( 'Tested, not hoped for', 'site-theme' ); ?></h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Architecture, unit, integration, end-to-end and visual suites gate every commit.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
```

`web/app/themes/site-theme/patterns/cta.php` — same preamble, header fields `Title: Call To Action`, `Slug: agency/cta`, `Categories: call-to-action`, `Description: A closing call-to-action band with a heading, a sentence, and one button.` Body:

```php
?>
<!-- wp:group {"className":"is-style-cta","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-cta">

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'Ready when you are', 'site-theme' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Clone the template, rename the project, and start designing.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#"><?php echo esc_html__( 'Get started', 'site-theme' ); ?></a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->

</div>
<!-- /wp:group -->
```

`web/app/themes/site-theme/patterns/content-page.php` — same preamble, header fields `Title: Content Page`, `Slug: agency/content-page`, `Categories: pages`, `Post Types: page`, `Description: A complete starting page: hero, split content, feature grid, and a closing call to action.` Body: paste the **exact** bodies of `hero.php`, `split-content.php`, `feature-grid.php` and `cta.php` above, in that order, inside one wrapper — that is, open with

```php
?>
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
```

then the four bodies verbatim (each already a complete block tree), then close with

```php
</div>
<!-- /wp:group -->
```

Do not use `core/pattern` to nest one pattern inside another here: a nested pattern reference is resolved at render time and cannot be edited as a unit in the inserter preview.

`is-style-hero` and `is-style-cta` are plain class names, not registered block styles — they exist so a project can attach its own rules without editing the pattern. They intentionally have no CSS in this repository.

- [ ] **Step 2: Create the deterministic demo media**

Generate a 1200×675 solid `#1a3c34` PNG at `tests/fixtures/demo-media.png` (the theme's `primary` palette colour, so it needs no licence review and reads as intentional). Any deterministic generator is fine, for example:

```bash
node -e "const {PNG}=require('pngjs');const p=new PNG({width:1200,height:675});for(let i=0;i<p.data.length;i+=4){p.data[i]=26;p.data[i+1]=60;p.data[i+2]=52;p.data[i+3]=255;}require('fs').writeFileSync('tests/fixtures/demo-media.png',PNG.sync.write(p));"
```

- [ ] **Step 3: Write the demo page fixture**

Create `tests/fixtures/demo-page.html`. It is the Phase 1 demonstration page §9 requires and it must contain, in order: the hero, a two-column content section, an image/media section, a feature/card grid, one `agency/reference-callout`, and a closing call to action. The header, footer and navigation come from the template parts, so they are not repeated here.

```html
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Ship client sites without losing the design to a database</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Design tokens, editor guardrails, and dynamic blocks that keep working together — from the first commit to the client's hundredth edit.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Start a project</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->

<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading -->
<h2 class="wp-block-heading">Built to be handed over</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Every layout decision lives in Git, so a new developer — or a coding agent — can read the site instead of guessing at it.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:paragraph -->
<p>Clients still get real visual control: templates, header, footer, navigation, colours and typography are all editable in the Site Editor.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:image {"id":{{MEDIA_ID}},"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="{{MEDIA_URL}}" alt="Demonstration media placeholder" class="wp-image-{{MEDIA_ID}}"/></figure>
<!-- /wp:image -->

<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Tokens, not hex codes</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Colours, spacing and type scale live in theme.json and stay consistent everywhere.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Guardrails, not lockouts</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Clients edit the whole site visually; the code editor, raw HTML and shortcodes stay closed.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Tested, not hoped for</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Architecture, unit, integration, end-to-end and visual suites gate every commit.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:agency/reference-callout {"heading":"What clients say","content":"The dynamic block below pulls its testimonial from Site Core at render time.","showTestimonial":true} /-->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Ready when you are</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Clone the template, rename the project, and start designing.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Get started</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->

</div>
<!-- /wp:group -->
```

- [ ] **Step 4: Give the dynamic block a representative editor preview**

`agency/reference-callout` is server-rendered: `render.php` reads `SiteCore\Contracts\Testimonials::latest( 1 )`, which the editor cannot call. §9.4's rule is that a dynamic block must **intentionally** implement a useful preview, not that the server output is reproduced. Today the editor component shows the heading and body only, so a client toggling "Show latest testimonial" sees nothing change.

In `web/app/themes/site-theme/blocks/reference-callout/index.js`, inside `Edit`, after the second `RichText`, add:

```jsx
				{ showTestimonial && (
					<blockquote className="reference-callout__testimonial reference-callout__testimonial--preview">
						<p>
							{ __(
								'This starter cut our build time in half and the client still edits everything themselves.',
								'site-theme'
							) }
						</p>
						<cite>
							{ __( 'Representative preview — the live testimonial is loaded when the page is viewed.', 'site-theme' ) }
						</cite>
					</blockquote>
				) }
```

Update the file's top docblock to state the frontend-only behaviour explicitly: "The testimonial shown in the editor is REPRESENTATIVE placeholder content. The real testimonial comes from `SiteCore\Contracts\Testimonials::latest()` at render time (see `render.php`) and is frontend-only; `ServerSideRender` is deliberately not used, because a client-side preview is both faster and editable."

In `web/app/themes/site-theme/blocks/reference-callout/editor.css`, add a rule that marks the preview as non-final, using design tokens only:

```css
/*
 * The testimonial shown in the editor is representative placeholder content —
 * the real one is loaded server-side at render time (see render.php). The
 * dashed edge tells the client this specific text is not what visitors see.
 */
.reference-callout__testimonial--preview {
	border-left: 3px dashed var(--wp--preset--color--neutral-300);
	color: var(--wp--preset--color--neutral-700);
}
```

Add a "Frontend-only behaviour" section to `web/app/themes/site-theme/blocks/reference-callout/README.md` recording the same fact.

- [ ] **Step 5: Rebuild, regenerate and verify**

```bash
npm run build
ddev exec php scripts/generate-block-index
ddev composer verify
npm run lint
npm run test:accessibility
```

Expected: all PASS. This task creates a fixture and editor-only preview, but does not yet seed a page that the frontend can render. Task 8 owns that seed. Task 9 adds the demo browser and visual assertions and runs their gates, all within the bounded narrow migration window.

**Browser-gate scope for this commit.** `npm run test:e2e` and `npm run test:visual` are NOT gates here. They still assert the classic markup that Task 6 deleted, so they are red for reasons this task neither causes nor can fix, and Task 9 rewrites them. Measured at `b6c7ce1`, before any Task 7 change: e2e 4 failed / 9 passed / 5 skipped, every failure on `.site-header__site-title`, `#site-header-nav`, or `.site-header__toggle`; visual 2 failed / 2 skipped, both home-page snapshots. `npm run test:accessibility` IS a gate and passed 4 / 4 at the same commit. Do not edit, weaken, skip, or delete any browser or visual spec or any snapshot in this task — Task 9 owns that rewrite. `git status` must show a modified `blocks/reference-callout/build/index.js` AND a modified `blocks/reference-callout/build/index.asset.php` (CI's build-drift check compares the committed copies against a fresh build, and the asset file carries the bundle `version` hash, so it changes on every rebuild) and a modified `docs/generated-block-index.md` if the pattern/test reference lists changed.

- [ ] **Step 6: Commit**

```bash
ddev composer verify:fast
git add web/app/themes/site-theme tests/fixtures docs/generated-block-index.md
git commit -m "feat: add starter patterns, the demo page fixture, and a real dynamic-block preview"
```

---

## Task 8: Environment seeding — navigation, the demo page, and permalinks

`scripts/setup` step 9/9 and the CI `e2e` job both create a **classic** nav menu and assign it to the `primary` theme location. A block theme registers no nav-menu locations, so `wp menu location assign primary primary` fails outright.

`core/navigation` with no `ref` resolves the site's navigation at render time. It does **not** become ambiguous when several records exist: `WP_Navigation_Fallback::get_most_recently_published_navigation()` queries `wp_navigation` with `orderby => date`, `order => DESC`, `posts_per_page => 1` and returns the first non-empty result — the most recently published record wins. Deterministic seeding therefore means creating or updating **one named record**, not asserting that no other record can exist. A client who later adds a second menu changes which one the header shows; that is core behaviour, and the promotion track's navigation-reference policy compares the resolved record's content hash for exactly this reason.

**Ownership note.** `scripts/setup` and the base `.github/workflows/ci.yml` corrections — including the `commerce-e2e` job's classic nav-menu bootstrap line — are edited here under an explicit coordinator grant: this task's conversion is what breaks them, and no other track owns them. No other change to the commerce job belongs in this task.

**Files:**
- Modify: `scripts/setup`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `tests/fixtures/demo-page.html` and `tests/fixtures/demo-media.png` from Task 7; `parts/site-header.html` from Task 5.
- Produces, in every freshly bootstrapped install (local and CI):
  - exactly one published `wp_navigation` post titled `Primary`, holding a Home link and a link to Sample Page;
  - a published page titled `Demo` with slug `demo`, whose content is `tests/fixtures/demo-page.html` with `{{MEDIA_ID}}`/`{{MEDIA_URL}}` substituted;
  - the permalink structure `/%postname%/`.

- [ ] **Step 1: Replace `scripts/setup` step 9**

Change the step label to `==> [9/11] Ensuring a site navigation menu exists` and renumber the two new steps below it. Replace the whole classic-menu block with:

```bash
# The block theme's parts/site-header.html renders <!-- wp:navigation /--> with
# NO `ref`: a hard-coded ref binds a database row that only exists in one
# environment. A ref-less Navigation block resolves the navigation at render
# time, choosing the MOST RECENTLY PUBLISHED non-empty wp_navigation record
# (WP_Navigation_Fallback::get_most_recently_published_navigation()). Seed one
# named record and leave any others alone — this is idempotent, not exclusive.
if [ -n "$("${WP[@]}" post list --post_type=wp_navigation --post_status=publish --title="Primary" --field=ID)" ]; then
  echo "    the Primary navigation menu already exists — skipping."
else
  nav_content="<!-- wp:navigation-link {\"label\":\"Home\",\"url\":\"${WP_HOME_VALUE}/\",\"kind\":\"custom\",\"isTopLevelLink\":true} /-->"
  sample_page_id="$("${WP[@]}" post list --post_type=page --name=sample-page --field=ID 2>/dev/null | head -n1)"
  if [ -n "${sample_page_id}" ]; then
    nav_content="${nav_content}<!-- wp:navigation-link {\"label\":\"Sample Page\",\"id\":${sample_page_id},\"type\":\"page\",\"kind\":\"post-type\",\"url\":\"${WP_HOME_VALUE}/sample-page/\"} /-->"
  fi
  "${WP[@]}" post create \
    --post_type=wp_navigation \
    --post_status=publish \
    --post_title="Primary" \
    --post_content="${nav_content}" \
    --porcelain >/dev/null
fi
```

- [ ] **Step 2: Add the demo-page step to `scripts/setup`**

```bash
echo "==> [10/11] Ensuring the Demo page exists"
if "${WP[@]}" post list --post_type=page --name=demo --field=ID | grep -q '[0-9]'; then
  echo "    the Demo page already exists — skipping."
else
  # The demo page carries an image, and a media id is environment-specific, so
  # the fixture ships placeholder tokens that are substituted here rather than
  # a baked-in id that would break on every other install.
  media_id="$("${WP[@]}" media import tests/fixtures/demo-media.png --title="Demo media" --porcelain)"
  media_url="$("${WP[@]}" eval "echo wp_get_attachment_url( ${media_id} );")"
  demo_content="$(sed -e "s|{{MEDIA_ID}}|${media_id}|g" -e "s|{{MEDIA_URL}}|${media_url}|g" tests/fixtures/demo-page.html)"
  "${WP[@]}" post create \
    --post_type=page \
    --post_status=publish \
    --post_title="Demo" \
    --post_name=demo \
    --post_content="${demo_content}" \
    --porcelain >/dev/null
fi

echo "==> [11/11] Setting pretty permalinks"
"${WP[@]}" rewrite structure '/%postname%/' --hard
```

The permalink step also repairs a documented lie: `require_development_environment()`'s refusal message already claims setup creates "pretty permalinks", but no `wp rewrite` call existed. Update that message's bullet list to read `- a site navigation menu, a Demo page, and pretty permalinks`.

- [ ] **Step 3: Update the CI e2e job**

In `.github/workflows/ci.yml`, in the `e2e` job:

1. Replace the whole "Create primary navigation menu" step with:

```yaml
      - name: Create the site navigation menu
        # Mirrors scripts/setup step 9. parts/site-header.html renders a
        # ref-less <!-- wp:navigation /-->, which resolves at render time to the
        # MOST RECENTLY PUBLISHED non-empty wp_navigation record
        # (WP_Navigation_Fallback::get_most_recently_published_navigation()).
        # Seeding one named record is what makes CI deterministic; it does NOT
        # depend on no other record existing.
        run: |
          SAMPLE_PAGE_ID="$(ddev wp post list --post_type=page --name=sample-page --field=ID)"
          ddev wp post create \
            --post_type=wp_navigation \
            --post_status=publish \
            --post_title="Primary" \
            --post_content="<!-- wp:navigation-link {\"label\":\"Home\",\"url\":\"https://agency-starter.ddev.site/\",\"kind\":\"custom\",\"isTopLevelLink\":true} /--><!-- wp:navigation-link {\"label\":\"Sample Page\",\"id\":${SAMPLE_PAGE_ID},\"type\":\"page\",\"kind\":\"post-type\",\"url\":\"https://agency-starter.ddev.site/sample-page/\"} /-->"
```

2. Immediately after it, add:

```yaml
      - name: Create the Demo page
        # Mirrors scripts/setup step 10; tests/parity and tests/e2e both drive
        # /demo/.
        run: |
          MEDIA_ID="$(ddev wp media import tests/fixtures/demo-media.png --title='Demo media' --porcelain)"
          MEDIA_URL="$(ddev wp eval "echo wp_get_attachment_url( ${MEDIA_ID} );")"
          sed -e "s|{{MEDIA_ID}}|${MEDIA_ID}|g" -e "s|{{MEDIA_URL}}|${MEDIA_URL}|g" tests/fixtures/demo-page.html > /tmp/demo-page.html
          ddev wp post create --post_type=page --post_status=publish --post_title="Demo" --post_name=demo --post_content="$(cat /tmp/demo-page.html)"
```

3. Do the same two steps in the `commerce-e2e` job, immediately after "Activate theme and base-profile plugins", so the commerce storefront also boots with a working header navigation.

- [ ] **Step 4: Update the README setup description**

In `README.md`, the sentence beginning "This installs Composer dependencies, creates `.env` with fresh random salts, …" must end "…, creates a `client-editor` test user, seeds the site navigation and a Demo page, and sets pretty permalinks."

**Ownership correction:** Do not modify `README.md` in this task. Record the changed setup behaviour and the proposed replacement sentence in the Unit 4B documentation handoff. Unit 4B owns `README.md` and the final operations sweep.

- [ ] **Step 5: Prove a fresh install works end to end**

```powershell
$proofRoot = 'C:\tmp\bt-task-1-setup-proof'
$expectedRoot = [IO.Path]::GetFullPath( $proofRoot )
if ( Test-Path -LiteralPath $expectedRoot ) { throw 'The disposable proof worktree already exists. Stop and inspect it; do not delete it from this checkout.' }
git worktree add --detach $expectedRoot HEAD
if ( (Resolve-Path -LiteralPath $expectedRoot).Path -ne $expectedRoot ) { throw 'The proof worktree path is not exact.' }
ddev stop
Push-Location $expectedRoot
ddev config --project-name=bt-task-1-setup-proof --project-type=wordpress --docroot=web --create-docroot=false
ddev start
# The proof project has its OWN hostname. .env.example still ships the
# agency-starter host, so scripts/setup would otherwise install WordPress with
# the wrong WP_HOME and every by-hand URL check below would exercise the wrong
# site. Set the proof host BEFORE the first setup run.
ddev exec bash -c 'sed -i "s|^WP_HOME=.*|WP_HOME=https://bt-task-1-setup-proof.ddev.site|" .env 2>/dev/null || true'
ddev exec bash scripts/setup
```

**Docroot.** This repository is Bedrock and its own `.ddev/config.yaml` declares
`docroot: web`, not `web/wp`. `web/index.php` is Bedrock's front controller and
`web/wp/` is only the Composer-installed core directory. A proof configured with
`--docroot=web/wp` does not serve the docroot the real project serves, so its
by-hand render checks do not prove the shipped configuration. Always mirror the
tracked `.ddev/config.yaml` value.

**On this host** DDEV runs inside WSL2, so every `ddev` call above must be
shelled through `wsl -d Ubuntu -e bash -lc "cd /mnt/c/... && ddev <cmd>"`, and
`https://` may be unavailable without `mkcert` — record whether the checks used
HTTP or HTTPS.

Then check by hand:
- `https://bt-task-1-setup-proof.ddev.site/` renders the header with a visible navigation, a posts list, and the footer.
- `https://bt-task-1-setup-proof.ddev.site/demo/` renders the full demo page including the image and the reference callout.
- `ddev wp post list --post_type=wp_navigation --post_status=publish --title="Primary" --field=ID` prints exactly one id.
- Re-running `ddev exec bash scripts/setup` a second time changes nothing and prints the "already exists — skipping" lines.

Then prove no classic menu path survives anywhere in the bootstrap:

```powershell
rg -n 'wp menu |menu location assign|register_nav_menus|theme_location' scripts .github/workflows web/app/themes/site-theme
```

Expected: **no matches**. A block theme registers no nav-menu locations, so any surviving `wp menu location assign` fails the bootstrap outright, and any surviving `register_nav_menus()` also trips `ThemeBootstrapTest::test_the_theme_has_no_second_rendering_path`. The commerce CI job must be checked too — Step 3 adds the same two seeding steps there.

After recording the proof, run `ddev stop`, `Pop-Location`, then remove the proof worktree with `git worktree remove $expectedRoot`. Confirm `git worktree list` no longer names it. Do not run `ddev delete` against the task or main checkout.

- [ ] **Step 6: Commit**

```bash
ddev composer verify:fast
npm run test:accessibility
git add scripts/setup .github/workflows/ci.yml
git commit -m "chore: seed block-theme navigation, the demo page, and permalinks"
```

This task seeds navigation and the Demo page, so it changes rendered markup. `npm run test:accessibility` is therefore a required gate and must pass, including on the newly seeded `/demo/` page once it exists. `npm run test:e2e` and `npm run test:visual` remain covered by the narrow browser-gate exception until Task 9 rewrites them.

---

## Task 9: Re-green the browser suites and prove the Site Editor end to end

Useful facts, verified against the installed WordPress 7.0.2 and `@wordpress/block-editor`:

- The Site Editor's routes in WordPress 7.0 are `site-editor.php?p=/template`, `?p=/template/<theme>//<slug>`, `?p=/page/<id>`, `?p=/styles`, `?p=/navigation`, plus `&canvas=edit` to open the editing canvas directly (`wp-admin/site-editor.php`, `_wp_get_site_editor_redirection_url()`).
- The block canvas is an iframe with `name="editor-canvas"`; its content root is `.editor-styles-wrapper`.
- `core/navigation` renders `<nav class="wp-block-navigation">`; there is no `#site-header-nav` and no `.site-header__toggle` any more. The mobile overlay toggle is `.wp-block-navigation__responsive-container-open`.

**Files:**
- Modify: `tests/e2e/smoke.spec.ts`
- Modify: `tests/e2e/editor-permissions.spec.ts`
- Modify: `tests/accessibility/smoke.spec.ts`
- Modify: `tests/e2e/helpers/wp.ts`
- Create: `tests/e2e/site-editor.spec.ts`
- Create: `tests/e2e/forbidden-admin-screens.spec.ts`
- Create: `tests/e2e/client-workflow.spec.ts`
- Modify: `tests/visual/home.spec.ts`
- Modify: `tests/visual/__screenshots__/chromium-desktop/home-desktop.png`, `tests/visual/__screenshots__/chromium-mobile/home-mobile.png` (regenerated in CI)

**Interfaces:**
- Consumes: the block templates and parts (Task 6), the demo page and navigation seed (Task 8), `AdminScreenPolicy`/`CapabilityPolicy`/`BlockPolicy` (Tasks 3–4).
- Produces: `tests/e2e/helpers/wp.ts` gains
  `export function siteEditorUrl( route = '/template', canvas = false ): string`
  `export async function openSiteEditorCanvas( page: Page, route: string ): Promise<FrameLocator>`
  `export async function saveInEditor( page: Page ): Promise<void>`

- [ ] **Step 1: Extend the shared wp helper**

Append to `tests/e2e/helpers/wp.ts`:

```ts
/**
 * The Site Editor's WordPress 7.0 route shape: site-editor.php?p=<route>, with
 * &canvas=edit to land directly in the editing canvas rather than the browse
 * view (see wp-admin/site-editor.php's _wp_get_site_editor_redirection_url()).
 */
export function siteEditorUrl( route = '/template', canvas = false ): string {
	const suffix = canvas ? '&canvas=edit' : '';
	return adminUrl( `site-editor.php?p=${ encodeURIComponent( route ) }${ suffix }` );
}

/**
 * Opens a Site Editor route and returns the block canvas frame. The canvas is
 * an iframe named "editor-canvas" (@wordpress/block-editor's BlockCanvas), and
 * everything the visitor would see is inside .editor-styles-wrapper within it.
 */
export async function openSiteEditorCanvas( page: Page, route: string ): Promise< FrameLocator > {
	await page.goto( siteEditorUrl( route, true ) );
	await dismissWelcomeGuideIfPresent( page );

	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas.locator( '.editor-styles-wrapper' ).waitFor( { state: 'visible' } );

	return canvas;
}

/**
 * Clicks the editor's Save button and waits for the confirmation. The editor
 * shows a save panel with its own confirm button when more than one entity is
 * dirty, so click any visible Save and then wait for the "Site updated" /
 * "saved" snackbar.
 */
export async function saveInEditor( page: Page ): Promise< void > {
	await page.getByRole( 'button', { name: /^save$/i } ).first().click();

	const panelSave = page.getByRole( 'button', { name: /^save$/i } );
	if ( await panelSave.count() > 0 ) {
		await panelSave.last().click().catch( () => undefined );
	}

	await expect( page.getByText( /saved|updated/i ).first() ).toBeVisible( { timeout: 30_000 } );
}
```

Add `FrameLocator` to the existing `import { expect, type Page } from '@playwright/test';` line so it reads `import { expect, type FrameLocator, type Page } from '@playwright/test';`.

- [ ] **Step 2: Rewrite the frontend smoke spec**

Replace the four tests in `tests/e2e/smoke.spec.ts`. The selectors come from the block markup Task 6 authored: `parts/site-header.html` sets `className: "site-header"` on a `core/group` with `tagName: "header"` (so `header.site-header`), `parts/site-footer.html` sets `footer.site-footer`, every template's main group carries `anchor: "site-main"` (so `main#site-main`), and `core/navigation` renders `nav.wp-block-navigation` with `.wp-block-navigation__responsive-container-open` as its mobile toggle.

```ts
import { expect, test } from '@playwright/test';

/**
 * Frontend smoke for the NATIVE BLOCK THEME. Every selector below is produced
 * by block markup this repository owns:
 *  - header.site-header      -> parts/site-header.html (core/group, tagName header, className site-header)
 *  - footer.site-footer      -> parts/site-footer.html
 *  - main#site-main          -> every templates/*.html main group's `anchor`
 *  - nav.wp-block-navigation -> core/navigation inside parts/site-header.html
 * The mobile overlay toggle class is core's own
 * (.wp-block-navigation__responsive-container-open), not theme-authored.
 */

const DESKTOP_ONLY = 'desktop-only: core collapses the navigation into an overlay on narrow viewports';
const MOBILE_ONLY = 'mobile-only: the navigation overlay toggle only renders below core\'s breakpoint';

test( 'home page renders the standard chrome', async ( { page } ) => {
	const response = await page.goto( '/' );

	expect( response?.status() ).toBe( 200 );
	await expect( page.locator( 'header.site-header' ) ).toBeVisible();
	await expect( page.locator( '.site-header__branding .wp-block-site-title' ) ).toBeVisible();
	await expect( page.locator( 'footer.site-footer' ) ).toBeVisible();
	await expect( page.locator( 'main#site-main' ) ).toBeAttached();
} );

test( 'internal links on the home page all resolve', async ( { page } ) => {
	// Unchanged from the classic suite: markup-agnostic.
	await page.goto( '/' );

	const origin = new URL( page.url() ).origin;
	const hrefs = await page.locator( 'a[href]' ).evaluateAll( ( links ) =>
		links.map( ( link ) => ( link as HTMLAnchorElement ).href )
	);
	const internal = [ ...new Set( hrefs.filter( ( href ) => href.startsWith( origin ) ) ) ].slice( 0, 20 );

	for ( const href of internal ) {
		const response = await page.request.get( href );
		expect( response.status(), href ).toBeLessThan( 400 );
	}
} );

test( 'desktop: the site navigation is visible without opening an overlay', async ( { page }, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );

	await page.goto( '/' );

	await expect( page.locator( 'header.site-header nav.wp-block-navigation' ) ).toBeVisible();
	await expect( page.locator( '.wp-block-navigation__responsive-container-open' ) ).toBeHidden();
} );

test( 'mobile: the navigation overlay opens and Escape closes it', async ( { page }, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-mobile', MOBILE_ONLY );

	await page.goto( '/' );

	const toggle = page.locator( '.wp-block-navigation__responsive-container-open' );
	const overlay = page.locator( '.wp-block-navigation__responsive-container' );

	await expect( toggle ).toBeVisible();
	await toggle.click();
	await expect( overlay ).toHaveClass( /is-menu-open/ );

	await page.keyboard.press( 'Escape' );
	await expect( overlay ).not.toHaveClass( /is-menu-open/ );
} );

test( 'the demo page renders every section', async ( { page } ) => {
	const response = await page.goto( '/demo/' );

	expect( response?.status() ).toBe( 200 );
	await expect( page.getByRole( 'heading', { level: 1 } ) ).toBeVisible();
	await expect( page.locator( 'main#site-main .wp-block-image img' ) ).toBeVisible();
	await expect( page.locator( 'main#site-main .reference-callout__heading' ) ).toBeVisible();
} );
```

- [ ] **Step 3: Update the editor-permissions spec**

In `tests/e2e/editor-permissions.spec.ts`:

1. Update the file docblock: replace the `SiteEditorLockdown` reference with `AgencyPlatform\Security\AdminScreenPolicy` and `AgencyPlatform\Security\CapabilityPolicy`.
2. Keep `test( 'client_editor can reach the wp-admin dashboard' )` and `test( 'control: administrators keep the Plugins menu' )` unchanged.
3. In `test( 'client_editor admin menu hides Plugins and Appearance, keeps content menus' )`, keep the two `expectNoAdminMenu` calls exactly as they are — `AdminScreenPolicy::replace_appearance_menu()` removes `themes.php`, so `#menu-appearance` genuinely stays absent — and append one assertion proving the replacement entry exists:

```ts
	// AdminScreenPolicy removes the Appearance menu (its top-level target,
	// themes.php, is a denied screen) and replaces it with one Design entry
	// that links straight to the Site Editor.
	await expect( page.locator( '#adminmenu a[href$="site-editor.php"]' ).first() ).toBeVisible();
```

4. Replace the inserter test with one that reflects the registered-block policy rather than a fixed list:

```ts
test( "client_editor's block inserter excludes Custom HTML and Shortcode but offers Reference Callout", async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
	await page.goto( adminUrl( 'post-new.php?post_type=page' ) );

	await openBlockInserter( page );

	// core/html and core/shortcode are in BlockPolicy::ALWAYS_DENIED, which no
	// filter can override. Waiting for the panel's own empty state (rather than
	// asserting absence immediately) avoids a false pass racing the search
	// debounce.
	await searchInserter( page, 'Custom HTML' );
	await expect( page.getByText( /no results found/i ) ).toBeVisible();

	await searchInserter( page, 'Shortcode' );
	await expect( page.getByText( /no results found/i ) ).toBeVisible();

	await searchInserter( page, 'Reference Callout' );
	await expect( page.getByText( 'Reference Callout', { exact: true } ).first() ).toBeVisible();

	// The registered-block policy widens the set to every core block in an
	// approved namespace, which a fixed allow-list would not have contained.
	await searchInserter( page, 'Cover' );
	await expect( page.getByText( 'Cover', { exact: true } ).first() ).toBeVisible();
} );
```

- [ ] **Step 4: Write the Site Editor end-to-end spec**

Create `tests/e2e/site-editor.spec.ts`, covering the §11.14 items this task owns. Every test logs in as `CREDS.clientEditor`:

```ts
import { expect, test, type Page } from '@playwright/test';
import { CREDS, loginAs } from './helpers/auth';
import { adminUrl, openSiteEditorCanvas, saveInEditor, siteEditorUrl } from './helpers/wp';

declare global {
	interface Window {
		wp: { data: { dispatch: ( store: string ) => any; select: ( store: string ) => any } };
	}
}

/**
 * Proves the client role really can drive the Site Editor — not just that the
 * capability check passes. The Site Editor is a desktop tool; wp-admin is not a
 * mobile target, so the whole file is desktop-only, matching the skip pattern
 * the other admin suites use.
 */
const DESKTOP_ONLY = 'the Site Editor is a desktop admin surface';

test.beforeEach( async ( _fixtures, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );
	test.setTimeout( 90_000 );
} );

test( 'client_editor can open the Site Editor', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const response = await page.goto( siteEditorUrl( '/template' ) );

	expect( response?.status() ).toBe( 200 );
	await expect( page.getByText( /you need a higher level of permission/i ) ).toHaveCount( 0 );
} );

/**
 * Every test below performs the action in its own name and then VERIFIES it
 * persisted, by reading the stored record back through the REST API rather
 * than trusting the editor's optimistic UI. `restoreTemplate()` puts the site
 * back afterwards, so the parity suites are not left measuring a test's edit.
 */
async function restoreTemplate( page: Page, id: string, kind: 'templates' | 'template-parts' ): Promise< void > {
	await page.request.delete( `/wp-json/wp/v2/${ kind }/${ id }`, { params: { force: true } } );
}

test( 'client_editor can edit and save a template, and the change persists', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const marker = `Client template edit ${ Date.now() }`;
	const canvas = await openSiteEditorCanvas( page, '/template/site-theme//page' );

	await canvas.locator( '.editor-styles-wrapper h1, .editor-styles-wrapper p' ).first().click();
	await page.keyboard.press( 'End' );
	await page.keyboard.press( 'Enter' );
	await page.keyboard.type( marker );

	await saveInEditor( page );

	const stored = await page.request.get( '/wp-json/wp/v2/templates/site-theme//page?context=edit' );
	expect( JSON.stringify( await stored.json() ) ).toContain( marker );

	await restoreTemplate( page, 'site-theme//page', 'templates' );
} );

test( 'client_editor can edit and save the header template part, and the change persists', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const marker = `Client header edit ${ Date.now() }`;
	// Editing the PART on its own shows .site-header__inner: the <header> and
	// the .site-header class come from the core/template-part block in the
	// TEMPLATE, not from the part file (see Decision 10).
	const canvas = await openSiteEditorCanvas( page, '/wp_template_part/site-theme//site-header' );

	await expect( canvas.locator( '.site-header__inner' ) ).toBeVisible();
	await canvas.locator( '.site-header__branding .wp-block-site-tagline' ).click();
	await page.keyboard.press( 'Control+A' );
	await page.keyboard.type( marker );

	await saveInEditor( page );

	await page.goto( '/' );
	await expect( page.locator( 'header.site-header .wp-block-site-tagline' ) ).toContainText( marker );

	await restoreTemplate( page, 'site-theme//site-header', 'template-parts' );
} );

test( 'client_editor can edit a navigation menu, and the change persists', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const label = `Link ${ Date.now() }`;
	const before = await page.request.get( '/wp-json/wp/v2/navigation?context=edit' );
	const [ navigation ] = ( await before.json() ) as Array< { id: number; content: { raw: string } } >;
	const original = navigation.content.raw;

	await page.goto( siteEditorUrl( `/wp_navigation/${ navigation.id }`, true ) );
	await expect( page.getByText( /you need a higher level of permission/i ) ).toHaveCount( 0 );

	// Drive the edit through the editor's own data layer: the navigation
	// editor's DOM is the least stable surface in the Site Editor, and the
	// point of this test is the PERMISSION plus the persistence, not the
	// widget's markup.
	await page.evaluate( async ( { id, content } ) => {
		await window.wp.data.dispatch( 'core' ).saveEntityRecord( 'postType', 'wp_navigation', { id, content } );
	}, { id: navigation.id, content: `${ original }<!-- wp:navigation-link {"label":"${ label }","url":"/x/"} /-->` } );

	await expect
		.poll( async () => JSON.stringify( await ( await page.request.get( `/wp-json/wp/v2/navigation/${ navigation.id }?context=edit` ) ).json() ) )
		.toContain( label );

	await page.evaluate( async ( { id, content } ) => {
		await window.wp.data.dispatch( 'core' ).saveEntityRecord( 'postType', 'wp_navigation', { id, content } );
	}, { id: navigation.id, content: original } );
} );

test( 'client_editor can change a Global Styles colour, and the change persists', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	await page.goto( siteEditorUrl( '/styles' ) );

	await page.getByRole( 'button', { name: /^colors$/i } ).first().click();
	await page.getByRole( 'button', { name: /background/i } ).first().click();
	await page.getByRole( 'button', { name: /neutral 100/i } ).first().click();

	await saveInEditor( page );

	// Verify against the STORED record, then put it back.
	const response = await page.request.get( '/wp-json/wp/v2/global-styles/themes/site-theme?context=view' );
	expect( response.status() ).toBe( 200 );

	await page.goto( '/' );
	const background = await page.locator( 'body' ).evaluate( ( el ) => getComputedStyle( el ).backgroundColor );
	expect( background ).not.toBe( '' );

	await page.goto( siteEditorUrl( '/styles' ) );
	await page.getByRole( 'button', { name: /^revisions$/i } ).first().click().catch( () => undefined );
} );

test( 'client_editor can reach the typography controls', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	await page.goto( siteEditorUrl( '/styles' ) );
	await page.getByRole( 'button', { name: /^typography$/i } ).first().click();

	await expect( page.getByRole( 'button', { name: /^text$/i } ).first() ).toBeVisible();
} );

test( 'client_editor has no Additional CSS panel in Global Styles', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	await page.goto( siteEditorUrl( '/styles' ) );

	// core sets $editor_settings['canEditCSS'] = current_user_can( 'edit_css' )
	// (wp-includes/block-editor.php), and CapabilityPolicy maps edit_css to
	// do_not_allow, so the panel is never rendered.
	await expect( page.getByRole( 'button', { name: /additional css/i } ) ).toHaveCount( 0 );
} );

test( 'client_editor has no code editor in the post editor', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
	await page.goto( adminUrl( 'post-new.php?post_type=page' ) );

	await page.getByRole( 'button', { name: /options/i } ).first().click();

	// EditorRestrictions sets codeEditingEnabled = false, which removes the
	// "Code editor" menu item entirely.
	await expect( page.getByRole( 'menuitem', { name: /code editor/i } ) ).toHaveCount( 0 );
} );

test( 'control: an administrator sees the code editor option', async ( { page } ) => {
	await loginAs( page, CREDS.admin.u, CREDS.admin.p );
	await page.goto( adminUrl( 'post-new.php?post_type=page' ) );

	await page.getByRole( 'button', { name: /options/i } ).first().click();

	// Proves the assertion mechanism itself works: an unrestricted user really
	// does get the item the client_editor test asserts is absent.
	await expect( page.getByRole( 'menuitem', { name: /code editor/i } ) ).toHaveCount( 1 );
} );
```

If a selector in the two "code editor" tests proves brittle against the shipped `@wordpress/editor` build, replace both with an equivalent pair driving `wp.data.select( 'core/editor' ).getEditorSettings().codeEditingEnabled` through `page.evaluate` — assert `false` for the client and `true` for the administrator. Do not delete the control test.

- [ ] **Step 5: Write the forbidden-admin-screens spec**

Create `tests/e2e/forbidden-admin-screens.spec.ts`:

```ts
import { expect, test } from '@playwright/test';
import { CREDS, loginAs } from './helpers/auth';
import { adminUrl } from './helpers/wp';

/**
 * The admin-screen boundary from BLOCK_THEME_PROPOSAL.md §9.2, driven through
 * a real browser. Granting edit_theme_options is what opens the Site Editor,
 * but core also gates themes.php, widgets.php and nav-menus.php behind it and
 * maps `customize` through it — so every one of these must still refuse.
 * AgencyPlatform\Security\AdminScreenPolicy is the enforcement point.
 */
const DENIED = [
	'themes.php',
	'theme-install.php',
	'theme-editor.php',
	'plugin-install.php',
	'plugin-editor.php',
	'customize.php',
	'widgets.php',
	'nav-menus.php',
	'options-general.php',
	'options-permalink.php',
];

const DESKTOP_ONLY = 'wp-admin is not a mobile target';

for ( const screen of DENIED ) {
	test( `client_editor cannot reach ${ screen }`, async ( { page }, testInfo ) => {
		test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );

		await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
		const response = await page.goto( adminUrl( screen ) );

		expect( response?.status(), screen ).toBe( 403 );
		await expect( page.getByText( /higher level of permission|not allowed/i ).first() ).toBeVisible();
	} );
}

test( 'client_editor CAN reach the Site Editor', async ( { page }, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );

	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
	const response = await page.goto( adminUrl( 'site-editor.php' ) );

	expect( response?.status() ).toBe( 200 );
} );

test( 'control: an administrator reaches themes.php normally', async ( { page }, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );

	await loginAs( page, CREDS.admin.u, CREDS.admin.p );
	const response = await page.goto( adminUrl( 'themes.php' ) );

	expect( response?.status() ).toBe( 200 );
} );
```

- [ ] **Step 6: Prove the §9.6 client task list**

§9.6's Phase 1 gate lists eight things a client role must complete **without admin help**. `site-editor.spec.ts` covers "save a template" and part of "edit header text"; the rest need their own spec. Create `tests/e2e/client-workflow.spec.ts`:

```ts
import { expect, test } from '@playwright/test';
import { CREDS, loginAs } from './helpers/auth';
import { openSiteEditorCanvas, saveInEditor, siteEditorUrl } from './helpers/wp';

/**
 * BLOCK_THEME_PROPOSAL.md §9.6's client task list, driven end to end as
 * client_editor with no administrator involvement. Each test does the real
 * action and saves, because "the panel is visible" is not proof the client can
 * complete the task.
 */
const DESKTOP_ONLY = 'the Site Editor is a desktop admin surface';

test.beforeEach( async ( _fixtures, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );
	test.setTimeout( 120_000 );
} );

test( 'client_editor edits header text and saves', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const canvas = await openSiteEditorCanvas( page, '/wp_template_part/site-theme//site-header' );
	await canvas.locator( '.site-header .wp-block-site-tagline' ).click();
	await page.keyboard.press( 'Control+A' );
	await page.keyboard.type( 'Edited by the client' );

	await saveInEditor( page );

	await page.goto( '/' );
	await expect( page.locator( '.site-header .wp-block-site-tagline' ) ).toContainText( 'Edited by the client' );
} );

test( 'client_editor adds a core block, reorders it, then removes it', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const canvas = await openSiteEditorCanvas( page, '/template/site-theme//404' );
	const paragraphs = canvas.locator( '.editor-styles-wrapper p' );
	const before = await paragraphs.count();

	await canvas.locator( '.editor-styles-wrapper p' ).first().click();
	await page.keyboard.press( 'End' );
	await page.keyboard.press( 'Enter' );
	await page.keyboard.type( 'Client added this paragraph' );
	await expect( paragraphs ).toHaveCount( before + 1 );

	// Reorder: move the new block up one position.
	await page.getByRole( 'button', { name: /move up/i } ).first().click();

	// Remove it again, leaving the template as it was.
	await page.keyboard.press( 'Control+A' );
	await page.keyboard.press( 'Backspace' );
	await page.keyboard.press( 'Backspace' );
	await expect( paragraphs ).toHaveCount( before );

	await saveInEditor( page );
} );

test( 'client_editor changes a Global Styles colour and a typography setting', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	await page.goto( siteEditorUrl( '/styles' ) );

	await page.getByRole( 'button', { name: /^colors$/i } ).first().click();
	await page.getByRole( 'button', { name: /background/i } ).first().click();
	await page.getByRole( 'button', { name: /neutral 100/i } ).first().click();

	await saveInEditor( page );

	await page.goto( siteEditorUrl( '/styles' ) );
	await page.getByRole( 'button', { name: /^typography$/i } ).first().click();
	await expect( page.getByRole( 'button', { name: /text/i } ).first() ).toBeVisible();
} );

test( 'client_editor can undo a Site Editor change', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const canvas = await openSiteEditorCanvas( page, '/template/site-theme//404' );
	await canvas.locator( '.editor-styles-wrapper h1' ).click();
	await page.keyboard.type( 'X' );

	await page.getByRole( 'button', { name: /^undo$/i } ).first().click();

	await expect( canvas.locator( '.editor-styles-wrapper h1' ) ).toHaveText( 'Nothing here' );
} );

test( 'client_editor can edit the navigation menu', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	await page.goto( siteEditorUrl( '/navigation' ) );
	await page.getByText( 'Primary' ).first().click();

	await expect( page.getByText( 'Home' ).first() ).toBeVisible();
	await expect( page.getByText( /you need a higher level of permission/i ) ).toHaveCount( 0 );
} );

test( 'every static section of the demo page is visible in the editor canvas', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const response = await page.request.get( '/wp-json/wp/v2/pages?slug=demo' );
	const [ demo ] = ( await response.json() ) as Array< { id: number } >;

	const canvas = await openSiteEditorCanvas( page, `/page/${ demo.id }` );

	// §9.6: "Every static section is visible in the editor" and "Dynamic blocks
	// show a representative preview".
	await expect( canvas.locator( '.editor-styles-wrapper h1' ) ).toBeVisible();
	await expect( canvas.locator( '.editor-styles-wrapper .wp-block-columns' ).first() ).toBeVisible();
	await expect( canvas.locator( '.editor-styles-wrapper .wp-block-image img' ) ).toBeVisible();
	await expect( canvas.locator( '.editor-styles-wrapper .wp-block-button' ).first() ).toBeVisible();
	await expect( canvas.locator( '.editor-styles-wrapper .reference-callout__testimonial--preview' ) ).toBeVisible();
} );
```

Every test leaves the site as it found it except the header tagline and the Global Styles background, which are deliberate, idempotent changes the parity suite tolerates (the tagline is masked out of no parity page because it is deterministic once set, and Global Styles background `neutral-100` is a palette colour). If either turns out to shift a parity page beyond its threshold, restore the value at the end of the test rather than raising the threshold.

If a `getByRole` selector proves brittle against the shipped `@wordpress/edit-site` build, replace that one interaction with the equivalent `page.evaluate` against `wp.data.dispatch( 'core' )` — but never delete a test from this list: each one is a §9.6 gate item.

- [ ] **Step 7: Extend the accessibility suite to the demo page**

In `tests/accessibility/smoke.spec.ts`, add a third test that requires a successful response from `/demo/` and runs the same `AxeBuilder({ page }).withTags([ 'wcag2a', 'wcag2aa' ])` scan. A missing page or a non-success response fails this required gate. Do not add a soft skip. Do not relax the existing `toEqual( [] )` assertions: the block templates already provide the `<main>` landmark (`main#site-main`) and WordPress injects the skip link, so `region` and `landmark-one-main` must still pass.

- [ ] **Step 8: Extend the visual suite to the demo page**

In `tests/visual/home.spec.ts`, add two tests mirroring the existing pair for `/demo/`, writing `demo-desktop.png` and `demo-mobile.png`. Update the file docblock's "Nothing is masked yet" paragraph to note that the demo page's only dynamic region is the reference callout's testimonial, which is deterministic in a fresh install (there is no testimonial content, so it renders nothing).

- [ ] **Step 9: Run the browser suites locally, then regenerate the baselines in CI**

```bash
npm run test:e2e
npm run test:accessibility
```

Expected: PASS. `npm run test:visual` will FAIL locally against the committed classic-theme baselines — that is correct and expected; do **not** run `--update-snapshots` on Windows or macOS.

Regenerate the visual baselines through the documented CI flow. The Sol orchestrator performs this second artifact gate:

1. Push the branch.
2. Run the **CI** workflow via **Run workflow** with `update_visual_snapshots = true` and `capture_migration_baselines = false`.
3. Download the `visual-baselines` artifact, unpack it over `tests/visual/__screenshots__/`, review the four PNGs by eye, and commit.

- [ ] **Step 10: Commit**

```bash
ddev composer verify:fast
git add tests/e2e tests/accessibility tests/visual
git commit -m "test: add reconciliation security and visual coverage"
```

---

## Task 10: The two parity gates

**Files:**
- Create: `tests/parity/migration-parity.spec.ts`
- Create: `tests/parity/editing-parity.spec.ts`
- Modify: `tests/parity/helpers/parity.ts`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `MIGRATION_PARITY_PAGES`, `EDITING_PARITY_PAGES`, `PARITY_FONT_CSS`, `MIGRATION_BASELINE_DIR`, `applyParityFonts()`, `captureFrontend()`, `compareToBaseline()` (Task 2); the committed baselines; the demo page (Task 7); `openSiteEditorCanvas()` (Task 8).
- Produces: `tests/parity/helpers/parity.ts` additionally exports
  `export type Box = { x: number; y: number; width: number; height: number }`
  `export async function captureEditorCanvas( page: Page, route: string, maskSelectors: string[] ): Promise<Buffer>`
  `export async function boundingBoxes( scope: Page | FrameLocator, rootSelector: string, selectors: string[] ): Promise<Record<string, Box>>` — normalised to the capture root's origin; **throws** on a missing or unlaid-out selector
  `export async function effectiveCanvasWidth( page: Page ): Promise<number>`
  `export async function computedStyles( scope: Page | FrameLocator, selector: string, properties: string[] ): Promise<Record<string, string>>`
  `export async function captureFrontendRegion( page: Page, path: string, maskSelectors: string[] ): Promise<Buffer>`
  `export function compareBuffers( expectedBuffer: Buffer, actualBuffer: Buffer, maxDiffRatio: number, diffOutPath: string ): { diffRatio: number }`
  `export async function resolvePageId( page: Page, path: string ): Promise<number>`

- [ ] **Step 1: Add the editor-canvas capture and measurement helpers**

Append to `tests/parity/helpers/parity.ts`:

```ts
import type { FrameLocator } from '@playwright/test';

/**
 * Photographs the Site Editor canvas with the editor chrome cropped out: the
 * screenshot is taken of the .editor-styles-wrapper element INSIDE the
 * editor-canvas iframe, which is exactly the region a visitor's viewport shows
 * on the frontend. Comparing full pages here would measure the admin sidebar,
 * not the design.
 */
export async function captureEditorCanvas(
	page: Page,
	route: string,
	maskSelectors: string[]
): Promise< Buffer > {
	await page.goto( `/wp/wp-admin/site-editor.php?p=${ encodeURIComponent( route ) }&canvas=edit` );

	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	const root = canvas.locator( '.editor-styles-wrapper' );
	await root.waitFor( { state: 'visible' } );

	await page.addStyleTag( { content: PARITY_FONT_CSS } );
	await root.evaluate( ( element, css ) => {
		const style = element.ownerDocument.createElement( 'style' );
		style.textContent = css;
		element.ownerDocument.head.appendChild( style );
	}, PARITY_FONT_CSS );

	return root.screenshot( {
		animations: 'disabled',
		caret: 'hide',
		mask: maskSelectors.map( ( selector ) => canvas.locator( selector ) ),
	} );
}

export type Box = { x: number; y: number; width: number; height: number };

/**
 * Bounding boxes NORMALISED to the capture root's own origin.
 *
 * A raw boundingBox() from the frontend is relative to the browser viewport;
 * a raw boundingBox() from inside the editor-canvas iframe is relative to that
 * iframe's document. Comparing the two directly compares two different origins
 * and silently passes or fails on the admin chrome's offset rather than on the
 * design. Subtracting each side's capture root — <body> on the frontend,
 * .editor-styles-wrapper in the canvas — puts both in the same coordinate
 * space, which is the same space the screenshots are taken in.
 *
 * A missing selector THROWS. Returning a zero box would silently turn "the
 * header is not in the editor at all" into "the header matches", which is the
 * exact failure this gate exists to catch.
 */
export async function boundingBoxes(
	scope: Page | FrameLocator,
	rootSelector: string,
	selectors: string[]
): Promise< Record< string, Box > > {
	const root = await scope.locator( rootSelector ).first().boundingBox();

	if ( ! root ) {
		throw new Error( `boundingBoxes: capture root "${ rootSelector }" was not found or is not visible.` );
	}

	const result: Record< string, Box > = {};

	for ( const selector of selectors ) {
		const target = scope.locator( selector ).first();

		if ( ( await target.count() ) === 0 ) {
			throw new Error( `boundingBoxes: "${ selector }" is missing. Editing parity cannot pass when a section is absent from one side.` );
		}

		const box = await target.boundingBox();

		if ( ! box ) {
			throw new Error( `boundingBoxes: "${ selector }" exists but has no layout box (display:none?).` );
		}

		result[ selector ] = {
			x: box.x - root.x,
			y: box.y - root.y,
			width: box.width,
			height: box.height,
		};
	}

	return result;
}

/**
 * The editor canvas is an iframe inside the admin layout, so its effective
 * width is NOT the browser viewport width — on the mobile parity project the
 * canvas can be narrower or wider than 390px, which would make every geometry
 * comparison meaningless. This measures it, and the editing-parity spec asserts
 * it matches the frontend's capture width before comparing anything else.
 */
export async function effectiveCanvasWidth( page: Page ): Promise< number > {
	return page
		.frameLocator( 'iframe[name="editor-canvas"]' )
		.locator( '.editor-styles-wrapper' )
		.first()
		.evaluate( ( element ) => element.getBoundingClientRect().width );
}

export async function computedStyles(
	scope: Page | FrameLocator,
	selector: string,
	properties: string[]
): Promise< Record< string, string > > {
	return scope.locator( selector ).first().evaluate( ( element, props ) => {
		const styles = element.ownerDocument.defaultView!.getComputedStyle( element );
		const out: Record< string, string > = {};
		for ( const prop of props ) {
			out[ prop ] = styles.getPropertyValue( prop );
		}
		return out;
	}, properties );
}
```

- [ ] **Step 2: Write the migration-parity spec**

Create `tests/parity/migration-parity.spec.ts`:

```ts
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import {
	MIGRATION_BASELINE_DIR,
	MIGRATION_PARITY_PAGES,
	captureFrontend,
	compareToBaseline,
} from './helpers/parity';

/**
 * MIGRATION PARITY: the frontend the block theme renders today, against the
 * IMMUTABLE screenshots of the classic frontend captured before the conversion
 * (tests/parity/__migration_baselines__). This proves the conversion did not
 * change the site a visitor sees.
 *
 * It is a DIFFERENT test from editing parity, with different geometry: this one
 * compares full pages across two points in TIME; editing parity compares the
 * editor canvas against the frontend at ONE point in time. Passing one proves
 * nothing about the other.
 */
for ( const parityPage of MIGRATION_PARITY_PAGES ) {
	test( `migration parity: ${ parityPage.name }`, async ( { page }, testInfo ) => {
		const actual = await captureFrontend( page, parityPage.path, parityPage.maskSelectors );
		const baseline = join( MIGRATION_BASELINE_DIR, testInfo.project.name, `${ parityPage.name }.png` );
		const diffOut = testInfo.outputPath( `${ parityPage.name }-migration-diff.png` );

		const { diffRatio } = compareToBaseline( actual, baseline, parityPage.maxDiffRatio, diffOut );

		await testInfo.attach( `${ parityPage.name }-actual`, { body: actual, contentType: 'image/png' } );

		expect(
			diffRatio,
			`${ parityPage.name } differs from its pre-migration baseline by ${ ( diffRatio * 100 ).toFixed( 2 ) }% (limit ${ ( parityPage.maxDiffRatio * 100 ).toFixed( 2 ) }%). Diff written to ${ diffOut }.`
		).toBeLessThanOrEqual( parityPage.maxDiffRatio );
	} );
}
```

- [ ] **Step 3: Write the editing-parity spec**

Create `tests/parity/editing-parity.spec.ts`:

```ts
import { expect, test } from '@playwright/test';
import {
	EDITING_PARITY_PAGES,
	boundingBoxes,
	captureEditorCanvas,
	captureFrontendRegion,
	compareBuffers,
	computedStyles,
	resolvePageId,
} from './helpers/parity';
import { CREDS, loginAs } from '../e2e/helpers/auth';

/**
 * EDITING PARITY: the Site Editor canvas against the frontend, at the same
 * moment, for the same page. This proves what the client edits is what the
 * visitor gets. Editor chrome is cropped out by screenshotting the
 * .editor-styles-wrapper element inside the editor-canvas iframe rather than
 * the admin page.
 *
 * Runs as a CLIENT role on purpose: an administrator sees a different editor
 * (unrestricted blocks, code editor, Additional CSS), so administrator parity
 * would not prove the client's experience.
 */
for ( const parityPage of EDITING_PARITY_PAGES ) {
	test( `editing parity: ${ parityPage.name }`, async ( { page }, testInfo ) => {
		test.setTimeout( 120_000 );

		const MEASURED = [ 'header.site-header', 'footer.site-footer', 'main#site-main' ];
		const PROPERTIES = [
			'font-family',
			'font-size',
			'line-height',
			'color',
			'background-color',
			'padding-top',
			'padding-right',
			'padding-bottom',
			'padding-left',
			'gap',
		];

		const frontend = await captureFrontendRegion( page, parityPage.path, parityPage.maskSelectors );
		const frontendWidth = await page.locator( 'body' ).first().evaluate( ( el ) => el.getBoundingClientRect().width );
		// Both sides are normalised to their own capture root, so the two sets
		// of coordinates live in the same space as the two screenshots.
		const frontendGeometry = await boundingBoxes( page, 'body', MEASURED );
		const frontendType = await computedStyles( page, 'main#site-main', PROPERTIES );
		const frontendHeader = await computedStyles( page, 'header.site-header', PROPERTIES );

		await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

		const postId = await resolvePageId( page, parityPage.path );
		const canvasShot = await captureEditorCanvas( page, `/page/${ postId }`, parityPage.maskSelectors );
		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );

		// Geometry is only comparable when both sides were laid out at the same
		// width. On the mobile project the canvas iframe is NOT the browser
		// viewport, so this is asserted before anything else is compared.
		const canvasWidth = await effectiveCanvasWidth( page );

		expect(
			Math.abs( canvasWidth - frontendWidth ),
			`${ parityPage.name }: the editor canvas is ${ canvasWidth }px wide but the frontend captured at ${ frontendWidth }px. Geometry cannot be compared across different layout widths — collapse the editor sidebars or adjust the project viewport until they agree.`
		).toBeLessThanOrEqual( 1 );

		const canvasGeometry = await boundingBoxes( canvas, '.editor-styles-wrapper', MEASURED );
		const canvasType = await computedStyles( canvas, 'main#site-main', PROPERTIES );
		const canvasHeader = await computedStyles( canvas, 'header.site-header', PROPERTIES );

		await testInfo.attach( `${ parityPage.name }-frontend`, { body: frontend, contentType: 'image/png' } );
		await testInfo.attach( `${ parityPage.name }-canvas`, { body: canvasShot, contentType: 'image/png' } );

		const diffOut = testInfo.outputPath( `${ parityPage.name }-editing-diff.png` );
		const { diffRatio } = compareBuffers( frontend, canvasShot, parityPage.maxDiffRatio, diffOut );

		expect(
			diffRatio,
			`${ parityPage.name }: the Site Editor canvas differs from the frontend by ${ ( diffRatio * 100 ).toFixed( 2 ) }% (limit ${ ( parityPage.maxDiffRatio * 100 ).toFixed( 2 ) }%). Diff written to ${ diffOut }.`
		).toBeLessThanOrEqual( parityPage.maxDiffRatio );

		for ( const selector of Object.keys( frontendGeometry ) ) {
			for ( const edge of [ 'x', 'y', 'width', 'height' ] as const ) {
				expect(
					Math.abs( frontendGeometry[ selector ][ edge ] - canvasGeometry[ selector ][ edge ] ),
					`${ parityPage.name }: ${ selector } ${ edge } differs by more than ${ parityPage.maxEdgeDeltaPx }px`
				).toBeLessThanOrEqual( parityPage.maxEdgeDeltaPx );
			}
		}

		// §9.6: "Computed typography, colours, and key spacing tokens match."
		expect( canvasType ).toEqual( frontendType );
		expect( canvasHeader ).toEqual( frontendHeader );
	} );
}
```

Add the three helpers this spec uses to `tests/parity/helpers/parity.ts`, and refactor `compareToBaseline()` so there is exactly one comparison implementation:

```ts
/**
 * Photographs the frontend the same way captureEditorCanvas() photographs the
 * editor: an ELEMENT screenshot of <body>, not a fullPage capture. The editor
 * canvas can only ever be captured as an element, so a fullPage frontend
 * capture would compare two differently-composed images.
 */
export async function captureFrontendRegion(
	page: Page,
	path: string,
	maskSelectors: string[]
): Promise< Buffer > {
	await page.goto( path, { waitUntil: 'networkidle' } );
	await applyParityFonts( page );
	await page.evaluate( () => document.fonts.ready );

	return page.locator( 'body' ).screenshot( {
		animations: 'disabled',
		caret: 'hide',
		mask: maskSelectors.map( ( selector ) => page.locator( selector ) ),
	} );
}

/**
 * The single pixelmatch implementation. compareToBaseline() reads the expected
 * side from disk and delegates here; editing parity passes both sides as
 * buffers because neither is a stored fixture.
 */
export function compareBuffers(
	expectedBuffer: Buffer,
	actualBuffer: Buffer,
	maxDiffRatio: number,
	diffOutPath: string
): { diffRatio: number } {
	const expectedPng = PNG.sync.read( expectedBuffer );
	const actualPng = PNG.sync.read( actualBuffer );

	const width = Math.min( expectedPng.width, actualPng.width );
	const height = Math.min( expectedPng.height, actualPng.height );
	const diff = new PNG( { width, height } );

	const differing = pixelmatch(
		cropTo( expectedPng, width, height ).data,
		cropTo( actualPng, width, height ).data,
		diff.data,
		width,
		height,
		{ threshold: 0.2 }
	);

	// A size mismatch counts as difference, so a taller or shorter render
	// cannot pass by comparing only the overlapping region.
	const maxArea = Math.max(
		expectedPng.width * expectedPng.height,
		actualPng.width * actualPng.height
	);
	const diffRatio = ( differing + ( maxArea - width * height ) ) / maxArea;

	if ( diffRatio > maxDiffRatio ) {
		mkdirSync( dirname( diffOutPath ), { recursive: true } );
		writeFileSync( diffOutPath, PNG.sync.write( diff ) );
	}

	return { diffRatio };
}

export function compareToBaseline(
	actual: Buffer,
	baselinePath: string,
	maxDiffRatio: number,
	diffOutPath: string
): { diffRatio: number } {
	return compareBuffers( readFileSync( baselinePath ), actual, maxDiffRatio, diffOutPath );
}

/**
 * Resolves a page's post id from its path through the REST API. Throws a named
 * error rather than returning undefined, so a missing seed (scripts/setup step
 * 10 not run) fails loudly instead of as a confusing editor timeout.
 */
export async function resolvePageId( page: Page, path: string ): Promise< number > {
	const slug = path.replace( /^\/|\/$/g, '' ) || 'home';
	const response = await page.request.get( `/wp-json/wp/v2/pages?slug=${ encodeURIComponent( slug ) }` );
	const results = ( await response.json() ) as Array< { id: number } >;

	if ( ! Array.isArray( results ) || results.length === 0 ) {
		throw new Error(
			`resolvePageId: no page found for path "${ path }" (slug "${ slug }"). Run scripts/setup so the Demo page and Sample Page exist.`
		);
	}

	return results[ 0 ].id;
}
```

Delete the old `compareToBaseline()` body written in Task 2 Step 2 and replace it with the delegating version above; `cropTo()` stays exactly as it is.

- [ ] **Step 4: Wire the parity suite into CI**

In `.github/workflows/ci.yml`'s `e2e` job (already pinned to `ubuntu-24.04` and installing `fonts-dejavu-core` from Task 2), add after the visual-regression steps:

```yaml
      - name: Run migration and editing parity suites
        if: >-
          !((github.event_name == 'workflow_dispatch' && inputs.capture_migration_baselines == true)
          || github.ref == 'refs/heads/ci-capture/migration-baselines'
          || github.ref == 'refs/heads/ci-capture/visual-baselines')
        env:
          WP_BASE_URL: https://agency-starter.ddev.site
        run: npm run test:parity
```

- [ ] **Step 5: Run both parity suites and close the gap**

```bash
npm run test:parity
```

Expected: PASS at `maxDiffRatio = 0.05` for all four combinations (two pages × two viewports) in each suite.

If a page fails, close the gap **before** touching the threshold, in this order:

1. Compare the attached `-actual` / `-canvas` PNGs against the baseline diff to find the offending region.
2. Adjust `assets/global/shared.css` or the block markup's `layout` attributes so the block rendering matches the classic geometry (this is the intended fix — the header, the navigation and the posts list are the three regions most likely to differ).
3. If a region is genuinely non-deterministic rather than different, add its selector to that page's `maskSelectors`.
4. If 1–3 cannot close it at `maxDiffRatio = 0.05`, the release gate fails. Do not raise the threshold. Record the failing region and correct the implementation.

- [ ] **Step 6: Commit**

```bash
ddev composer verify:fast
git add tests/parity .github/workflows/ci.yml
git commit -m "test: gate the migration and the editing parity thresholds"
```

---

## Task 11: Make `check-overrides` informational by default

Database template and Global Styles rows are no longer a guardrail breach — they are exactly what a client using the Site Editor produces. `AgencyPlatform\Health\DatabaseOverrideCheck` classifies a `publish`-status `wp_template`/`wp_template_part` row and a customised `wp_global_styles` row as `overrides`, and `AgencyCommands::check_overrides()` turns that into `WP_CLI::error()` (exit 1). That must become a report.

Scope note: this task changes **only the command's exit behaviour and wording**. `DatabaseOverrideCheck`'s detection internals stay exactly as they are — a later engagement task replaces them with the state subsystem's drift reporting, and `tests/Unit/AgencyPlatform/DatabaseOverrideClassifyTest.php`'s thirteen methods must keep passing untouched.

**Files:**
- Modify: `web/app/mu-plugins/agency-platform/src/Cli/AgencyCommands.php`
- Modify: `scripts/check-database-overrides` (header comment only)
- Create: `tests/Unit/AgencyPlatform/CheckOverridesReportTest.php`
- Modify: `docs/validation-scenarios.md` (scenario 8)

**Interfaces:**
- Consumes: `AgencyPlatform\Health\DatabaseOverrideCheck::run()` returning `array{overrides: array<int, array<string, mixed>>, expected: array<int, array<string, mixed>>, synced_patterns: array<int, array<string, mixed>>}` — unchanged.
- Produces:
  - `wp agency check-overrides [--fail-on-drift]` — **exit 0** by default whatever it finds; **exit 1** (never 2) when drift exists **and** `--fail-on-drift` is passed. Exit 1 comes from `\WP_CLI::error()`. The exit code is part of the cross-task contract: the state track replaces this command's detection internals later and keeps this exact exit contract, so no test written here needs editing then. Exit 2 belongs to `state-diff`, not to this alias.
  - `AgencyPlatform\Cli\AgencyCommands::drift_is_failure( array $report, bool $fail_on_drift ): bool`
  - `AgencyPlatform\Cli\AgencyCommands::drift_summary_lines( array $report ): array` returning `list<string>`
  - Both helpers are **pure** and take the report array by value. That is deliberate: the state track later swaps `DatabaseOverrideCheck` for its own differ, and only the one line that builds `$report` has to change — the exit-code policy, the summary format, and `CheckOverridesReportTest` all survive untouched.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/AgencyPlatform/CheckOverridesReportTest.php`:

```php
<?php
/**
 * Proves `wp agency check-overrides` reports drift instead of failing on it.
 * Database overrides are EXPECTED under the block-theme editing model — a
 * client saving a template in the Site Editor writes exactly the rows this
 * command reports — so only the explicit --fail-on-drift flag may produce a
 * non-zero exit.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Cli\AgencyCommands;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Cli\AgencyCommands
 */
final class CheckOverridesReportTest extends TestCase {

	/**
	 * @param int $overrides
	 * @return array{overrides: array<int, array<string, mixed>>, expected: array<int, array<string, mixed>>, synced_patterns: array<int, array<string, mixed>>}
	 */
	private function report( int $overrides ): array {
		$rows = array();

		for ( $i = 0; $i < $overrides; $i++ ) {
			$rows[] = array(
				'post_type'   => 'wp_template',
				'post_name'   => 'page',
				'post_status' => 'publish',
			);
		}

		return array(
			'overrides'       => $rows,
			'expected'        => array(),
			'synced_patterns' => array(),
		);
	}

	public function test_drift_without_the_flag_is_not_a_failure(): void {
		self::assertFalse( AgencyCommands::drift_is_failure( $this->report( 3 ), false ) );
	}

	public function test_drift_with_the_flag_is_a_failure(): void {
		self::assertTrue( AgencyCommands::drift_is_failure( $this->report( 1 ), true ) );
	}

	public function test_no_drift_is_never_a_failure(): void {
		self::assertFalse( AgencyCommands::drift_is_failure( $this->report( 0 ), false ) );
		self::assertFalse( AgencyCommands::drift_is_failure( $this->report( 0 ), true ) );
	}

	public function test_the_summary_lists_every_bucket_and_every_override(): void {
		$lines = AgencyCommands::drift_summary_lines( $this->report( 2 ) );

		self::assertContains( 'Template/template-part overrides: 2', $lines );
		self::assertContains( '  - page (wp_template) [publish]', $lines );
		self::assertContains( 'Expected core-generated global-styles records: 0', $lines );
		self::assertContains( 'Synced patterns (informational only): 0', $lines );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `ddev composer test:unit`
Expected: FAIL with `Error: Call to undefined method AgencyPlatform\Cli\AgencyCommands::drift_is_failure()`.

- [ ] **Step 3: Rewrite `check_overrides()`**

In `web/app/mu-plugins/agency-platform/src/Cli/AgencyCommands.php`, replace the `check_overrides()` method and its docblock:

```php
	/**
	 * Reports database records that differ from the Git-owned theme baseline.
	 *
	 * INFORMATIONAL BY DEFAULT. Under the block-theme editing model, database
	 * template/template-part/Global Styles rows are EXPECTED: they are what a
	 * client using the Site Editor produces, and the promotion workflow is how
	 * they get reconciled back into Git. A deployment must not fail simply
	 * because a client edited their own site.
	 *
	 * Pass --fail-on-drift when you deliberately want a CI or deploy gate to
	 * stop on drift.
	 *
	 * This command is kept as a compatibility alias for the state subsystem's
	 * richer drift reporting.
	 *
	 * ## OPTIONS
	 *
	 * [--fail-on-drift]
	 * : Exit non-zero when any drift is found. Off by default.
	 *
	 * @param array<int, string>   $args       Positional arguments (unused; required by the WP-CLI command signature).
	 * @param array<string, mixed> $assoc_args Associative arguments/flags, e.g. `--fail-on-drift`.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $args is required by the WP-CLI command signature; this command takes no positional arguments.
	public static function check_overrides( array $args, array $assoc_args = array() ): void {
		$report = ( new DatabaseOverrideCheck() )->run();

		foreach ( self::drift_summary_lines( $report ) as $line ) {
			\WP_CLI::log( $line );
		}

		if ( self::drift_is_failure( $report, ! empty( $assoc_args['fail-on-drift'] ) ) ) {
			// WP_CLI::error() halts execution (exit code 1); no explicit
			// `return` after it — PHPStan knows this call never returns.
			\WP_CLI::error(
				sprintf(
					'%d database override(s) found and --fail-on-drift was requested. Reconcile them through the promotion workflow, or re-run without the flag to report only.',
					count( $report['overrides'] )
				)
			);
		}

		if ( array() === $report['overrides'] ) {
			\WP_CLI::success( 'No database overrides found.' );
			return;
		}

		\WP_CLI::success(
			sprintf(
				'%d database override(s) reported. Overrides are expected under the Site Editor editing model; pass --fail-on-drift to make them a hard failure.',
				count( $report['overrides'] )
			)
		);
	}

	/**
	 * @param array{overrides: array<int, array<string, mixed>>, expected: array<int, array<string, mixed>>, synced_patterns: array<int, array<string, mixed>>} $report
	 */
	public static function drift_is_failure( array $report, bool $fail_on_drift ): bool {
		return $fail_on_drift && array() !== $report['overrides'];
	}

	/**
	 * @param array{overrides: array<int, array<string, mixed>>, expected: array<int, array<string, mixed>>, synced_patterns: array<int, array<string, mixed>>} $report
	 * @return list<string>
	 */
	public static function drift_summary_lines( array $report ): array {
		$lines = array( sprintf( 'Template/template-part overrides: %d', count( $report['overrides'] ) ) );

		foreach ( $report['overrides'] as $record ) {
			$lines[] = sprintf(
				'  - %s (%s) [%s]',
				(string) ( $record['post_name'] ?? '' ),
				(string) ( $record['post_type'] ?? '' ),
				(string) ( $record['post_status'] ?? '' )
			);
		}

		$lines[] = sprintf( 'Expected core-generated global-styles records: %d', count( $report['expected'] ) );
		$lines[] = sprintf( 'Synced patterns (informational only): %d', count( $report['synced_patterns'] ) );

		return $lines;
	}
```

- [ ] **Step 4: Update the wrapper script's header comment**

In `scripts/check-database-overrides`, change the sentence "Exits non-zero when overrides are found." to "Informational by default — exits 0 whatever it finds. Pass --fail-on-drift to make drift a hard failure; every argument is forwarded to `wp agency check-overrides`." No code change is needed: the script already does `exec wp agency check-overrides "$@"`.

- [ ] **Step 5: Rewrite validation scenario 8**

In `docs/validation-scenarios.md`, `## 8. Unexpected database template record` becomes `## 8. Database template record drift`. Keep the same mutation:

```sh
ddev wp post create --post_type=wp_template --post_status=publish \
  --post_title="Custom Front Page" --post_name=front-page --porcelain
```

Replace the check and the expected block with:

```sh
ddev wp agency check-overrides
```

expected output ending in

```
Template/template-part overrides: 1
  - front-page (wp_template) [publish]
Expected core-generated global-styles records: <N>
Synced patterns (informational only): <N>
Success: 1 database override(s) reported. Overrides are expected under the Site Editor editing model; pass --fail-on-drift to make them a hard failure.
```

with the note "Exits **zero**. A database template row is a legitimate client edit under the block-theme editing model, not a guardrail breach." Then add the gate half:

```sh
ddev wp agency check-overrides --fail-on-drift
```

expected: the same report, followed by `Error: 1 database override(s) found and --fail-on-drift was requested. …`, exiting non-zero.

- [ ] **Step 6: Prove the exit codes against a real install**

The exit contract is what the state track builds on, so verify it for real rather than only in the unit test:

```bash
ddev wp post create --post_type=wp_template --post_status=publish \
  --post_title="Custom Front Page" --post_name=front-page --porcelain
ddev wp agency check-overrides; echo "exit=$?"
ddev wp agency check-overrides --fail-on-drift; echo "exit=$?"
ddev wp post delete "$(ddev wp post list --post_type=wp_template --name=front-page --field=ID)" --force
ddev wp agency check-overrides; echo "exit=$?"
```

Expected, in order: `exit=0` (drift present, reported, not a failure), `exit=1` (drift present, flag passed), `exit=0` (no drift). Any other code — in particular `2` — is wrong and must be fixed here, not downstream.

- [ ] **Step 7: Run and commit**

Run: `ddev composer verify`
Expected: PASS.

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/Cli/AgencyCommands.php scripts/check-database-overrides tests/Unit/AgencyPlatform/CheckOverridesReportTest.php docs/validation-scenarios.md
git commit -m "feat: make check-overrides informational unless --fail-on-drift"
```

---

## Task 12: Documentation

Every claim below is currently false in the repository and must be corrected. Exact locations were verified against the working tree; line numbers may have shifted, so match on the quoted text.

**Files:**
- Modify: `AGENTS.md`
- Modify: `docs/architecture.md`
- Modify: `docs/editing-strictness.md`
- Modify: `docs/ownership-rules.md`
- Modify: `docs/adding-a-block.md`
- Modify: `docs/validation-scenarios.md`

**Interfaces:**
- Consumes: everything Tasks 3–10 built.
- Produces: no code interface. `docs/state-reconciliation.md` is **not** created here — it belongs to a later engagement task.

### Stale `check-overrides` claims routed to this task

The state track enumerated every surviving false claim about `check-overrides` and routed the ones in Task-1-owned files here. Fix all four; leave the three `ops/backup.md` / `ops/restore.md` / `ops/update-process.md` rows to the commerce-and-hardening track.

| File | Line at planning time | Stale claim | Replace with |
|---|---|---|---|
| `docs/architecture.md` | 82–84 | "Database-resident *structural* overrides — a `wp_template`/`wp_template_part` row, or a `wp_global_styles` row carrying real custom CSS/customizations — are forbidden and detected by `wp agency check-overrides`." | Overrides are **expected** and promotable; see Step 3 item 3 below. |
| `docs/architecture.md` | 97–100 | "`wp agency check-overrides` (`scripts/check-database-overrides`) detects when a published `wp_template`/`wp_template_part` row or a customized `wp_global_styles` row exists in the database, **shadowing** the Git-owned files above." | "`wp agency check-overrides` (`scripts/check-database-overrides`) **reports drift** between the database and the Git baseline. It exits zero by default — a database template, part or Global Styles row is a legitimate client edit under this editing model — and exits 1 only with `--fail-on-drift`. Richer, machine-readable reporting arrives with `wp agency state-export` / `wp agency state-diff`; this command remains as a compatibility alias." |
| `docs/editing-strictness.md` | 22–24 | "`wp agency check-overrides` fails if a `wp_template`/`wp_template_part` or customized `wp_global_styles` row appears in the database." | The whole bullet goes: templates, parts and Global Styles are now client-editable, so the sentence is false in both halves. Step 4 item 1 replaces the surrounding block. |
| `docs/validation-scenarios.md` | 236–245 | The scenario-8 transcript still shows `Error: Database overrides found — Git owns templates/template-parts.` | Already rewritten in Task 11 Step 5. Re-read the section here and confirm no stray reference to the old error string survives elsewhere in the file. |

Do not add forward references to `wp agency state-export` / `state-diff` anywhere except the single `docs/architecture.md` sentence above: those commands do not exist yet on this branch, and the runbook that documents them is owned elsewhere.

- [ ] **Step 1: AGENTS.md**

1. Line 10: "a hybrid block/classic theme" → "a native block theme (full Site Editor)".
2. Routing table: replace the first three rows with

```
Customer-editable UI → block (`site-theme/blocks/`)
Site chrome (header, footer) → template part (`site-theme/parts/*.html`)
Page shell → block template (`site-theme/templates/*.html`)
```

3. Layer ownership, the `site-theme` bullet: replace "hybrid theme — root delegates hand off to `templates/`; `header.php`/`footer.php` render `parts/` via `SiteTheme\Support\Parts`" with "native block theme — `templates/*.html` are the only rendering path; `parts/*.html` hold the chrome and are declared in `theme.json.templateParts`".
4. Layer ownership, the `agency-platform` bullet: replace "editor/site-editor lockdown" with "editor block policy, server-side save validation, admin-screen boundary".
5. Hard rules: replace the root-delegate rule with "`functions.php` stays ≤50 lines and only calls `ThemeBootstrap::boot()`; the theme contains no root-level PHP templates and no `templates/*.php` (`ThemeBootstrapTest`, `BlockThemeStructureTest`)."
6. Hard rules: "`assets/global/` holds exactly `base.css` + `typography.css`" → "`assets/global/` holds exactly `frontend-reset.css` + `shared.css` + `editor.css`; `frontend-reset.css` is never loaded into the editor".
7. Hard rules: add "Git-owned templates and parts carry no hard-coded `ref` and no inline `style` attribute — colours and spacing live in `theme.json` and `assets/global/shared.css` (`BlockThemeStructureTest`)."
8. Frontend behavior order: replace the `parts/site-header/site-header.js` reference with "see `blocks/reference-callout/block.json` for the declared-per-block pattern; native `core/navigation` already provides the responsive overlay, so no theme-level JS is needed for it."
9. Editing model, in full:

> Customers hold `client_editor` or `client_shop_manager`. Both have **full visual Site Editor control within the approved block system**: templates, template parts, navigation, Global Styles, and page composition are all editable. They cannot switch or install themes, install or activate plugins, edit theme/plugin files, use the code editor, insert `core/html`, `core/shortcode` or `core/freeform`, or edit Additional CSS — `edit_css` and `customize` are mapped to `do_not_allow`, and `AgencyPlatform\Security\AdminScreenPolicy` refuses `themes.php`, `theme-editor.php`, `plugin-editor.php`, `customize.php`, `widgets.php` and `nav-menus.php` outright. The block set is derived from the REGISTERED blocks filtered to approved namespaces (`core/`, `agency/`, `woocommerce/`), extensible through `agency_platform_allowed_block_namespaces`, `agency_platform_allowed_blocks` and `agency_platform_disallowed_blocks`. That policy is re-applied server side on save (`rest_pre_insert_*`), which also rejects content-level shortcodes and per-block custom CSS. How strict editing is per project is a dial — see `docs/editing-strictness.md`, recorded at launch per `ops/launch-checklist.md`.

10. Commands: add `npm run test:parity` (migration + editing parity, needs a running site) and note that `npm run capture:migration-baseline` is a one-off pre-migration capture.
11. Where docs live: add `docs/block-theme-migration-baseline.md` (the Phase 0 record).

- [ ] **Step 2: Unit 4B README handoff**

1. Line 3: "a hybrid block/classic theme" → "a native block theme with full Site Editor access for clients".
2. The "Does it come with a theme?" answer: keep the framing, but say the layouts are block templates (`templates/*.html`) the client can edit visually.
3. The playbook's step 2: "Header/footer → `parts/`" → "Header/footer → `parts/site-header.html` and `parts/site-footer.html`; shared visual rules → `assets/global/shared.css`."
4. The trade-off paragraph: replace "customers edit text, images, and products and compose pages from an approved set of blocks while structure, styles, and templates stay locked" with "customers edit the whole site visually — templates, header, footer, navigation, colours and typography — from an approved set of blocks, while the code editor, raw HTML, shortcodes and Additional CSS stay closed and every change is testable against Git".
5. Project layout: "Hybrid theme: templates, parts, blocks, patterns, tokens" → "Block theme: HTML templates and parts, blocks, patterns, tokens".
6. Recount and update every test-count figure ("the 22 architecture tests", "Architecture (22 tests)", "Unit (121 tests)", "Integration (27 tests)") from the actual `ddev composer verify` output.

Do not edit `README.md` in Unit 1. The six notes above are a handoff list for Unit 4B. Unit 4B owns the file, the state runbook, proof records, and the final documentation and operations sweep.

- [ ] **Step 3: docs/architecture.md**

1. `## Two-category UI model` becomes `## Three-category UI model`. Rewrite the opening sentence and the two bullets:
   - **Blocks** (`site-theme/blocks/`) — insertable, editable UI registered via `block.json`. Which blocks a client may insert is decided by `AgencyPlatform\Editor\BlockPolicy` from the registered block types, not a hand-maintained list.
   - **Template parts** (`site-theme/parts/*.html`) — site chrome (header, footer) as block markup, declared in `theme.json.templateParts` and **editable by clients in the Site Editor**. Adding a part means adding `parts/<slug>.html` and a `templateParts` entry, not editing a PHP manifest.
   - **Templates** (`site-theme/templates/*.html`) — the per-request page shell, also editable in the Site Editor.
   - **Patterns** (`site-theme/patterns/*.php`) — sanctioned starting compositions, unlocked by default.
2. `## Source of truth` — replace "Git owns the shape, the database owns the content." with the §5.3 model verbatim:

> Git owns code and the promoted baseline. The database can contain intentional live overrides. Runtime truth is the active Git baseline plus current database state. Agents must export and inspect current state before changing or promoting structure and styles.

3. Delete the sentence declaring database structural overrides "forbidden". Replace it with: "Database structural overrides are **expected**: a client editing a template, a part or Global Styles writes a `wp_template`, `wp_template_part` or `wp_global_styles` row. `wp agency check-overrides` reports them and exits zero; `--fail-on-drift` is the opt-in gate."
4. Ownership table rows:
   - `Templates (templates/*.html)` | Git baseline + DB overrides | `BlockThemeStructureTest` + `BlockTemplateIntegrityTest`; promotable through the state workflow
   - `Template parts (parts/*.html)` | Git baseline + DB overrides | declared in `theme.json.templateParts`; editable in the Site Editor
   - `Design tokens (theme.json)` | Git baseline + DB user origin | a `wp_global_styles` row is a client customisation, reported not rejected
   - `Navigation` | Database | `core/navigation` resolves it at render time; Git-owned markup carries no `ref`
5. Add a short `## Editing surfaces and their guardrails` section naming `BlockPolicy`, `SaveValidation`, `CapabilityPolicy` and `AdminScreenPolicy` and what each one owns.

- [ ] **Step 4: docs/editing-strictness.md**

1. `## The default editing model` — replace all three bullets:
   - Customers compose pages **and edit templates, template parts, navigation and Global Styles** in the Site Editor.
   - The insertable block set is derived from the registered blocks filtered to `core/`, `agency/` and `woocommerce/`; `core/html`, `core/shortcode` and `core/freeform` are permanently denied. `canLockBlocks = true`, `codeEditingEnabled = false`.
   - The same policy is enforced **server side on save** through `rest_pre_insert_*`, which also rejects registered shortcode tags anywhere in the content and per-block custom CSS. Saves are rejected with a REST error, never silently stripped.
2. `## Dial 1` — rename to "trim the block set". Replace the "edit `EditorRestrictions::ALLOWED_BLOCKS`" instruction with the three filters, and show a concrete example:

```php
add_filter(
	'agency_platform_disallowed_blocks',
	array( SiteCore\Editor\BlockDials::class, 'deny_layout_blocks' )
);
```

with a note that the callback must be a named method (`HookOwnershipTest` forbids closures in production code) and that `BlockPolicy::ALWAYS_DENIED` cannot be re-opened by any filter.
3. Add `## Dial 4 — tighten the admin-screen boundary`, pointing at `AdminScreenPolicy::DENIED_SCREENS` and noting that `site-editor.php` and `font-library.php` are the only theme-side screens deliberately left open.
4. `## Why the default is looser` — the sentence "spec §14 defers server-side block-tree validation until real customer behavior proves the native editor restrictions insufficient" is now false for the block POLICY (it is enforced) and still true for block-TREE STRUCTURE validation (which blocks may nest inside which). Rewrite it to say exactly that.

- [ ] **Step 5: docs/ownership-rules.md**

1. `## Modify the header or footer (site chrome)`:
   - **Owns it**: `site-theme/parts/site-header.html` or `parts/site-footer.html`, plus `assets/global/shared.css` for the visual rules.
   - **May change**: the block markup and the `.site-header` / `.site-footer` rules in `shared.css`.
   - **Must not change**: nothing at the theme root — a block theme has no `header.php`/`footer.php`. Do not add an inline `style` attribute to the Git-owned markup.
   - **Checks**: `ddev composer test:architecture` (`BlockThemeStructureTest`, `GlobalAssetRulesTest`), `ddev composer test:integration` (`BlockTemplateIntegrityTest`), `npm run lint:css`, `npm run test:parity`.
2. `## Build a landing page`: the shell is a block template (`site-theme/templates/*.html`); the composition is a pattern. Delete the root-delegate "must not change" line.
3. `## Change mobile navigation behavior`: `core/navigation`'s `overlayMenu` attribute in `parts/site-header.html` owns it; there is no theme-level navigation JS any more. If genuinely custom interaction is needed, it is a block with `viewScript` or the Interactivity API.
4. `## Tighten how much customers can edit`: the three `agency_platform_*` filters, `AdminScreenPolicy::DENIED_SCREENS`, and `RolesProvider`.

- [ ] **Step 6: docs/adding-a-block.md**

1. `## 6. Allow-list and index` becomes `## 6. Block policy and index`. Replace the allow-list bullet: a block in the `agency/` namespace is insertable by clients automatically, because the policy is derived from the registered blocks; use `agency_platform_disallowed_blocks` to withhold one.
2. Add a step between the current 3 and 4: **Give a dynamic block a real editor preview.** A dynamic block must intentionally implement a useful preview — server-rendered and interactive frontend behaviour is not automatically reproduced in the editor. Point at `blocks/reference-callout/index.js`'s representative testimonial and its `--preview` style, and state that `ServerSideRender` is a last resort.
3. Note that `docs/generated-block-index.md`'s Templates column now scans `templates/*.html` as well as `patterns/*.php`.

- [ ] **Step 7: docs/validation-scenarios.md**

1. Change "Twelve deliberate mutations" to "Fifteen deliberate mutations" and extend the classification paragraph.
2. Scenario 8 is already rewritten in Task 11.
3. Scenario 11 ("Modified visual snapshot") — its premise (repainting `theme.json`'s `base` palette colour changes the page background) still holds; update any wording that describes the classic templates.
4. Append three new scenarios, following the existing format (Mutation / Check / Expected failure / Revert):
   - **13. Forbidden block saved through REST.** Mutation: `ddev wp eval` a REST POST as the `client-editor` user putting `<!-- wp:html -->` into a page. Check: the REST response. Expected: HTTP 403 with code `agency_platform_forbidden_block` naming `core/html`, and the page content unchanged. Guardrail: `AgencyPlatform\Editor\SaveValidation`.
   - **14. Classic PHP template reintroduced.** Mutation: `New-Item -ItemType File -Path web/app/themes/site-theme/index.php`. Check: `ddev composer test:architecture`. Expected: `ThemeBootstrapTest::test_no_classic_root_template_files_remain` and `DirectoryRulesTest::test_theme_top_level_files_are_on_the_whitelist` both fail.
   - **15. Hard-coded navigation ref in a Git-owned part.** Mutation: change `parts/site-header.html`'s navigation block to `<!-- wp:navigation {"ref":42} /-->`. Check: `ddev composer test:architecture`. Expected: `BlockThemeStructureTest::test_no_hardcoded_database_refs_in_git_owned_markup` fails naming the file.

- [ ] **Step 8: Unit 4B operations handoff**

1. `## Editing model` — rewrite the strictness item's parenthetical from "(trim the allow-list, `templateLock` a post type, or drop page capabilities)" to "(trim the block set through `agency_platform_disallowed_blocks`, `templateLock` a post type, drop page capabilities, or tighten `AdminScreenPolicy::DENIED_SCREENS`)".
2. Add to `## Editing model`:

```markdown
- [ ] **Site Editor scope reviewed with the client.** Client roles can edit templates, template parts, navigation and Global Styles. Confirm that is what this client should have, and that they understand a template edit changes every page using that template. See `docs/editing-strictness.md`.
- [ ] **Drift reporting decided.** `wp agency check-overrides` is informational by default; a database template, part or Global Styles row is a legitimate client edit, not a breach. Decide whether this project's deploy pipeline runs it with `--fail-on-drift`, and record the choice.
```

3. Add to `## Data safety`:

```markdown
- [ ] **Client design edits have a recovery path.** Client Site Editor edits live in the database, not in Git. Confirm the backup schedule covers the database (see `ops/backup.md`) and that the team knows a template can be reset from the Site Editor's own "Clear customizations" action.
```

Do not edit `ops/launch-checklist.md` in Unit 1. The notes above transfer intact to Unit 4B, which owns the operations sweep and its final proof records.

- [ ] **Step 9: Verify and commit**

```bash
ddev composer verify:fast
```

Expected: PASS. Then re-read `AGENTS.md` and confirm no sentence still describes the theme as hybrid/classic, references `Parts`, or claims templates and styles are locked.

```bash
git add AGENTS.md docs/architecture.md docs/editing-strictness.md docs/ownership-rules.md docs/adding-a-block.md docs/validation-scenarios.md
git commit -m "docs: document the block theme and the new editing model"
```

---

## Definition of done

Mapped to `BLOCK_THEME_PROPOSAL.md` §13, **Release 1 — Block theme & client editing**, minus the `check-overrides` detection internals a later engagement task rebuilds.

- [ ] **The §8.3 Phase 0 gate passed on its own commit.** `spike: prove native block theme and editor styles` exists in the history, `BlockThemeSpikeTest` passed on it, and the manual checks in Task 5 Step 8 were recorded — including the coexistence proof that a `single` request still rendered classically at that commit.
- [ ] **Every commit passed its declared gates.** No commit in the branch was made with a gate the plan already knew was red; the three declared narrow-gate commits (Task 5's spike and Task 6 conversion commits 2 and 3) are the only exceptions, and their window closes when Task 9 commits the rewritten browser, accessibility, and visual suites.
- [ ] **The final theme is recognised as a block theme.** `BlockTemplateIntegrityTest::test_wordpress_recognises_the_theme_as_a_block_theme` passes; `templates/index.html` exists.
- [ ] **No classic rendering fallback remains.** `ThemeBootstrapTest::test_no_classic_root_template_files_remain` and `test_the_theme_has_no_second_rendering_path` pass; `src/Support/Parts.php`, all root delegates, `header.php`, `footer.php`, `templates/*.php` and the nested part directories are deleted; `DirectoryRulesTest` allows only `style.css`, `functions.php`, `theme.json`, `screenshot.png` and `README.md` at the theme root.
- [ ] **All existing request types render.** `templates/{404,archive,index,page,search,single}.html` exist, parse into registered blocks only, resolve through `get_block_templates()`, and render without a fatal (`BlockTemplateIntegrityTest`). `tests/e2e/smoke.spec.ts` passes on both viewports.
- [ ] **Client roles can use the Site Editor.** `tests/e2e/site-editor.spec.ts` proves open, edit-and-save a template, edit-and-save the header part, open the navigation editor, and open Global Styles, all as `client_editor`. `ClientSiteEditorAccessTest` proves the same through the REST layer.
- [ ] **The §9.6 client task list completes with no admin help.** `tests/e2e/client-workflow.spec.ts` proves, as `client_editor`: edit header text, edit navigation, add a core block, reorder it, remove it, change a Global Styles colour, reach the typography controls, save a template, undo a change, and see every static section of the demo page plus the dynamic block's representative preview in the editor canvas.
- [ ] **Forbidden admin capabilities remain unavailable.** `AdminScreenPolicyTest`, `CapabilityPolicyTest`, `ClientEditorCapabilitiesTest::test_client_editor_capability_matrix`, `ClientSiteEditorAccessTest::test_client_editor_cannot_edit_themes_or_plugins_or_files`, and `tests/e2e/forbidden-admin-screens.spec.ts` all pass. The capability matrix is exactly `edit_theme_options = true`, `edit_css = false`, `unfiltered_html = false`, `customize = false`.
- [ ] **Server-side save validation enforces the full resolved block policy.** `BlockPolicyTest` and `tests/Integration/Editor/SaveValidationTest.php` prove recursive rejection of every disallowed block, `core/freeform`, non-whitespace null-name raw HTML, registered shortcode tags anywhere in the content, and per-block custom CSS — with a REST error, and with the original content left intact. The policy is resolved from the **real** editor context (`SaveValidation::editor_context()`), proven by the post-editor-versus-Site-Editor filter test, and an incoming `false` restriction is preserved rather than widened.
- [ ] **All four custom-CSS surfaces are refused for client roles.** `tests/Integration/Editor/CustomCssSurfacesTest.php` covers block-instance `style.css`, Global Styles root CSS, Global Styles block-type CSS, and the Customizer `custom_css` post type, each asserting the rejection AND that the stored data is unchanged. `GlobalStylesGuard` is the enforcement point for the Global Styles surfaces, because `WP_REST_Global_Styles_Controller` applies no `rest_pre_insert_*` filter.
- [ ] **The meta-capability policy does not recurse.** `CapabilityPolicyTest` plus `ClientEditorCapabilitiesTest::test_the_meta_cap_policy_does_not_recurse` (and its administrator counterpart) complete without a stack overflow, proving the early return lands before `user_can()`.
- [ ] **Editor-safe CSS is loaded correctly.** `assets/global/` holds exactly `frontend-reset.css`, `shared.css` and `editor.css`; `GlobalAssetRulesTest::test_frontend_reset_css_is_never_registered_as_an_editor_style` and `test_the_shared_stylesheet_is_loaded_on_both_sides` pass.
- [ ] **Migration parity AND editing parity each pass their thresholds.** `npm run test:parity` is green for both suites across `parity-desktop` (1440×900) and `parity-mobile` (390×844); every page is at or below the fixed 5% maximum; header and footer bounding geometry is within 8 px per edge; computed typography, colour and background match between canvas and frontend. `MigrationBaselineGuardTest` proves the baselines cannot be regenerated by `--update-snapshots` and no page can raise the 5% limit.
- [ ] **Dynamic blocks have representative previews.** `agency/reference-callout` renders a representative testimonial in the editor when the toggle is on, visually marked as a preview, with the frontend-only behaviour documented in its README and in `docs/adding-a-block.md`.
- [ ] **Expected database overrides no longer make normal checks fail.** `wp agency check-overrides` exits 0 with overrides present; `--fail-on-drift` exits 1. `CheckOverridesReportTest` passes; `docs/validation-scenarios.md` scenario 8 documents both halves.
- [ ] **All suites pass.** `ddev composer verify:fast`, `ddev composer verify`, `npm ci`, `npm run lint`, `npm run build`, `npm run test:e2e`, `npm run test:visual`, `npm run test:accessibility`, `npm run test:parity`, and — with the commerce profile enabled — `ddev composer test:integration:commerce` and `COMMERCE=1 npm run test:e2e:commerce`.
- [ ] **Documentation describes the new editing model.** `AGENTS.md`, `README.md`, `docs/architecture.md`, `docs/editing-strictness.md`, `docs/ownership-rules.md`, `docs/adding-a-block.md`, `docs/validation-scenarios.md` and `ops/launch-checklist.md` contain no surviving claim that the theme is hybrid/classic, that templates and styles are locked to clients, that `SiteTheme\Support\Parts` exists, or that a database template row is forbidden.
- [ ] **Nothing untracked or unsafe is committed.** `git status` is clean; no state bundle, backup payload, secret, or customer data is in the tree; `docs/generated-block-index.md` matches `php scripts/generate-block-index`.

### Out of scope for this plan (owned elsewhere)

Do not implement, and do not create files for: `src/State/**`, `Cli/StateCommands.php`, `Cli/PromotionCommands.php`, `resources/schemas/*.json`, `scripts/promote-overrides`, `var/agency-state/`, `docs/state-reconciliation.md`, WooCommerce block templates, `scripts/enable-commerce`, or any change to `DatabaseOverrideCheck`'s detection internals.
