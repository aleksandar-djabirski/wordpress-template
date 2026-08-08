# State Reconciliation

The database can hold intentional live overrides of the Git baseline: a
client editing a template, a template part or Global Styles through the Site
Editor writes a `wp_template`, `wp_template_part` or `wp_global_styles` row,
and that row is legitimate state, not corruption. This runbook is the
operating procedure for inspecting that state — export it, diff it, read the
drift — and for selectively promoting an override into Git when it should
become the new baseline. Everything here describes the implemented
`AgencyPlatform\State\*` surface; run the exact commands below, never a
recalled variant.

All WP-CLI commands run through the DDEV project (`ddev exec wp …`), and
environment variables reach the container only in the form
`ddev exec env KEY=VALUE … wp agency …` — a plain `export` on the host never
reaches the container. A promotion moves state between environments, so
`AGENCY_PROMOTION_HMAC_KEYS` and `AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID` are
required before any export or prepare; the shipped `.env` template carries
them only as comments (lines 62-63), so a freshly set-up site exits 1 until
an operator provisions the keyring. That is designed behaviour, not a bug.

## Runtime truth

> Git owns code and the promoted baseline. The database can contain
> intentional live overrides. Runtime truth is the active Git baseline plus
> current database state. Agents must export and inspect current state before
> changing or promoting structure and styles.

Database changes are expected, not corruption. `wp agency check-overrides`
(`scripts/check-database-overrides`) remains as a deprecated compatibility
alias that reports the same drift and exits zero by default; the commands in
this runbook are the full surface.

## Ownership table

This table is the single source of truth for state ownership. `State` and
`Default owner` reproduce the §5.4 defaults; `Provider slug` is the
implemented provider (`web/app/mu-plugins/agency-platform/src/State/Providers/`).

| State | Default owner | Promotion policy | Provider slug |
| --- | --- | --- | --- |
| Theme templates | Git baseline + DB overrides | Promotable | `templates` |
| Template parts | Git baseline + DB overrides | Promotable | `template-parts` |
| Global Styles | Git baseline + DB user origin | Promotable | `global-styles` |
| Navigation | Database | Export and diff; do not promote to Git in v1 | `navigation` |
| Synced patterns (`wp_block`) | Database | Export and diff; do not promote automatically in v1 | `synced-patterns` |
| Page/post/CPT content | Database | Export and diff; never promote to theme files | `content` |
| Media and attachment records | Database + uploads | Export references/metadata; never copy blindly | `media-references` |
| Font Library records/files | Database + uploads | Export; keep DB/filesystem-owned in v1 | `fonts` |
| Additional CSS | Forbidden for client roles | Detect and refuse automatic promotion | `custom-css` |

"Promotion policy" is the declared policy. What can actually be promoted
today is a smaller set: promotion strategies are registered for exactly
`templates`, `template-parts` and `global-styles`
(`PromotionStrategyRegistrar::add_strategies()`), so only those three can
ever be selected for promotion — the selector refuses any other provider
with exit 1. The other six export and diff only.

The extension point is the `agency_platform_state_providers` filter
(`StateRegistry::FILTER`). A project plugin can add providers by returning
them from that filter; every returned value must be a `StateProvider`
instance supplied as a named class, and providers are re-keyed by `slug()`
and sorted. Registrants never use closures.

Record keys are `<provider>:<slug>`: `templates:page`,
`template-parts:site-header`, the single Global Styles record
`global-styles:active`, `navigation:<post_name>`, `synced-patterns:<post_name>`,
`content:<post_type>-<ID>`, `fonts:wp_font_family-<ID>` /
`fonts:wp_font_face-<ID>`, `media-references:attachment-<ID>`, and the two
fixed custom-css records `custom-css:global-styles` and
`custom-css:custom-css-post`.

## Export and diff

```
ddev exec wp agency state-export --output=<path> [--providers=<list>] [--include-content]
ddev exec wp agency state-diff [--source=<state-bundle>] [--providers=<list>] [--include-content] [--format=table|json]
```

`state-export` writes a signed, schema-validated bundle. `--output` is
required; `--output=-` writes the bundle document itself to STDOUT, and a
real path writes the bundle atomically and reports the envelope
(`{output, exportId, exportedAtUtc, stateHash, providers}`) on STDOUT. An
output path inside the web root is refused: customer state must never be
publishable over HTTP.

