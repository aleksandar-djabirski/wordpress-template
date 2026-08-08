# Validation Scenarios

Twenty-four deliberate mutations that each break one guardrail this starter
enforces, the exact command to trigger the check, the failure signature the
mutation should produce, and how to revert. Use these to prove a guardrail
actually fails closed (not just that it exists) — for example after
changing an architecture test, or when onboarding to trust the toolchain.

Every failure message from `tests/Architecture/*` follows the same
five-line shape (see `tests/support/FormatsArchitectureFailures.php`):
`Architecture rule broken` / `Offending file` / `Why this rule exists` /
`Where the code belongs` / `How to validate the fix`. That shape is quoted
verbatim below wherever the check is a PHPUnit architecture test.

Scenarios 1–7, 12, 14, 15, 16, 17 and 19 run with no database and are the
same checks CI's `php-qa`/`frontend` jobs run on every push — **proven in
this repo's CI**. Scenarios 8–10, 13 and 21–24 need a live WordPress install
(DDEV); scenario 11 additionally relies on the committed,
Linux-CI-authoritative visual baselines — **requires DDEV/CI context**.
Scenarios 18 and 20 need the commerce profile — WooCommerce installed via
`bash scripts/enable-commerce`.

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
Why this rule exists:     The base profile must run without WooCommerce; commerce PHP belongs only in site-commerce and the commerce-owned theme override locations.
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

## 8. Database template record drift

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

Expected report (`AgencyPlatform\Cli\AgencyCommands::check_overrides`):
```
Template/template-part overrides: 1
  - front-page (wp_template) [publish]
Expected core-generated global-styles records: <N>
Synced patterns (informational only): <N>
Success: 1 database override(s) reported. Overrides are expected under the Site Editor editing model; pass --fail-on-drift to make them a hard failure.
```
Exits **zero**. A database template row is a legitimate client edit under the block-theme editing model, not a guardrail breach.

Gate check:
```sh
ddev wp agency check-overrides --fail-on-drift
```

