# Block theme migration — Phase 0 baseline

Baseline commit: `b59c099c35d9f12ed6e40169e7be48dff43fe2fd`

Branch: `feat/bt-task-1-theme-and-editing`

Captured: 2026-08-03

Host: Windows 11, WSL2 Ubuntu, DDEV v1.25.3, Docker 29.4.3, Node v24.16.0, local npm 11.16.0. CI target is Node 22 / npm 10.

## Baseline command results

| Command | Result | Notes |
|---|---|---|
| `ddev composer verify:fast` | PASS (exit 0) | deptrac 0 violations; PHPStan level 6 clean; 22 architecture tests / 130 assertions; 121 unit tests / 298 assertions. Composer prints `A script named audit would override a Composer command and has been skipped` — pre-existing and benign. |
| `ddev composer verify` | PASS (exit 0) | `verify:fast` plus 27 integration tests / 75 assertions. |
| `npm ci` | FAIL (exit 1, `EUSAGE`) | Local npm is 11.16.0. It rejects the committed lock: `npm ci can only install packages when your package.json and package-lock.json ... are in sync`, then `Missing: @parcel/watcher-<platform>@2.5.6 from lock file` for every platform variant. This is a local toolchain mismatch, NOT a repository defect — the lock is correct for npm 10, which is what CI runs. |
| `npx -y npm@10 ci` | PASS (exit 0) | 1004 packages. This is the supported local path and it matches CI. `AGENTS.md` already requires npm 10 for lock work; record here that npm 10 is also required to INSTALL locally when the host npm is a newer major. |
| `npm run lint` | PASS (exit 0) | ESLint + Stylelint. |
| `npm run build` | PASS (exit 0) | wp-scripts production build. `git status --porcelain` is empty afterwards, so the committed `blocks/reference-callout/build/` output has no drift. |
| `npm run test:e2e` | FAIL (exit 1) at default parallelism; PASS (exit 0) at `--workers=1` | Default run: 10 passed, 3 failed, 5 skipped, 16 workers. Serial run: 13 passed, 5 skipped, 0 failed. See "Finding A" below. |
| `npm run test:accessibility` | PASS (exit 0) | 4 passed. No WCAG 2 A/AA violations on the home page or on Sample Page, desktop and mobile. |
| `npm run test:visual` | FAIL (exit 1) | 1 passed (desktop), 1 failed (mobile), 2 skipped. `home-mobile.png` differs by 60725 pixels, ratio 0.18, against `maxDiffPixelRatio: 0.01`. See "Finding B" below. |

Site under test: `https://agency-starter.ddev.site`, HTTP 200 on both schemes.

**Finding A — the e2e suite is not safe at high worker counts.** `playwright.config.ts` sets `fullyParallel: true` and caps no worker count, so a 16-core developer host runs 16 workers against ONE WordPress instance. All three failures came from the same place, `tests/e2e/helpers/auth.ts:27`. Every failure is a `client_editor` login. Concurrent logins as the same user invalidate each other's auth cookie, and WordPress sends the loser back to `wp-login.php` with `reauth=1`. The failures move between projects between runs. Re-running the identical suite with `--workers=1` gives 13 passed, 5 skipped, 0 failed, exit 0. The `client-editor` user exists and its role is correct. Record this as a PRE-EXISTING host-parallelism defect, not a code defect and not a migration blocker. Do not fix it in this task. A local `npm run test:e2e` needs `--workers=1` to be trustworthy on a many-core host.

**Finding B — the visual failure is the documented Windows condition.** The plan's Global Constraints state that visual screenshots are Linux-CI-authoritative and that a baseline PNG must never be generated on Windows or macOS. The committed baselines came from Linux CI. This run was on Windows, so a mismatch is expected, not new damage. The mobile viewport is the one that fails because narrower text reflows line breaks and every later element shifts. Record it as expected and NOT as a pre-existing defect.

**Finding C — the shared local database carries WooCommerce residue.** The DDEV database volume is shared by project name, so it followed this worktree from the main checkout. It contains leftovers from an earlier `scripts/enable-commerce` run:

- Published pages `Shop`, `Cart`, `Checkout`, `My account` (IDs 15 to 18).
- 2 `product` posts.
- Users `shop-manager` (`client_shop_manager`) and `test-customer` (`customer`).
- `wp_woocommerce_*` tables.