Provider scoping works the same for both commands:

- Default: every registered structural provider — `templates, template-parts,
  global-styles, navigation, synced-patterns, fonts, media-references,
  custom-css`.
- `--include-content` adds the `content` provider. Naming a provider
  explicitly (`--providers=content`) includes it even without
  `--include-content`. An unknown slug is a hard error that lists the valid
  slugs.

`state-diff` has two comparison modes:

- Without `--source`: the live database is compared against the Git baseline
  files. Database-owned providers (navigation, synced patterns, content,
  fonts, media-references) are informational only and never count as drift —
  they have no Git counterpart.
- With `--source=<bundle>`: the live database is compared against the
  exported bundle. Database-owned providers DO count as post-export drift,
  so a navigation or content change made after export is caught. `--source=-`
  reads the bundle from STDIN; it is schema-validated, signature-verified and
  its `stateHash` is recomputed before anything is read.

Exit codes:

- `state-export`: `0` success, `1` hard error (missing `--output`, unknown
  provider slug, refused output path, missing HMAC keyring, any
  `StateException::hard_error`). Codes `2`, `3` and `4` are never emitted by
  `state-export`.
- `state-diff`: `0` no drift, `2` drift (the deploy-gate mapping), `1` hard
  error (bad `--format`, unknown provider, malformed `--source`,
  configuration-level keyring errors), `4` tamper (missing or invalid
  signature, unknown key id, HMAC mismatch, or `stateHash` mismatch).

STDOUT carries only machine-readable JSON — the bundle, the envelope, the
diff table or the report document — and every warning and diagnostic goes to
STDERR. Live example: with the keyring unset, `state-export` exits 1 and
STDERR reads `AGENCY_PROMOTION_HMAC_KEYS is not set: no HMAC key is
available.` Provision the keyring; there is no WordPress-salt fallback.

## Prepare, seal, finalise, confirm, rollback

The promotion lifecycle moves a selected override from the database into the
Git baseline. Its five stages, in order:

1. **Prepare** (`--prepare --source=<bundle> --select=<records> --manifest=<path>`).
   A local operation that refuses to run in production. It reads the bundle,
   verifies `AGENCY_TARGET_SITE_UUID` matches the bundle's `siteUuid`, checks
   the branch (`AGENCY_EXPECTED_BRANCH` when set; a detached HEAD refuses
   unless `AGENCY_ALLOW_DETACHED_HEAD` is set), stages each selected record
   into a prepared file next to its final theme path, writes the signed,
   unsealed manifest, and commits nothing. Per-record refusals land in the
   manifest report and the run continues; run-level refusals abort before
   anything is written.

2. **Commit the prepared files** into Git and record the commit SHA. The
   deploy commit does not exist during prepare — the prepared files are
   staged but not yet committed — which is exactly why `--seal` exists.

3. **Seal** (`--seal --manifest=<path> --deploy-commit=<sha>`). Binds the
   manifest to the deploy commit. Every prepared file is verified twice: on
   disk and in the named commit. A file modified after prepare, or committed
   with different bytes, is tamper (exit 4); a prepared file absent from the
   commit is a hard error (exit 1) — commit the prepared files, never re-seal
   against a commit that does not carry them. Re-sealing the same commit is
   idempotent; a different commit refuses.

4. **Deploy, then finalise** (`--finalize --manifest=<path>`), on the target
   host. `scripts/promote-overrides` does NOT transfer the manifest — placing
   the protected artifact in the host's state directory is the deployment
   pipeline's job. Finalize first runs the run-level guards, which abort the
   whole run writing NOTHING: the manifest must be sealed,
   `AGENCY_DEPLOY_COMMIT` must equal the sealed commit, and the active theme
   stylesheet, theme version, site uuid, site URL and environment must all
   match the manifest. Then the per-record loop promotes each pending record:
   the live row must still match the exported record (object id, content hash
   and modification marker — a concurrent edit refuses), the navigation
   expectation is verified against the target, the row is backed up and the
   backup proven retrievable, the row is reset, caches are flushed, and the
   resolved state must equal the recorded expectation — otherwise the row is
   restored from the backup and the record refused. The canonical host
   manifest is written before the first reset, so a crash mid-loop always
   leaves a recoverable record.

