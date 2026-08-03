# Master Implementation Brief — Block Theme/FSE Migration and DB↔Git Reconciliation

## 1. Role and execution policy

You are the implementing agent for:

* Primary repository: `aleksandar-djabirski/wordpress-template`
* Primary branch to create: `feat/block-theme-fse-migration`
* Optional later repository: `cinq-wp`
* WordPress project root: repository root
* Theme: `web/app/themes/site-theme`
* Guardrail plugin: `web/app/mu-plugins/agency-platform`

Complete the release gates in this order: Release 1, Release 2, Release 3, Release 4, commerce hardening, and final closure. The phase descriptions below define required behaviour, not a conflicting merge order. Do not skip validation gates. Continue automatically when a gate passes. Stop only when a hard gate fails or required repository/environment access is unavailable. When stopped, leave the branch in a safe state and report the exact blocker, evidence, and smallest next action.

Use `feat/block-theme-fse-migration` as the integration branch. Keep `main` unchanged until every release gate, the final independent review, the post-fix review, and the complete verification matrix pass. Then merge the integration branch into local `main` and push `main` without force. If `origin/main` changed after the integration branch was created, integrate it first and repeat the complete final verification.

Only the orchestrator edits the shared tracking file. Task workers report status and commit SHAs to the orchestrator. Do not run two DDEV-backed task environments at the same time when they use the same DDEV project name or database.

Do not ask the operator to make WordPress or PHP design decisions. Use the decisions in this brief.

Before editing, read:

* `AGENTS.md`
* `docs/architecture.md`
* `docs/editing-strictness.md`
* `docs/ownership-rules.md`
* `docs/adding-a-block.md`
* `docs/validation-scenarios.md`
* `ops/launch-checklist.md`
* Existing architecture, unit, integration, commerce, and Playwright tests

Run PHP and Composer commands through DDEV as required by the repository.

---

## 2. Goal

Convert `site-theme` from a locked-down classic/hybrid theme to a native WordPress block theme.

The result must provide:

1. Full visual Site Editor control for client roles — over templates, template parts, navigation, Global Styles, and page composition within the approved block system.
2. Near-WYSIWYG editing for static layout, typography, spacing, colours, header, footer, templates, and page composition.
3. One reusable starter template with no editing-mode switch.
4. A text-based and agent-readable implementation.
5. Complete agent-readable access to Site Editor state, visual structure, and supported content state (not the entire WordPress database — see §6 and §7.2 for scoping).
6. Safe, selective promotion of database template/style changes into Git.
7. Concurrency protection, backup, verification, and rollback during promotion.
8. The existing layered architecture and verification pipeline.
9. No Elementor or proprietary page-builder data model.
10. No classic-theme fallback in the final result.

Do not describe the result as identical to Elementor, and do not overstate it as "full native Gutenberg freedom" — clients still cannot use HTML/Shortcode blocks, the code editor, Additional CSS, or install plugins. Describe it accurately as full visual Site Editor control within the approved block system. Only claim a parity percentage when screenshot results support it.

---

## 3. Scope

### 3.1 Primary engagement

Complete Phases 0–3 in `wordpress-template`.

### 3.2 Optional Phase 4

Back-port the completed architecture to `cinq-wp` only when all of these are true:

* The template migration has been merged or its final commit is available.
* The `cinq-wp` repository is available.
* `EXECUTE_CINQ_BACKPORT=1` is explicitly supplied.

Without that flag, produce the Phase 4 handoff plan and inventory report, but do not modify `cinq-wp`.

### 3.3 Out of scope

Do not:

* Add Elementor, Visual Composer, ACF layout systems, or another page builder.
* Add a Permissive/Hybrid/Controlled mode switch.
* Maintain a parallel classic template path.
* Move business logic into the theme or `agency-platform`.
* Add outbound HTTP to `agency-platform`.
* Automatically reconcile production edits during deployment without an explicit promotion manifest.
* Promote unresolved database IDs blindly.
* Commit production state exports, promotion backup payloads, user content, uploaded font binaries without licence review, or secrets.

---

## 4. Non-negotiable repository rules

Preserve all existing hard rules unless this brief explicitly replaces a classic-theme-specific assertion:

* `functions.php` remains at most 50 lines.
* `functions.php` registers no hooks directly.
* Production hooks use named methods, not closures.
* No forbidden catch-all directories.
* Every custom block has valid `block.json`.
* Theme dependencies remain limited to `SiteCore\Contracts\*`.
* `agency-platform` has no project-layer dependencies.
* Outbound HTTP remains limited to integration layers.
* CSS colours use WordPress design-token variables.
* WooCommerce behaviour remains in `site-commerce`.
* Generated block index stays current.
* `verify:fast`, `verify`, frontend lint/build, and relevant Playwright suites remain commit gates.
* The final theme has only one rendering path.

The following classic-theme rules must be replaced rather than weakened:

* Root PHP template delegates.
* PHP files under `templates/`.
* Nested PHP part directories and `Parts::MANIFEST`.
* Part-local asset naming tied to `Parts::assets()`.

---

## 5. Final architecture decisions

### 5.1 Theme architecture

The final theme is a block theme because it contains `templates/index.html`.

Use:

* `templates/*.html` for request templates.
* `parts/*.html` directly under `parts/` for template parts.
* `patterns/*.php` for reusable compositions.
* Native core blocks whenever possible.
* Custom blocks only when core blocks, patterns, bindings, or variations cannot satisfy the requirement.
* JavaScript `edit` components and editor styles for every custom dynamic block.

Do not add `add_theme_support('block-templates')`. The block-theme file structure is the mechanism.

### 5.2 Editing posture

Ship one posture: full visual Site Editor control within the approved block system.

Client roles can:

* Open the Site Editor.
* Edit theme templates and template parts.
* Edit page/post composition.
* Edit navigation.
* Use Global Styles.
* Change colours, typography, spacing, and layout through visual controls.
* Lock their own blocks.

Client roles cannot:

* Switch or install themes.
* Install or activate plugins.
* Edit theme/plugin files.
* Use the code editor.
* Insert `core/html`.
* Insert `core/shortcode`.
* Edit raw Additional CSS.
* Receive `manage_options`, `edit_themes`, `edit_plugins`, `edit_files`, `switch_themes`, `install_plugins`, or `activate_plugins`.

Keep `unfiltered_html` denied.

### 5.3 Source-of-truth model

Replace “Git owns shape; DB owns content” with:

> Git owns code and the promoted baseline. The database can contain intentional live overrides. Runtime truth is the active Git baseline plus current database state. Agents must export and inspect current state before changing or promoting structure and styles.

Database changes are expected, not automatically considered corruption.

### 5.4 Database ownership defaults

Use these defaults:

| State                        | Default owner                 | Promotion policy                                    |
| ---------------------------- | ----------------------------- | --------------------------------------------------- |
| Theme templates              | Git baseline + DB overrides   | Promotable                                          |
| Template parts               | Git baseline + DB overrides   | Promotable                                          |
| Global Styles                | Git baseline + DB user origin | Promotable                                          |
| Navigation                   | Database                      | Export and diff; do not promote to Git in v1        |
| Synced patterns (`wp_block`) | Database                      | Export and diff; do not promote automatically in v1 |
| Page/post/CPT content        | Database                      | Export and diff; never promote to theme files       |
| Media and attachment records | Database + uploads            | Export references/metadata; never copy blindly      |
| Font Library records/files   | Database + uploads            | Export; keep DB/filesystem-owned in v1              |
| Additional CSS               | Forbidden for client roles    | Detect and refuse automatic promotion               |

A future project can add providers through a documented extension point.

---

## 6. Required command surface

Implement these WP-CLI commands:

```text
wp agency state-export --output=<path> [--providers=<list>] [--include-content]
wp agency state-diff [--source=<state-bundle>] [--format=table|json]
wp agency promote-overrides --prepare --source=<state-bundle> --select=<selectors> --manifest=<path>
wp agency promote-overrides --seal --manifest=<path> --deploy-commit=<sha>
wp agency promote-overrides --finalize --manifest=<path>
wp agency promote-overrides --confirm --manifest=<path>
wp agency promote-overrides --rollback --manifest=<path>
wp agency promote-overrides --heartbeat --manifest=<path>
wp agency promotion-backups list
wp agency promotion-backups prune [--older-than=30d] [--dry-run]
```