WooCommerce itself is NOT installed and `site-commerce` is inactive, so no commerce code runs and the base-profile results above remain valid. The primary navigation menu holds only `Home` and `Sample Page`, so the header render is unaffected. Record this as a baseline-hygiene warning. The authoritative pre-migration parity baselines are captured by CI on a fresh install in Task 2, which is immune to this residue.

Environment notes:

- DDEV runs inside WSL2 Ubuntu on this host, not on Windows. Every PHP and Composer command must be issued from WSL against the `/mnt/c/...` path.
- DDEV refuses to move a listed project. Moving the `agency-starter` project between the main checkout and a task worktree requires `ddev stop --unlist agency-starter` first. The `agency-starter-mariadb` volume survives that, so the installed database follows the project and no WordPress re-install is needed.
- `ddev delete` would destroy the volume and is forbidden against the main checkout.

## Classic rendering path inventory

All paths below are relative to `web/app/themes/site-theme/`. Each path exists at this commit. Phase 3 deletes these files.

- Root delegates: `404.php`, `archive.php`, `index.php`, `page.php`, `search.php`, `single.php`.
- Root chrome: `header.php`, `footer.php`.
- PHP templates: `templates/404.php`, `templates/archive.php`, `templates/index.php`, `templates/page.php`, `templates/search.php`, `templates/single.php`.
- PHP parts: `parts/site-header/site-header.php`, `parts/site-header/site-header.css`, `parts/site-header/site-header.js`, `parts/site-footer/site-footer.php`, `parts/site-footer/site-footer.css`.
- Support class: `src/Support/Parts.php`.
- Global CSS being replaced: `assets/global/base.css`, `assets/global/typography.css`.

`functions.php` and `src/Bootstrap/ThemeBootstrap.php` remain after the migration. This is not the full theme file list.

## Patterns and custom blocks

The pattern file is `patterns/reference-landing-section.php`. The custom block is `agency/reference-callout` at `blocks/reference-callout/`. The block directory contains these files:

- `block.json`
- `index.js`
- `README.md`
- `render.php`
- `style.css`
- `editor.css`
- `build/index.js`
- `build/index.asset.php`

## Tests coupled to the classic path

Each listed file contains the named symbol or assertion.

- `tests/Architecture/DirectoryRulesTest.php` — `ALLOWED_THEME_FILES` lists the six root delegates plus `header.php` and `footer.php`.
- `tests/Architecture/ThemeBootstrapTest.php` — `test_each_template_has_a_thin_root_delegate` and `test_every_root_delegate_maps_to_a_template` exist.
- `tests/Architecture/GlobalAssetRulesTest.php` — `ALLOWED_GLOBAL_CSS = array( 'base.css', 'typography.css' )` exists, and `test_part_assets_are_named_after_their_part` exists.
- `tests/Unit/SiteTheme/PartsTest.php` — the full file validates only `SiteTheme\Support\Parts`. Its `@covers` annotation and assertions show this.
- `tests/e2e/smoke.spec.ts` — executable assertions use `header.site-header`, `.site-header__site-title`, `footer.site-footer`, `main#site-main`, `#site-header-nav`, and `.site-header__toggle`.
- `tests/visual/__screenshots__/chromium-desktop/home-desktop.png` and `tests/visual/__screenshots__/chromium-mobile/home-mobile.png` — both files exist and must be regenerated after conversion.
- `tests/support/BlockIndexGenerator.php` — `files_containing()` filters to `.php` by checking `pathinfo( $file, PATHINFO_EXTENSION )`, so `.html` templates are invisible to the generated index.

Discrepancy: `.is-open` appears only in a comment at `tests/e2e/smoke.spec.ts:12`. The file has no executable assertion for the `.is-open` class. The other named selectors have executable assertions.

## Role, lockdown and database-override tests

- `tests/Integration/Permissions/ClientEditorCapabilitiesTest.php` — `never_grant_capabilities()` includes `edit_theme_options`. `test_client_editor_is_restricted_to_the_approved_block_allow_list` asserts `assertSame( EditorRestrictions::ALLOWED_BLOCKS, ... )`. `test_code_editing_and_block_locking_are_disabled_for_client_editor` calls `assertFalse( $settings['canLockBlocks'] )`.
- `tests/Unit/AgencyPlatform/EditorRestrictionsPolicyTest.php` — the file has eight test methods. Six methods directly reference `EditorRestrictions::ALLOWED_BLOCKS`.

Discrepancy: the brief says all eight methods use `EditorRestrictions::ALLOWED_BLOCKS`. The repository does not support this claim. The two privileged-user tests at lines 36 and 45 do not directly use the constant. The six direct references are at lines 16, 25, 29, 33, 51, and 58.