5. **Settle** — `--confirm --manifest=<path>` when the deployed site
   verified, `--rollback --manifest=<path>` when it did not. Confirm marks
   the promotion confirmed, releases its locks, and keeps the backups (only
   `promotion-backups prune` removes them). Rollback restores the
   pre-finalize rows from the protected backups in dependency-safe order
   (template-parts, then templates, then global-styles) and refuses rather
   than overwrites when anything changed after finalisation.

Between finalize and confirm the per-record locks must be kept alive with
`--heartbeat --manifest=<path>`; `scripts/promote-overrides` runs it
automatically (default every 60 seconds, `AGENCY_HEARTBEAT_INTERVAL`).

The selector syntax is `<provider>:<slug>`, comma-separated:
`--select=templates:page,template-parts:site-header,global-styles:active`.
`global-styles:active` targets the active theme's single Global Styles
record.

Exit codes, identical across the lifecycle:

| Code | Meaning |
| --- | --- |
| 0 | Success: every record prepared/promoted/restored/skipped, no refusals |
| 1 | Hard error: any refusal with zero successes, missing options, guard failures, unexpected Throwable |
| 2 | Partial success: at least one success AND at least one refusal; the refusal report is written into the manifest, and already-succeeded records are not reprocessed on re-run (idempotent) |
| 3 | Lock conflict: another promotion owns a record, the prepare lock is held, or the core lock API is unavailable |
| 4 | Tamper: manifest signature or stateHash verification failure |

`scripts/promote-overrides` mirrors these codes for the operator: `0`
success, `1` hard error, `2` partial success, `3` lock conflict, `4` tamper
detection. STDOUT carries exactly one JSON document per run — the outcome
report, or the signed manifest for `--prepare`/`--seal` with `--manifest=-`.

## Concurrency protection

One promotion at a time per canonical record key. At finalize, the
`RecordLockManager` acquires a lock for every pending record key in sorted
key order; the first conflict releases every lock the attempt already took
and exits 3. `--confirm` and `--rollback` release the locks (rollback
re-acquires them for the attempt and releases them in a finally). A refused
or self-restored record releases its lock at finalize — only promoted
records keep locks for settlement. `--heartbeat` refreshes the locks of the
records a promotion still owns; a lock that is no longer this promotion's
(expired and reclaimed, or released) is a lock conflict, exit 3.

Each lock is an atomic gate (`\WP_Upgrader::create_lock()`, the INSERT
IGNORE primitive) plus a metadata option, with every gate/metadata mutation
serialised through a per-key mutex so an abandoned heartbeat can never
interleave with another promotion's reclaim. The TTL defaults to 900 seconds
(`AGENCY_PROMOTION_LOCK_TTL`; a zero value is invalid and falls back to the
default), after which an expired lock is reclaimable by another promotion.
If the core lock API is unavailable the lifecycle fails CLOSED with exit 3 —
never a degraded, racy fallback.

Local `--prepare` uses a filesystem lock, not a database option: an
exclusive `flock()` on `<AGENCY_STATE_DIR>/prepare.lock`. The same lock file
is shared by every worktree of the repository, and it is released
automatically when the process exits, so a crashed prepare can never leave a
stale lock behind. Another prepare run holding it is a lock conflict, exit 3.

## Reference refusal

Before promotion, every selected record is scanned for environment-specific
references (`ReferenceScanner`). The scanner detects: navigation `ref`s,
synced-pattern `ref` values (`core/block`), attachment/media IDs
(`core/image`, `core/cover`, `core/media-text`, `core/video`, `core/audio`,
`core/file`), the site logo (`site_logo` option), gallery ID lists, font
file paths (`.woff`, `.woff2`, `.ttf`, `.otf`, or a path through
`/fonts/`), plugin block IDs (any block outside the `core`/`agency`/
`woocommerce` namespaces), post-meta block bindings, and unknown `ref`-like
attributes (`ref`, or an attribute ending in `Id`, `Ids`, or `_id`).

A refusal report names the record and provider, the block name, the
attribute, the referenced value and the suggested policy, so an operator can
act on it. An unresolved reference refuses THAT record — never the whole
run.