**Export scoping.** The default `state-export` includes structural providers
only: `templates,template-parts,global-styles,navigation,synced-patterns,fonts,media-references,custom-css`. Full page/post/CPT content requires `--include-content` — without it, a normal export can become very large and carry unnecessary client content. Use `--providers=<list>` to narrow further. The export is NOT a complete WordPress database dump; it is complete agent-readable access to Site Editor state, visual structure, and supported content state.

**Command contracts (agents need these exact).**

* **Selector syntax:** `--select=templates:page,template-parts:site-header,global-styles:active` (`<provider>:<slug>`, comma-separated; `global-styles:active` targets the active theme's user-origin record).
* **Schema files:** `resources/schemas/state-bundle-v1.json` and `resources/schemas/promotion-manifest-v1.json` — validate every export and manifest against them. Use `swaggest/json-schema` (or equivalent) as a **runtime `require` dependency** (not `require-dev`), because schema validation runs on the production WordPress host during finalisation.
* **Exit codes:** `0` success; `1` hard error; `2` partial success (some records promoted, some refused — see refusal report); `3` concurrency/lock conflict; `4` tamper detection.
* **Partial success:** `--prepare` and `--finalize` process the selection atomically per-record but report per-record refusals; a partial-success run exits `2` and leaves a refusal report in the manifest. Already-succeeded records are not re-processed on re-run (idempotent).
* **`state-diff` comparison target — two distinct modes:**
  * *Without `--source`:* compares current DB state against the Git baseline files. DB-owned providers (navigation, synced patterns, content, fonts, media) are **informational only and do NOT count as Git drift** (they have no Git counterpart).
  * *With `--source=<bundle>`:* compares current DB state against the exported bundle to detect drift since export. DB-owned providers **MUST be compared against the bundle and DO count as post-export drift** — otherwise a navigation or content change made after export would be missed.
  * `state-diff` exits `0` when there is no drift and `2` when drift exists (so CI can gate on it).
* **`--source` may be omitted** only by `state-diff` (compares against Git). `promote-overrides --prepare` requires `--source`.
* **`--output` / `--manifest` / `--source` with `-`:** direction depends on the command (the manifest is OUTPUT for `--prepare`/`--seal` but INPUT for `--finalize`/`--confirm`/`--rollback`, so `-` cannot always mean STDOUT):

  ```text
  prepare   --manifest=-     writes JSON to STDOUT
  seal      --manifest=-     reads JSON from STDIN, writes sealed JSON to STDOUT
  finalize  --manifest=-     reads JSON from STDIN
  confirm   --manifest=-     reads JSON from STDIN
  rollback  --manifest=-     reads JSON from STDIN
  state-export --output=-    writes JSON to STDOUT
  prepare   --source=-       reads bundle JSON from STDIN
  ```

  In every case, STDOUT carries ONLY machine-readable JSON; all diagnostics, warnings, and progress go to STDERR.
* **Manifest tamper detection:** use an HMAC (or a deployment-stored manifest hash separate from the manifest file itself). Recalculating ordinary hashes inside an editable manifest is NOT sufficient — the manifest is transportable and could be altered in transit.

Keep `wp agency check-overrides` as a deprecated compatibility alias that reports drift. It must not fail by default simply because legitimate DB overrides exist.

Support:

```text
wp agency check-overrides --fail-on-drift
```

Only the explicit flag can produce a non-zero exit code for drift.

Add a host-side orchestration wrapper:

```text
scripts/promote-overrides
```

This is a **deployment-side orchestration script**, NOT something that runs on
the WordPress host. Many production hosts lack Node, Playwright browsers, or the
repository checkout. The wrapper:

1. Calls remote WP-CLI finalisation (`wp agency promote-overrides --finalize`) on the WordPress host.
2. Runs Playwright from CI against the deployed URL.
3. Calls remote `--confirm` when checks pass, or remote `--rollback` when they fail.
4. Exits non-zero after rollback and preserves logs.

This keeps the starter hosting-independent. The WordPress host only needs to run
WP-CLI; the verification runs wherever CI runs. Define the remote transport
through environment variables (the wrapper treats the command as an adapter, not
a hardcoded hosting provider):

```text
AGENCY_REMOTE_WP_CLI_COMMAND  # e.g. ssh deploy@example.com 'cd /var/www && wp'
AGENCY_DEPLOY_URL             # the URL Playwright runs against
AGENCY_REMOTE_STATE_DIR       # where the manifest lands on the host
AGENCY_PLAYWRIGHT_PROJECT     # Playwright config/project for verification
AGENCY_PLAYWRIGHT_TESTS       # Playwright test path for verification
AGENCY_VERIFICATION_TIMEOUT   # how long to allow verification before rollback
```

---

## 7. State subsystem design

Create a purpose-named namespace under `agency-platform`, for example:

```text
src/State/
├── StateProvider.php
├── StateRegistry.php
├── StateExporter.php
├── StateDiffer.php
├── PromotionPreparer.php
├── PromotionFinalizer.php
├── PromotionManifest.php
├── PromotionBackup.php
├── ReferenceScanner.php
├── Normalizer.php
└── Providers/
    ├── TemplatesState.php
    ├── TemplatePartsState.php
    ├── GlobalStylesState.php
    ├── NavigationState.php
    ├── SyncedPatternsState.php
    ├── ContentState.php
    ├── FontLibraryState.php
    ├── MediaReferencesState.php
    └── CustomCssState.php
```

The exact classes can differ, but responsibilities must stay separated and testable.

### 7.1 Provider contract

Each provider must define:

* Stable provider slug.
* Export behaviour.
* Normalisation behaviour.
* Stable record key.
* Diff behaviour.
* Whether it is promotable.
* Dependency/reference detection.
* Prepare behaviour when promotable.
* Finalise/reset behaviour when promotable.
* Restore behaviour.
* Validation behaviour.

Register built-in providers through named code. Expose a filter such as:

```text
agency_platform_state_providers
```

Document that project plugins can add providers without changing `agency-platform`.

### 7.2 Export bundle

Write deterministic, normalised JSON.

Minimum top-level fields:

```json
{
  "schemaVersion": 1,
  "exportId": "uuid",
  "exportedAtUtc": "ISO-8601",
  "siteUuid": "stable-generated-identifier",
  "siteUrl": "https://example.test",
  "environment": "production",
  "wordpressVersion": "...",
  "activeTheme": {
    "stylesheet": "site-theme",
    "version": "...",
    "gitCommit": "..."
  },
  "providers": {},
  "hmacKeyId": "2026-01",
  "hmac": "..."
}
```

The state bundle is the INPUT that generates promoted files, so it must be
integrity-protected just like the manifest. Verify the bundle's `hmac` before
reading any promotable content. Use a **different HMAC purpose prefix** for
bundles vs manifests (e.g. `"state-bundle-v1:"` vs `"promotion-manifest-v1:"`)
so a signature from one artifact type cannot be replayed against the other.

Each record must include:

* Stable key.
* WordPress object ID when applicable.
* Slug/name.
* Status.
* `post_modified_gmt` or equivalent modification marker.
* Normalised content.
* SHA-256 hash of normalised content.
* Dependencies and references.
* Ownership/promotion classification.

The bundle contains a random `exportId` and a current `exportedAtUtc`, so the
whole JSON file cannot be byte-for-byte stable. Define determinism over the
content, not the wrapper:

```text
stateHash = SHA-256 of canonical provider records only
```

Repeated exports of unchanged state must produce identical `stateHash`, identical
per-record hashes, identical record ordering, and identical normalised provider
data. The `exportId`/`exportedAtUtc`/`hmac` fields are NOT part of `stateHash`.
Do not require the entire JSON file to be identical.

### 7.3 Sensitive-data handling

By default, write state bundles and promotion manifests under:

```text
var/agency-state/
```

Add the path to `.gitignore`.

Print a warning that exports can contain customer content and internal site structure.

Do not include:

* Passwords.
* Session tokens.
* Application passwords.
* Secrets.
* WooCommerce order/customer PII.
* User email addresses unless a future provider explicitly requires and sanitises them.

Use the existing sanitisation rules as a boundary reference.

### 7.4 Reference detection

Scan block markup and relevant attributes for:

* `navigation` references.
* Synced pattern `ref` values.
* Attachment/media IDs.
* Site logo IDs.
* Gallery IDs.
* Cover/image IDs.
* Font file paths.
* Plugin block IDs or post-meta dependencies.
* Unknown `ref` attributes.

Promotion must refuse an item with unresolved environment-specific references.

A refusal must report:

* Record/provider.
* Block name.
* Attribute.
* Referenced ID/value.
* Suggested policy or required mapping.

Support an explicit mapping file in a later extension, but do not invent mappings in v1.

**Navigation-reference promotion policy (v1).** Navigation remains database-owned and is never promoted to Git. A template or template part may be promoted without its environment-specific Navigation `ref`, but removing the `ref` must not silently select an arbitrary navigation record.

During export, record the normalised content hash and identifying metadata of the referenced navigation. During finalisation, resolve the navigation that the ref-less Navigation block would use in the target environment by using WordPress core's most-recently-published fallback behaviour. Compare that resolved navigation with the exported navigation.

Proceed only when one navigation resolves and its normalised content hash matches the exported navigation. The existence of older published navigation records is not by itself ambiguous because WordPress core selects the most recent non-empty published record. Refuse the promotion when no navigation resolves, when the core fallback cannot select one record deterministically, or when the content hash differs. Report the unresolved Navigation reference.

Do not promote navigation records, invent mappings, or automatically replace one environment-specific navigation ID with another in v1.

### 7.5 Template and part promotion

**Prepare must refuse to run when:**

* The environment is production (`--prepare` is a local operation).
* The Git working tree is dirty — UNLESS every modification matches a file already recorded as prepared in the current manifest (so a partial run can be re-run idempotently without a false "dirty tree" refusal).
* The branch is detached or unexpected unless `AGENCY_ALLOW_DETACHED_HEAD=1` or `AGENCY_EXPECTED_BRANCH` matches.
* The state bundle's `siteUuid` does not match `AGENCY_TARGET_SITE_UUID`.
* The selection includes a custom template or template-part slug not already declared in `theme.json.customTemplates` / `theme.json.templateParts` — v1 only promotes files already declared in Git (see "New slugs" below).

The `siteUuid` check compares against `AGENCY_TARGET_SITE_UUID` (the production
target), NOT the local development site UUID — preparation commonly runs in CI
or a fresh local install that is not a clone of the production database, so the
local UUID would never match. `--finalize` is where the manifest is strictly
compared against the live production UUID (§7.8).

Git execution env vars (DDEV and CI do not always run WP-CLI with the repository
root as the current directory, and CI commonly uses detached HEAD):

```text
AGENCY_REPO_ROOT           # repository root (default: autodetect)
AGENCY_EXPECTED_BRANCH     # refuse if HEAD is on another branch
AGENCY_ALLOW_DETACHED_HEAD # permit detached HEAD (CI checkouts)
```

During `--prepare`:

* Read the exported DB override.
* Verify the block markup parses.
* Verify referenced template parts exist or are in the selected promotion set.
* Verify registered block names in an integration environment.
* Reject unresolved references.
* Write to a temporary file.
* Normalise line endings and block serialisation.
* Atomically replace the target `templates/<slug>.html` or `parts/<slug>.html`.
* Record original and prepared file hashes in the manifest.

Do not write nested template-part directories. Template parts live directly under `parts/`.

**New custom templates/parts.** A newly-created custom template or part needs an
entry in `theme.json.customTemplates` / `theme.json.templateParts` for the editor
to recognise it. In v1, `--prepare` REFUSES undeclared slugs and only promotes
files already declared in Git — promoting a brand-new template/part requires a
manual `theme.json` metadata edit first. This keeps automatic promotion safe.

### 7.6 Global Styles promotion

Do not use generic recursive `array_merge`.

Use WordPress theme JSON APIs behind a small adapter.

**`WP_Theme_JSON_Resolver` is documented as an internal Core API not intended for
plugin use.** Public functions such as `wp_get_global_styles()` can read resolved
output, but accessing and manipulating individual theme/user origins may still
require internal APIs. Therefore: put ALL internal WordPress Theme JSON calls
behind one adapter, and fail closed when the adapter detects an unsupported Core
API shape. Without that, a future WordPress update could silently break promotion.

**WordPress version policy.** This repository's CI targets a single
WordPress/PHP/Node setup (resolved by `composer.lock`) — it is not a
version-matrix library. So: support only the WordPress version resolved by
`composer.lock`, and run the adapter compatibility tests on every WordPress
dependency update (Dependabot PR). Do NOT introduce a multi-version test matrix.

Required behaviour:

1. Load theme origin.
2. Load user origin from the active theme’s `wp_global_styles` record.
3. Record fully resolved settings/styles before promotion.
4. Merge user-origin intent into the theme baseline with WordPress-aware logic.
5. Write valid `theme.json`.
6. Validate its schema/structure.
7. Simulate removal of the user origin.
8. Resolve settings/styles again.
9. Compare normalised resolved output.
10. Refuse promotion when the resolved result changes unexpectedly.

Do not claim that `theme.json` has a “custom fonts” switch. Font Library access comes from the Site Editor/capability model. Keep Font Library records and files DB/filesystem-owned in v1.

### 7.7 Prepare manifest

The promotion manifest must include:

* Schema version.
* Promotion UUID.
* Export UUID.
* Active theme.
* Expected Git commit/base commit.
* Selected records.
* Original DB hash.
* Original modification marker.
* Prepared file path.
* Prepared file hash.
* Dependency scan result.
* Expected post-reset semantic hash.
* `postFinalizeRecordState` — `present` or `absent` (records whether the DB override still exists after finalisation; rollback uses this — see §7.9).
* Verification commands.
* Finalisation status.
* Backup identifier.
* Confirmation/rollback status.
* HMAC of the above (tamper detection — see §6).

**Manifest transport between environments.** The manifest is created locally
and must reach production, but must NOT be committed to Git (it can contain
client state). Use a **protected CI deployment artifact**, copied by the
deployment pipeline into `AGENCY_STATE_DIR` on the target host. Support these
environment variables so production does not depend on `.git` being present:

```text
AGENCY_STATE_DIR        # where manifests/backups live (default: var/agency-state/)
AGENCY_DEPLOY_COMMIT    # the commit production is running, verified at finalize
AGENCY_TARGET_SITE_UUID # the production site UUID the manifest targets (checked at prepare)
```

Generate a stable site identifier (`agency_platform_site_uuid` option) on first
run; the finaliser verifies site UUID, URL, environment, active theme, and
deployment commit against the manifest before touching anything.

**HMAC key management (keyring, not single key).** The manifest's and bundle's
`hmac` fields need a shared secret between local/CI preparation and production
finalisation. WordPress salts differ between environments, so do NOT fall back
to them. A single key cannot satisfy the rotation requirement (production must
accept manifests signed by an older key during a controlled rotation), so use a
keyring:

```text
AGENCY_PROMOTION_HMAC_KEYS             # JSON keyring: {"2026-01":"secret-one","2026-06":"secret-two"}
AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID   # which key ID signs new manifests/bundles
```

Rules: canonical JSON serialisation (sorted keys, no trailing whitespace) is the
signed payload; the `hmac`/`hmacKeyId` fields are excluded from the payload; a
missing/empty keyring causes a hard failure (exit 1, no fallback to WordPress
salts); production validates against ANY key ID present in the keyring and
rejects unknown IDs; rotation = add the new key to the keyring, advance
`SIGNING_KEY_ID`, keep the old key until outstanding manifests/backups expire.
Use a different HMAC purpose prefix for bundles vs manifests (see §7.2) so
signatures cannot be replayed across artifact types.

**Seal step (`--seal`).** The deploy commit cannot be known during `--prepare`
(it does not exist until the prepared files are committed). So the lifecycle is
`--prepare` (write files + draft manifest) → commit → `--seal` (bind the commit)
→ deploy → `--finalize`. `--seal`:

* Re-verifies every prepared file hash against the manifest.
* Records `deployCommit` (the just-created commit SHA).
* Re-signs the manifest HMAC with the bound commit included.
* Marks the manifest immutable for deployment (any later field change invalidates the HMAC).
* When given a file path (`--manifest=manifest.json`), rewrites that file **atomically** (temp write + rename) — same path in/out. With `-`, reads from STDIN and writes sealed JSON to STDOUT (§6).

Without `--seal`, the finalize commit-verification contract is unsatisfiable.

**Site UUID and cloned databases.** `agency_platform_site_uuid` lives in the
database, so a production database cloned to staging or local development
carries the SAME UUID — which weakens the `AGENCY_TARGET_SITE_UUID` protection.
Update `wp agency sanitize` to regenerate the site UUID — but **idempotently**:
store an environment marker alongside the UUID and regenerate ONLY when a
production UUID is first seen outside production (a subsequent sanitize run sees
the local UUID + non-production marker and preserves it). Alternatively, expose
`--regenerate-site-uuid` as an explicit import flag. Do NOT regenerate on every
run (that breaks sanitize's idempotency contract). The finaliser verifies the
bundle/manifest's site UUID **plus** URL, environment, and active theme together
— never UUID alone.

Do not commit manifests containing client state or raw backup payloads.

### 7.8 Finalise

**Finalise must refuse to run when:**

* The deployment commit (`AGENCY_DEPLOY_COMMIT`) differs from the manifest's expected commit.
* The active theme does not match the manifest.
* The site UUID does not match the manifest.
* The prepared file is missing or its hash differs from the manifest.
* An active, non-expired promotion lock owned by another deployment is held (see §7.10).
* Any DB record changed after export (hash/mtime mismatch).

For every selected record:

1. Verify the active theme and environment.
2. Verify the deployed file hash matches the manifest.
3. Re-read the live DB record.
4. Compare its current hash and modification marker with the export.
5. Refuse to touch it if the client changed it after export.
6. Save the complete original record in a protected promotion backup.
7. Reset/delete the override through WordPress APIs, not direct SQL.
8. Resolve the resulting template/style state.
9. Compare it with the expected semantic hash.
10. Restore the DB record immediately if semantic verification fails.
11. Leave the backup available until `--confirm`.

Finalisation must be idempotent.

### 7.9 Confirm and rollback

`--confirm`:

* Marks the promotion successful.
* Does NOT immediately delete the backup. The backup remains available for a defined retention period (14–30 days) so that an application-level deployment rollback (restoring old Git files) can still re-apply the matching DB overrides via `--rollback`.
* Adds a scheduled or manual pruning operation that expires backups only after the retention window.
* Does not delete audit metadata immediately.

`--rollback`:

* Restores every original DB record.
* Restores records in dependency-safe order.
* Verifies hashes after restoration.
* Can be run repeatedly without causing additional changes.
* Remains possible AFTER `--confirm` while the backup still exists within the retention window.
* **Must NOT overwrite newer work.** Before restoring a record, verify: (a) the current record still matches the post-finalise semantic hash recorded in the manifest; (b) no newer promotion has since claimed the same record; (c) the current modification marker has not changed. If any check fails, REFUSE that record (do not overwrite) and report it — rolling back Promotion A must not clobber a client edit or Promotion B that landed afterwards.
* **Deleted-record case.** For a record whose manifest `postFinalizeRecordState = absent`, verify the override is STILL absent before restoring. A newly-created override means the client re-edited after finalisation — REFUSE that record rather than overwriting the newer work.
* Reports partial rollback clearly and exits non-zero if any item cannot be restored.

Store backup payloads in a protected, **non-autoloaded** WordPress option (or
another small WordPress-owned storage mechanism). Define a maximum option size;
if a backup exceeds it, use chunked storage across multiple options. Do not
create a custom database table unless tests show the option approach is unsafe
for the expected payload size. Backups are pruned only by the explicit
`wp agency promotion-backups prune` command (§6), not automatically on confirm.

### 7.10 Promotion-to-promotion locking

The concurrency checks in §7.8 protect against a client editing after export,
but they do not stop two deployments from finalising separate promotions at the
same time. A single option holding multiple record keys cannot atomically
prevent overlap, so use **one lock option per canonical record key**:

```text
agency_promotion_lock_<sha256-of-record-key>
```

Acquire locks in **sorted key order**. If any acquisition fails (the option
already exists with a different owner), release every lock acquired by the
current attempt and exit `3` (lock conflict), reporting the conflicting
promotion. Same-promotion re-runs may re-enter their own locks.

Lock lifecycle:

* **Acquired at `--finalize`**, held through verification until `--confirm` or `--rollback` releases it.
* **Refreshed by `wp agency promote-overrides --heartbeat --manifest=<path>`**, which the host-side wrapper (`scripts/promote-overrides`) calls periodically between finalize and confirm/rollback. Without a heartbeat command, either a healthy lock expires during a long Playwright run, or the TTL must be so long that abandoned deployments block later promotions.
* **Expiration:** a defined TTL; an expired lock is reclaimable by a new promotion (after the TTL, treat as abandoned).
* **Released** by `--confirm` and `--rollback` once the record set is settled.

**Local `--prepare` uses a filesystem lock**, NOT a WordPress option — a
WordPress option on the local/CI site cannot coordinate with production. The
per-record WordPress-option lock above governs the production
finalize/confirm/rollback phase only.

Lock record fields: promotion UUID, start timestamp, owner/deployment
identifier, expiration, canonical record key.

---

## 8. Phase 0 — Baseline and architecture spike

### 8.1 Baseline

Create the feature branch.

Run and record:

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

If a baseline command already fails on `main`, record it before making changes. Do not hide unrelated failures.

Create an inventory of:

* Root theme files.
* PHP templates.
* PHP parts and their CSS/JS.
* Patterns.
* Custom blocks.
* Architecture tests tied to classic delegates/parts.
* Role and lockdown tests.
* Database override tests.
* Commerce-specific tests.
* Current visual snapshots.

**Capture immutable migration baselines.** The migration-parity test (§9.6)
compares the old frontend against the new frontend — but once the classic path is
deleted, the old frontend no longer exists. So Phase 0 MUST capture desktop and
mobile baseline screenshots of the classic-rendered site and store them as
immutable test fixtures (separate from the normal visual-snapshot directory),
recording the exact browser, viewport, content fixtures, fonts, masks, and commit
SHA. Normal `--update-snapshots` runs must NOT be able to silently replace these
migration baselines — otherwise an agent could regenerate expected snapshots and
make a regression appear to pass.

### 8.2 Spike implementation

Build a minimum block-theme spike:

* Add `templates/index.html`.
* Add a temporary `templates/page.html`.
* Add `parts/site-header.html`.
* Add `parts/site-footer.html`.
* Register both parts through `theme.json.templateParts`.
* Use native Site Logo, Site Title, Site Tagline, Navigation, Group, and Buttons blocks.
* Add a minimal editor-safe stylesheet.
* Make the Site Editor work for an administrator.
* Verify the theme is recognised as a block theme.
* Confirm PHP code can continue registering custom blocks.

This spike can temporarily coexist with classic files, but no classic files may remain after Phase 3.

### 8.3 Phase 0 gate

Pass when:

* WordPress recognises the theme as a block theme.
* `templates/index.html` renders.
* Header/footer parts render.
* An administrator can edit and save the template.
* The repository’s PHP architecture remains valid after intentionally updating classic-specific tests.
* No project layer boundary is broken.
* Findings and required test replacements are recorded.

Commit the spike checkpoint.

---

## 9. Phase 1 — Realistic demo and full workflow proof

Build one realistic demonstration page inside `wordpress-template`, not in `cinq-wp`.

The page must contain:

* Editable native header/navigation.
* Hero section.
* Two-column content section.
* Image/media section.
* Cards or feature grid.
* Call-to-action section.
* Editable footer.
* One instance of `agency/reference-callout`.
* Desktop and mobile layouts.

Prefer core blocks and patterns. Do not create a custom block merely to reproduce a composition.

### 9.1 CSS split

Replace the current global CSS layout with:

```text
assets/global/frontend-reset.css
assets/global/shared.css
assets/global/editor.css
```

Rules:

* `frontend-reset.css`: body/reset/frontend-only rules.
* `shared.css`: editor-safe design-system classes used on frontend and editor.
* `editor.css`: editor-only corrections and canvas alignment.
* Never load `frontend-reset.css` into the editor.
* Remove obsolete `.site-main` rules when block layouts replace that wrapper.
* Preserve accessibility helpers where needed.
* Keep token-only CSS colour enforcement.

Update `ThemeBootstrap`:

* Keep `after_setup_theme`.
* Call `add_editor_style()` with `shared.css` and `editor.css`.
* Enqueue `frontend-reset.css` and `shared.css` on the frontend.
* Remove part-manifest asset discovery when native parts replace it.
* Keep auto-registration of custom blocks.
* Remove classic nav-menu registration when no longer used.

### 9.2 Client role proof

Implement the minimum permission changes needed for the demo:

* Remove `SiteEditorLockdown` from `Plugin`.
* Delete `SiteEditorLockdown` when no longer referenced.
* Explicitly add `edit_theme_options => true` to `client_editor_capabilities()`.

Important: removing `edit_theme_options` from `NEVER_GRANT` is not sufficient because the core Editor role does not normally grant it. Add it explicitly after deriving the baseline.

Because `client_shop_manager` derives from `client_editor`, it must inherit the capability.

Add a named capability policy that denies raw Additional CSS. WordPress exposes
the Additional CSS editor through the `edit_css` capability (which normally maps
to `unfiltered_html`). A `false` role entry is NOT the strongest enforcement
point for a meta capability — add an explicit `map_meta_cap` policy returning
`do_not_allow` for client roles on `edit_css`, so the denial survives any plugin
granting the underlying cap. The role already removes `unfiltered_html`, but the
`map_meta_cap` policy + tests make the contract reliable.

`edit_theme_options` is required for the Site Editor but also gates other
theme/customizer operations, and core maps `customize` through `edit_theme_options`
— so granting it can expose the Customizer entry point. Map the `customize`
capability to `do_not_allow` for client roles, and enforce an explicit
admin-screen boundary (not vague "hidden menus"):

**Allow:** `site-editor.php`, the Site Editor REST endpoints, and the
template / template-part / navigation / Global Styles endpoints.

**Deny:** `themes.php`, `theme-install.php`, `theme-editor.php`,
`plugin-install.php`, `plugin-editor.php`, `customize.php`, `widgets.php`,
legacy `nav-menus.php`, and unrelated settings screens.

### 9.3 Editor restrictions

Replace the fixed allow-list with a registered-block policy.

Rules:

* Start from the currently registered block types.
* Allow only approved namespaces: `core/`, `agency/`, and `woocommerce/` when commerce is active.
* Permanently exclude `core/html` and `core/shortcode`.
* Respect an incoming array restriction by intersecting with it.
* Use `WP_Block_Editor_Context`.
* Do not apply one blind list that breaks the Site Editor.
* Keep `codeEditingEnabled = false`.
* Set `canLockBlocks = true`.

The `core/` / `agency/` / `woocommerce/` namespace set covers the base
repository, but normal third-party plugins (forms, multilingual, booking, SEO)
register necessary editor blocks under other namespaces. Make the policy
extensible via filters so projects can add namespaces without modifying
`agency-platform`:

```text
agency_platform_allowed_block_namespaces
agency_platform_allowed_blocks
agency_platform_disallowed_blocks
```

`core/html` and `core/shortcode` stay permanently denied regardless of filter
output.

**Server-side save validation (the editor allow-list is NOT a security boundary).**
A direct REST request or pasted block markup can submit disallowed blocks.
Add server-side validation on content save (`rest_pre_insert` / `content_save_pre`
for the relevant post types) that enforces the **full resolved block policy** for
that user and editor context — recursively reject EVERY block not allowed by the
namespace policy (not only `core/html` and `core/shortcode`). Also reject:

* `core/freeform` (the Classic block — stores arbitrary raw HTML directly in content).
* Non-whitespace raw HTML where `parse_blocks()` returns a null block name.
* Unknown/unsupported blocks unless specifically allowed.

Return a clear REST error identifying the forbidden block. Do NOT silently strip
on save (that destroys content without telling the client); stripping is reserved
for an explicit sanitisation command only.

**Shortcodes execute at the content level, not the block level.** WordPress runs
`do_shortcode()` on the complete post content, so a client can place a registered
shortcode tag inside a Paragraph block (e.g. `[contact-form]`) and it will
execute — no `core/shortcode` block required. Blocking the Shortcode block alone
does NOT block shortcodes. Save validation must detect registered shortcode tags
ANYWHERE in client-authored content, using the registered shortcode list +
`get_shortcode_regex()`. Do NOT reject every `[...]` pair (that breaks legitimate
bracket text) — match only registered shortcode tags.

Also reject per-block custom CSS (the block instance `style.css` attribute —
WordPress 7.0 stores per-block CSS there, gated by `edit_css`) for client roles
on save. The `edit_css` capability denial covers the UI; this server-side check
covers direct REST. Test all four CSS surfaces: Global Styles custom CSS,
Customizer `custom_css`, block-type custom CSS, and individual block-instance
`style.css`.

Create explicit tests for post editor and Site Editor contexts.

### 9.4 Dynamic block preview

The current reference block already has a JavaScript editor component. Improve it only as required for representative preview.

The correct rule is:

> A dynamic block must intentionally implement a useful editor preview. Server-rendered and interactive frontend behaviour is not automatically reproduced in the editor.

For `reference-callout`, show representative testimonial content when the
testimonial toggle is enabled (the current editor component shows only the
heading and body). Use `ServerSideRender` only if a client-side preview is
impractical.

### 9.5 State workflow proof

Implement a vertical slice of:

* Template export (verify bundle HMAC before reading).
* Template-part export.
* Global Styles export.
* Prepare one template/part override.
* Seal the manifest with a deploy commit.
* Finalise it (against the sealed commit).
* Verify semantic equality.
* Roll it back.
* Re-finalise and confirm it.
* Attempt rollback of an already-overwritten record → confirm it is REFUSED (does not clobber newer work).

Do not wait until the end to prove reconciliation.

### 9.6 Phase 1 parity gate

Use Playwright at:

* Desktop: `1440 × 900`
* Mobile: `390 × 844`

Pass when:

**Two separate visual comparisons (passing one does NOT prove the other — they need different masking, cropping, and geometry rules):**

1. **Migration parity** — old frontend vs new frontend (proves the block-theme conversion did not change the rendered site). Screenshot difference at most 5% after masking explicitly excluded animated/dynamic regions.
2. **Editing parity** — new Site Editor canvas vs new frontend (proves the editor matches what the visitor sees). Screenshot difference at most 5% with editor chrome cropped out and dynamic regions masked.

Both pixel thresholds and the geometry threshold below **must be configurable per page**, but no page may set a pixel-difference threshold above 5%. Visual tests must use deterministic locally-hosted fonts so font-loading differences do not create false failures. A page that cannot pass at 5% fails the release gate until the rendering or deterministic masking is corrected.

* Header/footer bounding geometry differs by no more than 8 px on key edges.
* Computed typography, colours, and key spacing tokens match.
* Every static section is visible in the editor.
* Dynamic blocks show a representative preview.
* Animations, hover states, WebGL, and transient interaction states are documented as frontend-only.
* The client role completes these tasks without admin help:

  * Edit header text/logo.
  * Edit navigation.
  * Reorder a section.
  * Add/remove a core block.
  * Change a colour.
  * Change typography.
  * Save a template.
  * Restore a revision or undo the test change.
* The state export sees every saved test change.
* The test override can be promoted, finalised, verified, rolled back, and confirmed.
* Security tests prove the client still cannot switch themes, install plugins, or edit files.

Commit the demo checkpoint.

---

## 10. Phase 2 — Automatic decision gate

Do not request operator approval when all hard gates pass. Continue to Phase 3.

Stop and report when any of these fail:

* Block theme cannot coexist with required plugin behaviour.
* Site Editor cannot be granted without exposing forbidden admin actions that cannot be separately denied.
* Editor/frontend parity exceeds the defined tolerance for static sections.
* The state workflow cannot safely detect concurrent client edits.
* Global Styles cannot round-trip without changing resolved output.
* Promotion rollback cannot restore original state.
* Existing layer boundaries require weakening.
* Required CI/verification cannot be restored to green.

The report must include logs, screenshots, affected files, and a recommended correction.

---

## 11. Phase 3 — Full template conversion

### 11.1 Convert all templates

Create block templates matching the current hierarchy:

```text
templates/404.html
templates/archive.html
templates/index.html
templates/page.html
templates/search.html
templates/single.html
```

Add other templates only when justified by current behaviour.

Each template must:

* Use block markup only.
* Include native template parts.
* Use semantic `<main>` wrappers through block attributes.
* Preserve title, content, query, pagination, search, and 404 behaviour.
* Preserve accessibility landmarks.
* Avoid hard-coded DB IDs.

Use core Query/Post Template blocks for archives and search where practical.

### 11.2 Convert template parts

Create directly under `parts/`:

```text
parts/site-header.html
parts/site-footer.html
```

Register them in `theme.json`:

```json
"templateParts": [
  {
    "name": "site-header",
    "title": "Site Header",
    "area": "header"
  },
  {
    "name": "site-footer",
    "title": "Site Footer",
    "area": "footer"
  }
]
```

Use the native Navigation block so the old mobile-menu JavaScript is unnecessary.

If custom interaction remains necessary, implement it as a custom block with `block.json` and `viewScript` or the Interactivity API. Do not recreate the old global part-asset loader.

### 11.3 Delete the classic path

Delete when equivalents are complete:

* Root `index.php`, `page.php`, `single.php`, `archive.php`, `search.php`, `404.php`.
* Root `header.php` and `footer.php`.
* `templates/*.php`.
* Nested PHP part directories.
* `SiteTheme\Support\Parts`.
* Unit tests that exclusively validate `Parts::MANIFEST`, PHP part rendering, or part asset conventions.
* Obsolete header/footer JavaScript and CSS that native blocks replace.
* Any unused classic menu registration.

Do not leave dead fallback code.

### 11.4 Theme bootstrap

Keep:

* Thin `functions.php`.
* Named `ThemeBootstrap` methods.
* Theme support still relevant to block themes.
* Custom-block auto-registration.
* Frontend and editor style registration.

Remove:

* `Parts` dependencies.
* Classic part asset discovery.
* Classic nav menu locations if no longer used.
* Comments that call the theme hybrid/classic.

### 11.5 `theme.json`

Keep version 3 unless the installed WordPress version requires a documented newer schema.

Enable:

* `appearanceTools`.
* Custom colours.
* Custom gradients where desired.
* Custom font sizes.
* Spacing controls.
* Existing palette, typography, layout, and token definitions.
* `templateParts`.

Do not invent a nonexistent custom-font boolean.

Ensure Global Styles controls remain usable by client roles.

### 11.6 Patterns

Retain patterns as the sanctioned starting compositions.

Update or add patterns for:

* Hero.
* Split content.
* Feature/card grid.
* CTA.
* Content page.
* Optional commerce sections when the commerce profile is enabled.

Patterns may be unlocked because the editing posture gives clients full control within the approved block system. Use locking only when a pattern's internal semantics require it, not as a general client restriction.

### 11.7 Dynamic blocks

For every dynamic block:

* Confirm a native core/Woo block cannot replace it.
* Keep `render.php` only when server-side data is required.
* Provide `editorScript`.
* Provide a JavaScript `edit` component.
* Provide `editorStyle`.
* Render representative state in the editor.
* Document frontend-only behaviour.
* Add or update block manifest and generated-index tests.

Do not use an `editor.php` convention.

### 11.8 WooCommerce profile

When commerce is enabled, block-theme overrides belong under `templates/`, including as needed:

```text
templates/single-product.html
templates/archive-product.html
templates/taxonomy-product_cat.html
templates/taxonomy-product_tag.html
templates/taxonomy-product_attribute.html
templates/product-search-results.html
templates/page-cart.html
templates/page-checkout.html
templates/order-confirmation.html
```

Do not create `templates/woocommerce/*`.

Use the WooCommerce Mini-Cart block in the header when required.

Prefer `theme.json` and supported block styles. Avoid CSS that depends on private nested WooCommerce block markup.

Update:

* Architecture documentation.
* WooCommerce isolation test/allow-list where block template names or Woo blocks are now valid under `templates/`.
* Commerce E2E coverage.
* The previous `woocommerce/README.md` policy. Keep the classic override directory only if a still-supported non-block override genuinely remains; otherwise remove it and document the block-theme policy.
* **`scripts/enable-commerce`** — this script currently replaces Cart and Checkout *blocks* with classic shortcodes for deterministic tests. Update it (and its tests) to seed native Cart/Checkout block content instead, so the commerce suite proves the block-theme workflow rather than a classic shortcode path.

### 11.9 Role and security implementation

Modify `RolesProvider` so that:

1. It derives capabilities from the core Editor role.
2. It removes every existing forbidden capability.
3. It explicitly adds `edit_theme_options => true`.
4. It explicitly denies `edit_css` (gates the Additional CSS panel; normally maps to `unfiltered_html`) — reinforce with a `map_meta_cap` → `do_not_allow` policy for client roles.
5. It keeps `unfiltered_html` false/absent.
6. It applies an admin-screen policy so `edit_theme_options` opens the Site Editor but not unrelated theme/customizer admin screens.

Test the exact capability matrix for both client roles:

```text
edit_theme_options = true
edit_css           = false
unfiltered_html    = false
```

Update `ShopRole` documentation and tests to reflect inherited Site Editor access.

Delete `SiteEditorLockdown` and remove it from `Plugin`.

Add security tests proving both client roles:

Can:

* Access Site Editor.
* Read/edit/save templates and parts.
* Edit navigation and Global Styles.

Cannot:

* Switch themes.
* Install/activate plugins.
* Edit files.
* Access plugin/theme file editors.
* Access unrelated administrator settings.
* Use Additional CSS.
* Use the block code editor.
* Insert HTML or shortcode blocks.

### 11.10 Replace override-check semantics

The existing `DatabaseOverrideCheck` cannot remain a deployment failure because DB overrides are now expected.

Replace or evolve it into drift reporting:

* Report templates, parts, Global Styles, navigation, synced patterns, fonts, and content changes.
* Detect Custom CSS in BOTH places: CSS stored inside Global Styles AND the WordPress `custom_css` post type.
* Classify each as promotable, DB-owned, forbidden, unresolved, or unchanged.
* `check-overrides` is informational by default.
* `--fail-on-drift` is explicit.
* Update `scripts/check-database-overrides`.
* Update launch/deployment documentation.
* Remove wording that says any published template/part DB row is always invalid.

### 11.11 Complete the state subsystem

Finish every provider and command from Sections 6–7.

Add:

* Deterministic normalisation.
* JSON schema validation.
* Reference scanner.
* Atomic file writes.
* Concurrency checks.
* Protected backup.
* Semantic post-reset validation.
* Confirm/rollback.
* Audit logs.
* Extension point.
* Host orchestration wrapper.
* Idempotency tests.
* Tamper tests.

Do not use direct SQL where WordPress APIs exist.

### 11.12 Architecture test changes

#### `DirectoryRulesTest`

* Remove classic root delegates from the allowed file list.
* Update hybrid/classic wording.
* Continue enforcing the fixed top-level layout.
* Assert no PHP template files remain.
* Assert template parts are direct `.html` files under `parts/`.

#### `ThemeBootstrapTest`

Replace classic delegate tests with:

* `templates/index.html` exists.
* No classic root template delegates exist.
* `functions.php` stays at most 50 lines.
* `functions.php` has no hooks or closures.
* `ThemeBootstrap` owns setup hooks.
* Final theme has no dual rendering path.

#### New architecture test

Add a block-theme structure test that checks:

* Required folders/files.
* No nested template parts.
* Template and part filenames.
* Every referenced part file exists.
* No forbidden raw PHP template path remains.
* No hard-coded navigation/synced-pattern refs in Git-owned templates unless explicitly allow-listed.

#### Integration template test

In a WordPress-loaded test:

* Parse every template and part with WordPress block parsing.
* Confirm block markup is valid.
* Confirm every referenced block is registered for the tested profile.
* Confirm every referenced part exists.
* Render representative templates without fatal errors.

#### `GlobalAssetRulesTest`

* Change the global allow-list to the three-file split.
* Remove the old PHP part asset convention test.
* Continue enforcing block-local CSS.
* Add checks preventing frontend reset CSS from being registered as editor CSS.

#### Remove/replace `PartsTest`

Delete tests that only validate the removed `Parts` class. Replace with template-part file and integration tests.

#### Existing tests

Update, do not weaken:

* Block manifest tests.
* Hook ownership tests.
* WooCommerce isolation tests.
* Generated index freshness.
* Role capability tests.
* Admin smoke tests.
* Database override tests, now state/drift tests.
* Environment safety tests.

### 11.13 State subsystem tests

Unit tests:

* Normalisation stability.
* `stateHash` stability (unchanged state → identical `stateHash`; changed wrapper fields do not affect it).
* Provider classification.
* Reference extraction.
* Unresolved-reference refusal.
* Bundle HMAC verification (tampered bundle fails before any promotable content is read; bundle signature cannot be replayed as a manifest signature and vice versa).
* Manifest parsing + HMAC tamper detection (altered manifest fails verification).
* HMAC keyring: missing/empty keyring → hard failure; unknown `KEY_ID` rejected; rotation accepts any valid key ID in the keyring; signing key advances by `SIGNING_KEY_ID`.
* `--seal` binds `deployCommit` and re-signs; a manifest without a sealed commit is rejected at finalize.
* Concurrency mismatch refusal (client edit after export).
* Per-record-key locking: two finalize calls on overlapping record keys — second exits 3 after releasing its own acquired locks; sorted acquisition order; same promotion re-enters its own locks; expired lock reclaimable.
* Idempotent confirm/rollback.
* Capability matrix: `edit_theme_options = true`, `edit_css = false`, `unfiltered_html = false`.
* `map_meta_cap` returns `do_not_allow` for client roles on `edit_css` regardless of role grants.
* Server-side save validation enforces the FULL resolved block policy (rejects every disallowed block, not only html/shortcode); REJECTS (not silently strips) with a REST error; stripping is sanitise-only.
* Server-side validation rejects `core/freeform` (Classic block), null-name raw-HTML blocks, and unknown/unsupported blocks.
* Content-level shortcode detection: a registered shortcode tag placed in a Paragraph block (not a `core/shortcode` block) is detected via `get_shortcode_regex()` + registered shortcode list and rejected; legitimate bracket text is NOT rejected.
* `customize` capability maps to `do_not_allow` for client roles; admin-screen boundary denies `themes.php`/`theme-editor.php`/`customize.php`/`widgets.php`/`nav-menus.php` etc., allows only `site-editor.php` + its REST endpoints.
* `--heartbeat` refreshes a held lock; an expired lock is reclaimable.
* Per-block custom CSS rejected on all four surfaces for client roles: Global Styles custom CSS, Customizer `custom_css`, block-type custom CSS, and block-instance `style.css` (WordPress 7.0).

Integration tests:

* Export with default providers excludes content; `--include-content` includes it.
* `--providers=<list>` narrows the export.
* `state-diff` exits `0` on no drift and `2` on drift; DB-owned providers do NOT count as drift when comparing against Git without `--source`, but DO count as post-export drift when comparing against a bundle with `--source`.
* Template round-trip.
* Template-part round-trip.
* Global Styles resolved-output equivalence (single WP version per `composer.lock`; re-run on dependency update).
* Prepare verifies against `AGENCY_TARGET_SITE_UUID`, NOT the local site UUID.
* Prepare refuses on production, detached branch (without `AGENCY_ALLOW_DETACHED_HEAD`), target-UUID mismatch, and undeclared custom template/part slugs.
* Prepare allows a dirty tree when every modification matches a file already recorded as prepared in the current manifest (partial re-run does not false-refuse).
* Seal → commit → finalize lifecycle: finalize refuses an unsealed manifest; accepts a sealed one whose `deployCommit` matches `AGENCY_DEPLOY_COMMIT`.
* Finalise refuses on deploy-commit mismatch, theme mismatch, live-production-UUID+URL+environment mismatch, missing/altered prepared file, active non-expired per-record lock owned by another, and changed DB record.
* Client edit after export causes finalise refusal.
* Deployed file checksum mismatch causes refusal.
* Reset failure restores original DB record.
* Rollback restores original state — including AFTER confirm while backup is in retention.
* Rollback REFUSES a record when a newer promotion or client edit has since changed it (does not overwrite newer work).
* Rollback REFUSES a deleted record (`postFinalizeRecordState = absent`) when a new override has since appeared — verifies still-absent before restoring.
* `wp agency sanitize` regenerates `agency_platform_site_uuid` on non-production imports.
* `promotion-backups list` and `prune --older-than --dry-run` behave correctly.
* Backup pruning only after retention window.
* Navigation/pattern/font/content providers remain export-only.
* Additional CSS detected in BOTH Global Styles CSS and the `custom_css` post type, and refused.
* Remote-wrapper contract honours `AGENCY_REMOTE_WP_CLI_COMMAND` / `AGENCY_DEPLOY_URL` (deployment-side orchestration, not host-local Playwright).

### 11.14 Playwright tests

Add tests for:

* Client Editor Site Editor access.
* Shop Manager Site Editor access when commerce is active.
* Template edit/save.
* Header/footer edit/save.
* Navigation edit.
* Global Styles colour/typography edit.
* Code editor unavailable.
* HTML/Shortcode blocks unavailable.
* Forbidden admin screens inaccessible.
* **Migration parity** — old frontend vs new frontend (conversion did not change the rendered site).
* **Editing parity** — Site Editor canvas vs new frontend, editor chrome cropped out (separate test from migration parity).
* Frontend unchanged after prepare/finalise.
* Rollback restores frontend state.

Mask animations and nondeterministic dynamic content in visual tests.

### 11.15 Documentation

Update:

* `AGENTS.md`
* `docs/architecture.md`
* `docs/editing-strictness.md`
* `docs/ownership-rules.md`
* `docs/adding-a-block.md`
* `docs/validation-scenarios.md`
* `ops/launch-checklist.md`
* Backup/restore/deployment docs where state bundles and promotion backups matter
* Commerce documentation
* Generated block index

Add a focused runbook:

```text
docs/state-reconciliation.md
```

It must explain:

* Runtime truth.
* Export/diff.
* Ownership table.
* Prepare/seal/finalise/confirm/rollback.
* Concurrency protection (per-record locking + heartbeat).
* Reference refusal (and the v1 navigation-ref exclusion policy).
* Global Styles handling.
* Font/media policy.
* Sensitive file handling.
* Recovery procedure.
* **Revision-history trade-off:** promotion converts a saved DB override into the Git baseline and resets the DB override. Existing Site Editor revision history for that template/part may no longer be reachable through the normal UI afterwards. The protected promotion backup (and `--rollback` within the retention window) is the agency-side recovery path — it is NOT the same as the client opening Site Editor and restoring an old revision. State this explicitly so clients are not surprised.

---

## 12. Final validation

Run:

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

With commerce enabled, also run:

```bash
ddev composer test:integration:commerce
COMMERCE=1 npm run test:e2e:commerce
```

Run a fresh-clone proof:

1. Clone the final branch into a clean directory.
2. Run the documented setup.
3. Confirm the theme activates as a block theme.
4. Confirm no generated/local state file is required.
5. Confirm client roles are synchronised correctly.
6. Confirm the Site Editor works.
7. Confirm all verification commands pass.

Run a full promotion proof. A dedicated, isolated DDEV target is sufficient for this hosting-independent starter when the deployment wrapper uses its command adapter to reach that target. The same disposable proof site may supply the exported bundle and receive finalisation because this starter has no hosting provider. The wrapper contract tests must separately prove command quoting, heartbeat, confirmation, and rollback behaviour. Label this as an isolated adapter proof, not as a real-host deployment test.

1. Save a client template edit.
2. Save a template-part edit.
3. Save a Global Styles edit.
4. Export state (verify bundle HMAC).
5. Diff state.
6. Prepare selected overrides.
7. Commit the prepared files.
8. Seal the manifest with the deploy commit.
9. Deploy in the test environment.
10. Finalise (against the sealed commit).
11. Run Playwright.
12. Confirm.
13. Repeat with a test that intentionally fails Playwright → verify automatic rollback restores the original DB override and frontend.
14. Attempt rollback of a record a second promotion has since changed → verify it is REFUSED (does not overwrite newer work).

---

## 13. Definition of done — by release gate

The work is split into four release gates so the editing goal ships independently
of the brittle Global Styles promotion system. Each release is independently
mergeable; do NOT block an earlier release on a later one.

### Release 1 — Block theme & client editing (the primary goal)

* The final theme is recognised as a block theme.
* No classic rendering fallback remains.
* All existing request types render.
* Client roles can use the Site Editor (templates, parts, navigation, Global Styles editing).
* Forbidden admin capabilities remain unavailable (explicit admin-screen boundary + `customize` → `do_not_allow`).
* Server-side save validation enforces the full resolved block policy + content-level shortcode detection + `core/freeform`/null-block rejection.
* Editor-safe CSS is loaded correctly.
* Migration parity AND editing parity each pass their defined thresholds.
* Dynamic blocks have representative previews.
* Expected DB overrides no longer make normal checks fail (`check-overrides` informational by default).
* All architecture/unit/integration/E2E/visual/accessibility/commerce tests pass.
* Documentation describes the new editing model.

### Release 2 — Agent visibility (state export & diff)

* State export covers every provider listed in this brief (`--providers` / `--include-content` scoping).
* Deterministic `stateHash`; HMAC-signed bundles (keyring, separate purpose prefix).
* `state-diff` two-mode contract (Git-baseline vs bundle) with correct exit codes.
* Schema validation (`swaggest/json-schema` in `require`).
* `wp agency sanitize` regenerates the site UUID idempotently on non-production imports.

### Release 3 — Safe template & template-part promotion

* Promotion is selective and dependency-aware (navigation `ref` excluded by v1 policy; synced-pattern/media refs still hard-refused).
* Promotion is sealed (`--seal` binds the deploy commit) before finalisation.
* Bundles and manifests HMAC-signed; tampering detected.
* Per-record-key locking with `--heartbeat` refresh; locks hold finalize→confirm/rollback.
* Concurrent edits are never overwritten; rollback refuses records changed by a newer promotion or re-created after deletion.
* Finalisation is backed up and reversible; backups retained past confirm.
* Host-side `scripts/promote-overrides` wrapper (remote WP-CLI + CI Playwright + confirm/auto-rollback).

### Release 4 — Global Styles promotion (independent — do NOT block Releases 1-3)

* Global Styles round-trip without resolved-output drift (Theme JSON adapter passes resolved-output equivalence against the locked WordPress version).
* Global Styles promotion may remain export-and-diff only until this gate passes.

Release 4 does not block merging Releases 1-3 into the integration branch. It is still required before this full engagement can close or before the integration branch can merge to `main`, because the stated goal includes safe promotion of database style changes.

### All releases

* No state bundle, backup payload, secret, or customer data is committed.
* `git status` is clean.

---

## 14. Phase 4 — `cinq-wp` back-port

Execute only with `EXECUTE_CINQ_BACKPORT=1`.

Use a separate branch and engagement.

### 14.1 Inventory first

Inventory:

* Existing PHP templates/parts.
* Marketing pages.
* Dynamic blocks.
* WebGL/animation behaviour.
* Query/archive blocks.
* Existing page sync command.
* DB overrides.
* Navigation and patterns.
* Media and font references.
* Visual snapshots at desktop/mobile.

Export production state before edits.

### 14.2 Classify every UI unit

Classify each existing section/block as:

1. Replace with native core block.
2. Replace with WooCommerce block.
3. Replace with pattern/composition.
4. Keep as custom static block.
5. Keep as dynamic block with JavaScript preview.
6. Keep as frontend-only enhancement over editable static markup.

Prefer an editable static block structure with progressive frontend enhancement for WebGL and animation sections.

### 14.3 Migration

* Apply the final template architecture.
* Convert templates and parts.
* Move header/navigation to native blocks where practical.
* Preserve content and URLs.
* Migrate client DB edits through the state system.
* Do not promote unresolved IDs.
* Keep navigation/content DB-owned.
* Ensure each dynamic block has a representative editor preview.
* Rebuild the page sync behaviour on top of the generic state provider system instead of retaining a separate competing mechanism.

### 14.4 Acceptance

Use the same:

* Security tests.
* State round-trip tests.
* Desktop/mobile screenshot thresholds.
* Dynamic preview rules.
* Rollback proof.
* Full verification pipeline.

Produce a separate migration report listing:

* Replaced blocks.
* Retained custom blocks.
* Frontend-only effects.
* DB-owned state.
* Promoted state.
* Unresolved/manual items.
* Visual differences.
* Rollback procedure.

---

## 15. Execution ownership across agents

Keep this brief as the master specification. If the orchestrator delegates work
across agents, use four ownership tracks. Execute the release units in the
tracking file's serial order. Do not let multiple agents edit the same shared
files or own the same DDEV project concurrently.

**Use separate command registrars** so tracks 2 and 3 do not collide on command
registration:

```text
Cli/AgencyCommands.php    # existing command surface (check-overrides, etc.)
Cli/StateCommands.php     # state-export, state-diff, promotion-backups
Cli/PromotionCommands.php # promote-overrides prepare/finalize/confirm/rollback
```

| Track | Owns | Does NOT touch |
|---|---|---|
| **1. Block theme & permissions** | Templates, parts, CSS split, `ThemeBootstrap`, `RolesProvider`, `EditorRestrictions`, `Plugin.php` (lockdown removal + command wiring), `AgencyCommands.php`, theme architecture tests | State subsystem, commerce templates |
| **2. State export & diff** | `src/State/` providers, schemas, normalisation, `StateCommands.php`, reference scanner | Promotion lifecycle, theme files |
| **3. Promotion lifecycle** | `src/State/` promotion classes, `PromotionCommands.php`, manifest, locking, backup, confirm/rollback, `scripts/promote-overrides`, Theme JSON API adapter | Theme templates, role policy |
| **4. Commerce & final hardening** | WooCommerce block templates, `scripts/enable-commerce` update, commerce-specific tests, fresh-clone proof, documentation runbook | Base theme structure, role policy, shared architecture tests (add commerce-specific test files instead of editing shared ones) |

**Single-owner rule:** `Plugin.php`, `ThemeBootstrap.php`, and the shared
architecture tests under `tests/Architecture/` each have exactly one owning
track per change. Track 4 adds *new* commerce test files rather than editing the
same architecture tests Track 1 owns. Coordinate through the commit sequence
(§16), not through concurrent edits.

**Implementation order and a critical independence rule.** The block-theme
migration is straightforward; the Global Styles DB-to-Git promotion system is the
hard part (it leans on WordPress's internal `WP_Theme_JSON_Resolver`). Implement
in this order so the editing goal lands early and the hard part is isolated:

1. Block theme, permissions, editor restrictions, and visual parity (Track 1).
2. State export and state diff (Track 2).
3. Template and template-part promotion (Track 3, template/part providers).
4. Global Styles promotion (Track 3, Global Styles provider + Theme JSON adapter).

**Do NOT make client Site Editor access depend on the Global Styles promotion
system being completed.** They solve different problems: Site Editor access is a
permissions/theme-structure change (Track 1); Global Styles promotion is a
reconciliation feature (Track 3). Clients can edit visually before promotion
exists — the promotion system only reconciles their edits back to Git. The four
steps above are the four **release gates** in §13 — each is independently
mergeable, and Releases 1-3 must NOT be blocked by Release 4 (Global Styles
promotion).

---

## 16. Commit strategy

Use small, reviewable commits. Recommended sequence:

1. `test: capture block-theme migration expectations`
2. `spike: prove native block theme and editor styles`
3. `feat: add client Site Editor capability policy`
4. `feat: convert theme templates and parts`
5. `feat: migrate global styles and editor assets`
6. `chore: remove classic theme path and stale tests`
7. `feat: add state export and diff framework`
8. `feat: add promotion prepare finalize and rollback`
9. `feat: add Global Styles promotion`
10. `feat: add commerce block-theme templates`
11. `test: add reconciliation security and visual coverage`
12. `docs: document block theme and state workflow`

Do not combine the complete migration into one opaque commit.

Review and merge each release branch into `feat/block-theme-fse-migration` before the next dependent release starts. Run one independent Claude Code Opus 5 review over the complete integration diff after all release branches are merged. Apply its accepted fixes with GPT-5.6 Luna at max reasoning when that model is available. Then run a GPT-5.6 Sol high-reasoning review of the fix diff and the complete final verification matrix before merging and pushing `main`.

---

## 17. Required final report

At completion, report:

* Final commit SHA.
* All commits created.
* Files added, changed, and deleted.
* Architecture changes.
* Permission changes.
* State provider coverage.
* Command examples.
* Tests run and exact results.
* Visual parity metrics.
* Promotion/rollback proof.
* Commerce results.
* Known limitations.
* Any Phase 4 status.
* Confirmation that no customer state or secrets were committed.

Do not claim completion when a required test was skipped. Clearly label unavailable environment-dependent tests.
