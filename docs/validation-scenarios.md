# Validation Scenarios

Twelve deliberate mutations that each break one guardrail this starter
enforces, the exact command to trigger the check, the failure signature the
mutation should produce, and how to revert. Use these to prove a guardrail
actually fails closed (not just that it exists) — for example after
changing an architecture test, or when onboarding to trust the toolchain.

Every failure message from `tests/Architecture/*` follows the same
five-line shape (see `tests/support/FormatsArchitectureFailures.php`):
`Architecture rule broken` / `Offending file` / `Why this rule exists` /
`Where the code belongs` / `How to validate the fix`. That shape is quoted
verbatim below wherever the check is a PHPUnit architecture test.

Scenarios 1–7 and 12 run with no database and are the same checks CI's
`php-qa`/`frontend` jobs run on every push — **proven in this repo's CI**.
Scenarios 8–11 need a live WordPress install (DDEV) or a CI-only artifact
(committed visual baselines) — **requires DDEV/CI context**; Task 13
executes these live.

---

## 1. `components/` directory — forbidden catch-all name

**Proven in CI** (`php-qa` / `test:architecture`).

Mutation:
```sh
mkdir -p web/app/themes/site-theme/src/components
touch web/app/themes/site-theme/src/components/.gitkeep
```

Check:
```sh
ddev composer test:architecture
```

Expected failure (`DirectoryRulesTest::test_no_forbidden_directory_names_in_theme_or_plugins`):
```
Architecture rule broken: Catch-all directory name is forbidden
Offending file:           web/app/themes/site-theme/src/components
Why this rule exists:     Directories like inc/, includes/, helpers/, misc/, utils/ collect unrelated code and defeat a predictable, purpose-named layout.
Where the code belongs:   Give the code a purpose-named home: a feature namespace under src/, or the relevant blocks/parts/templates folder.
How to validate the fix:  ddev composer test:architecture
```

Revert:
```sh
rm -rf web/app/themes/site-theme/src/components
```

---

## 2. Anonymous hook callback

**Proven in CI** (`php-qa` / `test:architecture`).

Mutation — add to `web/app/plugins/site-core/src/Plugin.php` (inside `boot()`):
```php
add_action( 'init', function () {} );
```

Check:
```sh
ddev composer test:architecture
```

Expected failure (`HookOwnershipTest::test_no_closures_are_registered_as_hooks`):
```
Architecture rule broken: Closure passed to add_action()/add_filter()
Offending file:           web/app/plugins/site-core/src/Plugin.php:<line> (add_action)
Why this rule exists:     Anonymous callbacks cannot be unhooked, identified in stack traces, or unit-tested in isolation; every hook needs a named owner.
Where the code belongs:   Replace the closure with a [ self::class, 'method' ] / [ $this, 'method' ] callback on a named class method.
How to validate the fix:  ddev composer test:architecture
```

Revert: remove the added line.

---

## 3. site-core referencing a theme class (Deptrac)

**Proven in CI** (`php-qa` / `deptrac`).

Mutation — add to `web/app/plugins/site-core/src/Plugin.php`:
```php
use SiteTheme\Bootstrap\ThemeBootstrap;
// ...and reference it somewhere reachable, e.g.:
$unused = ThemeBootstrap::class;
```

Check:
```sh
ddev composer deptrac
```

Expected failure signature: exit code 1, with Deptrac's formatter reporting
one violation naming the offending class, the class it must not depend on,
and the layer pair — the general shape is `<file>:<line> <FromClass> must
not depend on <ToClass> (SiteCore on SiteTheme)`, plus a summary line
(`1 violation(s) detected`, or similar wording depending on the installed
Deptrac version). Exact table formatting depends on the Deptrac version
resolved in the container — verify the shape directly rather than
string-matching it, since it isn't controlled by this repo's own code.

Revert: remove the `use` statement and the reference.

---

## 4. WooCommerce reference inside site-core

**Proven in CI** (`php-qa` / `test:architecture`).

Mutation — add to `web/app/plugins/site-core/src/Plugin.php`:
```php
$has_woo = class_exists( 'WooCommerce' );
```