The v1 navigation policy: navigation is never promoted. The promotion
contract removes the `ref` from every `core/navigation` block, and it may do
so safely only when the ref-less block resolves to exactly one navigation
whose normalised content hash matches the exported one. At prepare, every
navigation block is matched to its exported navigation record and the
identity/hash is recorded in the manifest; a block with no exported
reference, a reference with no target hash, or two different navigation
contents in one record all refuse. At finalize, the target's deterministic
fallback — the most recently published `wp_navigation` post, the same query
core's `WP_Navigation_Fallback` runs — must resolve the exported hash,
for a source block that was ref-less and for a block whose explicit ref was
removed. The record refuses otherwise (`missing-navigation`,
`navigation-content-mismatch`). v1 invents no mappings: any record that
cannot be resolved this way stays database-owned.

## Global Styles

Global Styles is the third promotable provider: exactly one record,
`global-styles:active`, the user origin of the active theme (the empty user
origin is the baseline; anything the customer saves is an override), promoted
to the theme's `theme.json`.

Every internal Core Theme JSON call — `WP_Theme_JSON`, `WP_Theme_JSON_Data`,
`WP_Theme_JSON_Resolver`, and every cache-clearing call — sits behind the
`ThemeJsonAdapter`, the only file in the project allowed to name those
internals. The adapter fails closed: before any operation it probes the
locked WordPress for every symbol it depends on (classes, methods,
functions, constants), and a missing symbol is a hard error naming the
missing symbols — Global Styles promotion is refused, never degraded.

The repository targets exactly one WordPress version — the version
`composer.lock` resolves, WordPress 7.0.2 at the time of writing — and there
is deliberately no version matrix. Adapter compatibility is asserted by
`tests/Integration/Promotion/ThemeJsonAdapterTest.php`, which re-runs on
every WordPress dependency update, so a WordPress upgrade that changes the
Core API shape turns the integration suite red.

Because the fully resolved settings and styles depend on the target host,
the strategy captures the pre-reset resolved view on the target — the
canonical flattened view from
`WP_Theme_JSON_Resolver::get_merged_data()->get_data()`, split into settings
and styles — and, after the reset, the resolved-output equivalence check must
pass: the post-reset resolved output must equal the pre-reset snapshot. A
mismatch restores the user origin from the backup and refuses the record
with `resolved-output-drift`; the check never reports drift by construction,
because promotion legitimately moves a user preset from the `custom` origin
to the `theme` origin, and the canonical view resolves both identically.

Release 4 is merged and the gate this section guards was green at Phase B
start: `GlobalStylesState.php`, `GlobalStylesPromotionTest.php` and
`ThemeJsonAdapterTest.php` all exist, and the integration suite measured 451
tests / 2105 assertions. A missing Global Styles promotion surface is a
failed Phase B gate.

## Fonts and media

The `fonts` provider (Font Library records: `wp_font_family` and
`wp_font_face`, publish status only) and the `media-references` provider
(attachment metadata of the fixed source set — templates, template parts,
navigation, synced patterns and the `site_logo` option) stay
database/filesystem-owned in v1. Exports carry references and metadata only,
never the files themselves, and nothing is copied blindly. A record that
references a font file or an attachment is refused at promotion — media IDs
are environment-specific and no ID mapping is invented; export the
reference and remap it manually.

## Sensitive file handling

Bundles and manifests default to `<repo root>/var/agency-state/`, which is
gitignored (`.gitignore` line 74) and outside the web root, so nothing there
is ever published over HTTP. `AGENCY_STATE_DIR` overrides the location; a
relative override resolves against the repository root and must not contain
`..` segments, and a path inside the web root is rejected outright. An
export can contain customer content and internal site structure — the
exporter prints a sensitivity warning to STDERR on every export. Treat
bundles as confidential, and never commit a bundle, a manifest, or a backup
payload.

The exporter excludes: passwords (a content row exports only the
`hasPassword` boolean, never `post_password`), session tokens, application
passwords, secrets, WooCommerce order/customer PII, and user email
addresses. The content provider reduces identifying fields to `authorId` (an
integer) and `hasPassword`.

## Recovery procedure

A bad promotion is undone with `--rollback --manifest=<path>` inside the
retention window — the implemented default is 30 days
(`AGENCY_PROMOTION_BACKUP_RETENTION_DAYS` overrides it, an integer number of
days; a missing, blank or non-digit value resolves to the default). The
window per backup is `max( finalizedAtUtc, settledAtUtc )` plus the
retention period, rendered as `retentionUntilUtc` in the backups list; a
backup is prunable once that time has passed.

```
ddev exec wp agency promotion-backups list [--format=json|table]
ddev exec wp agency promotion-backups prune [--older-than=<30d|12h|seconds>] [--dry-run]
```