Expected failure:
```
Error: 1 database override(s) found and --fail-on-drift was requested. Reconcile them through the promotion workflow, or re-run without the flag to report only.
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

**Requires CI context** — the visual baselines are committed at
`tests/visual/__screenshots__/chromium-{desktop,mobile}/` and are
Linux-CI-authoritative: CI's `e2e` job runs `npm run test:visual` against
them on every push. Regenerate them only through the documented CI flow —
run `ci.yml` via `workflow_dispatch` with the `update_visual_snapshots`
input set true, which (re)generates the baselines on a Linux runner and
uploads them as the `visual-baselines` artifact for a maintainer to review
and commit; nothing is committed automatically. Because browser font
hinting/anti-aliasing differs across OSes, a local Linux run (e.g.
WSL/Ubuntu driving Chromium through the official
`mcr.microsoft.com/playwright` Docker image) can only validate the
regression *mechanism* via `npx playwright test tests/visual
--update-snapshots` — those throwaway baselines must never be committed,
since CI's Linux runner is the sole authority (see `playwright.config.ts`'s
top-of-file comment). To run the mutation:

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
change instead, regenerate the baselines through `ci.yml`'s
`update_visual_snapshots` flow (Linux CI only), then have a maintainer
review and commit the result (see `playwright.config.ts`'s top-of-file
comment on why local/non-Linux snapshots must never be committed).

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

---

## 13. Forbidden block saved through REST

**Requires DDEV/CI context** — needs a live WordPress install and the
`client-editor` user.

Mutation — send a REST page update as `client-editor` with a `core/html` block:
```sh
ddev wp eval '
$page = get_page_by_path( "demo" );
$before = $page->post_content;
$user = get_user_by( "login", "client-editor" );
wp_set_current_user( $user->ID );
$request = new WP_REST_Request( "POST", "/wp/v2/pages/" . $page->ID );
$request->set_body_params( array( "content" => "<!-- wp:html -->bad<!-- /wp:html -->" ) );
$response = rest_do_request( $request );
echo wp_json_encode( array(
	"status" => $response->get_status(),
	"data" => $response->get_data(),
	"unchanged" => $before === get_post_field( "post_content", $page->ID ),
) );
'
```

Check: the REST response.

Expected failure:
```text
HTTP 403
code: agency_platform_forbidden_block
violations[0].block: core/html
unchanged: true
```

Guardrail: `AgencyPlatform\Editor\SaveValidation`.

Revert: no revert is needed; the rejected request leaves the page unchanged.

---

## 14. Classic PHP template reintroduced

Mutation:
```powershell
New-Item -ItemType File -Path web/app/themes/site-theme/index.php
```

Check:
```sh
ddev composer test:architecture
```

Expected failure:
```text
ThemeBootstrapTest::test_no_classic_root_template_files_remain
DirectoryRulesTest::test_theme_top_level_files_are_on_the_whitelist
```

Revert:
```powershell
Remove-Item -LiteralPath web/app/themes/site-theme/index.php
```

---

## 15. Hard-coded navigation ref in a Git-owned part

Mutation — change `parts/site-header.html`'s navigation block to:
```html
<!-- wp:navigation {"ref":42} /-->
```

Check:
```sh
ddev composer test:architecture
```

Expected failure:
```text
BlockThemeStructureTest::test_no_hardcoded_database_refs_in_git_owned_markup
```

Revert: restore the original navigation block in `parts/site-header.html`.

---

## 16. Commerce block markup in a base template, template part, or pattern

**Proven in CI** (`php-qa` / `test:architecture`).

Mutation — add a commerce block to a base template. Append to
`web/app/themes/site-theme/templates/page.html`:
```html
<!-- wp:woocommerce/cart /-->
```

Check:
```sh
ddev composer test:architecture
```

Expected failure (`CommerceBoundaryTest::test_commerce_blocks_only_appear_in_declared_commerce_templates`):
```
Architecture rule broken: Commerce block markup outside the declared commerce templates
Offending file:           templates/page.html
Why this rule exists:     The base profile must run with no commerce plugin installed; a commerce block in a base template, part, or pattern renders as a broken block there.
Where the code belongs:   Move the markup into a declared commerce template, or register it as a pattern from web/app/plugins/site-commerce/.
How to validate the fix:  ddev composer test:architecture
```
(The same violation in a template part or a theme pattern is reported by
`CommerceBoundaryTest::test_parts_and_patterns_carry_no_commerce_blocks`.)

Revert: remove the added line.

---

## 17. A declared commerce template loses a theme part

**Proven in CI** (`php-qa` / `test:architecture`).

Mutation — remove the site-header template part from a declared commerce
template. In `web/app/themes/site-theme/templates/single-product.html`,
delete:
```html
<!-- wp:template-part {"slug":"site-header","tagName":"header"} /-->
```

Check:
```sh
ddev composer test:architecture
```

Expected failure (`CommerceBoundaryTest::test_declared_commerce_templates_render_the_theme_chrome`):
```
Architecture rule broken: A commerce template does not render the theme chrome
Offending file:           single-product -> site-header
Why this rule exists:     Rendering the theme header/footer parts is the ONLY reason these overrides exist; a template without them is worse than no override at all.
Where the code belongs:   Re-derive the file from the upstream template, rewrite only the header/footer template-part slugs, and remove environment-specific template-part theme attributes.
How to validate the fix:  ddev composer test:architecture
```

Revert: `git checkout -- web/app/themes/site-theme/templates/single-product.html`.

---

## 18. The commerce plugin ships a new template slug

**Requires the commerce profile** — needs WooCommerce installed
(`bash scripts/enable-commerce`).

Mutation — remove one deliberate exclusion so an upstream slug becomes
unaccounted for. In `tests/Architecture/commerce-template-list.php`, delete
the `page-checkout` entry from the `excluded` list.

Check:
```sh
ddev composer test:integration:commerce
```

Expected failure
(`CommerceBlockTemplatesTest::test_no_upstream_commerce_template_slug_is_unaccounted_for`):
```
The commerce plugin ships block template slugs this theme has never decided about:
page-checkout
Add each to tests/Architecture/commerce-template-list.php — either as an owned template or as an excluded slug with a reason.
```
The failure stays until the slug is owned (`templates`) or deliberately
excluded with a reason.

Revert: restore the `page-checkout` exclusion.

---

## 19. The classic `site-theme/woocommerce/` override directory is recreated

**Proven in CI** (`php-qa` / `test:architecture`).

Mutation:
```powershell
New-Item -ItemType Directory -Path web/app/themes/site-theme/woocommerce
```

Check:
```sh
ddev composer test:architecture
```

Expected failure (`CommerceBoundaryTest::test_classic_override_directory_is_gone`):
```
Architecture rule broken: The classic commerce template override directory is back
Offending file:           web/app/themes/site-theme/woocommerce
Why this rule exists:     A block theme has one rendering path. Classic PHP template overrides would reintroduce the second path the migration removed.
Where the code belongs:   Override through templates/<slug>.html or a commerce hook in site-commerce instead; see docs/adding-commerce-behaviour.md.
How to validate the fix:  ddev composer test:architecture
```

Revert:
```powershell
Remove-Item -LiteralPath web/app/themes/site-theme/woocommerce
```

---

## 20. `scripts/enable-commerce` against a store whose checkout is not block-based

**Requires the commerce profile** — needs WooCommerce installed.

Mutation — replace the checkout page's content with plain text that lacks
the block:
```sh
ddev wp post update "$(ddev wp option get woocommerce_checkout_page_id)" \
  --post_content="plain text"