- `tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php` — `test_shop_manager_cannot_reach_the_keys_to_the_kingdom` includes `edit_theme_options`.
- `tests/Unit/AgencyPlatform/DatabaseOverrideClassifyTest.php` — the file has 13 public test methods. This test is unchanged by this plan. A later engagement task replaces the detection internals.

## Commerce-specific tests

Each listed path exists. This task changes none of these test files. The plan affects them as follows:

- `tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php` — **touched**. Task 3 Step 13 moves `edit_theme_options` from the negative list to a positive Site Editor inheritance test.
- `tests/commerce/Integration/Health/CommerceSanitizeStepTest.php` — untouched.
- `tests/commerce/Integration/SiteCommerce/PluginBootTest.php` — untouched.
- `tests/commerce/Integration/bootstrap.php` — untouched.
- `tests/commerce/e2e/commerce-journey.spec.ts` — untouched. It drives the classic storefront cart and checkout markup that `scripts/enable-commerce` seeds. The commerce track converts this markup to native Cart and Checkout blocks. This plan does not do that work.
- `tests/commerce/e2e/shop-manager-admin.spec.ts` — untouched. `AdminScreenPolicy` removes the full Appearance menu. Its `expectNoAdminMenu( page, 'menu-appearance' )` assertion stays true.
- `tests/Unit/SiteCommerce/CommerceSanitizeStepTest.php` and `tests/Unit/SiteCommerce/PluginGuardTest.php` — untouched.
- `tests/commerce/README.md` — untouched.

## Setup and CI assumptions that break

- `scripts/setup` step 9/9 runs the menu commands. `scripts/setup:198` has `echo "==> [9/9] Ensuring a primary navigation menu exists"`. `scripts/setup:202` has `"${WP[@]}" menu create "Primary" >/dev/null`. `scripts/setup:203` has `"${WP[@]}" menu item add-custom primary "Home" "${WP_HOME_VALUE}/" >/dev/null`. `scripts/setup:208` has `"${WP[@]}" menu item add-post primary "${sample_page_id}" >/dev/null`. `scripts/setup:210` has `"${WP[@]}" menu location assign primary primary >/dev/null`. A block theme has no nav-menu locations. The location assignment fails after conversion. The current classic theme registers `primary` and `footer` at `web/app/themes/site-theme/src/Bootstrap/ThemeBootstrap.php:57-61`.
- The refusal message says the script creates pretty permalinks. `scripts/setup:66` has `echo "    - a 'Primary' navigation menu and pretty permalinks" >&2`. `scripts/setup` has no `wp rewrite structure` call.
- The `.github/workflows/ci.yml` `e2e` job starts at line 165. Its `Create primary navigation menu` step is at `.github/workflows/ci.yml:223`. Lines 230-233 have `ddev wp menu create "Primary"`, `ddev wp menu item add-custom primary "Home" https://agency-starter.ddev.site/`, `ddev wp menu item add-post primary "$(ddev wp post list --post_type=page --name=sample-page --field=ID)"`, and `ddev wp menu location assign primary primary`.

## Phase 0 spike findings

The Phase 0 spike moves the posts-index front page (`/`) and page requests such as `/sample-page/` to block rendering through `templates/index.html` and the temporary `templates/page.html`. Requests whose hierarchy resolves to a PHP template without an equal-or-higher block template remain classic; `single.php` continues to serve `/hello-world/`. `locate_block_template()` first finds the PHP template and slices the hierarchy at that template, so only block templates with equal or higher specificity can replace it.

## Intentional content differences

The block footer drops the classic `gmdate( 'Y' )` year and the `get_bloginfo( 'name' )` interpolation because no core block produces either. The copyright line is client-editable static text.

## Required test replacements

This spike widens `GlobalAssetRulesTest::ALLOWED_GLOBAL_CSS` to include `editor.css`. Tasks 6 and 7 must replace the classic-path assertions coupled to the migration:

- `tests/Architecture/DirectoryRulesTest.php`
- `tests/Architecture/ThemeBootstrapTest.php`
- `tests/Architecture/GlobalAssetRulesTest.php`
- `tests/Unit/SiteTheme/PartsTest.php`
- `tests/e2e/smoke.spec.ts`
- `tests/visual/__screenshots__/chromium-desktop/home-desktop.png`
- `tests/visual/__screenshots__/chromium-mobile/home-mobile.png`