`list` renders one row per protected backup. `prune` deletes the backups
past BOTH their retention window and the `--older-than` cutoff (default
`30d`; a malformed duration is a hard error), and `--dry-run` previews
without deleting — the human summary is prefixed `DRY RUN` so a preview can
never be mistaken for a deletion.

A refused rollback means the tool refuses rather than clobbering. The
per-record refusals are: `changed-since-finalize` (the record changed after
finalisation), `record-recreated` (the client recreated a record that
finalize deleted), `claimed-by-newer-promotion` (a newer promotion owns the
record), and `backup-missing` (the backup was pruned or never written).
Rollback after confirm is refused outright — confirm is the point of no
return, because retention may prune the backups at any moment after it. When
a rollback refuses, a newer promotion or a client edit landed; resolve that
first, then roll back deliberately or keep the promotion. When a rollback
cannot restore content, or manual intervention is required, escalate to
`ops/incident-recovery.md`.

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

## Verification proofs

The promotion proof below is the reusable procedure for a staging
environment. It runs on the **base profile** — a commerce-enabled site
carries a seeded `site-header` override that shows up as extra drift in
every diff and would break the expected exit codes. It assumes one staging
host where prepare, seal and finalize all run in the same checkout, and that
the HMAC keyring was provisioned as described above.

Use the SAME keyring string for every command; a fresh site fails every
signed command with exit 1 until the keyring is provided.

1. **Provision the keyring and export the bundle.**

   ```
   ddev exec env AGENCY_PROMOTION_HMAC_KEYS='{"2026-01":"<32+ random characters>"}' AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID=2026-01 wp agency state-export --output=var/agency-state/proof-bundle.json
   ```

   Expected exit 0. STDOUT carries the envelope JSON, STDERR the sensitivity
   warning. Without the keyring the command exits 1 with
   `AGENCY_PROMOTION_HMAC_KEYS is not set: no HMAC key is available.` — that
   is designed behaviour.

2. **Confirm the clean baseline.**

   ```
   ddev exec wp agency state-diff
   ```

   Expected exit 0 with every row's `drift` column `no`. Record the target
   uuid for step 6:

   ```
   ddev exec wp option get agency_platform_site_uuid
   ```

   Expected exit 0; the value is the staging site's uuid.

3. **Create the override.** Open the Site Editor, edit the `templates:page`
   template (any visible change) and save it. This writes the
   `wp_template` row the promotion will move into Git.

4. **Drift gate.** Compare the live database against the step-1 bundle:

   ```
   ddev exec wp agency state-diff --source=var/agency-state/proof-bundle.json
   ```

   Expected exit 2: `templates:page` changed since export and counts as
   drift. This is the deploy gate in action.

5. **Re-export the bundle** with the same command as step 1, overwriting
   `var/agency-state/proof-bundle.json`. Expected exit 0. The promotion must
   be prepared from a bundle that contains the override.

6. **Prepare the promotion.**

   ```
   ddev exec env AGENCY_TARGET_SITE_UUID=<target-uuid> AGENCY_PROMOTION_HMAC_KEYS='{"2026-01":"<32+ random characters>"}' AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID=2026-01 wp agency promote-overrides --prepare --source=var/agency-state/proof-bundle.json --select=templates:page --manifest=var/agency-state/proof-manifest.json
   ```

   Expected exit 0 with the outcome report on STDOUT. A refusal would land
   in the report, not abort the run.

7. **Inspect the manifest.** Read `var/agency-state/proof-manifest.json` and
   confirm the record `templates:page` is present with its prepared file
   paths and `preparedFileHash`, and that the manifest is unsealed (no
   `deployCommit` yet).

8. **Commit the prepared file and record the SHA.** The prepared file for
   `templates:page` is `web/app/themes/site-theme/templates/page.html`:

   ```
   git add web/app/themes/site-theme/templates/page.html
   git commit -m "promotion: stage templates:page"
   git rev-parse HEAD
   ```

   Expected exit 0; the SHA is the deploy commit for step 9.

9. **Seal the manifest.**

   ```
   ddev exec env AGENCY_PROMOTION_HMAC_KEYS='{"2026-01":"<32+ random characters>"}' AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID=2026-01 wp agency promote-overrides --seal --manifest=var/agency-state/proof-manifest.json --deploy-commit=<deploy-sha>
   ```

   Expected exit 0. Sealing verifies the prepared file on disk and in the
   named commit.