Check:
```sh
ddev composer test:architecture
```

Expected failure (`WooCommerceIsolationTest::test_no_woocommerce_symbols_outside_commerce_and_allowlist`):
```
Architecture rule broken: WooCommerce symbol found outside the commerce boundary
Offending file:           web/app/plugins/site-core/src/Plugin.php:<line> uses 'WooCommerce'
Why this rule exists:     The base profile must run without WooCommerce; commerce code belongs only in site-commerce and the theme woocommerce/ overrides.
Where the code belongs:   Move the code into web/app/plugins/site-commerce/, or — if it is a reviewed exception — add it to tests/Architecture/woocommerce-allowlist.php with a reason.
How to validate the fix:  ddev composer test:architecture
```

Revert: remove the added line.

---

## 5. Invalid `block.json` asset path

**Proven in CI** (`php-qa` / `test:architecture`).

Mutation — in `web/app/themes/site-theme/blocks/reference-callout/block.json`,
change:
```json
"style": "file:./style.css",
```
to:
```json
"style": "file:./styles.css",
```
(a filename that doesn't exist).

Check:
```sh
ddev composer test:architecture
```

Expected failure (`BlockManifestTest::test_file_asset_references_resolve_inside_the_block`):
```
Architecture rule broken: block.json style references a missing file
Offending file:           web/app/themes/site-theme/blocks/reference-callout/styles.css
Why this rule exists:     A file: reference that does not resolve means the editor script/style or render callback is missing at runtime.
Where the code belongs:   Add the referenced file or correct the path in block.json.
How to validate the fix:  ddev composer test:architecture
```

Revert: change `"file:./styles.css"` back to `"file:./style.css"`.

---

## 6. Raw hex color in block CSS

**Proven in CI** (`frontend` / `npm run lint:css`).

Mutation — add to `web/app/themes/site-theme/blocks/reference-callout/style.css`:
```css
.reference-callout { color: #ff0000; }
```

Check:
```sh
npm run lint:css
```

Expected failure (stylelint, `scale-unlimited/declaration-strict-value`):
```
web/app/themes/site-theme/blocks/reference-callout/style.css
 X:Y  ✖  Use design tokens: var(--wp--preset--color--*) / var(--wp--custom--*) instead of raw colors (see theme.json).  scale-unlimited/declaration-strict-value
```
(`X:Y` and the surrounding report formatting depend on the installed
stylelint version; the rule name and message text above are quoted
verbatim from `.stylelintrc.json`.)

Revert: remove the added rule.

---

## 7. `wp_remote_get()` called from the theme

**Proven in CI** (`php-qa` / `test:architecture`).

Mutation — add to `web/app/themes/site-theme/src/Bootstrap/ThemeBootstrap.php`:
```php
wp_remote_get( 'https://example.com' );
```

Check:
```sh
ddev composer test:architecture
```

Expected failure (`IntegrationBoundaryTest::test_outbound_http_only_lives_in_integration_layers`):
```
Architecture rule broken: Outbound HTTP call outside an integration layer
Offending file:           web/app/themes/site-theme/src/Bootstrap/ThemeBootstrap.php:<line> (wp_remote_get)
Why this rule exists:     Direct network egress from the theme, site-core, or the platform makes side effects unpredictable and untestable; egress belongs behind an integration.
Where the code belongs:   Move the call into web/app/plugins/site-integrations/ (base profile) or web/app/plugins/site-commerce/src/Integrations/ (commerce), behind a SiteCore\Contracts\* interface.
How to validate the fix:  ddev composer test:architecture
```

Revert: remove the added line.

---

## 8. Unexpected database template record

**Requires DDEV/CI context** — needs a live database.

Mutation:
```sh
ddev wp post create --post_type=wp_template --post_status=publish \
  --post_title="Custom Front Page" --post_name=front-page --porcelain
```

Check:
```sh
ddev wp agency check-overrides
```

Expected failure (`AgencyPlatform\Cli\AgencyCommands::check_overrides`):
```
Template/template-part overrides: 1
  - front-page (wp_template) [publish]
Expected core-generated global-styles records: <N>
Synced patterns (informational only): <N>
Error: Database overrides found — Git owns templates/template-parts. Reconcile or intentionally re-export them to disk.
```
Exits non-zero (`WP_CLI::error()`).

Revert:
```sh
ddev wp post delete <ID> --force
```
(`<ID>` is the ID printed by the `--porcelain` create above.)

---

## 9. `client_editor` given an admin capability

**Requires DDEV/CI context** — needs a live database + `test:integration`.

Mutation — temporarily remove `'unfiltered_html'` from
`AgencyPlatform\Roles\RolesProvider::NEVER_GRANT` in
`web/app/mu-plugins/agency-platform/src/Roles/RolesProvider.php`. This is
the one `NEVER_GRANT` entry that's actually live for `client_editor`: core's
`editor` role is granted `unfiltered_html` by default on a single-site
install, so `client_editor_capabilities()` (which starts from `editor`'s
capability set) genuinely strips it via this exclusion — removing the
exclusion genuinely restores the capability. (`'manage_options'` is NOT a
usable mutation here: core's `editor` role never has `manage_options` to
begin with, so unsetting an absent key from a desired-capability array is a
no-op and the test would still pass. A raw `wp cap add client_editor
unfiltered_html` also would not reproduce this failure:
`RolesProvider::register_role()` re-syncs the role's capabilities against
its computed desired set on every `init`, so a capability outside
`NEVER_GRANT`'s exclusion is stripped back out on the very next request —
the array mutation above is the guardrail's actual failure mode.)

Check (filtered directly with phpunit, since `composer test:integration`
is a two-step script and `--` argument forwarding across composer script
arrays isn't reliable — set `WP_INTEGRATION=1` the same way the composer
script does):
```sh
ddev exec env WP_INTEGRATION=1 vendor/bin/phpunit --testsuite integration \
  --filter test_client_editor_lacks_every_never_grant_capability
```

Expected failure (`ClientEditorCapabilitiesTest::test_client_editor_lacks_every_never_grant_capability`,
quoting the test's own assertion message verbatim):
```
1) Tests\Integration\Permissions\ClientEditorCapabilitiesTest::test_client_editor_lacks_every_never_grant_capability
client_editor must not have the 'unfiltered_html' capability.
Failed asserting that true is false.
```

Revert: restore `'unfiltered_html'` in `NEVER_GRANT`.

---

## 10. Production webhook safety net disabled locally

**Requires DDEV/CI context** — reads the live environment.

Mutation — in `config/environments/development.php`, flip the hardcoded
kill-switch:
```php
Config::define('AGENCY_DISABLE_OUTBOUND_WEBHOOKS', true);
```
to:
```php
Config::define('AGENCY_DISABLE_OUTBOUND_WEBHOOKS', false);
```
while `WP_ENV` stays `development` (the checked-out default).

Editing the `AGENCY_DISABLE_OUTBOUND_WEBHOOKS` line in `.env` does NOT reproduce
this failure: that variable is informational/reserved and is never read into
the constant (see the note in `.env.example`). The live kill-switch — and the
exact thing `verify-env` checks (`! defined( 'AGENCY_DISABLE_OUTBOUND_WEBHOOKS' )
|| true !== AGENCY_DISABLE_OUTBOUND_WEBHOOKS`) — is the hardcoded
`Config::define()` above, present in both
`config/environments/development.php` and `config/environments/staging.php`.

Check:
```sh
ddev wp agency verify-env
# equivalently: bash scripts/verify-environment
```

Expected failure (`AgencyPlatform\Cli\AgencyCommands::verify_env`):
```
  - AGENCY_DISABLE_OUTBOUND_WEBHOOKS must be defined and true when WP_ENVIRONMENT_TYPE is not "production" (current: "development").
Error: 1 environment invariant(s) failed for "development".
```
Exits non-zero. Note this invariant only guards non-production environments
outright — `wp_get_environment_type() === 'production'` short-circuits
`verify-env` to a success with a warning, since there is nothing to check
there (see the command's docblock).

Revert: set the `Config::define('AGENCY_DISABLE_OUTBOUND_WEBHOOKS', ...)` in
`config/environments/development.php` back to `true` (or `git checkout --
config/environments/development.php`).

---

## 11. Modified visual snapshot

**Requires CI context** — visual baselines are Linux-CI-authoritative and
are not committed to this repository yet (`tests/visual/__screenshots__/`
does not exist until a maintainer runs `ci.yml` via `workflow_dispatch`
with `update_visual_snapshots: true` and commits the downloaded
`visual-baselines` artifact). Baselines are always CI-generated; a local
Linux run (e.g. WSL/Ubuntu driving Chromium through the official
`mcr.microsoft.com/playwright` Docker image) can generate throwaway
baselines with `npx playwright test tests/visual --update-snapshots` to
validate the regression *mechanism*, but those local baselines must never
be committed — CI's Linux runner font rendering is the only authority (see
`playwright.config.ts`'s top-of-file comment). Once baselines exist:

Mutation — change a color the `home.spec.ts` baseline covers across a
*large area* of the page. Edit `theme.json`'s `settings.color.palette`
`base` value (the page background, wired to the document background via
`styles.color.background` → `var(--wp--preset--color--base)`) — e.g.
`#ffffff` → `#ff0000`:
```json
{ "slug": "base", "name": "Base", "color": "#ff0000" }
```
Do NOT use the `primary` palette value for this scenario: on the fresh
install's sparse home page `primary` only tints a handful of thin text
links (`styles.elements.link.color.text`), a pixel delta well *under* the
`maxDiffPixelRatio: 0.01` threshold — the mutation reaches the rendered
CSS but the check still passes, so it proves nothing. `base` repaints the
whole page background and moves ~0.9 of all pixels, comfortably past the
threshold.

Check (flush any object cache first so the regenerated global styles are
served, then run the suite):
```sh
ddev wp cache flush
npm run test:visual
```

Expected failure (Playwright `toHaveScreenshot`):
```
Error: expect(page).toHaveScreenshot(expected) failed

  <N> pixels (ratio 0.91 of all image pixels) are different.

  Snapshot: home-desktop.png

Expected: tests/visual/__screenshots__/chromium-desktop/home-desktop.png
Received: test-results/.../home-desktop-actual.png
    Diff: test-results/.../home-desktop-diff.png
```
(threshold is `maxDiffPixelRatio: 0.01`, set in `playwright.config.ts`; a
`-diff.png` image is written next to the `-actual.png`/`-expected.png`
pair under `test-results/`).

Revert: restore the `theme.json` change (`git checkout --
web/app/themes/site-theme/theme.json`), `ddev wp cache flush`, and re-run
`npm run test:visual` — it goes green. To intentionally accept a visual
change instead, re-run with `--update-snapshots` on Linux CI only, then
have a maintainer review and commit the result (see `playwright.config.ts`'s
top-of-file comment on why local/non-Linux snapshots must never be
committed).

---

## 12. Stale generated block index

**Proven in CI** (`php-qa` / `test:architecture`, and weekly via
`scheduled-maintenance.yml`'s `stale-index-check` job).

Mutation — edit
`web/app/themes/site-theme/blocks/reference-callout/block.json`'s
`"title"` field without regenerating the index (the generator's `Title`
column reads directly from `block.json`; unlike `title`, `description`
is not part of the generated output, so editing it would NOT reproduce
this failure), and leave `docs/generated-block-index.md` untouched:
```json
"title": "Reference Callout (edited)",
```

Check:
```sh
ddev composer test:architecture
```

Expected failure (`GeneratedIndexFreshnessTest::test_committed_block_index_matches_regeneration`):
```
Architecture rule broken: Generated block index is stale
Offending file:           docs/generated-block-index.md
Why this rule exists:     The committed index no longer matches what the generator produces from the current blocks/patterns/tests.
Where the code belongs:   Run `php scripts/generate-block-index` and commit the regenerated docs/generated-block-index.md.
How to validate the fix:  ddev composer test:architecture
```

Revert:
```sh
git checkout -- web/app/themes/site-theme/blocks/reference-callout/block.json
# or, having intentionally kept the block.json change:
php scripts/generate-block-index
```
