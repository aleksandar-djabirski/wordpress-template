# Block Theme Task 4 — Commerce Profile & Final Hardening Implementation Plan
# Removed legacy cleanup output.
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
**Goal:** Bring the optional WooCommerce profile onto the new block theme (block templates, native Cart/Checkout blocks, Mini-Cart, commerce tests), then close the whole block-theme/FSE engagement with the state-reconciliation runbook, the full documentation sweep, the fresh-clone proof, the full promotion proof, and the required final report.
**Architecture:** Two hard-separated branches. **Phase A** starts after Release 4 has merged in the serialized execution order: commerce block templates are derived from the templates WooCommerce itself ships for block themes. The only allowed transformations are rewriting the `header`/`footer` template-part slugs to this theme's `site-header`/`site-footer` and removing a template-part block's environment-specific `theme` attribute. All WooCommerce PHP stays inside `site-commerce`; all WooCommerce block markup stays inside the declared commerce `templates/*.html` files and `site-commerce`-registered patterns; the base theme, its parts, its patterns and `theme.json` stay commerce-free. **Phase B** starts from a new branch after Phase A and Releases 2–4 are merged: it writes `docs/state-reconciliation.md`, sweeps every doc and ops contract, executes and records the §12 fresh-clone proof and the 14-step isolated-adapter promotion proof, and produces the §17 report.

**Tech Stack:** WordPress block themes (`templates/*.html`, `parts/*.html`, `theme.json` v3), WooCommerce (Composer/wpackagist, ephemeral in this repo), PHP 8.3, PHPUnit 9.6 (`architecture` + `commerce-integration` suites), Playwright (`COMMERCE=1` projects `chromium-desktop` / `chromium-mobile`), WP-CLI, DDEV, bash.

---

## Global Constraints

Copied verbatim from `BLOCK_THEME_PROPOSAL.md` §4 and `AGENTS.md`. Every task below inherits these.

**From spec §4 (non-negotiable repository rules):**

- `functions.php` remains at most 50 lines, and registers no hooks directly.
- Production hooks use named methods, not closures.
- No forbidden catch-all directories (`components/`, `layouts/`, `inc/`, `includes/`, `helpers/`, `misc/`, `common/`, `lib/`, `utils/`).
- Every custom block has valid `block.json`.
- Theme dependencies remain limited to `SiteCore\Contracts\*`.
- `agency-platform` has no project-layer dependencies.
- Outbound HTTP remains limited to integration layers (`site-integrations/`, `site-commerce/src/Integrations/`).
- CSS colours use WordPress design-token variables (`var(--wp--preset--color--*)` / `var(--wp--custom--*)`), never raw hex/rgb.
- **WooCommerce behaviour remains in `site-commerce`.**
- Generated block index stays current (`php scripts/generate-block-index`, committed).
- `verify:fast`, `verify`, frontend lint/build, and relevant Playwright suites remain commit gates.
- The final theme has only one rendering path (no classic fallback).

**From spec §3.3 (out of scope — never do these):**

- No Elementor/Visual Composer/ACF layout system/page builder; no Permissive/Hybrid/Controlled mode switch; no parallel classic template path.
- No business logic in the theme or `agency-platform`; no outbound HTTP in `agency-platform`.
- Never commit production state exports, promotion backup payloads, user content, uploaded font binaries, or secrets.

**From `AGENTS.md` (hard rules this task's files touch):**

- WooCommerce symbols (`WooCommerce`, `WC_*`, `wc_*`, `woocommerce_*`) may only appear in `site-commerce/`, `tests/commerce/`, or a reviewed entry in `tests/Architecture/woocommerce-allowlist.php` (`WooCommerceIsolationTest`). **Note:** `tests/Architecture/` and `tests/commerce/` are excluded from that scan by definition, and the scanner strips PHP comments before matching, so prose inside a docblock never trips it — but a *string literal* containing `WooCommerce`, `WC_x`, `wc_x`, or `woocommerce_x` in any other scanned file does.
- `assets/global/` holds a fixed allow-listed file set (`GlobalAssetRulesTest`). **This task adds no global CSS at all.**
- `docs/generated-block-index.md` must match `php scripts/generate-block-index`'s output (`GeneratedIndexFreshnessTest`).
- Commerce is optional: WooCommerce is NEVER added to base `verify` / `test:integration`. Commerce gates are `bash scripts/enable-commerce`, `ddev composer test:integration:commerce`, `COMMERCE=1 npm run test:e2e:commerce`.
- Run PHP/Composer commands via `ddev composer <script>`; npm runs natively. When changing npm dependencies, regenerate the lock with `npx -y npm@10 install`.
- `ddev composer verify:fast` immediately before every commit, after the exact
  `git add -- <paths>` and `git diff --cached --check` gate for that commit.
  This includes proof commits and every `git revert` that creates a commit.
- Before each commit, compare `git diff --cached --name-only` with that task's
  stated file list. A directory argument is not evidence of exact staging.
  Stop if an extra file or a missing stated file appears.
- Run POSIX shell checks through `ddev exec bash -c '<command>'` when the host
  shell is PowerShell. Use PowerShell only for host-side Git and disposable
  directory checks. Do not rely on `grep`, `test`, `sed`, `mktemp`, or `rm` as
  host commands on Windows.
- Response prose (commit messages excluded) is ASD-STE100 Simplified Technical English — this applies to the agent's replies, not to file contents.

**Single-owner file rule (spec §15) — files this task must NOT edit:**

- Base theme templates/parts/assets, `theme.json`, `functions.php`, `src/Bootstrap/ThemeBootstrap.php` (Task 1).
- `web/app/mu-plugins/agency-platform/**` including `src/State/**`, `Plugin.php`, `src/Cli/*` (Tasks 1/2/3).
- `scripts/promote-overrides`, `resources/schemas/*` (Tasks 2/3).
- Shared architecture **test classes** under `tests/Architecture/` (`DirectoryRulesTest`, `ThemeBootstrapTest`, `GlobalAssetRulesTest`, `WooCommerceIsolationTest`, `BlockManifestTest`, `HookOwnershipTest`, `IntegrationBoundaryTest`, `GeneratedIndexFreshnessTest`). This task adds **new** test files and **new** data files beside them instead.

---

## File map

**Created (Phase A):**

| Path | Responsibility |
|---|---|
| `web/app/themes/site-theme/templates/single-product.html` … `order-confirmation.html` (up to 9 files) | Commerce request templates; WooCommerce's own block templates with only the header/footer part slugs rewritten and environment-specific template-part `theme` attributes removed |
| `tests/Architecture/commerce-template-list.php` | Data file: canonical commerce template slugs + deliberately-excluded upstream slugs. Mirrors the `woocommerce-allowlist.php` data-file convention |
| `tests/Architecture/CommerceBoundaryTest.php` | Base-suite (no WordPress) boundary rules for commerce block markup: which `.html` files may contain commerce blocks, and that the classic override directory is gone |
| `tests/commerce/Integration/Theme/CommerceBlockTemplatesTest.php` | Commerce-profile proof: every commerce template parses, resolves its parts, uses only registered blocks, and wins over WooCommerce's shipped template |
| `tests/commerce/Integration/Theme/CommercePatternsTest.php` | Commerce-profile proof: `site-commerce`'s patterns and pattern category register |
| `web/app/plugins/site-commerce/src/Theme/CommercePatterns.php` | Registers the commerce pattern category and the two commerce patterns (header Mini-Cart, product grid) |
| `web/app/plugins/site-commerce/patterns/header-mini-cart.html`, `product-grid.html` | Generated pattern bodies; keep commerce block names out of PHP and make the composition reviewable as markup |
| `tests/commerce/e2e/helpers/checkout.ts` | Shared block Cart/Checkout Playwright helpers, addressed by accessible role and label |
| `tests/commerce/e2e/locators.spec.ts` | Permanent contract check: every helper locator resolves on the live store |

**Modified (Phase A):**

| Path | Change |
|---|---|
| `scripts/enable-commerce` | Seed native Cart/Checkout **blocks** (drop the classic shortcode swap); verify the block content; seed the header Mini-Cart template-part override |
| `web/app/plugins/site-commerce/src/Plugin.php` | Add `CommercePatterns` to the `boot()` provider list |
| `tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php` | New capability matrix (`edit_theme_options` now true; `edit_css` / `unfiltered_html` false) |
| `tests/commerce/Integration/SiteCommerce/PluginBootTest.php` | Assert the new provider is wired |
| `tests/commerce/e2e/commerce-journey.spec.ts` | Block Cart/Checkout journeys + header Mini-Cart |
| `tests/commerce/e2e/shop-manager-admin.spec.ts` | New admin-menu truth + Shop Manager Site Editor access (§11.14) |
| `tests/commerce/README.md`, `docs/adding-commerce-behaviour.md`, `AGENTS.md` (routing line) | Commerce documentation |
| `docs/generated-block-index.md` | Regenerated |

**Deleted (Phase A):** `web/app/themes/site-theme/woocommerce/README.md` and the now-empty `web/app/themes/site-theme/woocommerce/` directory.

**Created (Phase B):** `docs/state-reconciliation.md`; `tests/promotion/verification-drill.spec.ts` (only if Task 3 did not already provide an equivalent).

**Modified (Phase B):** `ops/backup.md`, `ops/restore.md`, `ops/update-process.md`, `ops/incident-recovery.md`, `ops/monitoring.md`, `ops/launch-checklist.md`, `AGENTS.md`, `README.md`, `docs/architecture.md`, `docs/ownership-rules.md`, `docs/editing-strictness.md`, `docs/validation-scenarios.md`, `docs/adding-a-block.md`, `docs/adding-commerce-behaviour.md`.

**Explicitly NOT modified by this task (coordinator ruling):** `tests/Architecture/WooCommerceIsolationTest.php` (Task 1 owns the wording update), `scripts/setup` and `.github/workflows/ci.yml` (Task 1 owns the base bootstrap/CI corrections; Task 3 owns the promotion CI wiring). Where this plan's proofs depend on those files being correct, they are stated as **verified preconditions** and a failure is escalated, never repaired here.

**Non-repository outputs (the `.superpowers/` tree is gitignored — nothing here is ever committed):** `.superpowers/sdd/BLOCK_THEME_PROPOSAL/task-4-commerce-ground-truth.md`, `.superpowers/sdd/BLOCK_THEME_PROPOSAL/proofs/*.log`, `.superpowers/sdd/BLOCK_THEME_PROPOSAL/task-4-final-report.md`.

---

## Fixed decisions this plan makes (the spec left these open)

1. **`site-theme/woocommerce/` is deleted.** It contains only a README and zero overrides. A block theme's sanctioned override surfaces are `templates/*.html` and `woocommerce_*` hooks; keeping an empty classic-override directory contradicts §4's "the final theme has only one rendering path". The policy moves into `docs/adding-commerce-behaviour.md` and is enforced by `CommerceBoundaryTest`.
2. **Commerce templates are derived, not authored.** Each is `sed`-transformed from the template WooCommerce ships for that slug, changing only `"slug":"header"` → `"slug":"site-header"` and `"slug":"footer"` → `"slug":"site-footer"` (and removing any `"theme":"…"` attribute on a template-part block). The single justification for every override is that WooCommerce's templates reference part slugs this theme does not have, so without an override the storefront renders with no header and no footer. A slug WooCommerce does not ship gets no file.
3. **No commerce CSS anywhere.** WooCommerce blocks use core block supports, so `theme.json`'s existing palette/typography/spacing presets already reach them. `assets/global/` is not touched (`GlobalAssetRulesTest`), and no stylesheet is added to `site-commerce`. Any real visual gap found in a client project is that project's decision, recorded in `docs/adding-commerce-behaviour.md`.
4. **No commerce keys in `theme.json`.** `theme.json` is Task 1-owned and must stay commerce-free so the base profile carries no WooCommerce vocabulary. Recorded as a deviation candidate in the planner report; nothing in §11.8 requires it once decision 3 holds.
5. **The base `parts/site-header.html` stays commerce-free; the Mini-Cart is a profile-level composition.** `site-commerce` registers a `Header with Mini-Cart` pattern, and `scripts/enable-commerce` seeds a `wp_template_part` **database override** of `site-header` that appends `woocommerce/mini-cart`, so the commerce E2E can prove the Mini-Cart works in a block-theme header without putting a WooCommerce block into the base theme's Git tree. This is also a live demonstration of the new source-of-truth model (§5.3): a legitimate, expected database override.
6. **`scripts/enable-commerce` never hand-writes Cart/Checkout markup.** It deletes a page whose content still holds the legacy shortcode, re-runs WooCommerce's own `install_pages` tool so WooCommerce recreates it with its current default **block** content, then hard-fails if the result does not contain `wp:woocommerce/cart` / `wp:woocommerce/checkout`. This stays correct across WooCommerce versions.
7. **Proof evidence is not committed.** Command transcripts and the §17 report live under the gitignored `.superpowers/` tree; the durable, reusable *procedures* live in `docs/state-reconciliation.md` and `ops/launch-checklist.md`. This satisfies §13's "no state bundle, backup payload, secret, or customer data is committed" and "`git status` is clean".
8. **Ownership of commerce boundary checks is strict.** Unit 1 alone owns the
   PHP-symbol wording in `tests/Architecture/WooCommerceIsolationTest.php`.
   Unit 4A alone owns the HTML block-markup rule in the new
   `tests/Architecture/CommerceBoundaryTest.php`. `WooCommerceIsolationTest`
   scans PHP, while commerce templates are HTML. Therefore Unit 4A adds no
   allow-list entry by default. Add an entry only when the scanner output proves
   that a new Unit 4A PHP symbol in a scanned path needs it. Record that output,
   the exact symbol, and the reviewed entry. Never edit the Unit 1-owned test
   wording to make this work.

9. **The base profile is never tested on a commerce-enabled site.** `scripts/enable-commerce` installs WooCommerce on disk (dirtying `composer.json`/`composer.lock`), rewrites the cart/checkout pages, seeds store fixtures, and seeds a `site-header` template-part database override. A base Playwright, `verify`, or `verify:fast` run against that state proves nothing about the base profile — and a base run that passes there is a false green. Two git worktrees cannot solve this (git refuses to check the same branch out twice), so this plan uses **one worktree and an explicit, asserted profile switch** built on a DDEV database snapshot plus a Composer restore. The two procedures are defined once, under "Profile switch procedures" below, and every task that crosses the boundary names the one it runs.

10. **This plan contains no unresolved placeholders.** Values that belong to WooCommerce's own markup (block names, template content) are not written from memory and are not left as slots either: each is produced by an exact discovery command whose output is substituted by an exact generation command in the same step, and the generation command hard-fails when discovery finds nothing. Playwright locators are written as complete, final code using accessible roles and label text, with a probe step that fails the task if any of them does not resolve on the live store.

---

## Profile switch procedures

Referenced by name from the tasks below. Run them from the current Task 4 phase worktree. Check `ddev snapshot --help` / `ddev snapshot restore --help` once for your DDEV version's argument form (older versions use `--name=`, current versions take the name positionally) and use that form consistently.

### `SWITCH TO COMMERCE`

```bash
# Capture the base-profile database ONCE, before WooCommerce ever touches it.
# Re-running this when the snapshot already exists is harmless; keep the name.
ddev snapshot --name=base-profile
# Preserve all non-Composer task changes. The Composer diff must be only the
# expected ephemeral WooCommerce require and its lock-file resolution.
mkdir -p var/agency-state
git diff --quiet -- composer.json composer.lock || { echo "FAIL: Composer files are already changed"; exit 1; }
git hash-object composer.json composer.lock > var/agency-state/composer-before-commerce.hashes
git rev-parse HEAD:composer.json HEAD:composer.lock > var/agency-state/composer-head-before-commerce.hashes
git diff --no-index -- var/agency-state/composer-before-commerce.hashes var/agency-state/composer-head-before-commerce.hashes || { echo "FAIL: Composer files do not match HEAD"; exit 1; }
bash scripts/enable-commerce
git diff --check -- composer.json composer.lock
git diff --name-only -- composer.json composer.lock > var/agency-state/composer-after-commerce.txt
ddev exec bash -c 'grep -q "wpackagist-plugin/woocommerce" composer.json'
ddev wp plugin list --status=active --field=name | ddev exec bash -c 'grep -qx "woocommerce"'
```

### `SWITCH TO BASE`

```bash
# 1. Preserve all non-Composer changes. Abort unless the Composer diff is only
# the ephemeral WooCommerce require introduced by SWITCH TO COMMERCE.
git diff --name-only -- . ':!composer.json' ':!composer.lock' > var/agency-state/non-composer-before-base.txt
git diff --check -- composer.json composer.lock
git rev-parse HEAD:composer.json HEAD:composer.lock > var/agency-state/composer-head-before-base.hashes
git diff --no-index -- var/agency-state/composer-before-commerce.hashes var/agency-state/composer-head-before-base.hashes || { echo "FAIL: HEAD Composer files changed after SWITCH TO COMMERCE"; exit 1; }
ddev exec bash -c 'grep -q "wpackagist-plugin/woocommerce" composer.json' || { echo "FAIL: Composer diff is not the expected ephemeral WooCommerce require"; exit 1; }
git diff --name-only -- composer.json composer.lock | ddev exec bash -c 'sort | diff -u <(printf "composer.json\ncomposer.lock\n") -' || { echo "FAIL: unexpected Composer paths"; exit 1; }
git restore --source=HEAD -- composer.json composer.lock
git diff --name-only -- . ':!composer.json' ':!composer.lock' > var/agency-state/non-composer-after-base.txt
git diff --no-index -- var/agency-state/non-composer-before-base.txt var/agency-state/non-composer-after-base.txt || { echo "FAIL: non-Composer task changes moved"; exit 1; }
ddev composer install --no-interaction --prefer-dist

# 2. Restore the pre-commerce database (drops the store fixtures, the block
#    cart/checkout page content, and the site-header template-part override).
ddev snapshot restore base-profile

# 3. ASSERT the base profile really is back. Every one of these must hold before
#    any base gate is allowed to run — a base suite that runs against leftover
#    commerce state is a false green.
test ! -d web/app/plugins/woocommerce && echo "OK: no WooCommerce on disk" || { echo "FAIL: WooCommerce still installed"; exit 1; }
ddev wp plugin list --field=name | grep -q '^woocommerce$' && { echo "FAIL: WooCommerce still registered"; exit 1; } || echo "OK: no WooCommerce plugin"
test -z "$(ddev wp post list --post_type=wp_template_part --post_status=any --field=post_name | tr -d '\r')" && echo "OK: no template-part overrides" || { echo "FAIL: template-part override survived"; exit 1; }
test -z "$(ddev wp post list --post_type=product --post_status=any --field=ID | tr -d '\r')" && echo "OK: no fixture products" || { echo "FAIL: fixture products survived"; exit 1; }
test -z "$(git status --porcelain -- composer.json composer.lock)" && echo "OK: Composer files restored" || { echo "FAIL: Composer files still modified"; git status --porcelain -- composer.json composer.lock; exit 1; }
```

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — assertion 5
> was RED BY CONSTRUCTION and has been replaced above.**
>
> As originally written, assertion 5 was
> `test -z "$(git status --porcelain)" && echo "OK: clean tree" || { echo "FAIL: tree dirty"; …; exit 1; }`,
> and this procedure's preamble requires every assertion to hold "before any base
> gate is allowed to run".
>
> That contradicts step 1 of this same procedure, which exists ONLY to PRESERVE
> the task's uncommitted non-Composer changes and to prove they did not move.
> Every Phase A task runs `SWITCH TO BASE` immediately BEFORE `verify:fast` and
> its commit — the exact moment its own new and modified files are uncommitted.
> So `git status --porcelain` is non-empty BY DESIGN, assertion 5 fails, and the
> plan then forbids the commit gate from running at all. Task A2 would abort on a
> single modified spec file; Task A3 on six new templates, two new test files, a
> deletion and two documentation edits.
>
> The property assertion 5 was reaching for is "no commerce artefact and no
> Composer modification survived", which is what the replacement tests. Step 1's
> `non-composer-before/after` diff already proves the task's own changes survived
> unmoved. The pristine-tree check belongs AFTER the commit, where every task
> already runs `git status --porcelain` and expects empty output.
>
> **Verified by execution on 2026-08-08.** After `git restore --source=HEAD --
> composer.json composer.lock` and `ddev composer install`, all four remaining
> assertions passed: `OK: no WooCommerce on disk`, `OK: no WooCommerce plugin`,
> `parts=[]`, `products=[]`. That run also confirms `composer install` really does
> delete `web/app/plugins/woocommerce/` — Composer logged
> `Deleting /var/www/html/web/app/plugins/woocommerce/ - deleted` — which is the
> mechanism assertion 1 depends on.

If `ddev snapshot restore` is unavailable or fails, stop the current proof as
BLOCKED. Do not delete or rebuild the shared project. Create and verify a named
disposable proof worktree or proof project before any recovery experiment, then
repeat the full profile proof there. Missing snapshot support is never a skip.

---

## Cross-task preconditions

Task 1 owns files this task depends on. Task A1 verifies each and **escalates instead of editing**.

**Hard gates on Phase A (Phase A cannot close until each is satisfied by Task 1):**

- **P1.** Task 1's new block-theme structure test must not enforce an exhaustive allow-list of template filenames (it must check naming/shape, not membership), or it must already include the commerce slugs. *Blocks Task A4.*
- **P2.** Task 1's integration template test must scope "every referenced block is registered" **to the tested profile** (spec §11.12 wording), i.e. it must skip `woocommerce/*` block names when `class_exists('WooCommerce')` is false. *Blocks Task A4.*
- **P3.** `tests/support/BlockIndexGenerator.php` must index `templates/*.html`, not `templates/*.php`. Until it does, `docs/generated-block-index.md` carries a silently wrong Templates column and `GeneratedIndexFreshnessTest` cannot detect it. **This is a hard gate on Phase A start, not an advisory note** — Task 4 must not run against a generator that cannot see the theme's templates. Task 1 owns the fix.

**Other verified preconditions (owned elsewhere; verify, record, escalate — never repair here):**

- **P4.** `scripts/setup` and `.github/workflows/ci.yml`'s base `e2e` job must bootstrap a block-theme navigation instead of `wp menu create Primary` + `wp menu location assign primary primary`. A block theme registers no `primary` nav-menu location, so the classic step either fails or silently no-ops and the header renders no navigation. **Owner: Task 1. Hard gate on Phase A start.** Task A1 verifies it through a fresh setup. Task B5 verifies it again through the fresh-clone proof.
- **P5.** `scripts/promote-overrides` must be drivable in the proof environment, and its Playwright wiring must accept both a project name and a test path. **Owner: Task 3. Hard gate on Phase B.** Task B6 verifies the implemented variable names against Task B1's inventory before running the drill.

# PHASE A — Commerce profile on the block theme

**Gate to start Phase A:** Releases 1–4 are merged into `feat/block-theme-fse-migration`. Phase A depends only on the Release 1 theme at the code level, but the authoritative tracking plan serializes DDEV-backed execution and starts commerce after Release 4.

---

### Task A1: Workspace, commerce ground truth, and precondition check

**Files:**
- Create (gitignored, never committed): `.superpowers/sdd/BLOCK_THEME_PROPOSAL/task-4-commerce-ground-truth.md`
- Read only: `web/app/themes/site-theme/parts/`, `tests/Architecture/`, `tests/support/BlockIndexGenerator.php`

**Interfaces:**
- Consumes: Task 1's merged block theme — `web/app/themes/site-theme/templates/*.html`, `parts/site-header.html`, `parts/site-footer.html`, `theme.json` with a `templateParts` entry per part.
- Produces: the ground-truth note file. Every later Phase A task reads these recorded values: `WC_TEMPLATE_DIR_REL` (the directory holding WooCommerce's block templates, **as a repository-relative path usable on the host**), `WC_SHIPPED_SLUGS`, the template-part slugs WooCommerce's templates reference, the Mini-Cart block name, and the WooCommerce version under test. Also produces the two working environments every later Phase A task uses.

- [ ] **Step 1: Verify the orchestrator-created Phase A worktree and branch**

```bash
git branch --show-current
git status --porcelain
git merge-base --is-ancestor feat/block-theme-fse-migration HEAD
git log --oneline -5
```

Expected: branch `feat/bt-task-4-commerce-hardening`, empty status, ancestry exit `0`, and a log that shows the Release 4 merge as well as Releases 1–3. If any check fails, STOP — the Phase A worktree or serialized execution gate is not correct.

- [ ] **Step 1b: Bootstrap the base-profile site and take the base snapshot**

```bash
cp .env.example .env
sed -i 's/^WP_ENV=.*/WP_ENV=development/' .env
ddev start
ddev composer install --no-interaction --prefer-dist
ddev exec bash scripts/setup
ddev wp plugin list --field=name
```

Expected: `scripts/setup` completes; the plugin list contains `site-core` and `site-integrations` and **not** `woocommerce`. If `scripts/setup` fails on its navigation step, precondition **P4** failed. Record it in the ground-truth file, STOP, and return it to the orchestrator. Task 1 owns `scripts/setup`, and Unit 4A must not continue from a failed Unit 1 prerequisite.

This is the base-profile state every base gate must run against. Capture it now, before WooCommerce ever exists on this site:

```bash
ddev snapshot --name=base-profile
ddev snapshot --list
```

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — the listing
> form was wrong and is fixed above.** `ddev snapshot list` is not a subcommand.
> `ddev snapshot --help` on the installed DDEV shows exactly one subcommand,
> `restore`; listing is the flag `-l, --list`. Passing the bare word `list` is
> parsed as a PROJECT NAME and fails. The other two forms the plan uses are
> correct as written: `ddev snapshot --name=<name>` and
> `ddev snapshot restore <name>` (positional). To replace an existing snapshot,
> `ddev snapshot --cleanup --name <name> -y` deletes it first.

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — the
> orchestrator has already performed Step 1b, and the carried-over database
> needed a repair before it could succeed. Do not repeat the bootstrap; verify
> it.**
>
> The worktree is bootstrapped (three steps, not two — `ddev composer install`,
> `ddev exec bash scripts/setup`, then `npx -y npm@10 ci` on the Windows host),
> `scripts/setup` completed at exit 0, and the plugin list is `site-commerce`,
> `site-core`, `site-integrations`, `bedrock-autoloader` with `site-theme`
> active and NO `woocommerce`. Precondition **P4** is satisfied: `scripts/setup`
> seeds a `wp_navigation` post at its step 9 and contains no `wp menu create`.
>
> **The `base-profile` snapshot already exists and was taken from a REPAIRED
> database.** The first attempt captured a dirty state. The DDEV volume, carried
> across five units, still held a previous commerce run's fixtures — 2 products,
> 2 product variations, 9 legacy `shop_order` rows in `wp_posts`, 1 coupon, and
> 14 rows in `wp_wc_orders`. Two consequences, both blocking:
>
> 1. `bash scripts/enable-commerce` ABORTED at its step 3 with
>    `Warning: [Failed] There are orders pending sync` /
>    `Error: HPOS pre-checks failed` / `Failed to run wp wc hpos enable: exit status 1`.
>    `wp wc hpos status` showed HPOS was ALREADY enabled with 5 unsynced orders,
>    so the script was failing a pre-check for a setting that was already on.
> 2. `SWITCH TO BASE` assertion 4 ("no fixture products") was unsatisfiable.
>
> The orchestrator took a reversible safety snapshot `pre-cleanup-4a`, deleted
> every commerce post and its postmeta, cleared `wp_wc_orders`,
> `wp_wc_order_addresses`, `wp_wc_order_operational_data` and `wp_wc_orders_meta`,
> then re-took `base-profile` from the clean state. Note for any future cleanup:
> **`wp post delete --force` is NOT sufficient for legacy `shop_order` rows** —
> with HPOS enabled and compatibility mode off, WooCommerce intercepts the
> deletion and the `wp_posts` rows survive; direct SQL was required.
>
> Do not take a new `base-profile` snapshot. Run `ddev snapshot --list` and
> confirm both `base-profile` and `pre-cleanup-4a` are present.

**From here on, every task states which profile it runs in, and crossing the boundary means running `SWITCH TO COMMERCE` or `SWITCH TO BASE` in full, assertions included.**

- [ ] **Step 2: Confirm the merged theme is a block theme with the expected parts**

```powershell
Get-ChildItem web/app/themes/site-theme/templates
Get-ChildItem web/app/themes/site-theme/parts
rg -n 'templateParts' web/app/themes/site-theme/theme.json -A 20
```

Expected: `templates/index.html` exists; `parts/site-header.html` and `parts/site-footer.html` exist as flat `.html` files; `theme.json` declares both parts. Record the exact part slugs — every commerce template will reference them.

- [ ] **Step 3: Check precondition P1 (no exhaustive template allow-list)**

```powershell
Get-ChildItem tests/Architecture
rg -n '404|archive|index|page|search|single' tests/Architecture -g '*.php' | Select-String -Pattern 'template' | Select-Object -First 40
```

Read whichever new structure test Task 1 added. If it asserts an exact, closed set of files under `templates/`, STOP and escalate to the coordinator: adding commerce templates requires that test to allow them, and this task must not edit a Task 1-owned test class. Record the finding in the ground-truth file and in the final report.

- [ ] **Step 4: Check precondition P2 (profile-scoped block registration assertion)**

```powershell
rg -l 'registered|WP_Block_Type_Registry' tests/Integration tests/Architecture
```

Read the integration template test. If it asserts that every block referenced by every theme template is registered **without** scoping to the active profile, STOP and escalate: commerce templates reference `woocommerce/*` blocks that the base profile never registers. Record the finding.

- [ ] **Step 5: Check precondition P3 (block index generator scans HTML)**

```powershell
rg -n 'templates' tests/support/BlockIndexGenerator.php
rg -n "'php' !== strtolower" tests/support/BlockIndexGenerator.php
```

If `files_containing( $theme . '/templates', … )` still filters to `.php`, record it as **precondition P3, unsatisfied** in the ground-truth file. Do **not** edit the generator (Task 1 owns it). STOP and return the defect to the orchestrator. Unit 4A must not continue from a failed Unit 1 prerequisite.

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — ALL FOUR
> preconditions are SATISFIED. Steps 3, 4 and 5 confirm; they do not escalate.**
>
> Each was verified against the merged source, not against the plan text. Record
> these citations in the ground-truth file and do not re-derive them:
>
> - **P1 satisfied.** `tests/Architecture/BlockThemeStructureTest.php` —
>   `test_every_template_file_matches_the_naming_and_format_contract()` is
>   documented as "Deliberately an ALLOW-PATTERN, not an enumeration", and the
>   `BASE_PROFILE_TEMPLATES` docblock names the commerce slugs as expected
>   additions: "This is a MINIMUM, never a closed set." Each base slug is checked
>   with its own `assertFileExists`, never as a membership test. Its filename
>   contract is `/^[a-z0-9]+(?:[-_][a-z0-9]+)*\.html$/`, which accepts
>   `taxonomy-product_attribute.html`.
> - **P2 satisfied.** `tests/Integration/Theme/BlockTemplateIntegrityTest.php`
>   declares `FOREIGN_PROFILE_NAMESPACES = array( 'woocommerce' )` and skips a
>   file only when it references that namespace AND the namespace has zero
>   registered blocks in the run. The test is named
>   `test_every_referenced_block_is_registered_for_this_profile()`, which is
>   §11.12's wording.
> - **P3 satisfied.** `tests/support/BlockIndexGenerator.php` filters with
>   `if ( 'php' !== $extension && 'html' !== $extension ) { continue; }`, so it
>   indexes HTML block templates.
> - **P4 satisfied.** `scripts/setup` step 9 seeds a `wp_navigation` post and
>   contains no `wp menu create`; `.github/workflows/ci.yml` does the same in all
>   three of its WordPress jobs.
>
> **One documentation inaccuracy, recorded so no worker acts on it.** `AGENTS.md`
> states that Git-owned templates carry "no hard-coded `ref` and no inline `style`
> attribute (`BlockThemeStructureTest`)". The `ref` half is real
> (`test_no_hardcoded_database_refs_in_git_owned_markup` matches
> `/"ref"\s*:\s*\d+/`). **There is no inline-`style` assertion anywhere in the
> test suite.** Upstream WooCommerce templates may carry inline styles and that is
> NOT a violation — do not hand-edit upstream markup for it, which decision 2
> forbids anyway. `AGENTS.md` prose is Unit 1/4B-owned; leave it alone and let the
> orchestrator carry it forward.

- [ ] **Step 6: Run `SWITCH TO COMMERCE`**

Run the procedure defined above in full.

Expected: exit 0. It still seeds the *classic* cart/checkout at this point — that is what Task A6 replaces.

This installs WooCommerce through Composer, so `composer.json` and `composer.lock` are now modified. That is expected and correct while the commerce profile is active (the template must never carry a committed WooCommerce require). Confirm nothing else moved:

```bash
git status --porcelain
```

Expected: exactly `composer.json` and `composer.lock` modified, nothing else. **No commit may be made while the tree is in this state.**

- [ ] **Step 7: Record WooCommerce's block-template directory as a REPOSITORY-RELATIVE path**

`ddev wp eval` returns container paths that do not exist on the host, and the derivation in Task A4 runs on the host. WooCommerce installs inside the repository (`web/app/plugins/woocommerce/`, per `composer.json`'s `installer-paths`), so a host-usable relative path always exists. Find it by locating the directory that holds `archive-product.html`:

```bash
WC_TEMPLATE_DIR_REL="$(find web/app/plugins/woocommerce -name 'archive-product.html' -print -quit | xargs -r dirname)"
if [ -z "${WC_TEMPLATE_DIR_REL}" ]; then
  echo "FAILED: no archive-product.html under the commerce plugin — record this and stop." >&2
else
  echo "WC_TEMPLATE_DIR_REL=${WC_TEMPLATE_DIR_REL}"
  ls "${WC_TEMPLATE_DIR_REL}"/*.html | sort
fi
```

Record `WC_TEMPLATE_DIR_REL` and the sorted slug list (`basename` without `.html`) as `WC_SHIPPED_SLUGS`. Then record every template-part reference:

```bash
for f in "${WC_TEMPLATE_DIR_REL}"/*.html; do
  echo "=== $(basename "$f")"
  grep -o 'wp:template-part[^/]*' "$f" || echo "  (no template-part)"
  grep -o '<!-- wp:[a-z0-9/-]*' "$f" | sort -u | head -15
done
```

- [ ] **Step 7b: Record the Mini-Cart block name** *(commerce profile)*

```bash
ddev wp eval '
$names = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
$hits  = array_values( array_filter( $names, static fn( $n ) => 1 === preg_match( "#/mini-cart$#", $n ) ) );
sort( $hits );
echo implode( "\n", $hits ), "\n";'
```

Expected: exactly one name (the Mini-Cart parent block). Record it as `MINI_CART_BLOCK`. If it prints nothing, STOP: the Mini-Cart block is not registered and decision 5 cannot be implemented as planned.

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — the filter
> above is the corrected one; the original returned ten names.**
>
> The original used `str_contains( $n, "mini-cart" ) && ! str_contains( $n, "-contents" )`.
> WooCommerce 11.0.0 names the inner blocks `woocommerce/mini-cart-*-block`, not
> `*-contents`, so that filter returns the parent plus nine children. The anchored
> `#/mini-cart$#` implements the rule this step's prose already described and
> returns exactly one: **`woocommerce/mini-cart`**. Task A5 Step 4 uses the same
> anchored filter behind a hard `-eq 1` check that the original would have aborted
> — see the correction there.
>
> **While you are on the live store, also record the Mini-Cart BUTTON's accessible
> name.** That is a different fact from the block name, and Task A7's `miniCart`
> Playwright locator depends on it. Read it from the rendered storefront rather
> than guessing.

- [ ] **Step 8: Record which templates WordPress actually resolves, and their origin** *(commerce profile)*

```bash
ddev wp eval '
foreach ( get_block_templates() as $t ) {
  printf( "%s | origin=%s | source=%s | theme=%s\n", $t->slug, (string) $t->origin, $t->source, $t->theme );
}'
```

Expected: WooCommerce's commerce slugs appear with a plugin origin (no theme override exists yet). This is the "before" reading that Task A4 flips to a theme origin.

- [ ] **Step 9: Write the ground-truth note file**

Write `.superpowers/sdd/BLOCK_THEME_PROPOSAL/task-4-commerce-ground-truth.md` containing, verbatim from the commands above:

1. WooCommerce version (`ddev wp plugin get woocommerce --field=version`).
2. `WC_TEMPLATE_DIR_REL` — the repository-relative directory holding the `.html` block templates.
3. `WC_SHIPPED_SLUGS` — the sorted slug list.
4. `MINI_CART_BLOCK` — the Mini-Cart block name from Step 7b.
5. For each slug: the `wp:template-part` attributes it uses, and the block names it references.
6. The decision table: for each §11.8 candidate slug — `single-product`, `archive-product`, `taxonomy-product_cat`, `taxonomy-product_tag`, `taxonomy-product_attribute`, `product-search-results`, `page-cart`, `page-checkout`, `order-confirmation` — either **OVERRIDE** (WooCommerce ships it and it references a part slug this theme lacks) or **SKIP** with the exact reason (WooCommerce does not ship it, or the shipped template references no part slug that this theme lacks). Never create an override when no missing-part rewrite is required.
7. Any upstream slug WooCommerce ships that is not in the candidate list — mark it EXCLUDED with a reason (it becomes an entry in `tests/Architecture/commerce-template-list.php`'s excluded list).
8. The P1/P2/P3 findings from Steps 3–5, and the P4 finding if `scripts/setup` failed in Step 1b.

- [ ] **Step 10: No commit; leave the commerce profile active for Task A2**

This task produces no repository change of its own.

```bash
git status --porcelain
```

Expected: exactly `composer.json` and `composer.lock` modified — the ephemeral WooCommerce require. The `.superpowers/` tree is gitignored, so the ground-truth file does not appear.

---

### Task A2: Re-green the commerce suites against the merged block theme

**Ownership (coordinator ruling).** Task 1 owns the §11.9 capability-matrix change inside `tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php`, and Task 1 merges first. Its plan already moves `edit_theme_options` out of the negative "keys to the kingdom" list and adds a positive Site Editor inheritance assertion. **This task must not rewrite, replace, or duplicate that work.** It VERIFIES Task 1's matrix is present, escalates if it is not, and ADDS only the commerce-specific assertion Task 1 does not make.

**Files:**
- Modify (additive only): `tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php`
- Modify: `tests/commerce/e2e/shop-manager-admin.spec.ts` (minimal menu-assertion fix only; Task A7 does the full rewrite)

**Interfaces:**
- Consumes: Task 1's merged role contract and its merged edits to this very test file — `client_editor` and its derived `client_shop_manager` have `edit_theme_options = true`, `edit_css = false`, `unfiltered_html = false`, and `customize` mapped to `do_not_allow` via `map_meta_cap`.
- Produces: a commerce suite that passes on the block theme, with the commerce-only capability assertion added beside Task 1's matrix.

- [ ] **Step 1: Read the file as it exists AFTER Task 1's merge**

Do not assume the pre-migration content. Read the merged file first:

```powershell
Get-Content tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php
rg -n 'function test_' tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php
```

Also read the base contract it mirrors:

```powershell
rg -n 'edit_theme_options|edit_css|customize|unfiltered_html' tests/Integration/Permissions/ClientEditorCapabilitiesTest.php | Select-Object -First 30
rg -n 'edit_theme_options|edit_css|customize|NEVER_GRANT' web/app/mu-plugins/agency-platform/src/Roles | Select-Object -First 40
```

- [ ] **Step 2: VERIFY Task 1's matrix assertions are present — do not repair**

Confirm the merged file asserts, for `client_shop_manager`:

1. `edit_theme_options` is **true** (a positive Site Editor inheritance assertion — it must no longer appear in any negative list);
2. `edit_css` is **false**;
3. `unfiltered_html` is **false**.

```powershell
rg -n 'edit_theme_options' tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php
rg -n 'edit_css|unfiltered_html' tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php
ddev composer test:integration:commerce
```

**If `edit_theme_options` is still inside a negative/`assertFalse` list, or the three assertions are absent: STOP and escalate to the coordinator**, exactly like preconditions P1–P4. Task 1 owns that change; this task must not make it, and must not work around it. Record the finding in the ground-truth file.

If the assertions are present and the suite is green, this step is complete and the file needs no correction — proceed to Step 3 and add only what is missing.

- [ ] **Step 3: ADD the commerce-only assertion**

Task 1's matrix covers the three capabilities above. It does not assert the commerce role's Customizer denial, because `customize` is not part of §11.9's stated three-value matrix and `client_shop_manager` is registered only in the commerce profile — which Task 1 cannot run. Append **one new method** to the class. Add nothing else, and do not touch any method Task 1 wrote:

```php
	/**
	 * Commerce-profile addition to the capability matrix the base migration
	 * pins. `client_shop_manager` exists only when the store plugin is active,
	 * so the base suite can never assert anything about it; and granting
	 * edit_theme_options for Site Editor access also exposes core's `customize`
	 * meta capability unless it is mapped to do_not_allow. This proves that
	 * mapping reaches the derived commerce role too.
	 */
	public function test_shop_manager_cannot_reach_the_customizer(): void {
		$shop_manager = $this->make_shop_manager();
		wp_set_current_user( $shop_manager->ID );

		self::assertFalse(
			current_user_can( 'customize' ),
			'client_shop_manager must never reach the Customizer entry point.'
		);
	}
```

Before writing it, check the merged file does not already contain an equivalent assertion:

```bash
grep -n "customize" tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php
```

If Task 1 already asserts it, **add nothing to this file** and record that in the ground-truth file. A duplicate assertion is a merge conflict waiting to happen, not extra safety.

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — Steps 2 and
> 3 both resolve to "verified, nothing to add". This task's PHP file does not
> change.**
>
> Task 1 already ships the whole matrix in
> `tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php`, inside
> `test_shop_manager_inherits_site_editor_access()`:
>
> ```php
> self::assertTrue( current_user_can( 'edit_theme_options' ) );
> self::assertFalse( current_user_can( 'edit_css' ) );
> self::assertFalse( current_user_can( 'customize' ) );
> ```
>
> and `unfiltered_html` is covered by
> `test_shop_manager_cannot_reach_the_keys_to_the_kingdom()`, which loops over
> `array( 'install_plugins', 'switch_themes', 'manage_options', 'unfiltered_html' )`.
>
> So Step 2's verification passes, and Step 3's
> `test_shop_manager_cannot_reach_the_customizer()` **must NOT be added** — it
> would duplicate line 125 of a Task 1-owned file. Confirm the three assertions
> are present, record it, and move to Step 5. Only
> `tests/commerce/e2e/shop-manager-admin.spec.ts` changes in this task.

- [ ] **Step 4: Run the commerce integration suite** *(commerce profile — active since Task A1)*

```bash
ddev composer test:integration:commerce
```

Expected: all tests pass, including the added `test_shop_manager_cannot_reach_the_customizer`. If it fails, the `map_meta_cap` policy does not reach the derived commerce role — STOP and escalate (roles are Task 1-owned; do not fix them here).

- [ ] **Step 5: Fix the stale admin-menu assertion in the shop-manager E2E**

In `tests/commerce/e2e/shop-manager-admin.spec.ts`, replace the body of the test named `'wp-admin lockdown holds: no Plugins or Appearance menus'` with the post-migration truth, and rename it:

```ts
	test( 'wp-admin lockdown holds: no Plugins menu, and Appearance is replaced by Design', async ( { page } ) => {
		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		await page.goto( adminUrl() );
		// Plugins stays fully hidden: client roles never get activate_plugins.
		await expectNoAdminMenu( page, 'menu-plugins' );

		// AdminScreenPolicy REMOVES the Appearance menu (its top-level target is
		// themes.php, a denied screen) and replaces it with a single Design entry
		// that links straight to the Site Editor. Same contract the base suite
		// pins in tests/e2e/editor-permissions.spec.ts.
		await expectNoAdminMenu( page, 'menu-appearance' );
		await expect(
			page.locator( '#adminmenu a[href$="site-editor.php"]' ).first()
		).toBeVisible();

		// No denied design screen is reachable from the menu at all.
		for ( const denied of [ 'themes.php', 'theme-editor.php', 'customize.php', 'widgets.php', 'nav-menus.php' ] ) {
			await expect(
				page.locator( `#adminmenu a[href*="${ denied }"]` )
			).toHaveCount( 0 );
		}
	} );