10. **Tamper drill.** Copy the manifest aside, change one character inside
    its signed payload, then finalize:

    ```
    ddev exec env AGENCY_DEPLOY_COMMIT=<deploy-sha> wp agency promote-overrides --finalize --manifest=var/agency-state/proof-manifest.json
    ```

    Expected exit 4: the signature no longer verifies and nothing is
    touched. Restore the pristine copy before continuing.

11. **Deploy-commit-mismatch drill.** Finalize with the wrong deploy commit:

    ```
    ddev exec env AGENCY_DEPLOY_COMMIT=<other-sha> wp agency promote-overrides --finalize --manifest=var/agency-state/proof-manifest.json
    ```

    Expected exit 1: the configured deploy commit does not match the
    manifest's sealed commit, refused before any mutation.

12. **Finalize for real.** Set `AGENCY_PROMOTION_LOCK_TTL=60` for this run
    and do not confirm — promotion A must stay finalized-but-unconfirmed so
    the superseded-record drill in step 14 can run:

    ```
    ddev exec env AGENCY_DEPLOY_COMMIT=<deploy-sha> AGENCY_PROMOTION_LOCK_TTL=60 wp agency promote-overrides --finalize --manifest=var/agency-state/proof-manifest.json
    ```

    Expected exit 0. The `templates:page` row is reset and the site now
    resolves the deployed file; the canonical host manifest is the durable
    recovery record.

13. **Verify, heartbeat, and the auto-rollback drill.**

    ```
    ddev exec wp agency state-diff
    ```

    Expected exit 0: `templates:page` no longer drifts — the override is the
    Git baseline. Keep promotion A's locks alive during verification:

    ```
    ddev exec wp agency promote-overrides --heartbeat --manifest=var/agency-state/proof-manifest.json
    ```

    Expected exit 0 (with the step-12 TTL of 60 seconds, these locks expire
    shortly after this command — the next drill touches a different record,
    so nothing conflicts).

    *Auto-rollback.* Create a second override on `template-parts:site-header`
    in the Site Editor, then repeat steps 5-9 for
    `--select=template-parts:site-header` as promotion B. Run the wrapper
    against a deploy URL whose verification fails:

    ```
    AGENCY_REMOTE_WP_CLI_COMMAND='<remote-wp-cli-command>' AGENCY_DEPLOY_URL='<url-that-fails-verification>' AGENCY_REMOTE_STATE_DIR='<host-state-dir>' AGENCY_PLAYWRIGHT_PROJECT='<project>' AGENCY_VERIFICATION_TIMEOUT=600 scripts/promote-overrides --manifest=<path-to-B-manifest>
    ```

    Expected: the wrapper finalizes B, the verification fails, and the
    wrapper settles a rollback automatically. Wrapper exit 1, and

    ```
    ddev exec wp agency state-diff
    ```

    exits 2 with the part's override restored — nothing was left promoted.

14. **Superseded-record drill and settlement refusals.** Promotion A's locks
    from step 12 expired long ago (TTL 60, no further heartbeats). Recreate
    the `templates:page` override, then run promotion C over the SAME record
    through steps 5-13 (export, prepare, commit, seal, finalize — every exit
    0; the default 900-second lock TTL applies to C). Now roll promotion A
    back:

    ```
    ddev exec wp agency promote-overrides --rollback --manifest=var/agency-state/proof-manifest.json
    ```

    Expected exit 1 with a refusal report naming
    `claimed-by-newer-promotion`: a newer promotion owns the record, so the
    tool refuses rather than clobbering. Confirm promotion C:

    ```
    ddev exec wp agency promote-overrides --confirm --manifest=var/agency-state/proof-c-manifest.json
    ```

    Expected exit 0: confirmed, locks released, backups kept. A rollback of
    C now refuses:

    ```
    ddev exec wp agency promote-overrides --rollback --manifest=var/agency-state/proof-c-manifest.json
    ```

    Expected exit 1: rollback after confirm is refused — confirm is the
    point of no return. Finish with backup hygiene:

    ```
    ddev exec wp agency promotion-backups list
    ddev exec wp agency promotion-backups prune --older-than=30d --dry-run
    ```

    Both expected exit 0: the backups of promotions A, B and C are listed
    and retained inside the window.