```

Check:
```sh
bash scripts/enable-commerce
```

Expected failure — the script's own step 4 verification
(`scripts/enable-commerce:257-266`), exit 1 before the store is configured
further:
```
FAILED: the checkout page does not hold the native block content.
  Expected a 'wp:woocommerce/checkout' block in page #<id>.
  The commerce suites must exercise the block checkout, not a shortcode fallback.
```

Revert:
```sh
ddev wp post delete "$(ddev wp option get woocommerce_checkout_page_id)" --force
bash scripts/enable-commerce   # install_pages recreates the page with block content
```

---

## 21. Tampered state bundle or manifest

**Requires DDEV/CI context** — needs a live database and a signed bundle.

Mutation — export a signed bundle, then change one character inside it:
```sh
ddev exec env AGENCY_PROMOTION_HMAC_KEYS='{"2026-01":"<32+ random characters>"}' AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID=2026-01 wp agency state-export --output=var/agency-state/probe-bundle.json
# then edit any value inside var/agency-state/probe-bundle.json
```

Check:
```sh
ddev exec env AGENCY_PROMOTION_HMAC_KEYS='{"2026-01":"<32+ random characters>"}' AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID=2026-01 wp agency state-diff --source=var/agency-state/probe-bundle.json
```

Expected failure — exit 4 (tamper), nothing read: `StateBundle::load()`
schema-validates, verifies the signature, then recomputes the `stateHash`
before any record is touched:
```
The HMAC does not match: the document was modified, or it was not signed for this purpose.
```
A manifest edited the same way is refused by `--finalize` with the same
exit 4, before any guard or mutation runs. (Without the keyring
environment, both commands exit 1 with
`AGENCY_PROMOTION_HMAC_KEYS is not set` instead — that is the missing-keyring
failure, not the tamper path.)

Revert: re-export the pristine bundle.

---

## 22. Two deployments finalising overlapping records

**Requires DDEV/CI context** — needs a live database and two sealed
manifests covering the same record.

Mutation — promotion A finalizes `templates:page` and stays unconfirmed
(its per-record locks are held); promotion B, prepared from a newer bundle
over the same record, attempts to finalize.

Check:
```sh
ddev exec env AGENCY_DEPLOY_COMMIT=<deploy-sha-B> AGENCY_PROMOTION_HMAC_KEYS='{"2026-01":"<32+ random characters>"}' AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID=2026-01 wp agency promote-overrides --finalize --manifest=var/agency-state/proof-b-manifest.json
```

Expected failure — exit 3 (lock conflict), and B releases every lock its
own attempt already acquired:
```
Record "templates:page" is locked by promotion <A's id> (owner <owner>) until <expiresAtUtc>.
```
(Message from `RecordLockManager::acquire`; see
`docs/state-reconciliation.md`'s exit-code table.)

Revert: settle A (`--confirm` or `--rollback`), then finalize B.

---

## 23. A client edit after export: finalise refuses the record

**Requires DDEV/CI context** — needs a live database and a sealed manifest.

Mutation — export, prepare and seal a promotion for `templates:page` (steps
1–9 of the verification proof in `docs/state-reconciliation.md`), then edit
the template in the Site Editor and save it — the live row no longer matches
the exported record.

Check:
```sh
ddev exec env AGENCY_DEPLOY_COMMIT=<deploy-sha> AGENCY_PROMOTION_HMAC_KEYS='{"2026-01":"<32+ random characters>"}' AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID=2026-01 wp agency promote-overrides --finalize --manifest=var/agency-state/proof-manifest.json
```

Expected failure — exit 1 (zero successes, one refusal), the refusal reason
`concurrent-edit` written into the manifest report:
```
The database override changed between export and finalisation; re-export before promoting.
```

Revert: re-export the bundle, then re-prepare and re-seal.

---

## 24. Rollback of a record a newer promotion changed

**Requires DDEV/CI context** — needs a live database and two manifests over
the same record.

Mutation — promotion A finalizes `templates:page`; promotion B later
finalizes the same record (steps 12–14 of the verification proof in
`docs/state-reconciliation.md`); roll A back.

Check:
```sh
ddev exec env AGENCY_PROMOTION_HMAC_KEYS='{"2026-01":"<32+ random characters>"}' AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID=2026-01 wp agency promote-overrides --rollback --manifest=var/agency-state/proof-manifest.json
```

Expected failure — exit 1, the refusal reason
`claimed-by-newer-promotion`:
```
A newer promotion (<B's id>) has claimed this record; refusing to roll it back.
```

Revert: confirm B (or resolve the newer promotion first and then roll back
deliberately).