```

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — the original
> Step 5 was RED BY CONSTRUCTION. The block above replaces it.**
>
> The original asserted
> `await expect( page.locator( '#adminmenu li#menu-appearance' ) ).toHaveCount( 1 );`
> and reasoned that "Appearance now exists because the block-theme migration
> grants edit_theme_options". That is factually wrong about the shipped boundary.
>
> `AgencyPlatform\Security\AdminScreenPolicy::replace_appearance_menu()` runs on
> `admin_menu` at priority 999 for every user without `manage_options` and does:
>
> ```php
> remove_menu_page( 'themes.php' );
> add_menu_page( __( 'Design', … ), __( 'Design', … ), 'edit_theme_options', self::SITE_EDITOR_SCREEN, '', 'dashicons-admin-appearance', 60 );
> ```
>
> Its own docblock says so: "Removes the Appearance menu (whose top-level target
> is themes.php, a denied screen) and replaces it with one Design entry that links
> straight to the Site Editor." So `li#menu-appearance` has count **0**, and the
> original assertion could never pass.
>
> The assertion the original Step 5 wanted DELETED —
> `expectNoAdminMenu( page, 'menu-appearance' )`, already at
> `tests/commerce/e2e/shop-manager-admin.spec.ts:168` — is the correct one and is
> kept. Unit 1's own CI-green base spec asserts exactly this pair at
> `tests/e2e/editor-permissions.spec.ts:43-47`, and the replacement mirrors it.
>
> Note the denied-screen loop is no longer scoped to `li#menu-appearance`: with
> that menu gone, scoping to it would make the loop VACUOUS — five assertions over
> an element that does not exist all pass trivially. Scoping to `#adminmenu`
> instead is the assertion that can actually fail.

- [ ] **Step 6: Run the commerce E2E** *(commerce profile)*

```bash
COMMERCE=1 npm run test:e2e:commerce
```

If the menu assertion fails, read Task 1's admin-screen policy and align this test with the *implemented* boundary; never weaken it.

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — BOTH halves
> of the original expectation ("`shop-manager-admin.spec.ts` passes in full" and
> "`commerce-journey.spec.ts` still passes here") are FALSE, and chasing either
> would send this task far outside its grant.**
>
> The orchestrator measured the commerce e2e baseline on 2026-08-08, before any
> Phase A code was written, with the store freshly seeded by the UNCHANGED
> `scripts/enable-commerce`. `COMMERCE=1 npm run test:e2e:commerce`:
>
>     8 failed, 4 passed, 10 skipped — exit 1
>
> Every failure, classified:
>
> | # | test | cause |
> |---|---|---|
> | 1 | journey: product archive lists the fixture products | `.woocommerce-loop-product__title` not found — classic archive markup |
> | 2 | journey: simple product PDP | `p.price` not found — classic PDP markup |
> | 3 | journey: cart quantity + TESTCOUPON | `.cart-subtotal` not found |
> | 4 | journey: guest COD checkout (desktop) | `.woocommerce-order` not found |
> | 5 | journey: account order history | `.woocommerce-order` not found |
> | 6 | admin: orders screen + private note | 90 s timeout inside `placeGuestCodOrder()` on `.woocommerce-order-overview__order strong` |
> | 7 | admin: WooCommerce Settings reachable | `reauth=1`, 14 retries — ENVIRONMENTAL |
> | 8 | journey: guest COD checkout (mobile) | `.woocommerce-order` not found |
>
> Failure 4 is the exact `.woocommerce-order` failure the tracking file records as
> the documented cause of the `commerce-e2e` exemption, still at
> `commerce-journey.spec.ts:81`.
>
> **Failure 7 is concurrency, not code.** Re-running the file alone with
> `--workers=1` made it pass: `1 failed, 4 passed`. Two shop-manager logins racing
> across parallel workers produce the documented `reauth=1` bounce. Use
> `--workers=1` for your inner loop.
>
> **Failure 6 is real and is NOT yours to fix.** It fails inside this file's own
> local `placeGuestCodOrder()` helper, which drives the CLASSIC checkout and reads
> classic order-received markup the block storefront no longer emits. **Task A7
> Step 4 rewrites that helper** to call `addSimpleProductToCart` /
> `fillBlockCheckoutWithCod` / `placeOrderAndReadNumber`, and it can only work once
> **Task A6** seeds the native block cart and checkout. So the "passes in full"
> target is unreachable at A2 by construction.
>
> **Your actual success condition, and the only one:**
>
> 1. The one test you edit passes on BOTH projects.
> 2. Nothing that passed at baseline regresses.
>
> The serial baseline for your file is **4 passed, 1 failed**, the single failure
> being #6 above. Reproduce that number before you change anything, quote it, and
> quote it again afterwards.
>
> **Do not touch `commerce-journey.spec.ts` (Task A7), `scripts/enable-commerce`
> (Task A6), or the shared `npm run test:e2e:commerce` script.** Five failing
> journey tests are the expected state of the branch at this point; they are what
> Phase A exists to fix, task by task.

- [ ] **Step 7: Run `SWITCH TO BASE`, then verify and commit**

The commit gate is `verify:fast`, and `verify:fast` starts with `composer validate --strict` and `composer audit` — both of which would run against the ephemeral WooCommerce require if the commerce profile were still active. Return to the base profile first, assertions included.

```bash
# SWITCH TO BASE (see "Profile switch procedures") — all five assertions must pass.
ddev composer verify:fast
git status --porcelain
```

Expected: `verify:fast` green. The status lists `tests/commerce/e2e/shop-manager-admin.spec.ts`, plus `tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php` **only if Step 3 added the Customizer method**. If Task 1 already asserted `customize`, the PHP file is untouched and must not appear.

```bash
git add -- tests/commerce/e2e/shop-manager-admin.spec.ts
git diff --cached --name-only
git diff --cached --check
ddev composer verify:fast
git commit -m "test(commerce): pin the shop-manager Design menu boundary on the block theme"
git status --porcelain
```

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — the staging
> and the commit message are fixed above.**
>
> Per the Step 3 correction, `ShopManagerCapabilitiesTest.php` does NOT change in
> this task, so it must not be staged; the conditional `git add` invited a worker
> to stage an unmodified Task 1-owned file. The original message
> `"test(commerce): cover the shop-manager Customizer denial and the new
> Appearance menu boundary"` named two things the commit does not contain — no
> Customizer assertion is added (Task 1 already has it) and there is no Appearance
> menu (it is replaced by Design).
>
> `git status --porcelain` before staging must list exactly one path:
> `tests/commerce/e2e/shop-manager-admin.spec.ts`.

Expected: empty output after the commit.

Before committing, confirm this task removed nothing Task 1 wrote:

```bash
git diff --cached tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php
```

Expected: additions only. Any deleted line in that file is an ownership violation — restore it and re-read the coordinator ruling at the top of this task.

---

### Task A3: Derive the commerce block templates, pin the markup boundary, retire the classic directory

One red→green cycle, one green commit. The boundary test goes red first (the classic directory still exists, no commerce templates exist), the templates are derived and the directory is deleted, the suite goes green, and only then does the task commit. **Nothing in this plan is ever committed while a suite is red.**

- [ ] **Step 0: `SWITCH TO COMMERCE` boundary**

Run `SWITCH TO COMMERCE` in full before Task A3 reads the installed plugin
templates or creates commerce markup. Do not rely on the profile state from
Task A1 or A2. Record the active plugin result and the protected Composer diff
in the ground-truth log. A failed switch blocks Task A3.

**Files:**
- Create: `tests/Architecture/commerce-template-list.php`
- Create: `tests/Architecture/CommerceBoundaryTest.php`
- Create: `web/app/themes/site-theme/templates/<slug>.html` for every OVERRIDE slug from Task A1
- Delete: `web/app/themes/site-theme/woocommerce/README.md` (and the directory)
- Modify: `docs/adding-commerce-behaviour.md` (section 3 and section 4)
- Modify: `AGENTS.md` (one routing-table line)

**Interfaces:**
- Consumes: Task A1's ground-truth file — the decision table (OVERRIDE / SKIP / EXCLUDED per slug), `WC_TEMPLATE_DIR_REL`, and `WC_SHIPPED_SLUGS`. Requires precondition **P1** satisfied (Task 1's structure test must not enforce a closed template-filename list).
- Produces: `tests/Architecture/commerce-template-list.php` returning
  `array{templates: list<string>, excluded: array<string,string>}` — `templates` is the sorted list of commerce template slugs this theme owns; `excluded` maps an upstream slug this theme deliberately does not override to its one-line reason. Task A4's `CommerceBlockTemplatesTest` requires the same file. Also produces the commerce templates themselves, consumed by Task A6's E2E fixtures and Task A7's storefront journeys.

- [ ] **Step 1: Write the data file**

Create `tests/Architecture/commerce-template-list.php`. Populate `templates`
from Task A1's exact OVERRIDE rows and `excluded` from its exact EXCLUDED rows.
Record the resulting declared slug list and its count beside the file in the
ground-truth note. The sample structure below does not declare the templates;
replace every value with the discovered list. Never claim a fixed count.

```php
<?php
/**
 * Canonical commerce block-template set.
 *
 * The block theme overrides a commerce template for exactly one reason: the
 * templates the commerce plugin ships reference `header` / `footer` template
 * parts, and this theme's parts are `site-header` / `site-footer`. Without an
 * override the storefront renders with no header and no footer. Each file is
 * therefore a verbatim copy of the upstream template with only those two slug
 * attributes rewritten.
 *
 * `templates` — slugs this theme owns; every one MUST exist as
 * web/app/themes/site-theme/templates/<slug>.html.
 * `excluded`  — upstream slugs this theme deliberately does NOT override,
 * each with its reason. An upstream slug in neither list fails
 * CommerceBlockTemplatesTest, which is the intended prompt to decide about it.
 *
 * @return array{templates: list<string>, excluded: array<string, string>}
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

return array(
	'templates' => array(
		'archive-product',
		'order-confirmation',
		'page-cart',
		'product-search-results',
		'single-product',
		'taxonomy-product_attribute',
	),
	'excluded'  => array(
		'coming-soon'   => 'Shipped upstream but references no template part, so there is no missing-part rewrite to justify an override.',
		'page-checkout' => 'References the checkout-header part that the commerce plugin ships and explicitly scopes with "theme":"woocommerce/woocommerce", so no part of this theme is missing. Removing that theme attribute — which the derivation would do — repoints the reference at a part this theme does not have.',
	),
);
```

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — the sample
> above has been replaced with the DERIVED answer, verified by execution against
> the installed WooCommerce 11.0.0. Six overrides, not nine.**
>
> `WC_TEMPLATE_DIR_REL = web/app/plugins/woocommerce/templates/templates` — note
> the doubled segment; the classic PHP templates are one level up and `parts/`
> sits beside them.
>
> `WC_SHIPPED_SLUGS` is EIGHT files: `archive-product`, `coming-soon`,
> `order-confirmation`, `page-cart`, `page-checkout`, `product-search-results`,
> `single-product`, `taxonomy-product_attribute`.
>
> Template-part references, read from the shipped files:
>
> | slug | parts referenced |
> |---|---|
> | archive-product | `header` (tagName header), `footer` (tagName footer) |
> | coming-soon | none |
> | order-confirmation | `header`, `footer` (no tagName) |
> | page-cart | `header` (tagName header), `footer` (tagName footer) |
> | page-checkout | `checkout-header` with `"theme":"woocommerce/woocommerce"`, and NO footer |
> | product-search-results | `header`, `footer` |
> | single-product | `header`, `footer` |
> | taxonomy-product_attribute | `header`, `footer` |
>
> **`taxonomy-product_cat` and `taxonomy-product_tag` are not shipped as `.html`
> templates at all.** They were in the candidate list and are simply absent
> upstream, so they get no file and no `excluded` entry — `excluded` exists for
> slugs the plugin DOES ship. Record both as SKIP in the ground-truth file with
> "not shipped upstream" as the reason.
>
> **`page-checkout` must NOT be derived, and this is the release-blocking finding
> of the audit.** Three independent gates agree:
>
> 1. WooCommerce ships `templates/parts/checkout-header.html` itself, and the
>    reference carries `"theme":"woocommerce/woocommerce"`, so it resolves against
>    the PLUGIN. This theme is missing nothing, so decision 2's sole justification
>    for an override does not apply.
> 2. Step 4's `s/,"theme":"[^"]*"//g` would strip that attribute and repoint
>    `checkout-header` at the active theme, which has no such part — turning a
>    working distraction-free checkout header into a missing one.
> 3. A derived `page-checkout.html` would turn the BASE architecture suite RED.
>    `BlockThemeStructureTest::test_every_referenced_template_part_exists_and_is_declared()`
>    matches `"slug"\s*:\s*"([a-z0-9-]+)"` **unscoped** over every file in
>    `templates/`, so `checkout-header` matches and the test would demand
>    `parts/checkout-header.html` (absent) and a `theme.json.templateParts` entry
>    (absent). That test and `theme.json` are both Unit 1-owned, so Unit 4A has no
>    in-scope repair. Not creating the file is the only correct action.
>
>    The Step 4 sed rewrites `"slug":"header"`, and does NOT touch
>    `"slug":"checkout-header"`, because the pattern includes the opening quote.
>
> `page-checkout` also has no footer part, so
> `CommerceBoundaryTest::test_declared_commerce_templates_render_the_theme_chrome()`
> would fail on it too.
>
> **The unscoped-`"slug"` hazard, audited in full.** Across all eight shipped
> templates the `"slug"` values are:
>
>     6  "slug":"footer"                                     -> rewritten to site-footer
>     6  "slug":"header"                                     -> rewritten to site-header
>     1  "slug":"checkout-header"                            -> the ONLY hazard, page-checkout only
>     1  "slug":"woocommerce/coming-soon"                    -> safe, contains "/"
>     5  "slug":"woocommerce/order-confirmation-*-heading"   -> safe, all contain "/"
>
> Every namespaced value is safe because `/` falls outside the regex's
> `[a-z0-9-]` class. So with `page-checkout` excluded, the six derived templates
> introduce no `"slug"` value beyond `site-header` and `site-footer`, both of
> which exist as part files and are declared in `theme.json`, and the base
> architecture suite stays green.
>
> **This must be re-derived, never trusted, on any WooCommerce upgrade.** After
> Step 4, run this and confirm the only values are `site-header` and
> `site-footer`:
>
> ```bash
> grep -rho '"slug"[[:space:]]*:[[:space:]]*"[a-z0-9-]*"' web/app/themes/site-theme/templates/*.html | sort | uniq -c
> ```
>
> You must still re-derive the whole table from the live install rather than
> copying it — the values above are the orchestrator's verified answer to check
> your derivation against, and a mismatch is a finding worth reporting.
>
> ---
>
> **THE GATE WAS PROVEN CAPABLE OF PASSING BEFORE ANY CODE WAS WRITTEN, and the
> defect was proven by executing it.** The orchestrator ran Step 4's derivation
> verbatim against WooCommerce 11.0.0 and then ran the base architecture suite.
>
> *With the six OVERRIDE slugs derived, GREEN:*
>
> ```
> ............................................                      44 / 44 (100%)
> OK (44 tests, 456 assertions)
> ```
>
> Assertions rose from the unit baseline's 408 to 456 as the six new files flowed
> through the existing per-file loops, and the slug audit over the whole
> `templates/` directory reported exactly two values and nothing else:
>
> ```
>      12 "slug":"site-footer"
>      12 "slug":"site-header"
> ```
>
> No unrewritten `header`/`footer` slug survived, and no `"ref":<digits>` appeared
> in any derived file.
>
> *Then `page-checkout` was derived as well, and the SAME suite went RED:*
>
> ```
> ........F...................................                      44 / 44 (100%)
>
> 1) Tests\Architecture\BlockThemeStructureTest::test_every_referenced_template_part_exists_and_is_declared
> Architecture rule broken: Referenced template part does not exist
> Offending file:           web/app/themes/site-theme/templates/page-checkout.html
> Failed asserting that file ".../web/app/themes/site-theme/parts/checkout-header.html" exists.
>
> Tests: 44, Assertions: 437, Failures: 1.
> ```
>
> The derived first line was
> `<!-- wp:template-part {"slug":"checkout-header","tagName":"header"} /-->` — the
> `"theme":"woocommerce/woocommerce"` attribute stripped by the sed, exactly as
> predicted. All seven probe files were then deleted and the worktree returned to
> a clean state.
>
> So the six-slug list is proven to keep the base suite green, and the seventh is
> proven to break it. Do not re-litigate either half.

- [ ] **Step 2: Write the failing boundary test**

Create `tests/Architecture/CommerceBoundaryTest.php`:

```php
<?php
/**
 * Keeps commerce block markup inside its sanctioned files.
 *
 * The symbol-level rule (WooCommerceIsolationTest) only scans PHP, so it
 * cannot see block markup in *.html. This test is the block-theme half of the
 * same boundary: commerce blocks may appear ONLY in the commerce templates
 * named by tests/Architecture/commerce-template-list.php, never in a base
 * template, never in a template part, never in a theme pattern. It also pins
 * the retirement of the classic override directory: a block theme's only
 * override surfaces are templates/*.html and the commerce plugin's hooks.
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\FormatsArchitectureFailures;

require_once dirname( __DIR__ ) . '/support/FormatsArchitectureFailures.php';

final class CommerceBoundaryTest extends TestCase {

	use FormatsArchitectureFailures;

	private const COMMERCE_BLOCK_PATTERN = '/<!--\s+\/?wp:woocommerce\//';

	public function test_classic_override_directory_is_gone(): void {
		self::assertDirectoryDoesNotExist(
			$this->theme_root() . '/woocommerce',
			$this->architecture_failure(
				'The classic commerce template override directory is back',
				'web/app/themes/site-theme/woocommerce',
				'A block theme has one rendering path. Classic PHP template overrides would reintroduce the second path the migration removed.',
				'Override through templates/<slug>.html or a commerce hook in site-commerce instead; see docs/adding-commerce-behaviour.md.'
			)
		);
	}

	public function test_every_declared_commerce_template_exists(): void {
		foreach ( $this->declared()['templates'] as $slug ) {
			self::assertFileExists(
				$this->theme_root() . '/templates/' . $slug . '.html',
				$this->architecture_failure(
					'A declared commerce template is missing',
					$slug,
					'The declared set is the contract the commerce suites and the storefront depend on.',
					'Add templates/' . $slug . '.html, or remove the slug from tests/Architecture/commerce-template-list.php.'
				)
			);
		}
	}

	public function test_commerce_blocks_only_appear_in_declared_commerce_templates(): void {
		$declared   = $this->declared()['templates'];
		$violations = array();

		foreach ( $this->html_files( $this->theme_root() . '/templates' ) as $file ) {
			$slug = basename( $file, '.html' );

			if ( in_array( $slug, $declared, true ) ) {
				continue;
			}

			if ( 1 === preg_match( self::COMMERCE_BLOCK_PATTERN, (string) file_get_contents( $file ) ) ) {
				$violations[] = 'templates/' . $slug . '.html';
			}
		}

		self::assertSame( array(), $violations, $this->boundary_failure( $violations ) );
	}

	public function test_parts_and_patterns_carry_no_commerce_blocks(): void {
		$violations = array();

		foreach ( array( '/parts', '/patterns' ) as $relative ) {
			foreach ( $this->all_files( $this->theme_root() . $relative ) as $file ) {
				if ( 1 === preg_match( self::COMMERCE_BLOCK_PATTERN, (string) file_get_contents( $file ) ) ) {
					$violations[] = $relative . '/' . basename( $file );
				}
			}
		}

		self::assertSame( array(), $violations, $this->boundary_failure( $violations ) );
	}

	public function test_declared_commerce_templates_render_the_theme_chrome(): void {
		$missing = array();

		foreach ( $this->declared()['templates'] as $slug ) {
			$path = $this->theme_root() . '/templates/' . $slug . '.html';

			if ( ! is_file( $path ) ) {
				continue;
			}

			$content = (string) file_get_contents( $path );

			foreach ( array( 'site-header', 'site-footer' ) as $part ) {
				if ( ! str_contains( $content, '"slug":"' . $part . '"' ) ) {
					$missing[] = $slug . ' -> ' . $part;
				}
			}

			if ( preg_match( '/<!--\s+wp:template-part\s+\{[^}]*"theme":/', $content ) === 1 ) {
				$missing[] = $slug . ' -> environment-specific theme attribute';
			}
		}

		self::assertSame(
			array(),
			$missing,
			$this->architecture_failure(
				'A commerce template does not render the theme chrome',
				implode( "\n                          ", $missing ),
				'Rendering the theme header/footer parts is the ONLY reason these overrides exist; a template without them is worse than no override at all.',
				'Re-derive the file from the upstream template, rewrite only the header/footer template-part slugs, and remove environment-specific template-part theme attributes.'
			)
		);
	}

	/**
	 * @param list<string> $violations
	 */
	private function boundary_failure( array $violations ): string {
		return $this->architecture_failure(
			'Commerce block markup outside the declared commerce templates',
			implode( "\n                          ", $violations ),
			'The base profile must run with no commerce plugin installed; a commerce block in a base template, part, or pattern renders as a broken block there.',
			'Move the markup into a declared commerce template, or register it as a pattern from web/app/plugins/site-commerce/.'
		);
	}

	/**
	 * @return array{templates: list<string>, excluded: array<string, string>}
	 */
	private function declared(): array {
		/** @var array{templates: list<string>, excluded: array<string, string>} $declared */
		$declared = require __DIR__ . '/commerce-template-list.php';

		return $declared;
	}

	private function theme_root(): string {
		return $this->repo_root() . '/web/app/themes/site-theme';
	}

	/**
	 * @return list<string>
	 */
	private function html_files( string $dir ): array {
		return $this->files_with_extension( $dir, 'html' );
	}

	/**
	 * @return list<string>
	 */
	private function all_files( string $dir ): array {
		return $this->files_with_extension( $dir, null );
	}

	/**
	 * @return list<string>
	 */
	private function files_with_extension( string $dir, ?string $extension ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files = array();

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $item ) {
			if ( ! $item instanceof \SplFileInfo || ! $item->isFile() ) {
				continue;
			}

			if ( null !== $extension && strtolower( $item->getExtension() ) !== $extension ) {
				continue;
			}

			$files[] = $item->getPathname();
		}

		sort( $files );

		return $files;
	}
}
```

Two mandatory adjustments before this file will pass the gates:

1. Check `tests/support/FormatsArchitectureFailures.php` for the exact trait method names (`architecture_failure`, `repo_root`, `to_relative`) and signatures, and match the calls above to it — the trait is shared and must not be edited.
2. `phpcs` scans `tests/`, and `WordPress.WP.AlternativeFunctions` flags every `file_get_contents()` call. Add the same style of inline suppression the repository already uses in `tests/support/ArchitectureScanner.php`, on each call:

```php
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- pure static analysis of local source files; this test must stay WordPress-free.
```

Run `ddev composer lint:php` and fix anything else WPCS reports (Yoda conditions, `array()` long syntax, spacing) before moving on.

- [ ] **Step 3: Run it and watch it fail for the right reasons**

```bash
ddev composer test:architecture
```

Expected: `test_classic_override_directory_is_gone` FAILS (the directory still exists) and `test_every_declared_commerce_template_exists` FAILS (no commerce templates yet). The other three pass. **Do not commit in this state** — Steps 4–6 make it green.

- [ ] **Step 4: Derive the templates from upstream** *(commerce profile — active since Task A1)*

The derivation runs on the host, so it uses the **repository-relative** `WC_TEMPLATE_DIR_REL` recorded in Task A1 Step 7, never a container path from `ddev wp eval`. Paste that recorded value into the first line:

```bash
WC_TPL="<the WC_TEMPLATE_DIR_REL value recorded in the ground-truth file, e.g. web/app/plugins/woocommerce/templates>"
THEME_TPL="web/app/themes/site-theme/templates"

test -d "${WC_TPL}" || { echo "FAILED: ${WC_TPL} does not exist on the host — re-run Task A1 Step 7"; exit 1; }
test -f "${WC_TPL}/archive-product.html" || { echo "FAILED: ${WC_TPL} is not the block-template directory"; exit 1; }

for slug in $(php -r '$d = require "tests/Architecture/commerce-template-list.php"; echo implode(" ", $d["templates"]);'); do
  src="${WC_TPL}/${slug}.html"
  if [ ! -f "${src}" ]; then
    echo "MISSING UPSTREAM: ${slug}" >&2
    continue
  fi
  sed -e 's/"slug":"header"/"slug":"site-header"/g' \
      -e 's/"slug":"footer"/"slug":"site-footer"/g' \
      -e 's/,"theme":"[^"]*"//g' \
      -e 's/"theme":"[^"]*",//g' \
      "${src}" > "${THEME_TPL}/${slug}.html"
  echo "derived ${slug}"
done
```

If any slug reports `MISSING UPSTREAM`, remove it from `commerce-template-list.php`'s `templates` array and record the reason in the ground-truth file — the override was not justified after all.

- [ ] **Step 5: Review every derived file by hand**

```bash
for slug in $(php -r '$d = require "tests/Architecture/commerce-template-list.php"; echo implode(" ", $d["templates"]);'); do
  echo "=== ${slug}"; cat "web/app/themes/site-theme/templates/${slug}.html"
done
```

For each derived commerce template, confirm and fix:

1. Exactly one `wp:template-part` with `"slug":"site-header"` and one with `"slug":"site-footer"`. Leave any `"tagName":"header"` / `"tagName":"footer"` attribute alone — that is the semantic HTML element, not the part slug.
2. No `"theme":"…"` attribute on a `wp:template-part` block. The derivation command removes it so the part resolves against the active theme. Do not make another content change.
3. The file ends with a single trailing newline and uses LF line endings (`file <path>` must not report CRLF).
4. No hard-coded database IDs (`"ref":`, `"id":` on navigation/pattern blocks) — spec §11.1. If upstream ships one, do not edit it out. Mark that slug **SKIP**, record the exact reason in the ground-truth file, and remove it from the declared template list. This keeps the allowed transformation set closed.

Then confirm the substitution left nothing behind:

```bash
grep -l '"slug":"header"\|"slug":"footer"' web/app/themes/site-theme/templates/*.html && echo "FAIL: an unrewritten part slug survived" || echo "OK: no unrewritten part slugs"
```

- [ ] **Step 6: Delete the classic override directory**

```bash
git rm web/app/themes/site-theme/woocommerce/README.md
rmdir web/app/themes/site-theme/woocommerce 2>/dev/null || true
ls web/app/themes/site-theme/
```

- [ ] **Step 7: Move the policy into the commerce documentation**

In `docs/adding-commerce-behaviour.md`, replace section **3. Hooks first, template overrides last** in full with:

```markdown
## 3. Hooks first, block templates second, classic overrides never

Prefer a `woocommerce_*` hook over any template change — a hook keeps tracking
upstream changes; an override silently stops.

When markup really must change, the block theme has exactly one override
surface: a block template at
`web/app/themes/site-theme/templates/<slug>.html`. The theme ships one per
commerce request type (product, product archive, product taxonomies, product
search, cart, checkout, order confirmation), and each is a **derived copy of
the template WooCommerce ships for that slug with only the header/footer
template-part slugs rewritten** to this theme's `site-header` / `site-footer`
and any environment-specific template-part `theme` attribute removed.
That missing-part rewrite is the whole justification for the override: WooCommerce's
templates reference `header` / `footer` parts this theme does not have, so
without it the storefront renders with no header and no footer.

Rules when you change one:

1. Keep the divergence minimal and reviewable — inherit as much upstream
   composition as you can.
2. Keep both `wp:template-part` blocks (`site-header`, `site-footer`).
   `CommerceBoundaryTest` fails if either disappears.
3. Re-derive from upstream after a major WooCommerce upgrade, then re-apply
   your divergence. `CommerceBlockTemplatesTest` fails when WooCommerce starts
   shipping a template slug that is neither owned nor deliberately excluded in
   `tests/Architecture/commerce-template-list.php`.
4. Commerce block markup lives ONLY in those declared templates. A commerce
   block in a base template, a template part, or a theme pattern renders as a
   broken block on any site without WooCommerce — register it as a pattern
   from `site-commerce` instead (`SiteCommerce\Theme\CommercePatterns`).

**The classic `web/app/themes/site-theme/woocommerce/` PHP override directory
is gone.** It held zero overrides, and a block theme has one rendering path
(spec §4); a classic template override would reintroduce the second one. If a
future project proves it needs a classic override, that is an ADR-level
decision, not a habit.

### Styling

Commerce blocks are core-block-supports based, so `theme.json`'s palette,
typography, and spacing presets already reach them. The starter therefore ships
**no commerce CSS at all** and adds **no commerce keys to `theme.json`** — the
base profile carries no commerce vocabulary. Prefer Global Styles and block
style variations over CSS; never write CSS that depends on private nested
commerce block markup, because it breaks on every upstream release.
```

In section **4. Isolation rules**, remove the `web/app/themes/site-theme/woocommerce/` bullet from the allowed-locations list and add a sentence after the list:

```markdown
`WooCommerceIsolationTest` scans PHP only. The block-markup half of the same
boundary is `tests/Architecture/CommerceBoundaryTest`, which pins which
`*.html` files may contain commerce blocks.
```

- [ ] **Step 8: Fix the routing table line in AGENTS.md**

In `AGENTS.md`, in the "Routing table" section, replace:

```
WooCommerce markup override → `site-theme/woocommerce/`
```

with:

```
WooCommerce markup override → `site-theme/templates/<commerce-slug>.html` (block template; the classic `woocommerce/` directory is retired)
```

Also replace the general pattern line with two explicit routes:

```text
Base-profile composition of blocks → pattern (`site-theme/patterns/`)
Commerce-profile composition of blocks → registered pattern (`site-commerce/patterns/`)
```

- [ ] **Step 9: Run the architecture suite and confirm it is GREEN**

```bash
ddev composer test:architecture
```

Expected: every `CommerceBoundaryTest` assertion passes — the directory is gone and every declared template exists with both theme-chrome parts. If any Task 1-owned test now fails (for example a structure test that enumerates template filenames — precondition **P1**), STOP and escalate; do not edit that test.

- [ ] **Step 10: Run `SWITCH TO BASE`, verify, and commit**

The commerce profile is still active from Task A1, so `composer.json`/`composer.lock` carry the ephemeral WooCommerce require. `verify:fast` runs `composer validate --strict` and `composer audit` and must not see it, and it must never be committed.

```bash
# SWITCH TO BASE (see "Profile switch procedures") — all five assertions must pass.
ddev composer verify:fast
git status --porcelain
```

Expected: `verify:fast` GREEN. `git status --porcelain` lists exactly: the deleted `web/app/themes/site-theme/woocommerce/README.md`, the new commerce templates, the two new `tests/Architecture/` files, `docs/adding-commerce-behaviour.md`, and `AGENTS.md`. Nothing else — in particular no `composer.json` / `composer.lock`.

```bash
git add tests/Architecture/commerce-template-list.php tests/Architecture/CommerceBoundaryTest.php \
        web/app/themes/site-theme/templates docs/adding-commerce-behaviour.md AGENTS.md
git add -u web/app/themes/site-theme/woocommerce
git diff --cached --check
ddev composer verify:fast
git commit -m "feat(commerce): add commerce block templates and retire the classic override directory"
git status --porcelain
```

Expected: empty output after the commit.

---

### Task A4: Prove the commerce templates in the commerce profile

Task A3 proved the templates exist and are shaped correctly using static analysis only. This task proves the things only a running WordPress + WooCommerce can prove: every block they name is actually registered, every part they reference resolves, the theme's file wins over the plugin's default, and no upstream template slug is unaccounted for in either direction. Requires precondition **P2** satisfied.

**Files:**
- Create: `tests/commerce/Integration/Theme/CommerceBlockTemplatesTest.php`
- Modify (only if the test exposes a defect): `web/app/themes/site-theme/templates/<slug>.html`, `tests/Architecture/commerce-template-list.php`

**Interfaces:**
- Consumes: `tests/Architecture/commerce-template-list.php` and the derived templates (Task A3); `WC_TEMPLATE_DIR_REL` from Task A1's ground-truth file.
- Produces: the commerce-profile gate the storefront journeys rely on.

- [ ] **Step 0: Run `SWITCH TO COMMERCE`**

Task A3 ended on the base profile. Run the procedure in full before writing the test.

- [ ] **Step 1: Write the commerce-profile test**

Create `tests/commerce/Integration/Theme/CommerceBlockTemplatesTest.php`:

```php
<?php
/**
 * Proves the theme's commerce block templates are real, resolvable block
 * templates in the commerce profile: they parse, every block they name is
 * registered, every template part they reference exists, and WordPress
 * resolves each slug to the THEME's file rather than the plugin's default.
 *
 * Runs only in the `commerce-integration` suite, which is the only place
 * WooCommerce is loaded.
 *
 * @package Tests\Commerce\Integration
 */

declare(strict_types=1);

namespace Tests\Commerce\Integration\Theme;

use Tests\Integration\IntegrationTestCase;

final class CommerceBlockTemplatesTest extends IntegrationTestCase {

	/**
	 * @return array{templates: list<string>, excluded: array<string, string>}
	 */
	private function declared(): array {
		/** @var array{templates: list<string>, excluded: array<string, string>} $declared */
		$declared = require dirname( __DIR__, 3 ) . '/Architecture/commerce-template-list.php';

		return $declared;
	}

	private function theme_templates_dir(): string {
		return dirname( __DIR__, 4 ) . '/web/app/themes/site-theme/templates';
	}

	/**
	 * Every block name a template names, flattened through inner blocks.
	 *
	 * @param list<array<string, mixed>> $blocks
	 * @return list<string>
	 */
	private function block_names( array $blocks ): array {
		$names = array();

		foreach ( $blocks as $block ) {
			if ( is_string( $block['blockName'] ) && '' !== $block['blockName'] ) {
				$names[] = $block['blockName'];
			}

			if ( is_array( $block['innerBlocks'] ) && array() !== $block['innerBlocks'] ) {
				$names = array_merge( $names, $this->block_names( $block['innerBlocks'] ) );
			}
		}

		return array_values( array_unique( $names ) );
	}

	public function test_every_declared_template_parses_into_named_blocks(): void {
		foreach ( $this->declared()['templates'] as $slug ) {
			$path = $this->theme_templates_dir() . '/' . $slug . '.html';

			self::assertFileExists( $path );

			$blocks = parse_blocks( (string) file_get_contents( $path ) );
			$names  = $this->block_names( $blocks );

			self::assertNotSame( array(), $names, "templates/{$slug}.html must contain block markup." );
		}
	}

	public function test_every_referenced_block_is_registered_in_the_commerce_profile(): void {
		$registry    = \WP_Block_Type_Registry::get_instance();
		$unregistered = array();

		foreach ( $this->declared()['templates'] as $slug ) {
			$blocks = parse_blocks( (string) file_get_contents( $this->theme_templates_dir() . '/' . $slug . '.html' ) );

			foreach ( $this->block_names( $blocks ) as $name ) {
				if ( null === $registry->get_registered( $name ) ) {
					$unregistered[] = $slug . ' -> ' . $name;
				}
			}
		}

		self::assertSame(
			array(),
			$unregistered,
			"A commerce template names a block that is not registered even with the commerce plugin active:\n" . implode( "\n", $unregistered )
		);
	}

	public function test_every_referenced_template_part_file_exists(): void {
		$missing = array();

		foreach ( $this->declared()['templates'] as $slug ) {
			$content = (string) file_get_contents( $this->theme_templates_dir() . '/' . $slug . '.html' );

			preg_match_all( '/wp:template-part\s+\{[^}]*"slug":"([^"]+)"/', $content, $matches );

			foreach ( $matches[1] as $part_slug ) {
				$part_path = dirname( $this->theme_templates_dir() ) . '/parts/' . $part_slug . '.html';

				if ( ! is_file( $part_path ) ) {
					$missing[] = $slug . ' -> parts/' . $part_slug . '.html';
				}
			}
		}

		self::assertSame(
			array(),
			$missing,
			"A commerce template references a template part that does not exist:\n" . implode( "\n", $missing )
		);
	}

	public function test_the_theme_template_wins_over_the_plugin_default(): void {
		self::assertNotSame( array(), $this->declared()['templates'], 'The declared list must not be empty, or this check passes vacuously.' );

		foreach ( $this->declared()['templates'] as $slug ) {
			$template = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template' );

			self::assertNotNull( $template, "WordPress must resolve a block template for '{$slug}'." );
			self::assertSame(
				'theme',
				$template->source,
				"The theme's templates/{$slug}.html must win over the commerce plugin's default template; source is still '{$template->source}'."
			);
			self::assertNull(
				$template->origin,
				"A theme-owned template has a null origin; '{$slug}' still reports origin '" . var_export( $template->origin, true ) . "'."
			);
		}
	}

	public function test_no_upstream_commerce_template_slug_is_unaccounted_for(): void {
		$declared    = $this->declared();
		$upstream    = $this->upstream_slugs();
		$unaccounted = array();

		foreach ( $upstream as $slug ) {
			if ( in_array( $slug, $declared['templates'], true ) ) {
				continue;
			}

			if ( array_key_exists( $slug, $declared['excluded'] ) ) {
				continue;
			}

			$unaccounted[] = $slug;
		}

		self::assertSame(
			array(),
			$unaccounted,
			"The commerce plugin ships block template slugs this theme has never decided about:\n"
			. implode( "\n", $unaccounted )
			. "\nAdd each to tests/Architecture/commerce-template-list.php — either as an owned template or as an excluded slug with a reason."
		);
	}

	/**
	 * The other direction: a slug this theme claims to override must still exist
	 * upstream. When the commerce plugin stops shipping a template, the theme's
	 * override becomes an unjustified fork of a template nothing renders — and
	 * the test above cannot see that, because it only walks upstream slugs.
	 */
	public function test_every_owned_slug_still_exists_upstream(): void {
		$upstream = $this->upstream_slugs();
		$orphans  = array();

		foreach ( $this->declared()['templates'] as $slug ) {
			if ( ! in_array( $slug, $upstream, true ) ) {
				$orphans[] = $slug;
			}
		}

		self::assertSame(
			array(),
			$orphans,
			"This theme overrides commerce template slugs the plugin no longer ships:\n"
			. implode( "\n", $orphans )
			. "\nDelete the override and its entry in tests/Architecture/commerce-template-list.php, or record why the fork is still wanted."
		);
	}

	/**
	 * Slugs the commerce plugin ships block templates for, read from ITS OWN
	 * template directory rather than from get_block_templates().
	 *
	 * get_block_templates() is the wrong source here twice over: it hides any
	 * plugin template the theme already overrides (so an owned slug could never
	 * be checked against upstream), and it includes templates registered by
	 * other plugins entirely. The directory is located by finding the file that
	 * every version of the plugin ships, so a plugin that relocates its
	 * templates fails loudly instead of emptying this check.
	 *
	 * @return list<string>
	 */
	private function upstream_slugs(): array {
		$directory = $this->upstream_template_dir();
		$slugs     = array();

		foreach ( (array) glob( $directory . '/*.html' ) as $file ) {
			if ( is_string( $file ) ) {
				$slugs[] = basename( $file, '.html' );
			}
		}

		sort( $slugs );

		self::assertNotSame(
			array(),
			$slugs,
			"No upstream block templates were found in {$directory}; this check must never pass vacuously."
		);

		return array_values( array_unique( $slugs ) );
	}

	private function upstream_template_dir(): string {
		$plugin_root = dirname( __DIR__, 4 ) . '/web/app/plugins/woocommerce';
		$anchor      = 'archive-product.html';

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $plugin_root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( $item instanceof \SplFileInfo && $item->isFile() && $anchor === $item->getFilename() ) {
				return $item->getPath();
			}
		}

		self::fail(
			"Could not locate {$anchor} under {$plugin_root}. The commerce plugin has moved its block templates; "
			. 'update this resolver and re-derive the theme overrides.'
		);
	}
}
```

The same two constraints as Task A3 Step 2 apply: `phpcs` scans this file, so every `file_get_contents()` call needs the inline `WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents` suppression with a reason, and `ddev composer lint:php` must be clean before the task ends.

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) —
> `test_the_theme_template_wins_over_the_plugin_default()` was VACUOUS. It is the
> only test in the whole unit that proves an override actually takes effect, and
> as written it passed with NO override present. Fixed in the code above.**
>
> The original asserted `assertSame( get_stylesheet(), $template->theme, … )`.
> `$template->theme` is filled with the ACTIVE STYLESHEET for plugin-provided
> templates too, so it equals `site-theme` whether or not the theme owns the file.
>
> **Proven by execution on 2026-08-08.** With `archive-product.html` derived into
> the theme and `single-product.html` deliberately left to the plugin:
>
> ```
> archive-product | origin=NULL     | source=theme  | theme=site-theme | theme===stylesheet? YES
> single-product  | origin='plugin' | source=plugin | theme=site-theme | theme===stylesheet? YES
> ```
>
> The original assertion answers YES in both rows. `source` and `origin` are the
> fields that discriminate: a theme-owned template is `source='theme'` with a NULL
> origin, and a plugin default is `source='plugin'` with `origin='plugin'`. Both
> are now asserted.
>
> An empty-list guard was added for the same reason the `upstream_slugs()` guard
> exists: a `foreach` over an accidentally empty declared list passes without
> asserting anything.
>
> Before the derivation, all eight upstream slugs resolve as
> `origin=plugin | source=plugin`, which is Task A1 Step 8's "before" reading.
> That is the state this test must flip for the six declared slugs.

- [ ] **Step 2: Run it** *(commerce profile)*

```bash
ddev composer test:integration:commerce
```

Every failure here is a real defect in Task A3's derivation, and each has exactly one correct fix:

| Failure | Fix |
|---|---|
| `test_every_referenced_block_is_registered_in_the_commerce_profile` | The derived file names a block the installed plugin does not register. Re-derive that slug from upstream — do not delete the block reference. If it persists, precondition **P2** may be unmet; check Task 1's integration template test too. |
| `test_every_referenced_template_part_file_exists` | The header/footer slug rewrite missed an occurrence, or upstream references a third part. Fix the template. |
| `test_the_theme_template_wins_over_the_plugin_default` | A stray `"theme":"…"` attribute or a wrong filename. Fix the template. |
| `test_no_upstream_commerce_template_slug_is_unaccounted_for` | Add the slug to `commerce-template-list.php` as owned (and derive it) or as excluded with a reason. |
| `test_every_owned_slug_still_exists_upstream` | Remove the unjustified override and its list entry. |

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — `excluded`
> is NOT empty, and two of these tests would fail if it were.**
>
> Per the Task A3 Step 1 correction, WooCommerce 11.0.0 ships eight block
> template slugs and this theme owns six. The other two must appear in
> `excluded`, or `test_no_upstream_commerce_template_slug_is_unaccounted_for`
> fails with `coming-soon` and `page-checkout` listed as undecided:
>
> - `coming-soon` — shipped, references no template part, nothing to rewrite.
> - `page-checkout` — references `checkout-header`, which the plugin ships and
>   scopes to itself; deriving it would break the reference AND turn the base
>   architecture suite red. Proven by execution; see the Task A3 correction.
>
> `taxonomy-product_cat` and `taxonomy-product_tag` need NO entry in either
> array: `excluded` exists for slugs the plugin DOES ship, and it ships neither.
> Adding them would make `test_no_upstream_commerce_template_slug_is_unaccounted_for`
> pass just the same (it only walks upstream slugs) but would misdescribe the
> repository. Record them as SKIP in the ground-truth file instead.
>
> One further point about `upstream_slugs()`: its
> `self::assertNotSame( array(), $slugs, … )` guard is the right shape and must be
> kept. Without it, a plugin that relocated its templates would empty the list and
> make BOTH direction checks pass vacuously — the dominant defect family of this
> engagement.

- [ ] **Step 3: Confirm the storefront actually renders the theme chrome** *(commerce profile)*

```bash
ddev wp rewrite flush --hard
curl -sk "$(ddev wp option get home)/shop/" | grep -c "<header" || true
curl -sk "$(ddev wp option get home)/product/test-simple-product/" | grep -c "<footer" || true
```

Expected: non-zero counts. Open both URLs in a browser once and confirm the header and footer render. This is the observable proof the overrides were justified — without them these pages render WooCommerce's own template, whose `header`/`footer` parts this theme does not have.

- [ ] **Step 4: Run `SWITCH TO BASE`, verify, and commit**

```bash
# SWITCH TO BASE (see "Profile switch procedures") — all five assertions must pass.
ddev composer verify:fast
git status --porcelain
```

Expected: `verify:fast` GREEN; the status lists only the new test file plus any template/list fix Step 2 required.

```bash
git add tests/commerce/Integration/Theme/CommerceBlockTemplatesTest.php web/app/themes/site-theme/templates tests/Architecture/commerce-template-list.php
git diff --cached --check
ddev composer verify:fast
git commit -m "test(commerce): prove the commerce block templates resolve in the commerce profile"
git status --porcelain
```

Expected: empty output after the commit.

---

### Task A5: Commerce patterns and the Mini-Cart composition

**Files:**
- Create: `web/app/plugins/site-commerce/src/Theme/CommercePatterns.php`
- Modify: `web/app/plugins/site-commerce/src/Plugin.php` (one line in the `$providers` array)
- Create: `tests/commerce/Integration/Theme/CommercePatternsTest.php`
- Modify: `tests/commerce/Integration/SiteCommerce/PluginBootTest.php`

**Interfaces:**
- Consumes: `MINI_CART_BLOCK` from Task A1 Step 7b; the derived `web/app/themes/site-theme/templates/archive-product.html` from Task A3.
- Produces: `SiteCommerce\Theme\CommercePatterns` with public constants `CATEGORY = 'site-commerce'`, `PATTERN_HEADER_MINI_CART = 'site-commerce/header-mini-cart'`, `PATTERN_PRODUCT_GRID = 'site-commerce/product-grid'`, and the public static hook callback `register_patterns(): void`. Task A6's `scripts/enable-commerce` and Task A7's E2E reference the header pattern's composition.

**Pattern content is generated, never typed.** Both pattern bodies come from files this repository already has: the Mini-Cart block name is the value Task A1 Step 7b discovered, and the product-grid body is the derived `archive-product.html` with its two template-part blocks removed — which is, by construction, exactly the upstream product-listing composition. Step 4 below is a single generation command that substitutes both and refuses to write anything if either input is missing.

- [ ] **Step 0: Run `SWITCH TO COMMERCE`**

Task A4 ended on the base profile. Run the procedure in full.

- [ ] **Step 1: Re-confirm the Mini-Cart block name against the running store**

```bash
ddev wp eval '
$names = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
$hits  = array_values( array_filter( $names, static fn( $n ) => str_contains( $n, "mini-cart" ) && ! str_contains( $n, "-contents" ) ) );
sort( $hits );
echo implode( "\n", $hits ), "\n";'
```

Expected: one name, identical to the `MINI_CART_BLOCK` recorded in Task A1 Step 7b. If it differs, the store changed underneath the plan — update the ground-truth file first, then continue with the new value.

- [ ] **Step 2: Write the pattern test**

Create `tests/commerce/Integration/Theme/CommercePatternsTest.php`:

```php
<?php
/**
 * Proves site-commerce registers its block patterns (and their category) in the
 * commerce profile. Patterns are the sanctioned way to bring commerce blocks
 * into the theme's header and page compositions WITHOUT putting commerce
 * markup into the base theme's Git tree (CommerceBoundaryTest forbids that).
 *
 * @package Tests\Commerce\Integration
 */

declare(strict_types=1);

namespace Tests\Commerce\Integration\Theme;

use SiteCommerce\Theme\CommercePatterns;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \SiteCommerce\Theme\CommercePatterns
 */
final class CommercePatternsTest extends IntegrationTestCase {

	public function test_the_commerce_pattern_category_is_registered(): void {
		$categories = \WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered();

		self::assertArrayHasKey(
			CommercePatterns::CATEGORY,
			array_column( $categories, null, 'name' ),
			'The commerce pattern category must be registered so the patterns are findable in the inserter.'
		);
	}

	public function test_both_commerce_patterns_are_registered(): void {
		$patterns = array_column(
			\WP_Block_Patterns_Registry::get_instance()->get_all_registered(),
			null,
			'name'
		);

		foreach ( array( CommercePatterns::PATTERN_HEADER_MINI_CART, CommercePatterns::PATTERN_PRODUCT_GRID ) as $name ) {
			self::assertArrayHasKey( $name, $patterns, "The '{$name}' pattern must be registered when the commerce profile boots." );
			self::assertNotSame( '', trim( (string) $patterns[ $name ]['content'] ), "The '{$name}' pattern must have content." );
		}
	}

	public function test_every_pattern_block_is_registered(): void {
		$patterns   = array_column( \WP_Block_Patterns_Registry::get_instance()->get_all_registered(), null, 'name' );
		$registry   = \WP_Block_Type_Registry::get_instance();
		$unresolved = array();

		foreach ( array( CommercePatterns::PATTERN_HEADER_MINI_CART, CommercePatterns::PATTERN_PRODUCT_GRID ) as $name ) {
			// Recursive, not top-level: the Mini-Cart sits INSIDE a Group, and
			// the product grid nests a product template inside a query block. A
			// top-level-only walk would assert almost nothing.
			foreach ( $this->block_names( parse_blocks( (string) $patterns[ $name ]['content'] ) ) as $block_name ) {
				if ( null === $registry->get_registered( $block_name ) ) {
					$unresolved[] = $name . ' -> ' . $block_name;
				}
			}
		}

		self::assertSame( array(), $unresolved, implode( "\n", $unresolved ) );
	}

	public function test_the_header_pattern_actually_contains_the_mini_cart(): void {
		$patterns = array_column( \WP_Block_Patterns_Registry::get_instance()->get_all_registered(), null, 'name' );
		$names    = $this->block_names( parse_blocks( (string) $patterns[ CommercePatterns::PATTERN_HEADER_MINI_CART ]['content'] ) );

		$mini_cart = array_values(
			array_filter(
				$names,
				static fn( string $name ): bool => str_contains( $name, 'mini-cart' )
			)
		);

		self::assertNotSame(
			array(),
			$mini_cart,
			'The header pattern exists to place the Mini-Cart; without it the pattern is pointless.'
		);
	}

	/**
	 * Every block name in a parsed tree, flattened through inner blocks. Same
	 * traversal CommerceBlockTemplatesTest uses.
	 *
	 * @param list<array<string, mixed>> $blocks
	 * @return list<string>
	 */
	private function block_names( array $blocks ): array {
		$names = array();

		foreach ( $blocks as $block ) {
			if ( is_string( $block['blockName'] ) && '' !== $block['blockName'] ) {
				$names[] = $block['blockName'];
			}

			if ( is_array( $block['innerBlocks'] ) && array() !== $block['innerBlocks'] ) {
				$names = array_merge( $names, $this->block_names( $block['innerBlocks'] ) );
			}
		}

		return array_values( array_unique( $names ) );
	}
}
```

- [ ] **Step 3: Run it and confirm it fails**

```bash
ddev composer test:integration:commerce
```

Expected: FAIL — `SiteCommerce\Theme\CommercePatterns` does not exist.

- [ ] **Step 4: Generate the pattern-content files**

The two pattern bodies are generated from real inputs, so no value is ever typed from memory and the command refuses to produce a file it cannot fill. Both land in `web/app/plugins/site-commerce/patterns/` as plain `.html` block markup that the provider reads at registration time — keeping WooCommerce block names out of PHP string literals and making the content reviewable as markup.

```bash
set -e
PATTERN_DIR="web/app/plugins/site-commerce/patterns"
ARCHIVE_TPL="web/app/themes/site-theme/templates/archive-product.html"
mkdir -p "${PATTERN_DIR}"

# 1. Mini-Cart block name — discovered, never typed. Fails if it is not unique.
#    The filter selects the PARENT block: a name that ENDS in /mini-cart. The
#    inner blocks are named woocommerce/mini-cart-*-block, so a substring match
#    returns ten names and the uniqueness check below aborts. See the CORRECTION
#    under this step.
MINI_CART_BLOCK="$(ddev wp eval '
$names = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
$hits  = array_values( array_filter( $names, static fn( $n ) => 1 === preg_match( "#/mini-cart$#", $n ) ) );
sort( $hits );
echo implode( "\n", $hits );' | tr -d '\r')"

test "$(printf '%s\n' "${MINI_CART_BLOCK}" | wc -l)" -eq 1 \
  || { echo "FAILED: expected exactly one mini-cart block name, got: ${MINI_CART_BLOCK}"; exit 1; }

printf '<!-- wp:group {"layout":{"type":"flex","justifyContent":"right"}} -->\n<div class="wp-block-group"><!-- wp:%s /--></div>\n<!-- /wp:group -->\n' \
  "${MINI_CART_BLOCK}" > "${PATTERN_DIR}/header-mini-cart.html"

# 2. Product grid — the derived archive template minus its two template parts,
#    which is exactly the upstream product-listing composition.
test -f "${ARCHIVE_TPL}" || { echo "FAILED: ${ARCHIVE_TPL} is missing; run Task A3 first"; exit 1; }
grep -v 'wp:template-part' "${ARCHIVE_TPL}" > "${PATTERN_DIR}/product-grid.html"

# 3. Neither file may be empty, and neither may still contain a template part.
for f in "${PATTERN_DIR}/header-mini-cart.html" "${PATTERN_DIR}/product-grid.html"; do
  test -s "${f}" || { echo "FAILED: ${f} is empty"; exit 1; }
  grep -q 'wp:template-part' "${f}" && { echo "FAILED: ${f} still references a template part"; exit 1; }
  echo "=== ${f}"; cat "${f}"
done
```

Read both generated files. `product-grid.html` must still be well-formed block markup after the `grep -v` — if upstream wrapped the listing in a group whose opening or closing comment shared a line with a template part, hand-fix the file now and note it in the ground-truth file.

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — the
> Mini-Cart discovery filter was wrong and this generation command ABORTED BY
> CONSTRUCTION. Fixed in the code above.**
>
> The original filter was
> `str_contains( $n, "mini-cart" ) && ! str_contains( $n, "-contents" )`. Against
> WooCommerce 11.0.0 that returns TEN names, because the inner blocks are suffixed
> `-block`, not `-contents`:
>
> ```
> woocommerce/mini-cart
> woocommerce/mini-cart-cart-button-block
> woocommerce/mini-cart-checkout-button-block
> woocommerce/mini-cart-footer-block
> woocommerce/mini-cart-items-block
> woocommerce/mini-cart-products-table-block
> woocommerce/mini-cart-shopping-button-block
> woocommerce/mini-cart-title-block
> woocommerce/mini-cart-title-items-counter-block
> woocommerce/mini-cart-title-label-block
> ```
>
> The very next line, `test "$(… | wc -l)" -eq 1 || { echo "FAILED: expected
> exactly one mini-cart block name…"; exit 1; }`, would then abort the whole step.
> Task A1 Step 7b's prose hedges for this ("If the command prints more than one
> line … pick the parent block — the one whose name has no further path segment
> after `mini-cart`"), but Step 4's command has no such tolerance.
>
> The corrected filter anchors on `#/mini-cart$#`, which implements exactly the
> rule A1 Step 7b describes and returns exactly one name:
> **`woocommerce/mini-cart`**. Apply the same anchored filter in Task A1 Step 7b
> so both steps agree.
>
> Keep the `-eq 1` uniqueness check. It is the right guard; only the filter feeding
> it was wrong.
>
> **This is the block NAME, not the Mini-Cart button's accessible name.** Task A7's
> `miniCart` Playwright locator needs the latter, which is a separate fact to read
> off the live store and record in the ground-truth file.

- [ ] **Step 5: Implement the provider**

Create `web/app/plugins/site-commerce/src/Theme/CommercePatterns.php` exactly as below. It contains no WooCommerce block name of its own — the markup comes from the two generated files:

```php
<?php

declare(strict_types=1);

namespace SiteCommerce\Theme;

/**
 * Registers the commerce profile's block patterns.
 *
 * The base theme's templates, parts, and patterns stay commerce-free, so a
 * site with no store installed never shows a broken block
 * (tests/Architecture/CommerceBoundaryTest enforces that). Patterns are the
 * sanctioned way back in: they exist only while this plugin is booted, and a
 * client composes them into a template or part through the Site Editor.
 *
 * `header-mini-cart` is the supported way to put a cart in the site header.
 * It is a composition, not a theme file, because the header part is shared
 * with every non-commerce project built from this starter.
 */
final class CommercePatterns {

	public const CATEGORY = 'site-commerce';

	public const PATTERN_HEADER_MINI_CART = 'site-commerce/header-mini-cart';

	public const PATTERN_PRODUCT_GRID = 'site-commerce/product-grid';

	/**
	 * Wires pattern registration. Called only from SiteCommerce\Plugin::boot()
	 * (the store plugin is confirmed active).
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'register_patterns' ), 11 );
	}

	/**
	 * Priority 11 on `init` so the theme's own pattern registration and the
	 * store plugin's block registration have both already run.
	 */
	public static function register_patterns(): void {
		register_block_pattern_category(
			self::CATEGORY,
			array( 'label' => __( 'Commerce', 'site-commerce' ) )
		);

		register_block_pattern(
			self::PATTERN_HEADER_MINI_CART,
			array(
				'title'      => __( 'Header with Mini-Cart', 'site-commerce' ),
				'categories' => array( self::CATEGORY ),
				'blockTypes' => array( 'core/template-part/header' ),
				'content'    => self::pattern_content( 'header-mini-cart' ),
			)
		);

		register_block_pattern(
			self::PATTERN_PRODUCT_GRID,
			array(
				'title'      => __( 'Product grid', 'site-commerce' ),
				'categories' => array( self::CATEGORY ),
				'content'    => self::pattern_content( 'product-grid' ),
			)
		);
	}

	/**
	 * Reads a pattern body from this plugin's patterns/ directory.
	 *
	 * The markup lives in files rather than PHP string literals for two
	 * reasons: it is generated from upstream composition (so it is reviewable
	 * as markup and re-derivable on upgrade), and it keeps commerce block names
	 * out of PHP source entirely.
	 */
	private static function pattern_content( string $slug ): string {
		$path = dirname( __DIR__, 2 ) . '/patterns/' . $slug . '.html';

		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled plugin asset from disk, not a remote resource.
		$content = file_get_contents( $path );

		return false === $content ? '' : $content;
	}
}
```

Constraints to respect while writing it: named static callbacks only (no closures — `HookOwnershipTest`); the `site-commerce` text domain (`phpcs.xml`'s I18n rule); WPCS formatting (`ddev composer lint:php`). Note that `patterns/` is not one of the forbidden catch-all directory names (`DirectoryRulesTest` forbids `components/`, `layouts/`, `inc/`, `includes/`, `helpers/`, `misc/`, `common/`, `lib/`, `utils/`) — confirm that by reading the test's list before creating the directory, and if `patterns/` is somehow rejected there, put the two files in `src/Theme/patterns/` and adjust `pattern_content()`'s path accordingly.

- [ ] **Step 6: Register the provider**

In `web/app/plugins/site-commerce/src/Plugin.php`, add the import and the provider entry:

```php
use SiteCommerce\Theme\CommercePatterns;
```

```php
		$providers = array(
			new ThemeSupport(),
			new CommercePatterns(),
			new ExampleProductRules(),
			new CommerceSanitizeStep(),
		);
```

- [ ] **Step 7: Assert the wiring in the boot test**

Add to `tests/commerce/Integration/SiteCommerce/PluginBootTest.php` (and add `use SiteCommerce\Theme\CommercePatterns;` plus a `@covers \SiteCommerce\Theme\CommercePatterns` line):

```php
	public function test_commerce_patterns_are_wired(): void {
		self::assertNotFalse(
			has_action( 'init', array( CommercePatterns::class, 'register_patterns' ) ),
			'CommercePatterns must wire its init hook once site-commerce boots.'
		);
	}
```

- [ ] **Step 8: Run the suites** *(commerce profile)*

```bash
ddev composer lint:php
ddev composer analyse
ddev composer test:integration:commerce
```

Expected: all PASS. If `test_every_pattern_block_is_registered` or `test_the_header_pattern_actually_contains_the_mini_cart` fails, the generated file is wrong — regenerate it with Step 4, do not relax the test.

- [ ] **Step 9: Run `SWITCH TO BASE`, verify, and commit**

```bash
# SWITCH TO BASE (see "Profile switch procedures") — all five assertions must pass.
ddev composer verify:fast
git status --porcelain
```

Expected: `verify:fast` GREEN. The status lists exactly: `CommercePatterns.php`, `Plugin.php`, the two `patterns/*.html` files, `CommercePatternsTest.php`, `PluginBootTest.php`.

```bash
git add web/app/plugins/site-commerce/src/Theme/CommercePatterns.php web/app/plugins/site-commerce/src/Plugin.php \
        web/app/plugins/site-commerce/patterns \
        tests/commerce/Integration/Theme/CommercePatternsTest.php tests/commerce/Integration/SiteCommerce/PluginBootTest.php
git diff --cached --check
ddev composer verify:fast
git commit -m "feat(commerce): register the commerce pattern category, header mini-cart, and product grid"
git status --porcelain
```

Expected: empty output after the commit.

---

### Task A6: `scripts/enable-commerce` seeds native Cart/Checkout blocks and the header Mini-Cart

**Files:**
- Modify: `scripts/enable-commerce` (header comment, step 4, new step 10, renumbering, closing message)

**Interfaces:**
- Consumes: `web/app/plugins/site-commerce/patterns/header-mini-cart.html` (generated in Task A5 Step 4) and `web/app/themes/site-theme/parts/site-header.html` (Task 1). The script reads both from disk, so **no WooCommerce block name is written into it**: the seeded override and the registered pattern are the same composition by construction.
- Produces: a store whose cart and checkout pages hold native block content, and a `wp_template_part` database override of `site-header` that ends with the Mini-Cart composition. Task A7's journeys depend on both.

- [ ] **Step 0: Run `SWITCH TO COMMERCE`**

Task A5 ended on the base profile. Run the procedure in full.

There is no PHPUnit test for this script: the `commerce-integration` suite runs against wp-phpunit's own throwaway database, which the script never touches. Its tests are (a) the script's own hard-fail verification, and (b) Task A7's storefront journeys, which only pass against block-rendered cart/checkout.

- [ ] **Step 1: Replace the classic cart/checkout step**

In `scripts/enable-commerce`, replace the whole `--- 4. Shop / cart / checkout / my-account pages ---` block (comment plus the `for page in cart checkout` loop) with:

```bash
# --- 4. Shop / cart / checkout / my-account pages ----------------------------
# WooCommerce creates these on activation; running the install_pages tool again
# re-creates any that are missing and is idempotent otherwise.
#
# NATIVE BLOCK cart/checkout: this script used to swap both pages to the
# classic [woocommerce_cart] / [woocommerce_checkout] shortcodes because the
# block Cart/Checkout hydrate client-side and the old server-rendered selectors
# were easier to automate. On a block theme that would prove the wrong thing —
# the commerce suite must exercise the block workflow the client actually gets.
# So the pages now keep WooCommerce's own default BLOCK content.
#
# The markup is never hand-written here (it changes between WooCommerce
# releases). Instead: any page still holding the legacy shortcode is force
# deleted, and install_pages recreates it with the CURRENT default block
# content. Then both pages are verified — a store whose checkout silently fell
# back to a shortcode must fail loudly, not run a green test suite against the
# wrong rendering path.
echo "==> [4/11] Ensuring shop/cart/checkout/my-account pages exist (native block cart/checkout)"
for page in cart checkout; do
  page_id="$("${WP[@]}" option get "woocommerce_${page}_page_id" 2>/dev/null | tr -d '\r')"
  if [ -n "${page_id}" ] \
      && "${WP[@]}" post get "${page_id}" --field=post_content 2>/dev/null | grep -q "\[woocommerce_${page}\]"; then
    "${WP[@]}" post delete "${page_id}" --force >/dev/null
    echo "    removed the legacy shortcode ${page} page (#${page_id}); it will be recreated with block content."
  fi
done

"${WP[@]}" wc tool run install_pages --user=admin >/dev/null

for page in cart checkout; do
  page_id="$("${WP[@]}" option get "woocommerce_${page}_page_id" 2>/dev/null | tr -d '\r')"
  if [ -z "${page_id}" ] \
      || ! "${WP[@]}" post get "${page_id}" --field=post_content 2>/dev/null | grep -q "wp:woocommerce/${page}"; then
    echo "" >&2
    echo "FAILED: the ${page} page does not hold the native block content." >&2
    echo "  Expected a 'wp:woocommerce/${page}' block in page #${page_id:-<none>}." >&2
    echo "  The commerce suites must exercise the block checkout, not a shortcode fallback." >&2
    exit 1
  fi
  echo "    ${page} page (#${page_id}) holds the native block content."
done
```

- [ ] **Step 2: Add the header Mini-Cart override step**

Insert a new step between the current step 9 (test users) and the permalinks step. It reads both inputs from disk, so nothing here names a commerce block.

```bash
# --- 10. Header Mini-Cart (database template-part override) ------------------
# The base theme's parts/site-header.html stays commerce-free: it ships in every
# project built from this starter, and a commerce block there would render as a
# broken block on a site with no store. The supported composition is the
# `site-commerce/header-mini-cart` pattern a client inserts through the Site
# Editor.
#
# For a deterministic test store, that client action is performed here as a
# DATABASE OVERRIDE of the site-header template part: the Git baseline part plus
# the Mini-Cart block appended. This is a legitimate, expected override under the
# project's source-of-truth model (`wp agency check-overrides` reports it; it is
# never automatically promoted back into Git). Idempotent: an existing override
# that already carries the Mini-Cart is left alone.
echo "==> [10/11] Seeding the header Mini-Cart as a template-part override"
HEADER_PART_SOURCE="web/app/themes/site-theme/parts/site-header.html"
MINI_CART_PATTERN="web/app/plugins/site-commerce/patterns/header-mini-cart.html"
for required in "${HEADER_PART_SOURCE}" "${MINI_CART_PATTERN}"; do
  if [ ! -f "${required}" ]; then
    echo "error: ${required} is missing; cannot seed the header Mini-Cart override." >&2
    exit 1
  fi
done

header_part_id="$("${WP[@]}" post list --post_type=wp_template_part --name=site-header \
  --post_status=any --field=ID --format=ids 2>/dev/null | tr -d '\r' | awk '{print $1}')"

if [ -n "${header_part_id}" ] \
    && "${WP[@]}" post get "${header_part_id}" --field=post_content 2>/dev/null | grep -q 'mini-cart'; then
  echo "    site-header override already carries the Mini-Cart — skipping."
else
  header_part_file="$(mktemp)"
  cat "${HEADER_PART_SOURCE}" > "${header_part_file}"
  printf '\n' >> "${header_part_file}"
  # The same composition site-commerce registers as its header pattern, read
  # from disk so this script never names a commerce block itself.
  cat "${MINI_CART_PATTERN}" >> "${header_part_file}"

  if [ -n "${header_part_id}" ]; then
    "${WP[@]}" post update "${header_part_id}" "${header_part_file}" >/dev/null
    echo "    updated the existing site-header override (#${header_part_id}) with the Mini-Cart."
  else
    header_part_id="$("${WP[@]}" post create "${header_part_file}" \
      --post_type=wp_template_part --post_title="Site Header" --post_name=site-header \
      --post_status=publish --porcelain 2>/dev/null | tr -d '\r')"
    echo "    created the site-header override (#${header_part_id}) with the Mini-Cart."
  fi

  "${WP[@]}" post term set "${header_part_id}" wp_theme site-theme >/dev/null
  "${WP[@]}" post term set "${header_part_id}" wp_template_part_area header >/dev/null
  rm -f "${header_part_file}"
fi
```

- [ ] **Step 3: Renumber and update the header comment**

- Change every `[n/10]` echo to `[n/11]`; the permalinks step becomes `[11/11]`.
- In the file header, replace the sentence describing classic cart/checkout with: `Cart and Checkout keep WooCommerce's native BLOCK content (verified, never hand-written), and the site-header template part gets a database override carrying the Mini-Cart block.`
- Append to the closing message block:

```bash
echo "    Cart/Checkout use the native blocks; the site-header override carries the Mini-Cart."
```

- [ ] **Step 4: Run the script from a clean commerce state and confirm it converts a shortcode store**

```bash
# Reproduce the legacy state first, so the delete/recreate path is exercised.
cart_id="$(ddev wp option get woocommerce_cart_page_id)"
ddev wp post update "${cart_id}" --post_content="[woocommerce_cart]"
bash scripts/enable-commerce
```

Expected: exit 0, and the step-4 output reports `removed the legacy shortcode cart page` followed by `cart page (#…) holds the native block content.`

- [ ] **Step 5: Run it again and confirm idempotency**

```bash
bash scripts/enable-commerce
```

Expected: exit 0; step 4 reports both pages already hold block content; step 10 reports the Mini-Cart override is already present.

- [ ] **Step 6: Confirm the storefront renders the block cart and the header Mini-Cart**

```bash
ddev wp post get "$(ddev wp option get woocommerce_cart_page_id)" --field=post_content | head -5
ddev wp post list --post_type=wp_template_part --name=site-header --field=ID
curl -sk "$(ddev wp option get home)/cart/" | grep -c "wc-block-cart" || true
```

Expected: the page content starts with a `wp:woocommerce/cart` block; a template-part override id exists; the cart page HTML contains block-cart markup. Open `/cart/` in a browser and confirm the Mini-Cart is visible in the header.

- [ ] **Step 7: Confirm no placeholder survived, then run `SWITCH TO BASE`, verify, and commit**

```bash
grep -n "<[A-Z_]\{3,\}>" scripts/enable-commerce && { echo "FAIL: unresolved placeholder"; exit 1; } || echo "OK: no placeholders"
```

```bash
# SWITCH TO BASE (see "Profile switch procedures") — all five assertions must pass.
ddev composer verify:fast
git status --porcelain
```

Expected: `verify:fast` GREEN; the status lists only `scripts/enable-commerce`.

```bash
git add scripts/enable-commerce
git diff --cached --check
ddev composer verify:fast
git commit -m "feat(commerce): seed native cart/checkout blocks and a header mini-cart override"
git status --porcelain
```

Expected: empty output after the commit. Then run `SWITCH TO COMMERCE` again — Task A7 needs the seeded store, and this is the first run of the *new* script, so it also re-proves the whole seeding path end to end.

---

### Task A7: Commerce Playwright coverage on the block storefront

**Files:**
- Create: `tests/commerce/e2e/helpers/checkout.ts`
- Create: `tests/commerce/e2e/locators.spec.ts`
- Modify: `tests/commerce/e2e/commerce-journey.spec.ts`
- Modify: `tests/commerce/e2e/shop-manager-admin.spec.ts`

**Interfaces:**
- Consumes: the store Task A6 seeds (block cart/checkout, header Mini-Cart), the fixtures `test-simple-product` / `test-variable-product` / `TESTCOUPON`, and the credentials `shop-manager` / `test-customer`.
- Produces: `tests/commerce/e2e/helpers/checkout.ts` exporting
  `SIMPLE_PRODUCT_SLUG`, `VARIABLE_PRODUCT_SLUG`, `checkoutLocators`,
  `verifyBlockCheckoutLocators( page: Page ): Promise<void>`,
  `addSimpleProductToCart( page: Page ): Promise<void>`,
  `fillBlockCheckoutWithCod( page: Page, email: string ): Promise<void>`,
  `placeOrderAndReadNumber( page: Page ): Promise<string>`.

The block Cart and Checkout render client-side, and their private class names change between WooCommerce releases. This task therefore addresses them through **accessible roles and label text**, and ships a permanent contract spec that fails with a named locator the moment one of them stops resolving.

- [ ] **Step 0: Confirm the commerce profile is active with the NEW seeding**

Task A6 Step 7 ended with a fresh `SWITCH TO COMMERCE` on the rewritten script. Confirm:

```bash
ddev wp post get "$(ddev wp option get woocommerce_cart_page_id)" --field=post_content | head -3
ddev wp post list --post_type=wp_template_part --name=site-header --field=ID
```

Expected: block cart content, and a template-part override id.

- [ ] **Step 1: Write the shared checkout helper**

The helper below is **complete, final code** — it contains no slot to fill. It addresses the block Cart and Checkout the way a customer does: by accessible role and visible label text, which the block checkout exposes as real `<label>` elements and button names. That is deliberate. CSS-class selectors on `wc-block-components-*` internals are private markup that changes between releases; role and label locators are the public, accessible surface, and Step 2 proves every one of them resolves on the live store before this task can finish.

Create `tests/commerce/e2e/helpers/checkout.ts`:

```ts
import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Block Cart/Checkout helpers for the commerce journeys.
 *
 * scripts/enable-commerce seeds the cart and checkout pages with WooCommerce's
 * NATIVE BLOCK content and hard-fails if either ever falls back to a shortcode,
 * so these helpers drive the block UI the client actually gets.
 *
 * LOCATOR POLICY: address controls by accessible role and visible label, never
 * by a `wc-block-components-*` class. Those classes are private block internals
 * that change between releases; the accessible names are the public surface and
 * are what a customer (and a screen reader) actually uses. `verifyBlockCheckoutLocators()`
 * below is run by a dedicated spec against the live store, so a WooCommerce
 * release that renames a label fails loudly with a named locator instead of
 * producing a mystery timeout inside a journey.
 *
 * The block Cart and Checkout hydrate client-side, so every accessor waits for
 * the hydrated control rather than the server-rendered placeholder.
 */

export const SIMPLE_PRODUCT_SLUG = 'test-simple-product';
export const VARIABLE_PRODUCT_SLUG = 'test-variable-product';

/** One named locator per control the journeys drive. */
export const checkoutLocators = {
	addToCart: ( page: Page ): Locator =>
		page.getByRole( 'button', { name: /add to cart/i } ).first(),
	email: ( page: Page ): Locator => page.getByLabel( /email address/i ).first(),
	firstName: ( page: Page ): Locator => page.getByLabel( /first name/i ).first(),
	lastName: ( page: Page ): Locator => page.getByLabel( /last name/i ).first(),
	country: ( page: Page ): Locator => page.getByLabel( /country\s*\/\s*region/i ).first(),
	address: ( page: Page ): Locator => page.getByLabel( /^address/i ).first(),
	city: ( page: Page ): Locator => page.getByLabel( /city/i ).first(),
	state: ( page: Page ): Locator => page.getByLabel( /state|province|county/i ).first(),
	postcode: ( page: Page ): Locator => page.getByLabel( /postcode|zip/i ).first(),
	cashOnDelivery: ( page: Page ): Locator =>
		page.getByRole( 'radio', { name: /cash on delivery/i } ),
	placeOrder: ( page: Page ): Locator =>
		page.getByRole( 'button', { name: /place order/i } ),
	miniCart: ( page: Page ): Locator =>
		page.locator( 'header' ).getByRole( 'button', { name: /cart/i } ).first(),
} as const;

/**
 * Asserts every locator above resolves on the live store. Called by
 * tests/commerce/e2e/locators.spec.ts so a renamed label fails as a named
 * assertion rather than a timeout buried in a journey.
 */
export async function verifyBlockCheckoutLocators( page: Page ): Promise<void> {
	await page.goto( `/product/${ SIMPLE_PRODUCT_SLUG }/` );
	await expect( checkoutLocators.addToCart( page ) ).toBeVisible();
	await checkoutLocators.addToCart( page ).click();

	await page.goto( '/checkout/' );
	for ( const name of [
		'email',
		'firstName',
		'lastName',
		'country',
		'address',
		'city',
		'state',
		'postcode',
	] as const ) {
		await expect(
			checkoutLocators[ name ]( page ),
			`block checkout locator "${ name }" did not resolve — read the live DOM and update helpers/checkout.ts`
		).toBeVisible( { timeout: 30_000 } );
	}

	await expect(
		checkoutLocators.cashOnDelivery( page ),
		'block checkout locator "cashOnDelivery" did not resolve — is the COD gateway enabled?'
	).toBeVisible( { timeout: 30_000 } );
	await expect(
		checkoutLocators.placeOrder( page ),
		'block checkout locator "placeOrder" did not resolve'
	).toBeVisible();
}

export async function addSimpleProductToCart( page: Page ): Promise<void> {
	await page.goto( `/product/${ SIMPLE_PRODUCT_SLUG }/` );
	await checkoutLocators.addToCart( page ).click();
	// The header Mini-Cart reflects cart state on every page, so it is the
	// storefront-wide confirmation that the item landed.
	await expect( checkoutLocators.miniCart( page ) ).toBeVisible();
}

export async function fillBlockCheckoutWithCod( page: Page, email: string ): Promise<void> {
	await expect( checkoutLocators.email( page ) ).toBeVisible( { timeout: 30_000 } );

	await checkoutLocators.email( page ).fill( email );
	await checkoutLocators.firstName( page ).fill( 'Test' );
	await checkoutLocators.lastName( page ).fill( 'Buyer' );
	await checkoutLocators.country( page ).selectOption( 'US' );
	await checkoutLocators.address( page ).fill( '123 Test Street' );
	await checkoutLocators.city( page ).fill( 'Los Angeles' );
	await checkoutLocators.state( page ).selectOption( 'CA' );
	await checkoutLocators.postcode( page ).fill( '90001' );

	// The payment panel re-renders once the address resolves shipping, so the
	// Cash-on-Delivery option is chosen last.
	await checkoutLocators.cashOnDelivery( page ).check();
}

export async function placeOrderAndReadNumber( page: Page ): Promise<string> {
	await checkoutLocators.placeOrder( page ).click();

	await page.waitForURL( /order-received/ );

	// The order confirmation states the order number in its summary; read it
	// from the page text rather than a private class name.
	const summary = await page.locator( 'main' ).innerText();
	const match = summary.match( /(?:order number|order)\D{0,20}?(\d+)/i );

	expect( match, `could not read an order number from the confirmation page:\n${ summary }` ).not.toBeNull();

	return ( match as RegExpMatchArray )[ 1 ];
}
```

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — two defects
> in the helper above. The `miniCart` locator is already fixed in the code;
> `verifyBlockCheckoutLocators()` needs the change described here.**
>
> **1. The `miniCart` locator was VACUOUS, and it guards this unit's headline
> claim.** It read
> `page.getByRole( 'button', { name: /cart/i } ).first()`, and `/cart/i` matches
> **"Add to cart"**. So on a product page it resolves to the add-to-cart button.
> `addSimpleProductToCart()` asserts `miniCart` is visible immediately after
> clicking add-to-cart, on the product page — which passes whether or not a
> Mini-Cart exists anywhere on the site. That would let Task A5's pattern and
> Task A6's template-part override both ship BROKEN behind a green commerce
> suite, and the Mini-Cart is the entire point of fixed decision 5.
>
> It is now scoped to the `<header>` element. The derived commerce templates
> render the header part with `"tagName":"header"`, so a real `<header>` is
> present on every storefront page. **Prove the scoping rather than assuming it:**
> the contract spec must exercise this locator on a page that ALSO carries an
> add-to-cart button, so a regression back to the unscoped form fails there.
> Read the Mini-Cart button's real accessible name off the live store (Task A5
> Step 1 discovers the block name; the button's name is a separate fact) and
> record it in the ground-truth file rather than guessing it.
>
> **2. The contract spec proves visibility but never interaction.**
> `verifyBlockCheckoutLocators()` asserts only `toBeVisible()`, yet
> `fillBlockCheckoutWithCod()` calls `selectOption( 'US' )` on `country` and
> `selectOption( 'CA' )` on `state`. `selectOption` requires a native `<select>`;
> recent WooCommerce releases render those two as comboboxes, which are visible
> while `selectOption` throws. A spec the plan calls a "permanent contract check"
> would therefore stay green while every journey failed, and Step 2's "re-run
> until green" gate would be satisfied by a contract that skips the two riskiest
> calls.
>
> **Extend `verifyBlockCheckoutLocators()` to perform the interaction** for
> anything the journeys do more than read — at minimum the two `selectOption`
> calls, or whichever form the live DOM actually needs. Read the live DOM in
> Step 2 to decide; do not assume either form. A control that is
> visible-but-not-selectable must fail in the contract spec, with its locator
> name, not inside a journey.

- [ ] **Step 2: Prove every locator resolves on the live store** *(commerce profile)*

Create `tests/commerce/e2e/locators.spec.ts` — a permanent spec, not a throwaway probe, so the contract keeps being checked on every commerce run:

```ts
import { test } from '@playwright/test';
import { verifyBlockCheckoutLocators } from './helpers/checkout';

/**
 * Contract check for helpers/checkout.ts. It runs FIRST in intent: when a
 * WooCommerce release renames a checkout label, this spec fails with the exact
 * locator name instead of every journey timing out somewhere in the middle.
 */
test.describe( 'block checkout locator contract', () => {
	test.skip( process.env.COMMERCE !== '1', 'commerce profile only — set COMMERCE=1 to run' );

	test( 'every block checkout locator resolves on the live store', async ( { page } ) => {
		test.setTimeout( 120_000 );
		await verifyBlockCheckoutLocators( page );
	} );
} );
```

Run it:

```bash
COMMERCE=1 npx playwright test tests/commerce/e2e/locators.spec.ts --project=chromium-desktop
```

If any locator fails, read the live DOM before changing anything:

```bash
COMMERCE=1 npx playwright test tests/commerce/e2e/locators.spec.ts --project=chromium-desktop --trace=on
npx playwright show-trace test-results/*/trace.zip
```

Fix the failing regex in `helpers/checkout.ts` to match the label the store actually renders, record the change and the WooCommerce version in the ground-truth file, and re-run until green. **Do not switch to a `wc-block-components-*` class selector** — if a control genuinely has no accessible name, that is a WooCommerce accessibility bug worth recording in the final report's known limitations, and only then may a class selector be used, with a comment naming the reason.

- [ ] **Step 3: Rewrite the journey spec against the block storefront**

In `tests/commerce/e2e/commerce-journey.spec.ts`:

1. Replace the docblock paragraph about classic shortcode selectors with the block-storefront rationale: block cart/checkout seeded by `scripts/enable-commerce`, controls addressed by accessible role and label (policy stated in `helpers/checkout.ts`, contract enforced by `locators.spec.ts`), header Mini-Cart seeded as a template-part override.
2. Import from `./helpers/checkout` and delete the now-duplicated local helpers.
3. Keep the existing test names and desktop/mobile split (`product archive lists the fixture products`, `simple product: PDP renders price and adds to the cart`, `variable product: selecting a variation updates the price, then adds`, `cart: updating quantity and applying TESTCOUPON lowers the total`, `checkout: a guest COD order reaches the order-received page` — the only one that also runs on mobile, `account: a logged-in customer sees the order in their history`), retargeting each at `checkoutLocators`. Keep the arithmetic assertions unchanged: subtotal `39.98` at quantity 2, order total `35.98` after `TESTCOUPON`. For the cart quantity and coupon controls, use the same policy: `page.getByLabel( /quantity/i )`, `page.getByRole( 'button', { name: /add a coupon|apply/i } )`, and assert totals against the page text (`await expect( page.locator( 'main' ) ).toContainText( '39.98' )`) rather than a private totals-row class.
4. Add two new tests:

```ts
	test( 'the block theme renders the shop archive inside the theme header and footer', async ( { page } ) => {
		test.skip( isMobileProject(), 'desktop journey; the mobile project runs the checkout smoke only' );

		await page.goto( '/shop/' );

		// The whole justification for templates/archive-product.html is that the
		// upstream template references header/footer parts this theme does not
		// have. Without the override these two assertions fail.
		await expect( page.locator( 'header' ).first() ).toBeVisible();
		await expect( page.locator( 'footer' ).first() ).toBeVisible();
	} );

	test( 'the header Mini-Cart reflects the cart contents', async ( { page } ) => {
		test.skip( isMobileProject(), 'desktop journey; the mobile project runs the checkout smoke only' );

		await page.goto( '/' );
		// Seeded by scripts/enable-commerce as a site-header template-part
		// override, so it is present on every storefront page.
		await expect( checkoutLocators.miniCart( page ) ).toBeVisible();

		await addSimpleProductToCart( page );
		await page.goto( '/' );

		// One fixture product at $19.99 is in the cart; the Mini-Cart's
		// accessible name and label report the cart state.
		await expect( checkoutLocators.miniCart( page ) ).toContainText( /1|19\.99/ );
	} );
```

- [ ] **Step 4: Add Shop Manager Site Editor coverage (§11.14)**

In `tests/commerce/e2e/shop-manager-admin.spec.ts`: replace the local `placeGuestCodOrder` helper's classic-checkout body with calls to `addSimpleProductToCart` / `fillBlockCheckoutWithCod` / `placeOrderAndReadNumber` from `./helpers/checkout`, and add:

```ts
	test( 'shop-manager can open the Site Editor', async ( { page } ) => {
		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		const response = await page.goto( adminUrl( 'site-editor.php' ) );
		expect( response?.status() ).toBe( 200 );
		await expect(
			page.getByText( /you do not have sufficient permissions|not allowed to access this page/i )
		).toHaveCount( 0 );

		// The Site Editor canvas is an iframe; its presence is the proof the
		// editor booted rather than rendering a permissions error.
		await expect( page.locator( 'iframe[name="editor-canvas"], .edit-site-visual-editor' ).first() ).toBeVisible( { timeout: 30_000 } );
	} );

	test( 'shop-manager is still refused the theme installer and the theme file editor', async ( { page } ) => {
		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		for ( const denied of [ 'themes.php', 'theme-editor.php', 'customize.php' ] ) {
			const response = await page.goto( adminUrl( denied ) );
			expect( response?.status(), denied ).toBe( 403 );
			await expect(
				page.getByText( /higher level of permission|not allowed/i ).first()
			).toBeVisible();
		}
	} );
```

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — the
> denied-screen assertion was RED BY CONSTRUCTION and is fixed above.**
>
> The original matched
> `/you do not have sufficient permissions|not allowed to access this page|do not have permission/i`.
> `AdminScreenPolicy::block_denied_screens()` intercepts on `admin_init`, BEFORE
> the screen's own capability check, and always renders ITS OWN message with HTTP
> 403:
>
>     You need a higher level of permission.
>     This screen is not part of the editing model for your role. Design changes belong in the Site Editor.
>
> None of the three original alternatives appears in that text. The plan hedged
> only for a redirect, which is not what the policy does — it calls `wp_die()`.
>
> The replacement is the shipped, CI-green form Unit 1 already uses at
> `tests/e2e/forbidden-admin-screens.spec.ts:32-33`. It also adds the **403 status
> assertion**, which the original omitted and which is the stronger half: a text
> match alone would pass on any page that happened to contain the phrase.

The Site Editor test deliberately claims only **access**, not a save: saving a template part from the Site Editor UI is covered by Task 1's own client-role Playwright suite, and §11.14's commerce item is "Shop Manager Site Editor access when commerce is active". Do not rename this test to claim more than it asserts.

- [ ] **Step 5: Run the full commerce E2E** *(commerce profile)*

```bash
COMMERCE=1 npm run test:e2e:commerce
```

Expected: every test passes on both projects (the mobile project runs only the checkout smoke). Then confirm no placeholder slipped in:

```powershell
if (rg -n '<[A-Z_]{3,}>' tests/commerce/e2e) { throw 'FAIL: unresolved placeholder' }
'OK: no placeholders'
```

- [ ] **Step 6: Run `SWITCH TO BASE` and confirm the base suites are untouched**

```bash
# SWITCH TO BASE (see "Profile switch procedures") — all five assertions must pass.
npm run lint:js
npm run test:e2e
npm run test:accessibility
```

These MUST run on the base profile: on a commerce-enabled site the seeded `site-header` override changes the header on every page, so a base run there would be a false green.

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — "Expected:
> PASS" is wrong for `test:e2e` on this host, and it invites the worst possible
> response.**
>
> The full base `e2e` suite shows **10 to 13 `reauth=1` login failures** on this
> machine. That band is environmental, was reproduced on pure HEAD in a previous
> unit, and CI on a fresh database is the authority. A worker told to expect zero
> failures will either stop on a false blocker or, far worse, "fix" a spec to make
> an environmental failure disappear.
>
> Compare against the band, not against zero. Report the exact count. A count
> OUTSIDE 10–13, or any failure whose message is not `reauth=1`, is a real
> finding — report it and stop.
>
> `npm run lint:js` must be clean, with no tolerance.
>
> `npm run test:accessibility` should be **6 passed**. Before attributing any
> accessibility failure to code, dump the dev site's Global Styles row — a
> previous unit lost a cycle to a leftover background colour there that produced
> contrast violations naming that colour. The orchestrator verified it is clean at
> the start of Unit 4A (`{"version":3,"isGlobalStylesUserThemeJSON":true}`), but
> e2e runs mutate it:
>
> ```bash
> ddev wp post list --post_type=wp_global_styles --post_status=any --field=ID
> ddev wp post get <id> --field=post_content
> ```
>
> **Do NOT run `npm run test:visual` as a gate here.** The carried-over database
> has drifted from the committed baselines (the demo page renders 1521px against
> an 1899px baseline). That divergence was CONFIRMED environmental in the previous
> unit, because the same baselines passed in CI. Only a `ci-capture/visual-baselines`
> ref may regenerate baselines, and never from a local run.

- [ ] **Step 7: Verify and commit**

```bash
ddev composer verify:fast
git status --porcelain
```

Expected: `verify:fast` GREEN; the status lists exactly the four `tests/commerce/e2e/` files.

```bash
git add tests/commerce/e2e/
git diff --cached --check
ddev composer verify:fast
git commit -m "test(commerce): drive the block cart, checkout, mini-cart, and shop-manager Site Editor"
git status --porcelain
```

Expected: empty output after the commit.

---

### Task A8: Commerce documentation, generated index, and the Phase A gate

**Files:**
- Modify: `tests/commerce/README.md`
- Modify: `docs/adding-commerce-behaviour.md` (sections 1 and 6)
- Modify: `docs/generated-block-index.md` (regenerated only)

**Interfaces:**
- Consumes: everything Phase A produced.
- Produces: a Phase A branch that passes every base and commerce gate — the state Phase B builds on.

- [ ] **Step 1: Update the commerce test README**

In `tests/commerce/README.md`:

1. In "What's here", add two bullets under `Integration/`:
   - `Integration/Theme/CommerceBlockTemplatesTest.php` — every commerce block template parses, names only registered blocks, resolves its `site-header`/`site-footer` parts, wins over the store plugin's default template, and no upstream template slug is left undecided.
   - `Integration/Theme/CommercePatternsTest.php` — the commerce pattern category and the `header-mini-cart` / `product-grid` patterns register when the profile boots.
2. Rewrite the `e2e/commerce-journey.spec.ts` bullet: the journeys now drive the **native block** cart and checkout (seeded by `scripts/enable-commerce`), plus the theme-chrome assertion on the shop archive and the header Mini-Cart.
3. Rewrite the `e2e/shop-manager-admin.spec.ts` bullet: add Site Editor access and the theme-installer/file-editor refusals; correct the Appearance-menu sentence — **the Appearance menu is REMOVED and replaced by a single Design entry that links to the Site Editor** (`AdminScreenPolicy::replace_appearance_menu()` calls `remove_menu_page( 'themes.php' )`). See the Task A2 Step 5 correction; the plan's earlier wording, "the menu now exists and exposes only the Editor", is wrong and must not be copied into the README.
4. In "Gates", add: `tests/Architecture/CommerceBoundaryTest` runs in the BASE architecture suite and keeps commerce block markup inside the declared commerce templates — it is the block-markup half of `WooCommerceIsolationTest`'s PHP-symbol rule.

- [ ] **Step 2: Update the enable-commerce description in the commerce guide**

In `docs/adding-commerce-behaviour.md` section 1, replace `classic cart/checkout` in the parenthesised list with `native block cart/checkout (verified, never hand-written)`, and add a sentence: `It also seeds a site-header template-part database override carrying the Mini-Cart block — the store-side composition the base theme deliberately does not ship.`

In section 6, replace the e2e bullet's journey summary with: `archive (inside the theme header/footer) → PDP (simple + variable) → block cart + TESTCOUPON → guest COD block checkout → order history, plus the header Mini-Cart.`

- [ ] **Step 3: Check precondition P3 — the block index must be able to see `.html` templates**

This is a **hard gate on Phase A close**. Regenerating an index from a generator that only scans `templates/*.php` produces a Templates column that is silently wrong, and `GeneratedIndexFreshnessTest` would happily certify it.

```bash
grep -n "files_containing( \$theme . '/templates'" tests/support/BlockIndexGenerator.php
grep -n "'php' !== strtolower" tests/support/BlockIndexGenerator.php
```

Then prove it empirically — the reference block is referenced from a template or a pattern, so the generated index must name at least one non-pattern reference for it:

```bash
php scripts/generate-block-index
grep -n "reference-callout" docs/generated-block-index.md
grep -c "\.html" docs/generated-block-index.md
```

**If the generator still filters `templates/` to `.php` (so no `.html` path can ever appear in the Templates column): STOP.** Record it, escalate to the coordinator, and do not close Phase A. Task 1 owns `tests/support/BlockIndexGenerator.php`; this task must not edit it and must not report completion against a generator that cannot see the theme's templates.

- [ ] **Step 4: Regenerate the block index**

```bash
php scripts/generate-block-index
git diff --stat docs/generated-block-index.md
```

Commit whatever it produces. If it produces no change, that is expected — the commerce templates reference no `agency/*` block.

- [ ] **Step 5: Run every Phase A gate, base profile first**

Run `SWITCH TO BASE` first (all five assertions must pass), then the base matrix:

```bash
ddev composer verify:fast
ddev composer verify
npx -y npm@10 ci
npm run lint > lint.log 2>&1; echo "EXIT=$?"; tail -25 lint.log
npm run build
git status --porcelain
npm run test:e2e
npm run test:accessibility
```

`git status --porcelain` after `npm run build` must show no build drift and, critically, **no `composer.json` / `composer.lock` modification** — if either appears, the WooCommerce require was not reverted and `SWITCH TO BASE` was not run properly.

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — three fixes
> applied to the command list above.**
>
> **1. `npm ci` FAILS on this host and is replaced by `npx -y npm@10 ci`.** Local
> npm is 11.x while the committed lock was written by npm 10, so npm 11 reads the
> lock as out of sync on optional platform packages
> (`Missing: @parcel/watcher-android-arm64@2.5.6 from lock file`) and refuses with
> EUSAGE. `npm ci` never writes the lock, so the npm 10 route is safe and leaves
> `package-lock.json` untouched. This is also the third bootstrap step the worktree
> needs, because `scripts/setup` installs `node_modules` INSIDE the Linux container
> and leaves no Windows `.cmd` shims.
>
> **2. `npm run lint` must never be chained behind a pipe.** `npm run lint | tail`
> reports exit 0 even when lint fails, because the pipeline's status is `tail`'s.
> That masked a real lint failure twice in one previous session. Redirect and
> capture the code, as written above. Delete `lint.log` before committing.
>
> **3. "Expected: all green" is wrong for `npm run test:e2e`.** See the Task A7
> Step 6 correction: 10 to 13 `reauth=1` failures are environmental on this host
> and CI is the authority. Compare against that band and report the exact count.
> `verify`, `verify:fast`, `lint` and `build` have no such tolerance and must be
> clean.

Then run `SWITCH TO COMMERCE` and the commerce matrix:

```bash
ddev composer test:integration:commerce
COMMERCE=1 npm run test:e2e:commerce
```

Record each command's result — including which profile it ran on — in `.superpowers/sdd/BLOCK_THEME_PROPOSAL/proofs/phase-a-gates.log`. A base command recorded from a commerce-enabled run is not a result; re-run it.

- [ ] **Step 6: Run `SWITCH TO BASE`, then commit and push**

```bash
# SWITCH TO BASE (see "Profile switch procedures") — all five assertions must pass.
ddev composer verify:fast
git status --porcelain
```

Expected: `verify:fast` GREEN; the status lists exactly `tests/commerce/README.md`, `docs/adding-commerce-behaviour.md`, and (if it changed) `docs/generated-block-index.md`.

```bash
git add -- tests/commerce/README.md docs/adding-commerce-behaviour.md docs/generated-block-index.md
git diff --cached --name-only
git diff --cached --check
ddev composer verify:fast
git commit -m "docs(commerce): document the block-theme commerce profile"
git status --porcelain
```

Expected: empty status after the commit.

> **CORRECTION (orchestrator, Unit 4A pre-start audit, 2026-08-08) — the push has
> been REMOVED, and Step 7 below cannot work as written.**
>
> **Workers never push.** The orchestrator owns every remote operation in this
> engagement. Stop after the commit and report.
>
> Step 7's `gh run list --branch feat/bt-task-4-commerce-hardening` would find
> nothing, because **CI triggers only on `main` and `ci-capture/**`**. Pushing a
> feature branch runs no workflow at all. The orchestrator verifies CI by pushing
> a `ci-capture/<name>` ref, reading **step-level outcomes rather than the job
> conclusion**, and deleting the ref afterwards. Reading the job conclusion instead
> of the steps has given the wrong answer three times in this engagement — most
> recently when a run reported "failure" while every required job was green,
> because the exempt commerce job failed.
>
> Treat Step 7 as the orchestrator's, not yours.

Report the Phase A commit range, gate results, and P1/P2/P3/P4 precondition status to the orchestrator. The orchestrator owns and updates `docs/superpowers/plans/2026-08-02-block-theme-tracking.md` after it reviews and merges this phase.

- [ ] **Step 7: Confirm CI is green before declaring Phase A closed**

```bash
gh run list --branch feat/bt-task-4-commerce-hardening --limit 5
gh run watch
```

Expected: `php-qa`, `frontend`, `integration`, `e2e`, and `commerce-e2e` all green. CI is the authority for the base/commerce separation — each job installs its own environment from scratch, so a green `commerce-e2e` next to a green `e2e` is the strongest available proof that neither profile depends on the other.

Stop here and return Phase A to the orchestrator. The orchestrator performs the review gate, merges the branch into `feat/block-theme-fse-migration`, updates the tracking file, and stops the Phase A DDEV project before it creates the Phase B worktree.

---

# HARD GATE — Phase B may not start until Phase A and Releases 2–4 are merged

Phase B documents and proves the complete state/promotion subsystem and the commerce result. It starts on the new `feat/bt-task-4-final-hardening` branch. It cannot start, and must not be partially started, before Phase A and Releases 2, 3, and 4 are merged into `feat/block-theme-fse-migration`.

**Gate check (run before Task B1's first edit):**

```bash
git fetch --all
git log --oneline feat/block-theme-fse-migration | head -30
git merge-base --is-ancestor feat/bt-task-4-commerce-hardening feat/block-theme-fse-migration
ls web/app/mu-plugins/agency-platform/src/State/
ls web/app/mu-plugins/agency-platform/src/Cli/
ls web/app/mu-plugins/agency-platform/resources/schemas/
ddev wp agency state-export --help
ddev wp agency state-diff --help
ddev wp agency promote-overrides --help
ddev wp agency promotion-backups --help
ls scripts/promote-overrides
```

**Pass condition:** the Phase A ancestry check exits `0`; `src/State/` holds the export/diff classes and all three promotion strategies, including Global Styles; `src/Cli/` holds `StateCommands.php` and `PromotionCommands.php`; `resources/schemas/` holds `state-bundle-v1.json` and `promotion-manifest-v1.json`; all four `--help` calls succeed; `scripts/promote-overrides` exists.

**If the gate fails:** STOP. Report which artifact is missing and wait. Do not write the runbook against an unmerged design, and do not implement any missing piece — those files belong to Tasks 2 and 3.

---

# PHASE B — Runbook, documentation sweep, proofs, final report

### Task B1: Phase B branch verification, gate verification, and the merged-surface inventory

**Files:**
- Create (gitignored): `.superpowers/sdd/BLOCK_THEME_PROPOSAL/proofs/merged-surface-inventory.md`
- No repository file is modified by this task.

**Interfaces:**
- Consumes: Phase A and Releases 2–4 from the integration branch.
- Produces: the inventory file every later Phase B task quotes — exact command names, flags, exit codes, env var names, class names, and file paths as *implemented*.

- [ ] **Step 1: Verify the orchestrator-created Phase B worktree and branch**

```bash
git branch --show-current
git status --porcelain
git merge-base --is-ancestor feat/block-theme-fse-migration HEAD
ddev composer verify:fast
```

Expected: branch `feat/bt-task-4-final-hardening`, empty status, ancestry exit `0`, and `verify:fast` green. If any check fails, STOP and report it to the orchestrator. Do not rebase or repair the worktree in this task.

- [ ] **Step 2: Run the hard-gate check above and record it**

Paste the full output of every gate command into `.superpowers/sdd/BLOCK_THEME_PROPOSAL/proofs/merged-surface-inventory.md`.

- [ ] **Step 3: Inventory the implemented command contract**

For each of `state-export`, `state-diff`, `promote-overrides`, `promotion-backups`, record from `--help` and from the source: every flag, its default, the documented exit codes, and the STDIN/STDOUT behaviour of `-`. Then record every environment variable the merged code reads:

```bash
grep -rhno "AGENCY_[A-Z_]*" web/app/mu-plugins/agency-platform/src/ scripts/promote-overrides | sed 's/.*://' | sort -u
```

Expected set (spec §6/§7.5/§7.7): `AGENCY_STATE_DIR`, `AGENCY_DEPLOY_COMMIT`, `AGENCY_TARGET_SITE_UUID`, `AGENCY_REPO_ROOT`, `AGENCY_EXPECTED_BRANCH`, `AGENCY_ALLOW_DETACHED_HEAD`, `AGENCY_PROMOTION_HMAC_KEYS`, `AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID`, `AGENCY_REMOTE_WP_CLI_COMMAND`, `AGENCY_DEPLOY_URL`, `AGENTS`-unrelated `AGENCY_REMOTE_STATE_DIR`, `AGENCY_PLAYWRIGHT_PROJECT`, `AGENCY_PLAYWRIGHT_TESTS`, `AGENCY_VERIFICATION_TIMEOUT`. Record any difference between the implementation and §6/§7 as a **defect** in the inventory — the runbook documents what is implemented, and the final report lists the divergence.

- [ ] **Step 4: Inventory the provider set and the backup retention window**

```powershell
Get-ChildItem web/app/mu-plugins/agency-platform/src/State/Providers
rg -n 'retention|RETENTION|older-than' web/app/mu-plugins/agency-platform/src/State | Select-Object -First 20
```

Record each provider's slug and whether it is promotable, plus the implemented retention window in days. The runbook's ownership table must match this, not the spec's prose, if they differ.

- [ ] **Step 5: Record the outstanding cross-task wording item — do NOT fix it here**

Task A3 deleted `web/app/themes/site-theme/woocommerce/`, so `tests/Architecture/WooCommerceIsolationTest.php`'s class docblock, `scanned_files()` docblock, and failure message may still name a directory that no longer exists.

```powershell
rg -n "woocommerce/ overrides|theme's woocommerce/" tests/Architecture/WooCommerceIsolationTest.php
```

**That file is Task 1-owned and this task must not edit it, not even a comment** (coordinator ruling). If the stale phrases are still present, record them in the merged-surface inventory as an open cross-task item with the exact line numbers and the suggested replacement — "the theme's declared commerce block templates (see `tests/Architecture/CommerceBoundaryTest`)" — and carry it into the final report's known limitations. The `'/woocommerce/'` path-exclusion fragment inside `scanned_files()` is harmless with the directory gone and needs no change either way.

- [ ] **Step 6: Confirm the gate and make no commit**

```bash
ddev composer verify:fast
git status --porcelain
```

Expected: green gate, empty status. This task produces only the gitignored inventory file.

---

### Task B2: `docs/state-reconciliation.md` — the state runbook

**Files:**
- Create: `docs/state-reconciliation.md`

**Interfaces:**
- Consumes: Task B1's merged-surface inventory (exact command names, flags, exit codes, env vars, provider slugs, retention window).
- Produces: the runbook every ops document links to, and the source of the promotion procedure Task B6 executes.

Spec §11.15 fixes the required contents. Write every section below; each must describe the **implemented** behaviour recorded in Task B1, and must state exact command lines.

- [ ] **Step 1: Write the runbook skeleton with its required sections**

Create `docs/state-reconciliation.md` with exactly these top-level sections, in this order:

1. `# State Reconciliation` — one paragraph naming the problem: the database can hold intentional live overrides, and this is how they are inspected and selectively promoted into Git.
2. `## Runtime truth` — quote spec §5.3 verbatim: "Git owns code and the promoted baseline. The database can contain intentional live overrides. Runtime truth is the active Git baseline plus current database state. Agents must export and inspect current state before changing or promoting structure and styles." Add: database changes are expected, not corruption.
3. `## Ownership table` — reproduce spec §5.4's table (State / Default owner / Promotion policy) with the provider slug from Task B1's inventory added as a fourth column. Note the `agency_platform_state_providers` filter as the documented extension point.
4. `## Export and diff` — the two commands with real flags: default provider scoping, `--providers=<list>`, `--include-content`, `--output=-`, and `state-diff`'s two modes (without `--source` = against the Git baseline, DB-owned providers informational only; with `--source=<bundle>` = against the bundle, DB-owned providers DO count as post-export drift). State the exit codes: `0` no drift, `2` drift. State that STDOUT carries only JSON and diagnostics go to STDERR.
5. `## Prepare, seal, finalise, confirm, rollback` — the full lifecycle as a numbered procedure with a command line per step, including the `--prepare` → commit → `--seal --deploy-commit=<sha>` → deploy → `--finalize` → verify → `--confirm` / `--rollback` order and why `--seal` exists (the deploy commit does not exist during prepare). Include the selector syntax `--select=templates:page,template-parts:site-header,global-styles:active`. List the exit codes `0/1/2/3/4` and what each means, and the partial-success rule (exit `2`, refusal report in the manifest, already-succeeded records are not reprocessed).
6. `## Concurrency protection` — per-record-key locks acquired in sorted key order at `--finalize`, released at `--confirm`/`--rollback`, refreshed by `--heartbeat` (called by `scripts/promote-overrides` between finalize and confirm), TTL and reclaim-after-expiry, exit `3` on conflict. Note that local `--prepare` uses a filesystem lock, not a database option.
7. `## Reference refusal` — what the scanner detects (navigation refs, synced-pattern `ref`, attachment/media IDs, site logo, gallery, cover/image IDs, font paths, plugin block IDs, unknown `ref`s), what a refusal reports (record/provider, block name, attribute, referenced value, suggested policy), and the **v1 navigation policy**: navigation is never promoted; a template/part may be promoted without its environment-specific navigation `ref` only when the ref-less block resolves to exactly one navigation whose normalised content hash matches the exported one — otherwise the promotion of that record is refused. No invented mappings in v1.
8. `## Global Styles` — the Theme JSON adapter, that all internal Core Theme JSON calls sit behind it and it fails closed on an unsupported Core API shape, the single-WordPress-version policy (the version `composer.lock` resolves; adapter compatibility tests re-run on every WordPress dependency update), and the resolved-output equivalence check that must pass before a Global Styles promotion is accepted. Release 4 must be green on the integration branch before this task starts. Treat any missing Global Styles promotion surface as a failed Phase B gate.
9. `## Fonts and media` — Font Library records/files and media stay database/filesystem-owned in v1; exports carry references and metadata only; nothing is copied blindly.
10. `## Sensitive file handling` — bundles and manifests default to `var/agency-state/`, which is gitignored; exports can contain customer content and internal site structure; never commit a bundle, a manifest, or a backup payload; the excluded-data list (passwords, session tokens, application passwords, secrets, commerce order/customer PII, user email addresses).
11. `## Recovery procedure` — how to get out of a bad promotion: `--rollback` inside the retention window (state the implemented window from Task B1), `promotion-backups list` / `prune --older-than=<n>d --dry-run`, what a refused rollback means (a newer promotion or a client edit landed; the tool refuses rather than clobbering), and when to escalate to `ops/incident-recovery.md`.
12. `## Revision-history trade-off` — write this in full, plainly:

```markdown
## Revision-history trade-off

Promotion converts a saved database override into the Git baseline and then
resets the database override. After that reset, the Site Editor revision
history a client built up for that template or template part may no longer be
reachable through the normal editor UI.

The protected promotion backup — and `promote-overrides --rollback` inside the
retention window — is the agency-side recovery path. It is NOT the same thing
as the client opening the Site Editor and restoring an old revision: it
restores the whole record as it stood at finalisation, it is run by an
operator with WP-CLI access, and it expires when the backup is pruned.

Tell clients this before the first promotion. A client who expects "my edit
history is always in the editor" will be surprised the first time a promotion
lands.
```

13. `## Verification proofs` — the reusable procedures Task B6 executes: the 14-step promotion proof, with the exact commands, written so any operator can re-run it on a staging environment.

- [ ] **Step 2: Cross-check every command line in the runbook against the implementation**

```bash
grep -o "wp agency [a-z-]* [^\`]*" docs/state-reconciliation.md | sort -u
```

Run each one with `--help` (or `--dry-run` where offered) against the local site and confirm the flags exist and are spelled as written. Fix the doc, not the code.

- [ ] **Step 3: Commit**

```bash
git add docs/state-reconciliation.md
git diff --cached --check
ddev composer verify:fast
git commit -m "docs: add the state reconciliation runbook"
```

---

### Task B3: Operations contracts

**Files:**
- Modify: `ops/backup.md`, `ops/restore.md`, `ops/update-process.md`, `ops/incident-recovery.md`, `ops/monitoring.md`, `ops/launch-checklist.md`

**Interfaces:**
- Consumes: `docs/state-reconciliation.md` (Task B2) and Task B1's inventory.
- Produces: ops contracts that cover state bundles, promotion backups, promotion locks, and the block-theme editing model. Task B5's fresh-clone proof and Task B6's promotion proof both reference `ops/launch-checklist.md`.

- [ ] **Step 1: `ops/backup.md`**

In "What must be backed up", replace the parenthesised phrase `and any database template/style overrides \`wp agency check-overrides\` would report` with: `and the database template, template-part, and Global Styles overrides clients make in the Site Editor — expected state under the source-of-truth model, not corruption (see docs/state-reconciliation.md)`.

Add a new bullet after the `.env` bullet:

```markdown
- **Promotion backups are not a substitute for database backups.** The
  protected promotion backups `wp agency promote-overrides --finalize` writes
  cover only the records one promotion touched, and only until they are pruned.
  They are a rollback mechanism, not a backup (see
  `docs/state-reconciliation.md`).
```

Add a new section before "Before a risky change":

```markdown
## What must NOT be backed up into the repository

State bundles (`wp agency state-export`) and promotion manifests contain
customer content and internal site structure. They default to
`var/agency-state/`, which is gitignored, and must stay out of Git and out of
any shared artifact store without the same access controls as a database
backup. Treat a leaked bundle as a data incident.
```

In "Before a risky change", add `a promotion finalisation` to the list of changes that need an ad hoc labelled backup first.

- [ ] **Step 2: `ops/restore.md`**

In the restore procedure, extend step 6's sanitize paragraph with:

```markdown
   Sanitize also regenerates the site's stable identifier
   (`agency_platform_site_uuid`) the first time a production database is seen
   outside production, so the restored copy can never satisfy a promotion
   manifest that targets production. The regeneration is idempotent — a second
   sanitize run on the same non-production copy preserves the local identifier.
```

Add a new step 7:

```markdown
7. If the restore is a rollback of a deployment that included a promotion,
   read `docs/state-reconciliation.md`'s recovery procedure BEFORE re-applying
   database overrides. `wp agency promote-overrides --rollback` refuses any
   record a newer promotion or a client edit has since changed; that refusal is
   correct and must be resolved by decision, not by force.
```

In the smoke-test checklist, replace the `check-overrides` item with:

```markdown
- [ ] `ddev wp agency check-overrides` runs and its report matches expectations.
      It is INFORMATIONAL by default — legitimate database overrides are normal
      and do not fail it. Use `--fail-on-drift` only where a non-zero exit is
      genuinely wanted (for example a CI gate).
```

Add two items:

```markdown
- [ ] `ddev wp agency state-export --output=-` succeeds and the bundle's
      signature verifies (proves the HMAC keyring is configured in this
      environment).
- [ ] `ddev wp agency promotion-backups list` runs and shows only backups you
      expect for this environment.
```

- [ ] **Step 3: `ops/update-process.md`**

Add a section (place it after the existing dependency-update guidance):

```markdown
## WordPress core updates and the Theme JSON adapter

Global Styles promotion reads and writes WordPress's theme.json origins through
a single adapter that fails closed when it does not recognise the core API
shape. A WordPress update can therefore break promotion silently if the adapter
is not re-verified.

On every WordPress dependency update (including a Dependabot PR):

1. Run the full base gate (`ddev composer verify`).
2. Run the adapter's compatibility tests (they are part of the integration
   suite; see `docs/state-reconciliation.md`).
3. If the adapter fails closed, do NOT merge the update until the adapter is
   updated — a passing site with a broken adapter means promotion silently
   stops working.

This repository targets exactly one WordPress version — the one `composer.lock`
resolves. Do not add a version matrix.
```

Add a second section:

```markdown
## Deploying while a promotion is in flight

A promotion holds per-record locks from `--finalize` until `--confirm` or
`--rollback`. Do not start a second deployment that promotes overlapping
records while the first is unresolved: it exits `3` (lock conflict) by design.
If a deployment was abandoned mid-promotion, its locks expire after their TTL
and become reclaimable; confirm the abandoned promotion's state with
`wp agency promotion-backups list` before reclaiming.
```

- [ ] **Step 4: `ops/incident-recovery.md`**

Add a scenario section following the file's existing structure:

```markdown
## A promotion made the site wrong

1. Do not hand-edit the database. Run
   `wp agency promote-overrides --rollback --manifest=<path>` — it restores the
   original records in dependency-safe order and verifies hashes afterwards.
2. Rollback is possible after `--confirm` too, while the backup is still inside
   its retention window.
3. Rollback REFUSES any record that a newer promotion or a client edit has
   changed since finalisation, and any deleted record that has since been
   re-created. Those refusals protect newer work — resolve each one by
   decision, never by forcing the restore.
4. A partial rollback exits non-zero and names every record it could not
   restore. Treat that as an open incident until each named record is resolved.
5. Full procedure and command lines: `docs/state-reconciliation.md`.
```

- [ ] **Step 5: `ops/monitoring.md`**

Add one bullet to whatever list covers what to watch:

```markdown
- **Unresolved promotions.** A promotion that reached `--finalize` but never
  reached `--confirm` or `--rollback` holds record locks and keeps a backup
  alive. Check `wp agency promotion-backups list` on a schedule and alert on
  any promotion older than one deployment cycle.
```

- [ ] **Step 6: `ops/launch-checklist.md`**

Under "Editing model", replace the first checkbox's body to describe the new posture and add two:

```markdown
- [ ] **Site Editor posture understood and recorded.** Client roles have full
      visual Site Editor control within the approved block system: templates,
      template parts, navigation, Global Styles, and page composition. They
      cannot switch or install themes/plugins, edit files, use the code editor,
      insert HTML or Shortcode blocks, or edit Additional CSS. Confirm the
      client has been told what they can and cannot change, and record any
      per-project tightening (`docs/editing-strictness.md`).
- [ ] **Revision-history trade-off communicated.** Promotion resets the database
      override it promotes, so Site Editor revision history for that record may
      become unreachable in the editor afterwards. Confirm the client knows this
      before the first promotion — see
      `docs/state-reconciliation.md#revision-history-trade-off`.
```

Add a new section before "Data safety":

```markdown
## State reconciliation

- [ ] **HMAC keyring provisioned in every environment that signs or verifies.**
      `AGENCY_PROMOTION_HMAC_KEYS` (a JSON keyring) and
      `AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID` are set in local/CI preparation and
      on the production host. A missing or empty keyring is a hard failure by
      design — there is no fallback to WordPress salts, which differ per
      environment. Record where the keys live and who can rotate them.
- [ ] **`AGENCY_TARGET_SITE_UUID` recorded for production**, and a rotation plan
      exists (add the new key, advance the signing key id, keep the old key
      until outstanding manifests and backups expire).
- [ ] **Promotion rehearsed once on staging.** Run the full proof in
      `docs/state-reconciliation.md#verification-proofs` end to end —
      export → diff → prepare → commit → seal → deploy → finalise → verify →
      confirm, plus one deliberate verification failure that auto-rolls back.
      A promotion system that has never been rehearsed is not a launch-ready
      system.
- [ ] **Promotion backup retention decided.** Confirm the retention window and
      that `wp agency promotion-backups prune` is scheduled or owned by a named
      person.
- [ ] **`var/agency-state/` is not served by the web server** and is excluded
      from any deployment artifact that leaves the host.
```

In "Data safety", extend the "No local seed artifacts in the production database" item's list of seeded artifacts with: `and a site-header template-part database override carrying the Mini-Cart block`.

- [ ] **Step 7: Verify the links resolve and commit**

```powershell
rg -l 'state-reconciliation\.md' ops docs | Sort-Object -Unique
Get-Item docs/state-reconciliation.md
```

```bash
git add -- ops/backup.md ops/restore.md ops/update-process.md \
  ops/incident-recovery.md ops/monitoring.md ops/launch-checklist.md
git diff --cached --check
ddev composer verify:fast
git commit -m "docs(ops): cover state bundles, promotion backups, and the Site Editor posture"
```

---

### Task B4: Documentation consistency sweep

**Files:**
- Modify: `AGENTS.md`, `README.md`, `docs/architecture.md`, `docs/ownership-rules.md`, `docs/editing-strictness.md`, `docs/validation-scenarios.md`, `docs/adding-a-block.md`, `docs/adding-commerce-behaviour.md`
- Modify: `docs/generated-block-index.md` (regenerated)

**Interfaces:**
- Consumes: the merged state of Tasks 1–3 and Phase A.
- Produces: a documentation set with no statement contradicted by the code. Task B8's report cites it.

Task 1 already updated several of these for the editing model. This task is the **consistency pass**: find and fix statements that the merged Tasks 2/3 and Phase A made wrong.

- [ ] **Step 1: Find every stale statement mechanically**

```powershell
rg -n 'hybrid|classic theme|classic template|Parts::|SiteEditorLockdown|site-theme/woocommerce|templates/\*\.php|shortcode cart|classic cart' AGENTS.md README.md docs ops tests/commerce/README.md -g '*.md' -g '!state-reconciliation.md'
```

```powershell
rg -n 'check-overrides' AGENTS.md README.md docs ops -g '*.md'
```

```powershell
rg -n 'always invalid|must be empty|any published template' AGENTS.md README.md docs ops -g '*.md'
```

Record every hit in a checklist and resolve each one. Spec §11.10 requires removing any wording that says a published template/part database row is always invalid.

- [ ] **Step 2: `AGENTS.md`**

1. In the intro line, confirm the theme is described as a **block theme** (not "hybrid block/classic"). Fix if Task 1 missed it.
2. Routing table: confirm the Phase A line for commerce markup overrides is present; add `State export / promotion → agency-platform src/State/ (never business logic)` if the table lacks it.
3. Layer ownership: extend the `agency-platform` bullet's list of guardrails with `state export/diff, promotion lifecycle, promotion backups`, keeping the "never business logic, never WooCommerce" clause.
4. Commands: add the state/promotion commands with one line each — `wp agency state-export`, `state-diff`, `promote-overrides --prepare|--seal|--finalize|--confirm|--rollback|--heartbeat`, `promotion-backups list|prune`, and `scripts/promote-overrides` (deployment-side, not host-side). Point at `docs/state-reconciliation.md`.
5. Commerce bullet: replace `classic cart/checkout` wording with the native block seeding, and mention the header Mini-Cart override.
6. Environment safety: add the HMAC keyring variables and the rule that a missing keyring is a hard failure with no WordPress-salt fallback.
7. "Where docs live": add `docs/state-reconciliation.md` (state export, promotion, rollback, recovery).

- [ ] **Step 3: `README.md`**

1. Fix any classic/hybrid theme description.
2. In the test table (the rows listing suites and how to run them), confirm the commerce rows still read correctly and add a row for the state/promotion integration coverage if Tasks 2/3 added a suite name not listed.
3. Add a short "State reconciliation" paragraph in the feature list pointing at `docs/state-reconciliation.md`, describing it accurately: selective, verified promotion of Site Editor changes back into Git, with backup and rollback — not automatic reconciliation.
4. Do not claim parity percentages; describe the editing posture as **full visual Site Editor control within the approved block system** (spec §2).

- [ ] **Step 4: `docs/architecture.md`**

1. Replace the source-of-truth section with spec §5.3's model and §5.4's ownership table (or a link to `docs/state-reconciliation.md`'s table plus a one-paragraph summary — do not maintain two divergent copies; keep the table in ONE place and link to it from the other).
2. Confirm the theme section describes `templates/*.html`, flat `parts/*.html`, `patterns/*.php`, and no PHP templates.
3. Add the commerce profile's template story: the exact ground-truth declared
   commerce template list and count from `commerce-template-list.php`, derived
   from upstream, with no classic override directory.

- [ ] **Step 5: `docs/ownership-rules.md`**

Add rows/lines mapping tasks to layers for the new surfaces: commerce block template change → `site-theme/templates/<slug>.html` (declared list); commerce composition for clients → `site-commerce` pattern; state provider → `agency-platform/src/State/Providers/` or a project plugin via `agency_platform_state_providers`; promotion behaviour → `agency-platform/src/State/` promotion classes.

- [ ] **Step 6: `docs/editing-strictness.md`**

1. Confirm it describes the shipped posture (one posture, no mode switch).
2. In the commerce-role dial section, add that `client_shop_manager` inherits Site Editor access from `client_editor`, and that dropping `edit_theme_options` for shop managers is a per-project dial with a named cost (no Site Editor for that role).

- [ ] **Step 7: `docs/validation-scenarios.md`**

Add a scenario per new guardrail, in the file's existing "how this is meant to fail" format:

- Commerce block markup added to a base template/part/pattern → `CommerceBoundaryTest` fails with the offending path.
- A declared commerce template loses its `site-header`/`site-footer` part → `CommerceBoundaryTest` fails.
- WooCommerce ships a new template slug → `CommerceBlockTemplatesTest::test_no_upstream_commerce_template_slug_is_unaccounted_for` fails until the slug is owned or excluded.
- The classic `site-theme/woocommerce/` directory is recreated → `CommerceBoundaryTest` fails.
- `scripts/enable-commerce` runs against a store whose checkout is not block-based → the script exits 1 with the "does not hold the native block content" message.
- A tampered state bundle or manifest → HMAC verification fails before any promotable content is read (exit 4).
- Two deployments finalising overlapping records → the second exits 3 after releasing its own locks.
- A client edit after export → finalise refuses that record.
- Rollback of a record a newer promotion changed → refused, non-zero exit.

- [ ] **Step 8: `docs/adding-a-block.md` and `docs/adding-commerce-behaviour.md`**

Confirm `adding-a-block.md` reflects block-theme reality (templates are `.html`; blocks are referenced from templates and patterns) and fix anything Task 1 missed. Confirm `adding-commerce-behaviour.md` reads correctly end to end after Phase A's edits, and add a "Verify" line for the new tests:

```sh
ddev composer test:architecture         # CommerceBoundaryTest, isolation + allow-list checks
```

- [ ] **Step 9: Regenerate the block index and run the doc-affecting gates**

```bash
php scripts/generate-block-index
ddev composer verify:fast
git status --porcelain
```

Expected: `GeneratedIndexFreshnessTest` green; only intended files modified.

- [ ] **Step 10: Commit**

```bash
git add -- AGENTS.md README.md docs/architecture.md docs/ownership-rules.md \
  docs/editing-strictness.md docs/validation-scenarios.md docs/adding-a-block.md \
  docs/adding-commerce-behaviour.md docs/generated-block-index.md
git diff --cached --check
ddev composer verify:fast
git commit -m "docs: sweep the documentation set for the block theme and state subsystem"
```

---

### Task B5: Fresh-clone proof (spec §12, 7 steps)

**Files:**
- Create (gitignored): `.superpowers/sdd/BLOCK_THEME_PROPOSAL/proofs/fresh-clone.log`
- No repository file is modified by this task.

**Interfaces:**
- Consumes: the merged integration branch plus this branch. Depends on precondition **P4** being satisfied by Task 1.
- Produces: a recorded proof that a clean clone bootstraps into a working block theme with no generated or local state file.

`scripts/setup` step 9 creates a classic navigation menu and assigns it to the `primary` theme location, and CI's base `e2e` job does the same. A block theme registers no such location. **Both files are Task 1-owned (coordinator ruling): this proof verifies them and escalates a failure; it does not repair them.** The following seven steps map one-to-one to proposal §12: (1) clean clone, (2) setup, (3) block-theme activation, (4) no generated/local state, (5) roles synchronised, (6) editor works, (7) all verification commands pass. Each required environment is a hard gate; it must not be marked skipped.

- [ ] **Step 1: Clone the branch into an isolated temporary directory**

The clone must not reuse the task worktree's DDEV project, its containers, or its database — otherwise "fresh clone" proves nothing. Give it its own project name through the gitignored `.ddev/config.freshclone.yaml` overlay, and never delete anything but the directory this step created.

Run this block in one PowerShell session. It derives and verifies the origin
URL from the current Task 4 worktree, pushes the exact branch first, and
records both commits outside the clone:

```powershell
$ErrorActionPreference = 'Stop'
$sourceRoot = (git rev-parse --show-toplevel).Trim()
$branch = (git branch --show-current).Trim()
if ($branch -ne 'feat/bt-task-4-final-hardening') { throw "FAIL: current branch is $branch" }
$originUrl = (git remote get-url origin).Trim()
if ([string]::IsNullOrWhiteSpace($originUrl)) { throw 'FAIL: origin URL is empty' }
git push origin $branch
if ($LASTEXITCODE -ne 0) { throw 'FAIL: exact branch push failed' }
$sourceSha = (git rev-parse HEAD).Trim()
$proofBase = Join-Path $env:TEMP 'wp-template-proofs'
$proofDir = Join-Path $proofBase ("freshclone-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $proofDir | Out-Null
$cloneRoot = Join-Path $proofDir 'clone'
git clone --branch $branch --single-branch $originUrl $cloneRoot
if ($LASTEXITCODE -ne 0) { throw 'FAIL: origin clone failed' }
Set-Location $cloneRoot
$cloneSha = (git rev-parse HEAD).Trim()
if ($cloneSha -ne $sourceSha) { throw "FAIL: clone HEAD $cloneSha does not equal source $sourceSha" }
@("source=$sourceSha", "clone=$cloneSha", "branch=$branch", "origin=$originUrl", "proofDir=$proofDir") | Set-Content (Join-Path $proofDir 'fresh-clone-identity.txt')
@("name: agency-freshclone") | Set-Content .ddev/config.freshclone.yaml
git status --porcelain
```

Expected: the clone contains **no** `var/agency-state/`, no `.env`, no `vendor/`, no `node_modules/`, and no state bundle or manifest; `git status --porcelain` is empty (the overlay is gitignored). Confirm the project is genuinely separate before starting it:

```bash
ddev start
ddev describe | head -20
```

Expected: project `agency-freshclone` at `https://agency-freshclone.ddev.site`, with its own database volume. If DDEV reports `agency-starter`, STOP — the overlay was not picked up and the proof would run against the task worktree's site.

- [ ] **Step 2: Run the documented setup**

```powershell
Copy-Item .env.example .env
(Get-Content .env) -replace '^WP_ENV=.*$', 'WP_ENV=development' -replace '^WP_HOME=.*$', 'WP_HOME=https://agency-freshclone.ddev.site' | Set-Content .env
ddev composer install --no-interaction --prefer-dist
ddev exec bash scripts/setup
```

Record the full output in `.superpowers/sdd/BLOCK_THEME_PROPOSAL/proofs/fresh-clone.log`.

If setup fails or warns, P4 is unsatisfied. Record the exact command and
output, escalate to the coordinator, and mark this proof BLOCKED. Do not use
the remaining checks as partial proof. Re-run from Step 1 after the Unit 1 fix.

- [ ] **Step 3: Confirm the theme activates as a block theme**

```bash
ddev wp theme list --status=active --field=name
ddev wp eval 'var_export( wp_is_block_theme() );'
ddev wp eval 'foreach ( get_block_templates() as $t ) { echo $t->slug, "\n"; }'
```

Expected: `site-theme`; `true`; the base template slugs plus the commerce slugs (the commerce ones appear because the files exist; WooCommerce is not installed in this proof).

- [ ] **Step 4: Confirm no generated or local state file is required**

```bash
git status --porcelain
ls var/agency-state 2>/dev/null || echo "no state dir — correct"
ddev wp agency check-overrides; echo "exit=$?"
```

Expected: clean status; no state directory; `check-overrides` exits `0` and is informational (spec §6 — only `--fail-on-drift` may produce a non-zero exit for drift). Also confirm:

```bash
ddev wp agency check-overrides --fail-on-drift; echo "exit=$?"
```

Expected: `0` on a freshly installed site with no overrides.

- [ ] **Step 5: Confirm client roles are synchronised**

```bash
ddev wp eval '
$r = get_role( "client_editor" );
var_export( array(
  "edit_theme_options" => $r->has_cap( "edit_theme_options" ),
  "unfiltered_html"    => $r->has_cap( "unfiltered_html" ),
) );'
ddev wp eval 'var_export( null !== get_role( "client_shop_manager" ) );'
```

Expected: `edit_theme_options` true, `unfiltered_html` false, and `client_shop_manager` **absent** (no WooCommerce in the base profile).

- [ ] **Step 6: Confirm the Site Editor works**

Log in as `client-editor` / `client-editor` at `<site>/wp/wp-login.php`, open `<site>/wp/wp-admin/site-editor.php`, open a template, make a trivial change, save, and confirm the save succeeds. Record what was changed and the result in the proof log. Then revert the change (or note it as an intentional override for Task B6's promotion proof).

- [ ] **Step 7: Confirm every verification command passes on the fresh clone**

```bash
ddev composer verify:fast
ddev composer verify
npm ci
npm run lint
npm run build
npm run test:e2e
npm run test:visual
npm run test:accessibility
git status --porcelain
```

Record every result. `npm run test:visual` compares against committed Linux-CI baselines — on a non-Linux host, record it as **environment-dependent, run in CI** rather than claiming a pass (spec §17: clearly label unavailable environment-dependent tests). Confirm the same job is green in CI before the final report claims it.

Every command in Step 7 is required. If the visual suite needs Linux CI, run
that CI job for the exact clone SHA and record the green result. If any other
required environment is absent, this proof is BLOCKED. Do not record a skip as
a pass.

- [ ] **Step 8: Tear down only the proof environment**

Remove only the verified disposable proof project and the verified disposable
directory created in Step 1. Do not use a fixed path or an unconditional
recursive cleanup command. Use the verified PowerShell procedure below.

Expected: `agency-freshclone` is gone; the task worktree's `agency-starter` project is untouched and still running.

Run this verified cleanup. It removes only the disposable directory that Step
1 created:

```powershell
$ErrorActionPreference = 'Stop'
Set-Location $cloneRoot
ddev delete -O -y
$resolvedProofDir = (Resolve-Path -LiteralPath $proofDir).Path
$resolvedBase = (Resolve-Path -LiteralPath $proofBase).Path
if ((Split-Path -Parent $resolvedProofDir) -ne $resolvedBase) { throw 'FAIL: proof directory is outside the proof base' }
if ((Get-Item -LiteralPath $resolvedProofDir).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'FAIL: proof directory is a reparse point' }
$childReparsePoints = @(Get-ChildItem -LiteralPath $resolvedProofDir -Force -Recurse | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint })
if ($childReparsePoints.Count -gt 0) { $childReparsePoints.FullName; throw 'FAIL: proof directory contains a reparse point' }
Remove-Item -LiteralPath $resolvedProofDir -Recurse -Force
ddev list
```

- [ ] **Step 9: Record the outcome; make no repository change**

This task commits nothing. If Step 2 exposed the stale bootstrap (precondition **P4**), the proof log must carry: the failing command, its output, the escalation raised, and the date. The final report lists the fresh-clone proof as BLOCKED until Task 1's fix lands and this task is re-run from Step 1.

```powershell
Set-Location $sourceRoot
git status --porcelain
```

Expected: empty output.

---

### Task B6: Full promotion proof (spec §12, 14 steps)

**Files:**
- Create (only if Task 3 provided no equivalent): `tests/promotion/verification-drill.spec.ts`
- Create (gitignored): `.superpowers/sdd/BLOCK_THEME_PROPOSAL/proofs/promotion-proof.log`

**Interfaces:**
- Consumes: Task 3's CLI as a **black box** — only documented command surfaces. Never call a promotion class directly.
- Produces: the recorded 14-step proof, including the auto-rollback drill and the refused rollback of a superseded record.

- [ ] **Step 1: Prepare the proof environment**

Run this proof on the **base profile** (`SWITCH TO BASE`, all five assertions passing). The promotion lifecycle touches templates, template parts, and Global Styles; a commerce-enabled site adds a seeded `site-header` template-part override that would appear as extra drift and confuse every diff in this proof.

Work against the local DDEV site on the task branch. Export the environment the commands need, using the names recorded in Task B1:

```bash
cd ../wp-bt-task-4-final
export AGENCY_STATE_DIR="$(pwd)/var/agency-state"
export AGENCY_REPO_ROOT="$(pwd)"
export AGENCY_EXPECTED_BRANCH="feat/bt-task-4-final-hardening"
export AGENCY_PROMOTION_HMAC_KEYS='{"proof-2026-08":"local-proof-secret-not-a-production-key"}'
export AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID='proof-2026-08'
export AGENCY_TARGET_SITE_UUID="$(ddev wp option get agency_platform_site_uuid)"
mkdir -p var/agency-state
git status --porcelain
```

Expected: clean tree. `var/agency-state/` must be gitignored (Task 2 added the entry) — confirm with `git check-ignore -v var/agency-state`.

- [ ] **Step 2: Steps 1–3 of the spec proof — save three client edits**

Before any proof action, inspect Task 3's `scripts/promote-overrides`. P5 is a
hard blocker unless the wrapper proves all five contracts: it quotes the remote
command and Playwright test path, runs `--finalize`, sends `--heartbeat` while
verification runs, runs `--confirm` only after a green verification, and runs
`--rollback` after a failed verification. Record the matching command lines and
variable names in the proof log. If any contract is absent or the local DDEV
wrapper run cannot execute it, file a Task 3 defect and stop B6 as BLOCKED. A
direct CLI sequence is never partial proof for a missing wrapper.

As `client-editor` in the Site Editor: (1) edit and save a **template** (`page`), (2) edit and save a **template part** (`site-header`), (3) make and save a **Global Styles** change (a palette colour). Record exactly what changed. Then confirm the database now holds the overrides:

```bash
ddev wp post list --post_type=wp_template --post_status=publish --fields=ID,post_name
ddev wp post list --post_type=wp_template_part --post_status=publish --fields=ID,post_name
ddev wp post list --post_type=wp_global_styles --post_status=publish --fields=ID,post_name
ddev wp eval 'echo wp_json_encode( wp_get_global_styles(), JSON_UNESCAPED_SLASHES );' > var/agency-state/proof-global-styles-before.json
```

- [ ] **Step 3: Step 4 — export state and verify the bundle signature**

```bash
ddev wp agency state-export --output=var/agency-state/proof-bundle.json; echo "exit=$?"
php -r '$b = json_decode(file_get_contents("var/agency-state/proof-bundle.json"), true); echo $b["schemaVersion"], " ", $b["hmacKeyId"], " ", substr($b["hmac"],0,12), "\n";'
```

Expected: exit `0`; the bundle carries `schemaVersion`, `hmacKeyId`, and `hmac`. Then prove tamper detection:

```bash
cp var/agency-state/proof-bundle.json var/agency-state/tampered-bundle.json
php -r '$p="var/agency-state/tampered-bundle.json"; $b=json_decode(file_get_contents($p),true); $b["siteUrl"]="https://tampered.invalid"; file_put_contents($p, json_encode($b));'
ddev wp agency promote-overrides --prepare --source=var/agency-state/tampered-bundle.json --select=templates:page --manifest=var/agency-state/should-not-exist.json; echo "exit=$?"
```

Expected: non-zero exit (`4`, tamper detection per §6) and no manifest written.

- [ ] **Step 4: Step 5 — diff state in both modes**

```bash
ddev wp agency state-diff --format=table; echo "exit=$?"
ddev wp agency state-diff --source=var/agency-state/proof-bundle.json --format=json; echo "exit=$?"
```

Expected: the Git-baseline mode reports the template and part overrides as drift (exit `2`) and lists database-owned providers as informational only; the bundle mode reports no post-export drift (exit `0`) because nothing changed since the export.

- [ ] **Step 5: Step 6 — prepare the selected overrides**

```bash
ddev wp agency promote-overrides --prepare \
  --source=var/agency-state/proof-bundle.json \
  --select=templates:page,template-parts:site-header \
  --manifest=var/agency-state/proof-manifest.json; echo "exit=$?"
git status --porcelain
```

Expected: exit `0`; `templates/page.html` and `parts/site-header.html` are modified in the working tree; the manifest records original and prepared hashes for both records. Release 4 is a required Phase B input. Repeat the prepare, seal, finalize, verify, confirm, and rollback-equivalence checks with `--select=global-styles:active`, and record the Global Styles result in the same proof log.

- [ ] **Step 6: Steps 7–8 — commit the prepared files and seal the manifest**

```bash
git add web/app/themes/site-theme/templates/page.html web/app/themes/site-theme/parts/site-header.html
git diff --cached --check
ddev composer verify:fast
git commit -m "chore: promote proof template and part overrides"
DEPLOY_SHA="$(git rev-parse HEAD)"
ddev wp agency promote-overrides --seal --manifest=var/agency-state/proof-manifest.json --deploy-commit="${DEPLOY_SHA}"; echo "exit=$?"
```

Expected: exit `0`; the manifest now carries `deployCommit` and a re-signed HMAC.

- [ ] **Step 7: Steps 9–10 — deploy and finalise**

"Deploy" here means the local site already runs the committed files. Finalise against the sealed commit:

```bash
export AGENCY_DEPLOY_COMMIT="${DEPLOY_SHA}"
ddev wp agency promote-overrides --finalize --manifest=var/agency-state/proof-manifest.json; echo "exit=$?"
```

Expected: exit `0`; the database overrides for both records are reset; the frontend is unchanged. Confirm:

```bash
ddev wp post list --post_type=wp_template --post_status=publish --fields=ID,post_name
curl -sk "$(ddev wp option get home)/sample-page/" | head -40
```

Also prove the refusal matrix while the manifest is live:

```bash
AGENCY_DEPLOY_COMMIT=0000000000000000000000000000000000000000 \
  ddev wp agency promote-overrides --finalize --manifest=var/agency-state/proof-manifest.json; echo "exit=$?"
```

Expected: non-zero — a deploy-commit mismatch must refuse.

- [ ] **Step 8: Step 11 — run Playwright against the promoted site**

```bash
npm run test:e2e
npm run test:accessibility
```

Expected: PASS — the frontend is unchanged by the promotion.

- [ ] **Step 9: Step 12 — confirm**

```bash
ddev wp agency promote-overrides --confirm --manifest=var/agency-state/proof-manifest.json; echo "exit=$?"
ddev wp agency promotion-backups list
```

Expected: exit `0`; the backup is still listed (retention keeps it past confirm). Re-run `--confirm` once and confirm it is idempotent (exit `0`, nothing changes).

- [ ] **Step 10: Step 13 — the deliberate verification failure and auto-rollback**

Before the wrapper failure drill, run the complete Release 4 Global Styles
promotion proof. This is required evidence, not a repeat note. It uses a
separate manifest and a separate proof commit:

```bash
ddev wp agency state-export --output=var/agency-state/proof-global-styles-bundle.json
ddev wp agency promote-overrides --prepare \
  --source=var/agency-state/proof-global-styles-bundle.json \
  --select=global-styles:active \
  --manifest=var/agency-state/proof-global-styles-manifest.json
git diff --name-only -- web/app/themes/site-theme/theme.json
git add -- web/app/themes/site-theme/theme.json
git diff --cached --check
ddev composer verify:fast
git commit -m "chore: promote proof global styles override"
DEPLOY_SHA_GLOBAL_STYLES="$(git rev-parse HEAD)"
ddev wp agency promote-overrides --seal \
  --manifest=var/agency-state/proof-global-styles-manifest.json \
  --deploy-commit="${DEPLOY_SHA_GLOBAL_STYLES}"
AGENCY_DEPLOY_COMMIT="${DEPLOY_SHA_GLOBAL_STYLES}" \
  ddev wp agency promote-overrides --finalize \
  --manifest=var/agency-state/proof-global-styles-manifest.json
ddev wp eval 'echo wp_json_encode( wp_get_global_styles(), JSON_UNESCAPED_SLASHES );' > var/agency-state/proof-global-styles-final.json
ddev exec -- diff -u var/agency-state/proof-global-styles-before.json var/agency-state/proof-global-styles-final.json
ddev wp agency promote-overrides --confirm --manifest=var/agency-state/proof-global-styles-manifest.json
ddev wp agency promote-overrides --rollback --manifest=var/agency-state/proof-global-styles-manifest.json
ddev wp eval 'echo wp_json_encode( wp_get_global_styles(), JSON_UNESCAPED_SLASHES );' > var/agency-state/proof-global-styles-restored.json
ddev exec -- diff -u var/agency-state/proof-global-styles-before.json var/agency-state/proof-global-styles-restored.json
```

Expected: prepare changes only the exact Global Styles adapter target;
`verify:fast` passes immediately before the proof commit; seal, finalise, and
confirm return zero; the first resolved-style diff proves the committed adapter
equals the client edit; and the second proves rollback restored the database
override. Any missing adapter, manifest, changed extra file, failed check, or
unavailable required environment is a Release 4 and B6 blocker.

Before the failure drill, run one fresh, green wrapper promotion using the
Task B1 variable names. Its log must show exact quoted values for the remote
command and test path, `--finalize`, at least one `--heartbeat` during the
verification wait, and `--confirm` after the green Playwright result. Confirm
the promoted record has no database override. A zero wrapper exit without all
of these observations fails P5 and blocks B6.

First, read how the merged wrapper selects what Playwright runs. **`AGENCY_PLAYWRIGHT_PROJECT` is a Playwright *project name* (`chromium-desktop` / `chromium-mobile`), not a path**; Task 3 defines a separate variable for the test path. Confirm both names against the implementation before using them:

```bash
ls tests/promotion/ 2>/dev/null || echo "no promotion spec dir"
grep -n "AGENCY_PLAYWRIGHT" scripts/promote-overrides
grep -n "playwright" scripts/promote-overrides
```

Expected: `AGENCY_PLAYWRIGHT_PROJECT` is passed to `--project=`, and `AGENCY_PLAYWRIGHT_TESTS` carries the test path as one quoted argument. Record both names in the proof log and use them below. If the wrapper does not support both inputs, P5 fails. STOP and return the defect to the orchestrator. Do not weaken the proof command.

If Task 3 provided a verification spec, use it and force it to fail (point `AGENCY_DEPLOY_URL` at a path that legitimately fails its assertion). If it did not, create `tests/promotion/verification-drill.spec.ts`:

```ts
import { test, expect } from '@playwright/test';

/**
 * Promotion verification drill. It exists to make `scripts/promote-overrides`
 * take its ROLLBACK branch on demand, so the auto-rollback path is proven
 * rather than assumed.
 *
 * It is inert unless AGENCY_PROMOTION_FAILURE_DRILL=1, so no npm script and no
 * CI job can ever run it by accident: `test:e2e`, `test:visual`,
 * `test:accessibility`, and `test:e2e:commerce` all target other directories,
 * and a bare `npx playwright test` would skip it.
 */
test.describe( 'promotion verification drill', () => {
	test.skip(
		process.env.AGENCY_PROMOTION_FAILURE_DRILL !== '1',
		'drill only — set AGENCY_PROMOTION_FAILURE_DRILL=1 to make verification fail on purpose'
	);

	test( 'fails on purpose so promotion verification rolls back', async ( { page } ) => {
		await page.goto( '/' );
		expect( 'promotion-verification-drill' ).toBe( 'this assertion always fails' );
	} );
} );
```

Then run a second promotion through the wrapper with the drill active:

```bash
# New override to promote, so this is a fresh promotion, not a re-run.
# (Re-edit the page template in the Site Editor as client-editor first.)
ddev wp agency state-export --output=var/agency-state/proof-bundle-2.json
ddev wp agency promote-overrides --prepare --source=var/agency-state/proof-bundle-2.json \
  --select=templates:page --manifest=var/agency-state/proof-manifest-2.json
git add web/app/themes/site-theme/templates/page.html
git diff --cached --check
ddev composer verify:fast
git commit -m "chore: promote proof template override (rollback drill)"
DEPLOY_SHA_2="$(git rev-parse HEAD)"
ddev wp agency promote-overrides --seal --manifest=var/agency-state/proof-manifest-2.json --deploy-commit="${DEPLOY_SHA_2}"

AGENCY_PROMOTION_FAILURE_DRILL=1 \
AGENCY_REMOTE_WP_CLI_COMMAND="ddev wp" \
AGENCY_DEPLOY_URL="$(ddev wp option get home)" \
AGENCY_REMOTE_STATE_DIR="$(pwd)/var/agency-state" \
AGENCY_PLAYWRIGHT_PROJECT="chromium-desktop" \
AGENCY_PLAYWRIGHT_TESTS="tests/promotion/verification-drill.spec.ts" \
AGENCY_VERIFICATION_TIMEOUT=300 \
AGENCY_DEPLOY_COMMIT="${DEPLOY_SHA_2}" \
  bash scripts/promote-overrides --manifest=var/agency-state/proof-manifest-2.json; echo "exit=$?"
```

`AGENCY_PLAYWRIGHT_PROJECT` names the Playwright project; `AGENCY_PLAYWRIGHT_TESTS` names the spec path. Use these implemented names. A different or missing interface fails P5 and blocks this proof.

Expected: the wrapper finalises, runs Playwright, sees the failure, calls `--rollback`, exits **non-zero**, and preserves logs. Confirm the database override is restored and the frontend matches the pre-promotion state:

```bash
ddev wp post list --post_type=wp_template --post_status=publish --fields=ID,post_name
curl -sk "$(ddev wp option get home)/sample-page/" | head -40
```

Use only the flag spellings Task B1 recorded. If the wrapper cannot be driven
against the local DDEV site, if its quoting loses an argument, if it lacks a
heartbeat, or if it does not confirm on green and roll back on failure, file a
Task 3 defect and mark P5 and B6 BLOCKED. Do not call `--finalize` or
`--rollback` directly as a substitute.

- [ ] **Step 11: Step 14 — a rollback REFUSED because a SECOND PROMOTION claimed the record**

Spec §12 step 14 is specific: "Attempt rollback of a record **a second promotion has since changed** → verify it is REFUSED (does not overwrite newer work)." That is the §7.9 rule "no newer promotion has since claimed the same record". A plain client edit exercises a *different* refusal rule (the modification-marker check) and does not prove this one. So promotion **C** must be a complete, confirmed promotion of the same record promotion **A** promoted.

Promotion A (Steps 2–9) promoted `templates:page` and `template-parts:site-header` and was confirmed; its backup is still in retention. Now run promotion C over `template-parts:site-header`:

```bash
# 1. A fresh client edit to that same record: as client-editor in the Site
#    Editor, edit and save the site-header template part again.
ddev wp post list --post_type=wp_template_part --post_status=publish --fields=ID,post_name,post_modified_gmt

# 2. Export, prepare, commit, seal, finalize, confirm — a full second promotion
#    of the SAME record, not a client edit left sitting in the database.
ddev wp agency state-export --output=var/agency-state/proof-bundle-3.json
ddev wp agency promote-overrides --prepare \
  --source=var/agency-state/proof-bundle-3.json \
  --select=template-parts:site-header \
  --manifest=var/agency-state/proof-manifest-3.json; echo "prepare exit=$?"

git add web/app/themes/site-theme/parts/site-header.html
git diff --cached --check
ddev composer verify:fast
git commit -m "chore: promote proof header override (second promotion)"
DEPLOY_SHA_3="$(git rev-parse HEAD)"

ddev wp agency promote-overrides --seal --manifest=var/agency-state/proof-manifest-3.json --deploy-commit="${DEPLOY_SHA_3}"; echo "seal exit=$?"
AGENCY_DEPLOY_COMMIT="${DEPLOY_SHA_3}" ddev wp agency promote-overrides --finalize --manifest=var/agency-state/proof-manifest-3.json; echo "finalize exit=$?"
ddev wp agency promote-overrides --confirm --manifest=var/agency-state/proof-manifest-3.json; echo "confirm exit=$?"
```

Expected: every exit code `0`. Promotion C now owns `template-parts:site-header`.

```bash
# 3. Roll back promotion A. Its backup is still in retention, so the command
#    runs — and must REFUSE the record promotion C has since claimed.
ddev wp agency promote-overrides --rollback --manifest=var/agency-state/proof-manifest.json; echo "rollback exit=$?"
```

Expected: **non-zero exit**. The refusal report must name `template-parts:site-header` and must attribute the refusal to a **newer promotion** (promotion C's UUID), not merely to a changed modification marker. Record the exact wording. If the tool refuses for the modification-marker reason instead, record that as a divergence from §7.9's "no newer promotion has since claimed the same record" check and report it as a defect — the protection is weaker than specified.

```bash
# 4. Prove promotion C's work survived untouched.
ddev wp post list --post_type=wp_template_part --post_status=publish --fields=ID,post_name,post_modified_gmt
git show --stat HEAD -- web/app/themes/site-theme/parts/site-header.html
curl -sk "$(ddev wp option get home)/" | head -40
```

Expected: the header content is promotion C's, not promotion A's pre-promotion original.

- [ ] **Step 12: Revert every proof-only commit and restore the baseline**

The proof created real commits (Steps 6, 10, and 11) whose content is promotion noise, not a wanted change to the starter's baseline. Revert all of them — never rewrite history on a pushed branch — and prove the files and the database are back to the intended baseline.

```bash
# List the proof commits, newest first, and revert them in that order.
git log --oneline --grep='^chore: promote proof' | tee ../proof-commits.txt
for sha in $(git log --format=%H --grep='^chore: promote proof'); do
  ddev composer verify:fast
  git revert --no-edit "${sha}"
done

# The reverted files must now match the integration branch exactly.
git diff origin/feat/block-theme-fse-migration -- web/app/themes/site-theme/templates/page.html web/app/themes/site-theme/parts/site-header.html web/app/themes/site-theme/theme.json
```

Expected: the diff is empty. If it is not, the reverts did not fully restore the baseline — resolve before continuing.

```bash
# Remove every proof artifact and confirm nothing leaked into Git.
rm -f var/agency-state/*.json ../proof-commits.txt
git status --porcelain
git check-ignore -v var/agency-state
```

Expected: empty status; `var/agency-state` reported as ignored.

Finally, confirm the database carries no leftover proof override:

```bash
ddev wp agency check-overrides; echo "exit=$?"
ddev wp agency promotion-backups list
```

Expected: `check-overrides` reports only what you intend to leave behind (ideally nothing), and the backup list shows only the proof promotions, which the retention window will expire. Record both outputs.

Write the full transcript, every exit code, and every observation into `.superpowers/sdd/BLOCK_THEME_PROPOSAL/proofs/promotion-proof.log`.

- [ ] **Step 13: Verify and commit the drill spec if one was created**

```bash
ddev composer verify:fast
npm run lint:js
npm run test:e2e
git status --porcelain
```

Expected: all green; the status lists at most `tests/promotion/verification-drill.spec.ts` plus the revert commits already made.

```bash
git add tests/promotion/verification-drill.spec.ts
git diff --cached --check
ddev composer verify:fast
git commit -m "test: add the promotion verification failure drill"
git status --porcelain
```

Expected: empty output after the commit. If Task 3 already provided a verification spec, make no commit here.

---

### Task B7: Full validation matrix (spec §12)

**Files:**
- Create (gitignored): `.superpowers/sdd/BLOCK_THEME_PROPOSAL/proofs/validation-matrix.log`

**Interfaces:**
- Consumes: the finished branch.
- Produces: the recorded command matrix Task B8's report quotes verbatim.

- [ ] **Step 1: Run the base matrix on the BASE profile and record each exact result**

Run `SWITCH TO BASE` first (all five assertions must pass). A base result recorded from a commerce-enabled site is not a result.

```bash
ddev composer verify:fast
ddev composer verify
npm ci
npm run lint
npm run build
npm run test:e2e
npm run test:visual
npm run test:accessibility
git status --porcelain
```

Record for each: the command, the profile it ran on, the exit code, and the test counts from its output. Do not summarise a failure as a pass. `npm run test:visual` on a non-Linux host is environment-dependent — label it and cite the CI run instead. `git status --porcelain` must be empty, and in particular must not list `composer.json` or `composer.lock`.

The matrix has no optional required environment. When a local visual environment
is unavailable, run the matching CI job for the exact branch SHA and record its
green result. Otherwise mark the matrix BLOCKED. Never mark a required suite as
skipped or passed without evidence.

- [ ] **Step 2: Run the commerce matrix on the COMMERCE profile and record each exact result**

Run `SWITCH TO COMMERCE`, then:

```bash
ddev composer test:integration:commerce
COMMERCE=1 npm run test:e2e:commerce
```

Record each with its profile. Note that `enable-commerce` has re-added the ephemeral WooCommerce require, so the tree is intentionally dirty in exactly two files right now.

- [ ] **Step 3: Return to the base profile and prove nothing leaked**

Run `SWITCH TO BASE` again — its assertions are the proof — then:

```bash
ddev composer verify:fast
git status --porcelain
git diff --stat composer.json composer.lock
```

Expected: green gate; empty status; an empty diff for both Composer files. The template must never carry a committed WooCommerce dependency, and no commerce database state may survive into a base run.

- [ ] **Step 4: Confirm CI is green on the pushed branch**

```bash
git push -u origin feat/bt-task-4-final-hardening
gh run list --branch feat/bt-task-4-final-hardening --limit 5
gh run watch
```

Record the job-by-job result: `php-qa`, `frontend`, `integration`, `e2e`, `commerce-e2e`. A job that is red is a blocker, not a note.

- [ ] **Step 5: Write the matrix log**

Write `.superpowers/sdd/BLOCK_THEME_PROPOSAL/proofs/validation-matrix.log` with one row per command: command, environment (local DDEV / CI), exit code, counts, and any "environment-dependent, see CI" label.

---

### Task B8: Required final report (spec §17) and closure

**Files:**
- Create (gitignored): `.superpowers/sdd/BLOCK_THEME_PROPOSAL/task-4-final-report.md`

**Interfaces:**
- Consumes: every proof log from Tasks B5–B7, the merged-surface inventory from B1, and the ground-truth file from A1.
- Produces: the engagement's closing report, delivered both as the file and as the agent's final message.

- [ ] **Step 1: Gather the commit and file facts**

```bash
git log --oneline origin/main..HEAD
git rev-parse HEAD
git diff --stat origin/main..HEAD
git diff --name-status origin/main..HEAD | sort -k1,1
```

- [ ] **Step 2: Write the report with every §17 heading**

Write `.superpowers/sdd/BLOCK_THEME_PROPOSAL/task-4-final-report.md` with these sections, each filled from recorded evidence — never from memory:

1. **Final commit SHA.**
2. **All commits created** (the full `git log --oneline` range).
3. **Files added, changed, and deleted** (from `git diff --name-status`).
4. **Architecture changes** — block theme; commerce templates derived from upstream; classic commerce override directory retired; `src/State/` subsystem; separate CLI registrars.
5. **Permission changes** — the final capability matrix for `client_editor` and `client_shop_manager` (`edit_theme_options` true, `edit_css` false, `unfiltered_html` false, `customize` → `do_not_allow`), the admin-screen allow/deny boundary, and the server-side save validation.
6. **State provider coverage** — every provider slug and whether it is promotable (from B1's inventory).
7. **Command examples** — a working example line for each command in §6.
8. **Tests run and exact results** — paste the validation matrix from B7. Label every environment-dependent test explicitly. Never claim a skipped test passed.
9. **Visual parity metrics** — migration parity and editing parity numbers from Task 1's suites, cited from the CI run that produced them; if this task did not re-run them, say so and cite Task 1's recorded figures.
10. **Promotion/rollback proof** — the 14-step transcript summary from B6, including the tamper refusal, the deploy-commit-mismatch refusal, the auto-rollback drill, and the refused rollback of a superseded record.
11. **Commerce results** — the commerce integration and E2E counts, the derived template list, the excluded upstream slugs, the Mini-Cart override, and confirmation that no WooCommerce require is committed.
12. **Known limitations** — write these honestly, including: no commerce CSS and no commerce keys in `theme.json`; the base header carries no Mini-Cart by design; the verified Global Styles promotion status from Release 4; anything the P1/P2/P3/P4/P5 precondition checks flagged; any command whose implemented contract diverges from §6.
13. **Phase 4 status** — `cinq-wp` is out of scope for this engagement; no back-port artifacts were produced.
14. **Confirmation that no customer state or secrets were committed.** Cite three checks. The scan must exclude the files that legitimately carry those words — the JSON **schemas** Tasks 2 and 3 committed (`resources/schemas/state-bundle-v1.json`, `promotion-manifest-v1.json`) are schema definitions, not state, and `.env.example` is a committed template with no secret in it. A scan that flags them and is then waved through is worse than no scan.

```bash
# (a) Nothing tracked under the state directory, ever.
git ls-files -- 'var/agency-state' | sed '/^$/d' | grep . && echo "FAIL: tracked state artifact" || echo "OK: no tracked state artifacts"
git check-ignore -v var/agency-state

# (b) Nothing tracked under the internal planning tree.
git ls-files -- '.superpowers' | sed '/^$/d' | grep . && echo "FAIL: tracked planning artifact" || echo "OK: .superpowers not tracked"

# (c) No state bundle, manifest instance, backup payload, or real .env in the
#     branch diff. Schema DEFINITIONS and .env.example are excluded by name.
git diff --name-only origin/main..HEAD \
  | grep -Ei 'agency-state|bundle|manifest|backup|\.env' \
  | grep -vE '^web/app/mu-plugins/agency-platform/resources/schemas/' \
  | grep -vE '^\.env\.example$' \
  | grep . && echo "FAIL: review each path above" || echo "OK: none"

# (d) No real secret value in the diff.
git diff origin/main..HEAD | grep -nE 'AGENCY_PROMOTION_HMAC_KEYS=.+[A-Za-z0-9]' | grep -v 'proof-2026-08' || echo "OK: no committed keyring value"
```

Every check must print its `OK` line. Any `FAIL` is a blocker: name the path, explain it, and do not close the task. If check (c) legitimately matches a documentation file that merely *mentions* a manifest, list it explicitly in the report with the reason it is safe — never widen the exclusion patterns to silence it.

- [ ] **Step 3: Check the release-gate closing conditions**

Walk spec §13's four release gates plus "All releases" and record PASS or FAIL for every bullet with the evidence line. Release 4 and its Global Styles promotion proof must be PASS. A FAIL is a blocker: report it and do not close.

- [ ] **Step 4: Report closure evidence to the orchestrator**

Report the commit range, Phase A and Phase B gate results, proof paths, and any escalation to the orchestrator. The orchestrator owns and updates `docs/superpowers/plans/2026-08-02-block-theme-tracking.md` after its review and merge gate.

- [ ] **Step 5: Deliver the report**

Emit the report as the final message, and confirm the branch is ready to merge into `feat/block-theme-fse-migration`:

```bash
git status --porcelain
ddev composer verify:fast
```

Expected: empty status, green gate.

---

## Definition of done

This task carries no release gate of its own — it closes Releases 1–4. It is done when **all** of the following hold, each with recorded evidence.

**Cross-task preconditions (this task cannot close without them):**

- [ ] **P1** and **P2** satisfied — Phase A's templates landed without editing a Task 1-owned test.
- [ ] **P3** satisfied — `tests/support/BlockIndexGenerator.php` indexes `templates/*.html`, proven empirically in Task A8 Step 3. **Hard gate: Phase A does not close otherwise.**
- [ ] **P4** satisfied by Task 1 — the fresh-clone proof completed on a bootstrap that creates a block-theme navigation. If it is still stale, the fresh-clone proof is BLOCKED, not passed.
- [ ] **P5** satisfied by Task 3 — `scripts/promote-overrides` runs in the proof environment, and its Playwright wiring accepts both the required project name and test path. If not, the promotion proof is BLOCKED, not passed.
- [ ] Every ownership item this task deliberately did NOT fix (the `WooCommerceIsolationTest` wording, `scripts/setup`, `.github/workflows/ci.yml`) is recorded and escalated, not silently left.

**Profile separation (the base profile is never certified from a commerce site):**

- [ ] Every base result in the validation matrix is labelled with the profile it ran on, and each was produced after a `SWITCH TO BASE` whose five assertions passed.
- [ ] CI shows `e2e` and `commerce-e2e` green independently — each builds its own environment, which is the strongest available proof neither profile depends on the other.

**Spec §13 "All releases" closing conditions:**

- [ ] No state bundle, backup payload, secret, or customer data is committed — all four checks in Task B8 Step 2.14 print their `OK` line.
- [ ] `git status` is clean, and every commit in the branch was made on a green `verify:fast`. No commit in this task's history was made while a suite was red.

**Spec §12 proofs recorded:**

- [ ] Full command matrix run and recorded, base and commerce, with every environment-dependent test explicitly labelled (`.superpowers/sdd/BLOCK_THEME_PROPOSAL/proofs/validation-matrix.log`).
- [ ] Fresh-clone proof, all 7 steps, recorded (`proofs/fresh-clone.log`), run in an isolated temporary directory under its own DDEV project.
- [ ] Full isolated-adapter promotion proof, all 14 steps, recorded (`proofs/promotion-proof.log`) — including the intentional Playwright failure that auto-rolls back, a rollback refused **because a second confirmed promotion claimed the record**, and the Release 4 Global Styles selection and resolved-output-equivalence result. The isolated DDEV proof target is the accepted full promotion target for this template.
- [ ] Every proof-only commit reverted, with `git diff origin/feat/block-theme-fse-migration` empty for the promoted files.

**Spec §11.8 (commerce profile):**

- [ ] Every justified commerce block template exists under `site-theme/templates/`, derived from upstream with only the header/footer part slugs rewritten and environment-specific template-part `theme` attributes removed. No `templates/woocommerce/*` directory exists.
- [ ] The Mini-Cart reaches the header through the `site-commerce/header-mini-cart` pattern and the `scripts/enable-commerce` template-part override; the base header part stays commerce-free.
- [ ] Styling is `theme.json`/block-supports only; no commerce CSS, no commerce keys in `theme.json`.
- [ ] `scripts/enable-commerce` seeds native Cart/Checkout **blocks** and hard-fails if either page is not block-based.
- [ ] `site-theme/woocommerce/` is deleted and the policy is documented in `docs/adding-commerce-behaviour.md`.
- [ ] `tests/Architecture/CommerceBoundaryTest` and `tests/commerce/Integration/Theme/CommerceBlockTemplatesTest` both pass; commerce E2E covers the block storefront.

**Spec §11.14 (commerce Playwright):**

- [ ] Shop Manager Site Editor access is proven, and the theme-installer/file-editor/Customizer refusals still hold.
- [ ] Commerce storefront journeys run against the block templates.

**Spec §11.15 (documentation):**

- [ ] `docs/state-reconciliation.md` exists and covers every required bullet, including the revision-history trade-off.
- [ ] `ops/backup.md`, `ops/restore.md`, `ops/update-process.md`, `ops/incident-recovery.md`, `ops/monitoring.md`, and `ops/launch-checklist.md` are updated.
- [ ] The consistency sweep leaves no document claiming the theme is hybrid/classic, that a published template/part database row is always invalid, or that the classic commerce override directory exists.
- [ ] `docs/generated-block-index.md` matches `php scripts/generate-block-index`.

**Spec §17:**

- [ ] The final report exists with all fourteen required elements and is delivered as the closing message, with no completion claim for a skipped test.
