# Block Theme Task 2 — State Export & Diff Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give agents complete, deterministic, HMAC-signed, schema-validated read access to WordPress Site Editor state (`wp agency state-export`) plus two-mode drift reporting (`wp agency state-diff`), with zero promotion behaviour.

**Architecture:** A new `AgencyPlatform\State\*` namespace under `web/app/mu-plugins/agency-platform/src/State/` holds slug-keyed *state providers* (one per §5.4 ownership row) behind a single `StateProvider` interface, discovered through `StateRegistry` and the `agency_platform_state_providers` filter. `StateExporter` serialises every selected provider's normalised records into a deterministic bundle whose `stateHash` covers canonical provider records only; `HmacSigner` signs the whole document with a keyring and a bundle-specific purpose prefix; `StateBundle` is the only sanctioned reader and refuses to hand out records before the signature verifies. `StateDiffer` compares live DB records either against Git baseline files (`GitBaseline`) or against a previously exported bundle, and classifies every entry as promotable / db-owned / forbidden / unresolved / unchanged. Everything WordPress-free is a pure static method so the `unit` suite can cover it without stubs; everything WordPress-coupled is covered by the `integration` suite.

**Tech Stack:** PHP 8.3, WordPress (Bedrock layout), WP-CLI, `swaggest/json-schema` (new runtime `require`), PHPUnit 9.6 (`architecture` / `unit` / `integration` suites), phpcs (WordPress-Extra), PHPStan level 6, deptrac.

---

## Execution order — corrected 2026-08-05

```
1 → 2 → 3 → 4 → 5 → 6 (scanner only) → 7 → 8 → 9 (resolver + the six providers) → 10 → 11 → 12 → 13
```

The task numbers run in sequence, but **Task 6's scope moved**. The unmodified plan had no valid order at all: Task 6 shipped `ReferenceResolver`, which reads `StateRegistry` from Task 8, while Task 8 consumes `ReferenceScanner` from Task 6. Task 6 now ships the pure scanner only and Task 9 ships `ReferenceResolver` plus `ReferenceScanner::scan()`. See the correction block at the head of Task 6.

Seven plan defects were found and corrected before Task 1 started. Six came from a read-only `gpt-5.6-terra` high audit; the seventh (`CheckOverridesReportTest.php`) came from the orchestrator while verifying the sixth. Each correction is recorded inline at the place it applies, with the evidence that proves the original text was wrong:

| # | Where | Defect |
|---|---|---|
| 1 | Task 8 test block | Two `content` assertions could not pass — `ContentState` first exists in Task 9. Moved to Task 9 Step 4. |
| 2 | Task 6 ↔ Task 8 | Circular dependency; no valid task order existed. `ReferenceResolver` and `scan()` moved to Task 9. |
| 3 | Global Constraints, file ownership | Claimed Task 3 appends its own `.env.example` entries. Task 3 lists and stages no such file; Task 1 already adds both HMAC settings. Task 1 is now the sole owner. |
| 4 | Task 3 Step 3 | `Logger::redact()` needed an explicit, recorded exception to the "WordPress core and PHP only" rule. It is intra-layer and deptrac-clean. |
| 5 | Task 13 Step 4 | Claimed `check_overrides()` "takes no arguments at all". False since Unit 1's `d0da3f3`. |
| 6 | Task 13 Step 3 | A test claimed to drive "the real alias logic" never called the alias. Renamed to a runner test; a real WP-CLI test added as Step 3c. |
| 7 | Task 13 Step 5 | Deleting the two drift helpers orphans `tests/Unit/AgencyPlatform/CheckOverridesReportTest.php`, which tests nothing else. Now deleted with them under a recorded ownership transfer. |
| 8 | Task 1 Step 8 (found during execution) | The `phpcs:ignore` sniff code was `Generic.CodeAnalysis.UnusedFunctionParameter.Found`. The installed PHPCS reports the warning as `…UnusedFunctionParameter.FoundAfterLastUsed`, so the suppression never matched and `lint:php` failed inside `verify:fast`. Proven by restoring the wrong code and re-running phpcs. Corrected to `FoundAfterLastUsed`. |

| 9 | Task 2 Step 5 (found during execution) | The plan's own verbatim `Normalizer` code cannot pass this repo's `lint:php`. `WordPress.Security.EscapeOutput.ExceptionNotEscaped` fires on every variable in an exception message, and this subsystem's diagnostics name the offending key by design. Resolved by a scoped `phpcs.xml` exclusion; see the ruling below. |
| 10 | Task 4 Interfaces block (found during execution) | Required "message lists every violation"; the pinned library is fail-fast and cannot. Amended to first-violation-with-pointer-path; see the amendment below. |
| 11 | Task 5 line 1700 → Task 9 (found during execution) | `BaseStateProvider::detect_references()` was specified to scan markup, but `ReferenceScanner` does not exist in Task 5, so it shipped as `return array();`. **Nothing forced a later task to restore it.** Task 9 Step 3c now does, non-optionally, with a required break-and-restore proof. This is the second consequence of the same Task 6/8 circularity — see defect 2. |
| 12 | Task 6 determinism test (found during execution) | The fixture used `customRef`, which matches no matcher, so both orderings emitted ONE reference and the assertion was **vacuous** — it passed against a completely unsorted implementation. Measured: count=1. Reference order feeds the bundle and therefore `stateHash`, so an unsorted scanner would make the hash vary with PHP attribute traversal and produce random phantom drift. Fixture corrected to `mediaId` + `someId` — both unknown-ref catch-alls emitted from the attribute loop — plus an explicit count assertion so the test can never go vacuous again. **The orchestrator's first correction (`id` + `mediaId`) was itself vacuous** and the worker caught it: `core/cover` is in `MEDIA_BLOCKS`, so `match_block_level()` consumes `id` before the attribute loop and its position never varies. Proven by neutering `sort_references()` and observing the test still pass. |

**Sniff codes are version-specific.** Defect 8 is a reminder for every later task in this plan: a `phpcs:ignore` whose code does not match what the installed sniff actually emits is silently inert. If a suppression does not take effect, re-read the real phpcs output for the exact code rather than assuming the plan's code is current. Never replace a failing suppression with a broader one, and never add `@phpstan-ignore` to production code.

### Ruling on exception messages and `phpcs.xml` — orchestrator, 2026-08-05

**This applies to EVERY task in this plan, not only Task 2.** Task 2 was simply the first task to throw a `StateException`; there is no other `throw new` anywhere in `agency-platform` or the `site-*` plugins, so the whole subsystem hit this at once.

`WordPress.Security.EscapeOutput.ExceptionNotEscaped` flags any variable interpolated into an exception message. This plan REQUIRES such messages throughout — Task 3 alone demands a message "naming `AGENCY_PROMOTION_HMAC_KEYS` and the offending key id". The measured failure on the plan's own verbatim Task 2 code was 4 errors across `Normalizer.php:91,198,205`.

**Two fixes were considered and rejected, with reasons:**

- **`esc_html()` on the message — rejected as actively harmful.** It would make `Normalizer` and every other state class depend on WordPress, which breaks the Global Constraint that unit-tested methods stay WordPress-free so `tests/support/wp-stubs.php` never grows into a shadow WordPress. It would also mangle CLI diagnostics for no security benefit, since these messages never reach a browser.
- **A `phpcs:ignore` at every throw site — rejected as unbounded.** The suppression count would grow across all 13 tasks, and each one is a place a genuine escaping bug could later hide. Unit 1 already established that suppressions added to shipping code to satisfy tooling are the wrong fix.

**Accepted fix: a scoped `phpcs.xml` exclusion.** `StateException` is a WP-CLI-only failure type; the command surface catches it and converts it to an exit code, so the sniff's premise — that the message may reach a browser — is false for this subsystem. The exclusion is limited to `web/app/mu-plugins/agency-platform/src/State/*` and the sniff stays fully active everywhere else.

**Proven scoped, not merely applied.** A probe class containing `throw new \RuntimeException( 'probe ' . $why )` was placed OUTSIDE `src/State/` at `agency-platform/src/Health/`, and phpcs still reported `FOUND 1 ERROR … (WordPress.Security.EscapeOutput.ExceptionNotEscaped)`. The probe was then removed. Without that check the exclusion could have blinded the sniff repo-wide and nothing would have failed.

**`WordPress.PHP.IniSet.Risky` is handled differently and deliberately so.** It is BOUNDED — `canonical_json()` is the single place that pins `serialize_precision`, and its test pins it once more to prove independence. Four sites total, and the code genuinely is unusual enough to deserve a note to the next reader. Those keep a per-site `phpcs:ignore` with a real reason, matching the house style already used in `AgencyCommands.php` and `EnvironmentConfig.php`. Do not add this to `phpcs.xml`.

**Ownership grant:** `phpcs.xml` is edited by the ORCHESTRATOR for this ruling only, in its own commit. No worker task may edit `phpcs.xml`. If a later task hits a sniff it believes is wrong, it must STOP and report rather than change the ruleset or broaden a suppression.

**Formatting violations are fixed, never suppressed.** The plan's verbatim test block also tripped `ArrayDeclarationSpacing.AssociativeArrayFound` and `MultipleStatementAlignment.DoubleArrowNotAligned`. Those are real style violations and phpcbf fixes them automatically. Suppressing a formatting sniff is not acceptable in any task.

### Amendment — Task 4 cannot list every schema violation, and does not have to

Tenth plan defect, ruled by the orchestrator on 2026-08-05. Task 4's Interfaces block required `message lists every violation`. **The pinned library cannot do it**, and the requirement is amended rather than faked.

Evidence, verified by the orchestrator against `vendor/` rather than accepted from a report:

- `vendor/swaggest/json-schema/src/Context.php` contains **no** error-collection mode — zero occurrences of `error` in the whole file.
- `InvalidValue::$subErrors` is populated only at `Schema.php:433-473` and `494-514`, both of which are the `oneOf`/`anyOf` composite branches. Those are sub-branches of ONE keyword, not independent violations.
- Object validation fails fast: `processObject()` propagates the first failure.
- Measured on the real schema with a document carrying BOTH an invalid `stateHash` and an unknown `surpriseField`: one message, `SUBERRORS: 0`.

**Loop-and-retry was considered and rejected as unsound**, not merely inconvenient. Removing a violating key from a copy to find the next error synthesises artefacts — `required` then fires for the removed key, and `providers.minProperties: 1` fails once the last provider is removed — so a generic retry can neither terminate reliably nor stay honest about what it found.

**Switching to an aggregating library was considered and rejected**, with the reason recorded so a reviewer can overturn it. `justinrainbow/json-schema` and `opis/json-schema` both aggregate, but the swap would change a runtime dependency already committed in Task 1, rewrite this class, and ripple into the unit that reuses it for promotion manifests. The decisive point is what these documents ARE: bundles and manifests are **machine-generated by this subsystem**, not hand-authored by an operator. A schema violation therefore signals a defect in the exporter, not a typo a human is iterating on, so "fix one, re-run, discover the next" is largely hypothetical. One precise violation with its JSON pointer path is sufficient to diagnose an exporter bug.

**The amended requirement:** the message names the first violation together with its JSON pointer path — the library already supplies e.g. `#->properties:stateHash->$ref[#/definitions/sha256]` — and states plainly that validation stopped at the first violation, so nobody reads a single error as "only one thing is wrong". Validation CORRECTNESS is unchanged: an invalid document is still rejected, which is the property that actually protects the production host.

Raised for the unit review. A reviewer who judges full aggregation worth a library swap should say so there.

---

## Global Constraints

Every task's requirements implicitly include this section.

**From `BLOCK_THEME_PROPOSAL.md` §4 (non-negotiable repository rules), the ones this task can break:**

- Production hooks use named methods, not closures.
- No forbidden catch-all directories (`components`, `layouts`, `inc`, `includes`, `helpers`, `misc`, `common`, `lib`, `utils`).
- `agency-platform` has no project-layer dependencies (it may not reference `SiteCore\*`, `SiteIntegrations\*`, `SiteCommerce\*`, or `SiteTheme\*`).
- Outbound HTTP remains limited to integration layers — **this task makes zero network calls**. Never use `wp_remote_*`, cURL, `fsockopen`, Guzzle, or `file_get_contents('http…')` anywhere in `src/State/` or `src/Cli/StateCommands.php`.
- `verify:fast` and `verify` remain commit gates.

**From `AGENTS.md` and the repository's enforced conventions:**

- Run PHP/Composer through DDEV: `ddev composer <script>`, `ddev exec vendor/bin/phpunit …`.
- `ddev composer verify:fast` before **every** commit. `ddev composer test:integration` additionally on every task that touches the database.
- Environment is read through core `wp_get_environment_type()`, never `WP_ENV`.
- No direct SQL where a WordPress API exists (spec §11.11). Every DB read in this task goes through `get_posts()`, `get_option()`, `get_post_meta()`, `wp_get_custom_css_post()`, `wp_get_attachment_metadata()`, etc.
- **Every PHP file:** `<?php`, blank line, `declare(strict_types=1);`, blank line, `namespace …;`. Classes are `final` unless something must extend them. Match the surrounding agency-platform file style: long array syntax `array()`, tabs for indentation, Yoda conditions (`array() === $x`), a real explanatory docblock on every class and non-trivial method.
- **Method and property names are snake_case** (`WordPress.NamingConventions.ValidFunctionName` / `ValidVariableName` are active through `WordPress-Extra`). Class names are `StudlyCase`. JSON *field* names in bundles/reports are camelCase — that is data, not PHP identifiers.
- PHPStan level 6 requires value types on every array in phpdoc (`@param list<string> $x`, `@return array<string, mixed>`). Add them or `analyse` fails.
- `file_get_contents` / `file_put_contents` / `fwrite` need a `// phpcs:ignore WordPress.WP.AlternativeFunctions…` line with a real reason (CLI-only, no `WP_Filesystem` credentials context). Follow the precedent in `tests/support/ArchitectureScanner.php:448` and `tests/Integration/bootstrap.php:84`.
- **Never add stubs to `tests/support/wp-stubs.php`.** Task 1 owns that area and has already merged. Design so that every unit-tested method is WordPress-free; put unavoidable WordPress calls in separate methods covered by the `integration` suite (the `classify()` / `run()` split in `src/Health/DatabaseOverrideCheck.php` is the house pattern).
- A required test environment is a release-gate dependency. When it is absent, make the test fail with a direct assertion or stop the task with evidence. Do not use a PHPUnit skip for a required case.

**File-ownership rules for this task (spec §15 single-owner rule):**

- You own `web/app/mu-plugins/agency-platform/src/State/**` (export/diff half only), `src/Cli/StateCommands.php`, `resources/schemas/state-bundle-v1.json`, `composer.json`'s `require` block, `.gitignore`, `scripts/check-database-overrides`, and your own test files under `tests/Unit/AgencyPlatform/State/` and `tests/Integration/State/`.
- **Coordinator ownership transfers (granted for this task):**
  - `.env.example` — **Task 1 of this plan is the sole owner of every `AGENCY_*` state entry, including the two HMAC settings.** Task 1 Step 4 already adds `AGENCY_PROMOTION_HMAC_KEYS` and `AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID`, and no later task in this plan lists or stages `.env.example`. (Orchestrator correction, 2026-08-05: this line previously said Task 3 appends its own entries later. Task 3's file list is `HmacSigner.php` plus its unit test, and its Step 5 `git add` stages neither `.env.example` nor anything else. Two owners for one file is exactly the §15 violation this section exists to prevent.)
  - `web/app/mu-plugins/agency-platform/src/Health/SanitizeSteps.php` — you own the docblock line that names `DatabaseOverrideCheck`.
  - `tests/Integration/Environment/EnvironmentSafetyTest.php` — you own the `DatabaseOverrideCheck` import, `@covers` tag, docblock sentence, and the `test_database_override_check_reports_a_clean_baseline_on_a_fresh_install()` method.
  Both of the last two are edited in the **same rebase-gated commit** as the class removal (Task 13). No compatibility adapter is kept.
- You **must not** touch: any file under `web/app/themes/site-theme/`, `src/Roles/**`, `src/Editor/**`, `src/Security/**`, `tests/Architecture/**`, `site-commerce`, `scripts/promote-overrides`, `src/Cli/PromotionCommands.php`, `resources/schemas/promotion-manifest-v1.json`, or any file under `docs/` or `ops/` other than this plan.
- The only other exceptions are **Task 13**, which is rebase-gated and touches `src/Plugin.php`, `src/Cli/AgencyCommands.php`, and `src/Health/DatabaseOverrideCheck.php` after Task 1 has merged. Do not touch those three files in Tasks 1–12.
- **`Plugin.php` gets exactly ONE new line** — a fully qualified `new \AgencyPlatform\State\StateSubsystem(),` array entry. No `use` import. The fixed cross-task boundary permits one registration line and nothing else.

**Branch model:** work on `feat/bt-task-2-state-export-diff`, branched from `feat/block-theme-fse-migration` after Release 1 has merged. The orchestrator creates the worktree and gives this task exclusive DDEV ownership. The integration branch must not change during this unit. Task 13 verifies ancestry before its shared-file wiring commit and never rebases inside the worker task.

---

## File Structure

New production files (all under `web/app/mu-plugins/agency-platform/`):

| Path | Responsibility |
|---|---|
| `src/State/StateException.php` | The one exception type; carries the CLI exit code. |
| `src/State/EnvironmentConfig.php` | Reads `AGENCY_*` settings from a PHP constant first, then `getenv()`. |
| `src/State/Normalizer.php` | Canonical JSON, recursive key sorting, SHA-256 hashing, line-ending and block-markup normalisation. |
| `src/State/HmacSigner.php` | Keyring-backed HMAC sign/verify with per-artifact purpose prefixes. |
| `src/State/SchemaValidator.php` | JSON-Schema validation of bundles (and, later, Task 3's manifests). |
| `src/State/Ownership.php` | §5.4 "default owner" string constants. |
| `src/State/PromotionPolicy.php` | §5.4 "promotion policy" string constants. |
| `src/State/DriftClassification.php` | §11.10 drift classification constants. |
| `src/State/StateRecord.php` | Immutable per-record value object + bundle serialisation. |
| `src/State/StateProvider.php` | The full §7.1 provider contract (interface). |
| `src/State/PromotionStrategy.php` | The §7.1 prepare/finalise/restore delegation interface. Defined here, implemented by the promotion track. |
| `src/State/PromotionStrategies.php` | Slug-keyed strategy registry + `agency_platform_promotion_strategies` filter. Ships empty. |
| `src/State/BaseStateProvider.php` | Shared provider defaults so each concrete provider stays small and `final`. |
| `src/State/ReferenceResolver.php` | Fills a reference's `targetKey` / `targetHash` / `targetIdentity`. |
| `src/State/StateCommandResult.php` | Captured exit code + STDOUT + STDERR of one command run. |
| `src/State/StateCommandRunner.php` | Every command body, free of WP-CLI, so exit codes and streams are testable. |
| `src/State/StateRegistry.php` | Built-in provider map, `agency_platform_state_providers` filter, slug resolution. |
| `src/State/ReferenceScanner.php` | §7.4 block-markup reference detection and unresolved-reference filtering. |
| `src/State/GitBaseline.php` | Repo-root/theme-dir resolution, HEAD commit, `templates/*.html` + `parts/*.html` + `theme.json` reads. |
| `src/State/SiteIdentity.php` | `agency_platform_site_uuid` option + its environment marker. |
| `src/State/StateDirectory.php` | `AGENCY_STATE_DIR` resolution and creation. |
| `src/State/StateExporter.php` | Bundle assembly, `stateHash`, signing, schema validation. |
| `src/State/StateBundle.php` | The only sanctioned bundle reader; refuses records before signature verification. |
| `src/State/StateDiffer.php` | Two-mode diff + pure classification. |
| `src/State/CliOutput.php` | STDOUT = machine-readable only; diagnostics to STDERR; exit-code halting. |
| `src/State/SiteUuidSanitizeStep.php` | Idempotent site-UUID regeneration registered on `agency_platform_sanitize_steps`. |
| `src/State/StateSubsystem.php` | The single provider class `Plugin::boot()` registers (one line). |
| `src/State/Providers/TemplatesState.php` | `wp_template` provider. |
| `src/State/Providers/TemplatePartsState.php` | `wp_template_part` provider. |
| `src/State/Providers/GlobalStylesState.php` | `wp_global_styles` user-origin provider. |
| `src/State/Providers/NavigationState.php` | `wp_navigation` provider. |
| `src/State/Providers/SyncedPatternsState.php` | `wp_block` provider. |
| `src/State/Providers/ContentState.php` | Page/post/CPT provider (`--include-content` only). |
| `src/State/Providers/FontLibraryState.php` | `wp_font_family` / `wp_font_face` provider. |
| `src/State/Providers/MediaReferencesState.php` | Attachment metadata for referenced media. |
| `src/State/Providers/CustomCssState.php` | Additional CSS in Global Styles **and** the `custom_css` post type. |
| `src/Cli/StateCommands.php` | `wp agency state-export` / `wp agency state-diff` registrar and handlers. |
| `resources/schemas/state-bundle-v1.json` | Bundle JSON Schema (draft-07). |

New test files:

| Path | Suite |
|---|---|
| `tests/Unit/AgencyPlatform/State/NormalizerTest.php` | unit |
| `tests/Unit/AgencyPlatform/State/HmacSignerTest.php` | unit |
| `tests/Unit/AgencyPlatform/State/SchemaValidatorTest.php` | unit |
| `tests/Unit/AgencyPlatform/State/StateRecordTest.php` | unit |
| `tests/Unit/AgencyPlatform/State/ReferenceScannerTest.php` | unit |
| `tests/Unit/AgencyPlatform/State/StateRegistryResolveTest.php` | unit |
| `tests/Unit/AgencyPlatform/State/StateDifferCompareTest.php` | unit |
| `tests/Unit/AgencyPlatform/State/StateBundleTest.php` | unit |
| `tests/Unit/AgencyPlatform/State/SiteUuidDecisionTest.php` | unit |
| `tests/Unit/AgencyPlatform/State/GitBaselineRefsTest.php` | unit |
| `tests/Unit/AgencyPlatform/State/PromotionStrategiesTest.php` | unit |
| `tests/Unit/AgencyPlatform/State/StateCommandsContractTest.php` | unit |
| `tests/Integration/State/StateCommandRunnerTest.php` | integration |
| `tests/Integration/State/ReferenceResolverTest.php` | integration |
| `tests/Integration/State/StateExportTest.php` | integration |
| `tests/Integration/State/ExportSensitiveDataTest.php` | integration |
| `tests/Integration/State/ProviderRecordsTest.php` | integration |
| `tests/Integration/State/StateDiffGitModeTest.php` | integration |
| `tests/Integration/State/StateDiffBundleModeTest.php` | integration |
| `tests/Integration/State/SiteUuidSanitizeTest.php` | integration |
| `tests/Integration/State/CheckOverridesAliasTest.php` | integration |
| `tests/Integration/State/SeedsStateFixtures.php` | integration (shared fixture trait) |
| `tests/Unit/AgencyPlatform/State/Doubles/FakeStateProvider.php` | unit (test double) |

Modified files: `composer.json` (add `swaggest/json-schema` to `require`), `.gitignore`, `.env.example`, and — **Task 13 only, rebase-gated** — `src/Plugin.php`, `src/Cli/AgencyCommands.php`, `src/Health/SanitizeSteps.php`, `tests/Integration/Environment/EnvironmentSafetyTest.php`, `scripts/check-database-overrides`, plus deletion of `src/Health/DatabaseOverrideCheck.php` and `tests/Unit/AgencyPlatform/DatabaseOverrideClassifyTest.php`.

---

## Decisions this plan fixes (the spec leaves them open)

Read these before starting. They are binding; do not re-litigate them mid-implementation.

1. **Record key format** is `<provider-slug>:<record-slug>`, matching §6's `--select=templates:page` selector syntax exactly. `global-styles` has exactly one record whose slug is `active` (so `global-styles:active` resolves, per §6).
2. **`stateHash`** = `hash( 'sha256', Normalizer::canonical_json( StateExporter::canonical_provider_records( $bundle['providers'] ) ) )`. `canonical_provider_records()` returns exactly `array<string, list<array<string, mixed>>>`: an ascending provider-slug map whose value is that provider's records in ascending record-key order, with each value exactly `StateRecord::to_array()` after normalisation. It includes no provider wrapper fields (`slug`, `ownership`, `promotion`, `hasGitBaseline`) and no bundle wrapper fields. Thus `exportId`, `exportedAtUtc`, `siteUuid`, `siteUrl`, `environment`, `wordpressVersion`, all `activeTheme` fields, `hmac`, `hmacKeyId`, and `stateHash` itself are excluded. The provider slug and every record field are included; a changed record changes the hash.
3. **Templates and template parts export markup only** (`content = array('markup' => …)`). Title, description, and area are deliberately NOT exported: core supplies template titles for hierarchy slugs and `theme.json` supplies them for declared custom slugs, so including them would produce permanent false drift against the Git baseline. Known v1 limitation: a client renaming a template is not captured.
4. **Block markup normalisation is `parse_blocks()` → `serialize_blocks()` → trim**, applied identically to DB content and Git baseline files, so cosmetic whitespace never registers as drift. It is a WordPress-coupled method; only the `integration` suite exercises it.
5. **Global Styles has a synthetic empty baseline.** `GlobalStylesState::baseline_records()` returns one record with `content = array()`. An uncustomised user-origin row normalises to `array()` too, because **both** bookkeeping keys (`version` **and** `isGlobalStylesUserThemeJSON`) are stripped, and **every `css` key is stripped as well** — Additional CSS belongs to the `custom-css` provider, and leaving it here would classify the same bytes as both `promotable` and `forbidden`. A genuinely customised user origin therefore reads as `changed` → drift, classified `promotable`. This reproduces today's `DatabaseOverrideCheck::global_styles_is_customized()` semantics through the generic differ.
6. **Custom CSS is a Git-baselined provider whose baseline is "no CSS".** `CustomCssState::has_git_baseline()` returns `true` and `baseline_records()` returns both records with `css => ''`. Git owns the theme's stylesheets and client roles cannot author CSS, so "no Additional CSS" *is* the Git baseline. Empty CSS is therefore `unchanged` (no drift) and any non-empty CSS is `changed` → drift, classified `forbidden` — with **no special case in `counts_as_drift`**. Both records (`custom-css:global-styles`, `custom-css:custom-css-post`) are always emitted so the record set stays deterministic.
7. **`state-diff` supports `--providers` and `--include-content` too** (§6 only shows them on `state-export`; a diff that cannot be narrowed the same way is unusable). In bundle mode the compared set is intersected with the bundle's own provider list; a requested provider missing from the bundle is a STDERR warning and is skipped, never drift.
8. **`state-diff --source` hard-fails (exit 1) when the bundle's `siteUuid` differs from the local site UUID.** "Drift since export" is meaningless across sites. There is no override flag in v1.
9. **Exit codes.** `state-export`: `0` success, `1` any hard error (all-or-nothing; there is no partial export). `state-diff`: `0` no drift, `2` drift, `1` hard error, `4` bundle tamper/signature failure. `check-overrides`: `0` always unless `--fail-on-drift` is passed, in which case drift exits `1` (`WP_CLI::error()`) — deliberately **not** `2`, so the deprecated alias keeps the exit contract Task 1 shipped and no Task-1 test needs editing.
10. **STDOUT discipline.** `state-export` writes only JSON to STDOUT (the bundle itself with `--output=-`, otherwise a small result envelope `{"output":…,"exportId":…,"stateHash":…,"providers":{…counts…}}`). `state-diff --format=json` writes only the report JSON. `state-diff --format=table` writes the human table (that is the explicitly requested format). Everything else — warnings, the §7.3 sensitivity notice, progress — goes to STDERR via `WP_CLI::warning()`.
11. **`--output` is required** on `state-export`, as the master command surface specifies. It accepts a filesystem path or `-` for bundle JSON on STDOUT. There is no implicit output path and no default write into `AGENCY_STATE_DIR`; operators must choose the destination deliberately.
12. **Site-UUID regeneration is marker-based only.** A `--regenerate-site-uuid` flag would require editing `AgencyCommands.php`'s WP-CLI synopsis, which this task may not do. A forced regeneration is `wp option delete agency_platform_site_uuid` followed by any command that reads the UUID.
13. **HMAC keys shorter than 32 characters are rejected** with a hard error. A weak shared secret defeats the whole tamper-detection design.
14. **`StateBundle` refuses to return records until `verify_signature()` has succeeded** (spec §9.5: "verify bundle HMAC before reading"). It also recomputes `stateHash` on load and treats a mismatch as tamper (exit 4).
15. **Media references are scanned from a fixed source set**: templates, template parts, navigation, synced patterns, and the `site_logo` option. Media referenced only from page/post content is out of scope in v1 (documented limitation) so the provider stays independent of `--include-content`.
16. **`AGENCY_*` settings are read constant-first, then `getenv()`.** Bedrock's dotenv loader registers a `PutenvAdapter`, and `config/environments/*.php` defines `AGENCY_*` as constants — both paths must work.
17. **No `docs/` or `ops/` file is edited by this task.** The wording changes §11.10 requires are enumerated in Task 13 Step 8 and handed to Task 4's documentation sweep.
18. **Git-mode drift compares the EFFECTIVE current state, not raw database rows.** Spec §5.3: "Runtime truth is the active Git baseline plus current database state." So `diff_against_git()` first *overlays* database records on top of the Git baseline records — a slug with a Git file and no database override contributes the **Git baseline record** to the current side, and therefore reads `unchanged`. Without this overlay, a clean block theme (every template in Git, no override in the database) would report every template as `removed` and exit `2`, which is exactly backwards. The **bundle** still contains database records only, because those are what Task 3 promotes.
19. **`counts_as_drift` has no per-provider special cases.** `unchanged` → `false`; Git mode → the provider's `hasGitBaseline`; bundle mode → `true`. Decision 6 removed the last special case that used to exist.
20. **Canonical order is ascending slug order everywhere** — `StateRegistry::providers()` `ksort`s, `resolve()` returns sorted slugs, `STRUCTURAL_SLUGS` is declared in sorted order, the bundle's `providers` map is sorted, and every records/entries list is sorted by key with `strcmp`. The §6 listing order in the master spec is informational prose, not the wire order.
21. **Numbers are canonicalised before hashing.** `JSON_PRESERVE_ZERO_FRACTION` is deliberately **not** used: it makes `1` and `1.0` hash differently for values WordPress treats as equal. Instead every float with an integral value becomes an int, `NAN`/`INF` are rejected outright, and `canonical_json()` pins `serialize_precision` to `-1` (shortest round-trip) for the duration of the encode so a host's `php.ini` cannot change a hash.
22. **Line-ending normalisation applies to every string leaf of every record's content**, not only to block markup. CSS, titles, and `theme.json`-shaped settings all pass through `Normalizer::normalize_content()`, so a CRLF in a title can never masquerade as drift.
23. **Every bundle byte written to a file or to STDOUT is `Normalizer::canonical_json()` output plus exactly one trailing LF.** No `JSON_PRETTY_PRINT`, no platform-dependent line ending.
24. **References carry their resolved target** (`targetKey`, `targetHash`, `targetIdentity`) as well as the raw attribute value. §7.4 requires the referenced ID, and §7.4's v1 navigation policy requires the exported navigation's normalised content hash at finalisation time — which Task 3 cannot reconstruct if the caller exported `--providers=templates` only.

---

### Task 1: Dependency, output directory, environment access, and the exception type

**Files:**
- Modify: `composer.json` (the `require` block, lines 23–32)
- Modify: `.gitignore`
- Modify: `.env.example`
- Create: `web/app/mu-plugins/agency-platform/src/State/StateException.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/EnvironmentConfig.php`
- Test: `tests/Unit/AgencyPlatform/State/EnvironmentConfigTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `AgencyPlatform\State\StateException extends \RuntimeException` with `__construct(string $message, int $exit_code = 1, ?\Throwable $previous = null)`, `exit_code(): int`, and static factories `hard_error(string $message): self` (exit 1) and `tamper(string $message): self` (exit 4).
  - `AgencyPlatform\State\EnvironmentConfig::get(string $name): ?string`, `::has(string $name): bool`, `::resolve(array<string,string> $source, string $name): ?string` (pure).

- [ ] **Step 1: Add the runtime dependency**

Run inside DDEV so the lock file is generated against the project platform (`php 8.3.0`):

```bash
ddev composer require swaggest/json-schema:^0.12
```

This must land in `require`, not `require-dev` — schema validation runs on the production WordPress host during Task 3's finalisation (spec §6).

- [ ] **Step 2: Verify the dependency did not break the quality gate**

```bash
ddev composer validate --strict
ddev composer audit --abandoned=report
ddev composer deptrac
```

Expected: all three pass. `deptrac.yaml` declares `AgencyPlatform: []`, but vendor paths are excluded from its `paths` list, so `Swaggest\*` classes are *uncovered*, not *violating*, and `deptrac analyse` (run without `--fail-on-uncovered`) exits 0. If deptrac does fail on the new vendor dependency, stop and report it rather than editing `deptrac.yaml` — that file is not owned by this task.

- [ ] **Step 3: Ignore the state output directory**

Append to `.gitignore`, after the "Testing" block:

```gitignore
# ------------------------------------------------------------------
# Agency state subsystem (BLOCK_THEME_PROPOSAL.md §7.3): exported state
# bundles and, later, promotion manifests and backup payloads. These can
# contain customer content and internal site structure, so the default
# AGENCY_STATE_DIR must never be committed. var/agency-state/ sits
# outside the web root (web/), so nothing here is reachable over HTTP.
# ------------------------------------------------------------------
/var/agency-state/
```

The rule is scoped to `var/agency-state/`, not all of `/var/`: §7.3 reserves only this path, and the repository does not otherwise claim `var/`. If a project points `AGENCY_STATE_DIR` somewhere else, that path is the project's own responsibility to ignore — `state-export` warns about it on every run (`StateExporter::sensitivity_warning()`).

- [ ] **Step 4: Document the new environment variables**

Append to `.env.example`:

```dotenv
# ------------------------------------------------------------------
# State subsystem (wp agency state-export / state-diff).
# ------------------------------------------------------------------
# Where promotion manifests and backup payloads are written. Relative paths
# resolve against the repository root. State export requires an explicit
# --output path; use var/agency-state/<file>.json when this directory is wanted.
# AGENCY_STATE_DIR=var/agency-state
#
# Repository root, when WP-CLI does not run from it (DDEV, CI).
# Default: autodetected upwards from ABSPATH.
# AGENCY_REPO_ROOT=/var/www/html
#
# HMAC keyring shared between the machine that exports/prepares and the
# production host that finalises. WordPress salts differ per environment
# and are NEVER used as a fallback — a missing keyring is a hard failure.
# Every key must be at least 32 characters. Rotate by adding a new key id,
# advancing AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID, and keeping the old key
# until outstanding bundles/manifests expire.
# AGENCY_PROMOTION_HMAC_KEYS='{"2026-01":"replace-with-32+-random-characters"}'
# AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID=2026-01
```

- [ ] **Step 5: Write the failing test for `EnvironmentConfig`**

Create `tests/Unit/AgencyPlatform/State/EnvironmentConfigTest.php`:

```php
<?php
/**
 * EnvironmentConfig must read a PHP constant first and fall back to the
 * process environment, because this repository defines AGENCY_* settings
 * both ways: config/environments/*.php uses Config::define() (constants),
 * while Bedrock's dotenv loader registers a PutenvAdapter (getenv()).
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\EnvironmentConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\EnvironmentConfig
 */
final class EnvironmentConfigTest extends TestCase {

	public function test_resolve_prefers_the_constant_over_the_environment(): void {
		$resolved = EnvironmentConfig::resolve(
			array(
				'constant'    => 'from-constant',
				'environment' => 'from-environment',
			),
			'AGENCY_STATE_DIR'
		);

		self::assertSame( 'from-constant', $resolved );
	}

	public function test_resolve_falls_back_to_the_environment(): void {
		$resolved = EnvironmentConfig::resolve(
			array( 'environment' => 'from-environment' ),
			'AGENCY_STATE_DIR'
		);

		self::assertSame( 'from-environment', $resolved );
	}

	public function test_resolve_returns_null_when_neither_source_has_a_value(): void {
		self::assertNull( EnvironmentConfig::resolve( array(), 'AGENCY_STATE_DIR' ) );
	}

	public function test_resolve_treats_an_empty_string_as_absent(): void {
		self::assertNull(
			EnvironmentConfig::resolve(
				array(
					'constant'    => '',
					'environment' => '',
				),
				'AGENCY_STATE_DIR'
			)
		);
	}

	public function test_get_reads_a_real_defined_constant(): void {
		define( 'AGENCY_TEST_ONLY_STATE_SETTING', 'defined-value' );

		self::assertSame( 'defined-value', EnvironmentConfig::get( 'AGENCY_TEST_ONLY_STATE_SETTING' ) );
		self::assertTrue( EnvironmentConfig::has( 'AGENCY_TEST_ONLY_STATE_SETTING' ) );
	}

	public function test_get_returns_null_for_an_unknown_setting(): void {
		self::assertNull( EnvironmentConfig::get( 'AGENCY_TEST_ONLY_MISSING_SETTING' ) );
		self::assertFalse( EnvironmentConfig::has( 'AGENCY_TEST_ONLY_MISSING_SETTING' ) );
	}
}
```

- [ ] **Step 6: Run it and watch it fail**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter EnvironmentConfigTest
```

Expected: FAIL — `Class "AgencyPlatform\State\EnvironmentConfig" not found`.

- [ ] **Step 7: Write `StateException`**

Create `web/app/mu-plugins/agency-platform/src/State/StateException.php`:

```php
<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The single failure type of the state subsystem. It carries the WP-CLI
 * exit code the command surface must produce (BLOCK_THEME_PROPOSAL.md §6):
 * 1 hard error, 2 partial success/drift, 3 lock conflict, 4 tamper. Task 3
 * reuses this type for the promotion lifecycle, so the code is a
 * constructor argument rather than a fixed per-subclass value.
 */
final class StateException extends \RuntimeException {

	public const EXIT_HARD_ERROR = 1;
	public const EXIT_DRIFT      = 2;
	public const EXIT_LOCKED     = 3;
	public const EXIT_TAMPER     = 4;

	private int $exit_code;

	public function __construct( string $message, int $exit_code = self::EXIT_HARD_ERROR, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );

		$this->exit_code = $exit_code;
	}

	public static function hard_error( string $message, ?\Throwable $previous = null ): self {
		return new self( $message, self::EXIT_HARD_ERROR, $previous );
	}

	public static function tamper( string $message, ?\Throwable $previous = null ): self {
		return new self( $message, self::EXIT_TAMPER, $previous );
	}

	public function exit_code(): int {
		return $this->exit_code;
	}
}
```

- [ ] **Step 8: Write `EnvironmentConfig`**

Create `web/app/mu-plugins/agency-platform/src/State/EnvironmentConfig.php`:

```php
<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * Reads an AGENCY_* setting from a PHP constant first, then the process
 * environment. Both matter in this repository: config/environments/*.php
 * declares AGENCY_* through Roots\WPConfig\Config::define() (constants),
 * while Bedrock's dotenv loader also registers a PutenvAdapter, so a value
 * placed in .env is visible to getenv(). A blank value counts as absent in
 * both sources, so an empty .env line never masks a constant.
 *
 * resolve() is the pure decision this class exists to make; get()/has()
 * only gather the two candidate values. That split keeps the precedence
 * rule unit-testable without defining constants or mutating the process
 * environment.
 */
final class EnvironmentConfig {

	private function __construct() {
		// Static-only utility class; never instantiated.
	}

	public static function get( string $name ): ?string {
		$source = array();

		if ( defined( $name ) ) {
			$value = constant( $name );

			if ( is_scalar( $value ) ) {
				$source['constant'] = (string) $value;
			}
		}

		$from_environment = getenv( $name );

		if ( is_string( $from_environment ) ) {
			$source['environment'] = $from_environment;
		}

		return self::resolve( $source, $name );
	}

	public static function has( string $name ): bool {
		return null !== self::get( $name );
	}

	/**
	 * Pure: picks the winning value from the two candidate sources.
	 *
	 * @param array<string, string> $source Keys 'constant' and/or 'environment'.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $name is part of the documented signature so callers and failure messages can name the setting; the precedence rule itself does not depend on it.
	public static function resolve( array $source, string $name ): ?string {
		foreach ( array( 'constant', 'environment' ) as $key ) {
			$value = $source[ $key ] ?? '';

			if ( '' !== trim( $value ) ) {
				return $value;
			}
		}

		return null;
	}
}
```

- [ ] **Step 9: Run the unit test to green**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter EnvironmentConfigTest
```

Expected: PASS (6 tests).


- [ ] **Step 10: Confirm `StateDirectory` is reserved for Task 7**

```bash
ddev exec bash -c 'if [ -e web/app/mu-plugins/agency-platform/src/State/StateDirectory.php ]; then echo "StateDirectory.php is reserved for Task 7." >&2; exit 1; fi'
```

Expected: exit 0. Task 7 creates `StateDirectory.php` after `GitBaseline.php` exists. This task never creates and deletes the same production file.

- [ ] **Step 11: Run the full fast gate**

```bash
ddev composer verify:fast
```

Expected: PASS.

- [ ] **Step 12: Commit**

```bash
git add composer.json composer.lock .gitignore .env.example \
  web/app/mu-plugins/agency-platform/src/State/StateException.php \
  web/app/mu-plugins/agency-platform/src/State/EnvironmentConfig.php \
  tests/Unit/AgencyPlatform/State/EnvironmentConfigTest.php
git commit -m "feat(state): add the state subsystem foundation and schema dependency"
```

---

### Task 2: Deterministic normalisation and hashing

**Files:**
- Create: `web/app/mu-plugins/agency-platform/src/State/Normalizer.php`
- Test: `tests/Unit/AgencyPlatform/State/NormalizerTest.php`

**Interfaces:**
- Consumes: `StateException` (Task 1).
- Produces (all `public static`, all pure except `normalize_block_markup()`):
  - `Normalizer::sort_recursive(array $data): array` — recursively `ksort`s associative arrays (`SORT_STRING`); leaves list arrays in order.
  - `Normalizer::normalize_scalars(array $data): array` — recursively canonicalises leaf values: every string through `normalize_line_endings()`; every float whose value is integral **and** finite to an `int`; every non-finite float (`NAN`, `INF`, `-INF`) throws `StateException::hard_error()`; `-0.0` becomes `0`; `bool` and `null` pass through untouched. Objects are rejected (`StateException`) — records carry arrays and scalars only.
  - `Normalizer::normalize_content(array $content): array` — the one call every provider uses to build a record's `content`: `normalize_scalars()` then `sort_recursive()`.
  - `Normalizer::canonical_json(array $data): string` — `normalize_content()` then `json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`, executed with `serialize_precision` pinned to `-1` and restored afterwards. **`JSON_PRESERVE_ZERO_FRACTION` is NOT used** (decision 21). Throws `StateException::hard_error()` on encode failure.
  - `Normalizer::canonical_json_document(array $data): string` — `canonical_json()` plus exactly one trailing `"\n"`. This is the only thing ever written to a bundle file or to STDOUT (decision 23).
  - `Normalizer::hash(array $data): string` — lowercase 64-char SHA-256 hex of `canonical_json()`.
  - `Normalizer::hash_string(string $value): string` — lowercase 64-char SHA-256 hex of an already-normalised string. Task 3 hashes normalised block markup directly with it.
  - `Normalizer::normalize_line_endings(string $text): string` — CR and CRLF → LF, trailing spaces/tabs stripped per line, whole string right-trimmed.
  - `Normalizer::prune_empty(array $data): array` — drops `null`, `''`, and empty-array leaves recursively; a branch whose leaves are all empty is dropped too. Keeps `0`, `0.0`, `false`, and `'0'`.
  - `Normalizer::normalize_block_markup(string $markup): string` — **WordPress-coupled**: `normalize_line_endings()` → `parse_blocks()` → `serialize_blocks()` → `normalize_line_endings()`. Must be idempotent (normalising twice equals normalising once) — Task 3 normalises a resolved template a second time at finalisation. Integration-tested only.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/AgencyPlatform/State/NormalizerTest.php`:

```php
<?php
/**
 * The determinism contract behind stateHash (BLOCK_THEME_PROPOSAL.md §7.2):
 * repeated exports of unchanged state must produce byte-identical canonical
 * JSON, and therefore identical hashes, no matter what order the source
 * arrays were built in.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\StateException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\Normalizer
 */
final class NormalizerTest extends TestCase {

	public function test_canonical_json_sorts_map_keys_at_every_depth(): void {
		$json = Normalizer::canonical_json(
			array(
				'zebra' => array(
					'gamma' => 1,
					'alpha' => 2,
				),
				'apple' => 3,
			)
		);

		self::assertSame( '{"apple":3,"zebra":{"alpha":2,"gamma":1}}', $json );
	}

	public function test_canonical_json_preserves_list_order(): void {
		$json = Normalizer::canonical_json( array( 'items' => array( 'c', 'a', 'b' ) ) );

		self::assertSame( '{"items":["c","a","b"]}', $json );
	}

	public function test_canonical_json_does_not_escape_slashes_or_unicode(): void {
		$json = Normalizer::canonical_json( array( 'url' => 'https://example.test/a/b', 'text' => 'café' ) );

		self::assertStringContainsString( 'https://example.test/a/b', $json );
		self::assertStringContainsString( 'café', $json );
	}

	public function test_an_integral_float_and_the_same_integer_canonicalise_identically(): void {
		self::assertSame(
			Normalizer::canonical_json( array( 'lineHeight' => 1 ) ),
			Normalizer::canonical_json( array( 'lineHeight' => 1.0 ) ),
			'1 and 1.0 are the same value to WordPress; they must not produce two different hashes.'
		);
		self::assertSame( '{"lineHeight":1}', Normalizer::canonical_json( array( 'lineHeight' => 1.0 ) ) );
	}

	public function test_negative_zero_canonicalises_to_zero(): void {
		self::assertSame( '{"offset":0}', Normalizer::canonical_json( array( 'offset' => -0.0 ) ) );
	}

	public function test_a_genuine_fraction_survives(): void {
		self::assertSame( '{"lineHeight":1.5}', Normalizer::canonical_json( array( 'lineHeight' => 1.5 ) ) );
	}

	public function test_a_non_finite_float_is_rejected(): void {
		$this->expectException( StateException::class );

		Normalizer::canonical_json( array( 'ratio' => INF ) );
	}

	public function test_nan_is_rejected(): void {
		$this->expectException( StateException::class );

		Normalizer::canonical_json( array( 'ratio' => NAN ) );
	}

	public function test_float_encoding_does_not_depend_on_serialize_precision(): void {
		$previous = ini_get( 'serialize_precision' );
		ini_set( 'serialize_precision', '3' );

		try {
			self::assertSame( '{"size":0.1234567}', Normalizer::canonical_json( array( 'size' => 0.1234567 ) ) );
		} finally {
			ini_set( 'serialize_precision', (string) $previous );
		}
	}

	public function test_normalize_content_normalises_line_endings_in_every_string_leaf(): void {
		$normalized = Normalizer::normalize_content(
			array(
				'title' => "Home\r\n",
				'css'   => "body {\r\n  color: red;\r\n}",
				'nested' => array( 'label' => "One\rTwo" ),
			)
		);

		self::assertSame( 'Home', $normalized['title'] );
		self::assertSame( "body {\n  color: red;\n}", $normalized['css'] );
		self::assertSame( "One\nTwo", $normalized['nested']['label'] );
	}

	public function test_canonical_json_document_ends_with_exactly_one_lf(): void {
		$document = Normalizer::canonical_json_document( array( 'a' => 1 ) );

		self::assertSame( "{\"a\":1}\n", $document );
	}

	public function test_hash_is_independent_of_input_key_order(): void {
		$first  = Normalizer::hash( array( 'b' => 2, 'a' => 1 ) );
		$second = Normalizer::hash( array( 'a' => 1, 'b' => 2 ) );

		self::assertSame( $first, $second );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first );
	}

	public function test_hash_string_hashes_an_already_normalised_string_directly(): void {
		$markup = '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->';

		self::assertSame( hash( 'sha256', $markup ), Normalizer::hash_string( $markup ) );
	}

	public function test_hash_changes_when_a_value_changes(): void {
		self::assertNotSame(
			Normalizer::hash( array( 'markup' => '<!-- wp:paragraph /-->' ) ),
			Normalizer::hash( array( 'markup' => '<!-- wp:heading /-->' ) )
		);
	}

	public function test_normalize_line_endings_collapses_crlf_and_trims(): void {
		self::assertSame(
			"one\ntwo",
			Normalizer::normalize_line_endings( "one\r\ntwo\r\n  \n" )
		);
	}

	public function test_prune_empty_drops_empty_leaves_and_empty_branches(): void {
		$pruned = Normalizer::prune_empty(
			array(
				'keep'       => 'value',
				'blank'      => '',
				'nothing'    => null,
				'emptyList'  => array(),
				'emptyTree'  => array( 'typography' => array( 'fontSize' => '' ) ),
				'mixedTree'  => array( 'typography' => array( 'fontSize' => '2rem', 'lineHeight' => '' ) ),
				'zeroStays'  => 0,
				'falseStays' => false,
			)
		);

		self::assertSame(
			array(
				'keep'       => 'value',
				'mixedTree'  => array( 'typography' => array( 'fontSize' => '2rem' ) ),
				'zeroStays'  => 0,
				'falseStays' => false,
			),
			$pruned
		);
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter NormalizerTest
```

Expected: FAIL — `Class "AgencyPlatform\State\Normalizer" not found`.

- [ ] **Step 3: Write `Normalizer`**

Create `web/app/mu-plugins/agency-platform/src/State/Normalizer.php`. Implement exactly the ten methods from the Interfaces block. Key implementation notes:

- `sort_recursive()` must use `array_is_list()` to decide between `ksort( $data, SORT_STRING )` (map) and leaving order alone (list), and must recurse into both.
- `canonical_json()` must use `wp_json_encode()`? **No** — use `json_encode()` with a `// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode` comment explaining that canonicalisation must not depend on WordPress being loaded (the `unit` suite covers this method). Wrap the encode so a host's `php.ini` cannot change a hash, and always restore the previous value:

```php
	public static function canonical_json( array $data ): string {
		$previous = ini_get( 'serialize_precision' );
		ini_set( 'serialize_precision', '-1' );

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- canonicalisation must not depend on WordPress being loaded; the unit suite covers this method with no WordPress present.
			return json_encode(
				self::normalize_content( $data ),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
			);
		} catch ( \JsonException $error ) {
			throw StateException::hard_error( 'The payload could not be canonicalised as JSON: ' . $error->getMessage(), $error );
		} finally {
			ini_set( 'serialize_precision', false === $previous ? '-1' : $previous );
		}
	}
```

- `normalize_scalars()` rejects non-finite floats and objects, folds integral finite floats to `int` (`(float) (int) $value === $value && is_finite( $value )`), and maps `-0.0` to `0`. Throw `StateException::hard_error()` naming the offending key path.
- `hash()` = `hash( 'sha256', self::canonical_json( $data ) )`; `hash_string()` = `hash( 'sha256', $value )`.
- `normalize_line_endings()` collapses CR/CRLF to LF, strips trailing spaces and tabs from every line, then trims the whole string:

```php
	public static function normalize_line_endings( string $text ): string {
		$text = (string) preg_replace( '/\r\n|\r/', "\n", $text );
		$text = (string) preg_replace( '/[ \t]+\n/', "\n", $text );

		return rtrim( $text );
	}
```
- `prune_empty()` keeps `0`, `0.0`, `false`, and `'0'`; drops `null`, `''`, whitespace-only strings, `array()`, and any array that prunes down to `array()`.
- `normalize_block_markup()` — the only WordPress-coupled method:

```php
	/**
	 * WordPress-coupled: canonicalises block markup by parsing and
	 * re-serialising it, so cosmetic whitespace, attribute spacing, and line
	 * endings can never register as drift. Applied identically to database
	 * content and to the Git baseline file, so both sides of every comparison
	 * pass through the same serialiser.
	 *
	 * Covered by the integration suite only — parse_blocks()/serialize_blocks()
	 * need a real WordPress install, and this repository deliberately keeps
	 * tests/support/wp-stubs.php from growing into a shadow WordPress.
	 */
	public static function normalize_block_markup( string $markup ): string {
		return self::normalize_line_endings( serialize_blocks( parse_blocks( self::normalize_line_endings( $markup ) ) ) );
	}
```

- [ ] **Step 4: Run the test to green**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter NormalizerTest
```

Expected: PASS (16 tests).

- [ ] **Step 5: Run the fast gate and commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Normalizer.php tests/Unit/AgencyPlatform/State/NormalizerTest.php
git commit -m "feat(state): add deterministic normalisation and canonical hashing"
```

---

### Task 3: HMAC keyring signer

**Files:**
- Create: `web/app/mu-plugins/agency-platform/src/State/HmacSigner.php`
- Test: `tests/Unit/AgencyPlatform/State/HmacSignerTest.php`

**Interfaces:**
- Consumes: `Normalizer::canonical_json()` (Task 2), `StateException` + `EnvironmentConfig` (Task 1).
- Produces — **this signature is a fixed cross-task contract; Task 3 of the migration reuses it verbatim for promotion manifests:**

```php
final class HmacSigner {
	public const PURPOSE_BUNDLE   = 'state-bundle-v1:';
	public const PURPOSE_MANIFEST = 'promotion-manifest-v1:';

	public const SETTING_KEYS           = 'AGENCY_PROMOTION_HMAC_KEYS';
	public const SETTING_SIGNING_KEY_ID = 'AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID';

	public const MINIMUM_KEY_LENGTH = 32;

	/**
	 * @param array<string, string>|null $keyring        null reads AGENCY_PROMOTION_HMAC_KEYS.
	 * @param string|null                $signing_key_id null reads AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID.
	 */
	public function __construct( ?array $keyring = null, ?string $signing_key_id = null );

	public static function from_environment(): self;

	/**
	 * @param array<string, mixed> $payload
	 * @return array{hmacKeyId: string, hmac: string}
	 */
	public function sign( array $payload, string $purpose ): array;

	/**
	 * @param array<string, mixed> $document A signed document including hmac + hmacKeyId.
	 * @throws StateException Exit 4 on a bad/absent signature, exit 1 on keyring misconfiguration.
	 */
	public function verify( array $document, string $purpose ): void;

	/** @param array<string, mixed> $payload */
	public function canonicalize( array $payload ): string;
}
```

Behaviour rules (spec §7.7):
- `sign()` and `verify()` both drop `hmac` and `hmacKeyId` before canonicalising, so the signature covers everything else and nothing else.
- The signed string is `$purpose . $this->canonicalize( $payload )`; the two purpose constants make a bundle signature unusable as a manifest signature and vice versa.
- Missing, blank, non-JSON, or empty keyring → `StateException::hard_error()` (exit 1). **Never** fall back to WordPress salts.
- A key shorter than `MINIMUM_KEY_LENGTH` → `StateException::hard_error()`.
- Missing `AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID`, or a signing key id absent from the keyring → `StateException::hard_error()`.
- `verify()` on a document with no `hmac`/`hmacKeyId`, an unknown `hmacKeyId`, or a mismatching signature → `StateException::tamper()` (exit 4). Compare with `hash_equals()`.
- Rotation works because `verify()` looks the key up by the document's own `hmacKeyId` — any id present in the keyring is accepted; only signing uses `SIGNING_KEY_ID`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/AgencyPlatform/State/HmacSignerTest.php`:

```php
<?php
/**
 * The tamper-detection contract for state bundles and promotion manifests
 * (BLOCK_THEME_PROPOSAL.md §7.2 and §7.7): a keyring rather than a single
 * key so production can accept an older key id during a controlled
 * rotation, distinct purpose prefixes so a bundle signature can never be
 * replayed as a manifest signature, and a hard failure — never a WordPress
 * salt fallback — when the keyring is missing.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\StateException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\HmacSigner
 */
final class HmacSignerTest extends TestCase {

	private const KEY_OLD = 'old-key-with-at-least-32-characters!!';
	private const KEY_NEW = 'new-key-with-at-least-32-characters!!';

	/** @return array<string, mixed> */
	private function payload(): array {
		return array(
			'schemaVersion' => 1,
			'siteUuid'      => 'b7c5b3a2-1111-4222-8333-444455556666',
			'providers'     => array( 'templates' => array( 'records' => array() ) ),
		);
	}

	private function signer( string $signing_key_id = '2026-06' ): HmacSigner {
		return new HmacSigner(
			array(
				'2026-01' => self::KEY_OLD,
				'2026-06' => self::KEY_NEW,
			),
			$signing_key_id
		);
	}

	public function test_sign_returns_the_signing_key_id_and_a_sha256_hex_signature(): void {
		$signature = $this->signer()->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );

		self::assertSame( '2026-06', $signature['hmacKeyId'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $signature['hmac'] );
	}

	public function test_verify_accepts_a_document_this_signer_signed(): void {
		$signer   = $this->signer();
		$document = $this->payload() + $signer->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );

		$signer->verify( $document, HmacSigner::PURPOSE_BUNDLE );

		$this->addToAssertionCount( 1 );
	}

	public function test_signature_ignores_the_order_the_payload_was_built_in(): void {
		$signer = $this->signer();

		$forward = $signer->sign( array( 'a' => 1, 'b' => 2 ), HmacSigner::PURPOSE_BUNDLE );
		$reverse = $signer->sign( array( 'b' => 2, 'a' => 1 ), HmacSigner::PURPOSE_BUNDLE );

		self::assertSame( $forward['hmac'], $reverse['hmac'] );
	}

	public function test_verify_rejects_a_tampered_payload(): void {
		$signer   = $this->signer();
		$document = $this->payload() + $signer->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );

		$document['siteUuid'] = 'ffffffff-1111-4222-8333-444455556666';

		$this->expectException( StateException::class );

		try {
			$signer->verify( $document, HmacSigner::PURPOSE_BUNDLE );
		} catch ( StateException $exception ) {
			self::assertSame( StateException::EXIT_TAMPER, $exception->exit_code() );
			throw $exception;
		}
	}

	public function test_a_bundle_signature_cannot_be_replayed_as_a_manifest_signature(): void {
		$signer   = $this->signer();
		$document = $this->payload() + $signer->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );

		$this->expectException( StateException::class );

		$signer->verify( $document, HmacSigner::PURPOSE_MANIFEST );
	}

	public function test_a_manifest_signature_cannot_be_replayed_as_a_bundle_signature(): void {
		$signer   = $this->signer();
		$document = $this->payload() + $signer->sign( $this->payload(), HmacSigner::PURPOSE_MANIFEST );

		$this->expectException( StateException::class );

		$signer->verify( $document, HmacSigner::PURPOSE_BUNDLE );
	}

	public function test_rotation_still_verifies_a_document_signed_by_the_older_key(): void {
		$old_signer = $this->signer( '2026-01' );
		$document   = $this->payload() + $old_signer->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );

		// Production has advanced its signing key id but kept the old key.
		$this->signer( '2026-06' )->verify( $document, HmacSigner::PURPOSE_BUNDLE );

		$this->addToAssertionCount( 1 );
	}

	public function test_verify_rejects_an_unknown_key_id(): void {
		$document              = $this->payload() + array(
			'hmacKeyId' => '1999-01',
			'hmac'      => str_repeat( 'a', 64 ),
		);

		$this->expectException( StateException::class );

		try {
			$this->signer()->verify( $document, HmacSigner::PURPOSE_BUNDLE );
		} catch ( StateException $exception ) {
			self::assertSame( StateException::EXIT_TAMPER, $exception->exit_code() );
			throw $exception;
		}
	}

	public function test_verify_rejects_a_document_with_no_signature_at_all(): void {
		$this->expectException( StateException::class );

		$this->signer()->verify( $this->payload(), HmacSigner::PURPOSE_BUNDLE );
	}

	public function test_an_empty_keyring_is_a_hard_failure_not_a_salt_fallback(): void {
		$this->expectException( StateException::class );

		try {
			( new HmacSigner( array(), '2026-06' ) )->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );
		} catch ( StateException $exception ) {
			self::assertSame( StateException::EXIT_HARD_ERROR, $exception->exit_code() );
			self::assertStringContainsString( 'AGENCY_PROMOTION_HMAC_KEYS', $exception->getMessage() );
			throw $exception;
		}
	}

	public function test_a_signing_key_id_missing_from_the_keyring_is_a_hard_failure(): void {
		$this->expectException( StateException::class );

		$this->signer( '2027-01' )->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );
	}

	public function test_a_short_key_is_rejected(): void {
		$this->expectException( StateException::class );

		( new HmacSigner( array( '2026-06' => 'too-short' ), '2026-06' ) )->sign( $this->payload(), HmacSigner::PURPOSE_BUNDLE );
	}

	public function test_canonicalize_excludes_the_signature_fields(): void {
		$signer = $this->signer();

		self::assertSame(
			$signer->canonicalize( $this->payload() ),
			$signer->canonicalize(
				$this->payload() + array(
					'hmac'      => str_repeat( 'b', 64 ),
					'hmacKeyId' => '2026-06',
				)
			)
		);
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter HmacSignerTest
```

Expected: FAIL — `Class "AgencyPlatform\State\HmacSigner" not found`.

- [ ] **Step 3: Write `HmacSigner`**

Create `web/app/mu-plugins/agency-platform/src/State/HmacSigner.php` implementing exactly the Interfaces-block signature and the behaviour rules above. Notes:

- `from_environment()` returns `new self( null, null )`; the constructor stores the nullable inputs and a private `keyring()` resolves them lazily so a misconfigured environment fails at sign/verify time with a message, not at construction.
- Parse `AGENCY_PROMOTION_HMAC_KEYS` with `json_decode( $raw, true )`; require an array with at least one entry, string keys, non-empty string values, each at least `MINIMUM_KEY_LENGTH` characters. Any violation → `StateException::hard_error()` whose message names `AGENCY_PROMOTION_HMAC_KEYS` and the offending key id.
- `canonicalize()` = `Normalizer::canonical_json( $this->strip_signature( $payload ) )`.
- Never log or echo a key. If you add a diagnostic, pass it through `AgencyPlatform\Logging\Logger::redact()` first.

  **Recorded deptrac exception (orchestrator, 2026-08-05).** `AgencyPlatform\Logging\Logger` is the ONLY class outside `src/State/` this plan permits any state file to reference, and it is permitted because it is intra-layer. `deptrac.yaml:56` declares `AgencyPlatform: []`, which forbids a dependency on another LAYER; a reference from `agency-platform/src/State/` to `agency-platform/src/Logging/` stays inside the `AgencyPlatform` layer and is not a violation. Verified against the file: `src/Logging/Logger.php:60` declares `public static function redact( array $context ): array`. This is the single stated exception to Global Constraints' "WordPress core and PHP only" rule for `src/State/`. Any other cross-directory reference is out of scope and must be reported before it is written.

- [ ] **Step 4: Run the test to green**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter HmacSignerTest
```

Expected: PASS (13 tests).

- [ ] **Step 5: Run the fast gate and commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/HmacSigner.php tests/Unit/AgencyPlatform/State/HmacSignerTest.php
git commit -m "feat(state): add the HMAC keyring signer for bundles and manifests"
```

---

### Task 4: Bundle JSON Schema and the schema-validation helper

**Files:**
- Create: `web/app/mu-plugins/agency-platform/resources/schemas/state-bundle-v1.json`
- Create: `web/app/mu-plugins/agency-platform/src/State/SchemaValidator.php`
- Test: `tests/Unit/AgencyPlatform/State/SchemaValidatorTest.php`

**Interfaces:**
- Consumes: `Normalizer::canonical_json()`, `StateException`.
- Produces:

```php
final class SchemaValidator {
	public const SCHEMA_STATE_BUNDLE       = 'state-bundle-v1';
	public const SCHEMA_PROMOTION_MANIFEST = 'promotion-manifest-v1';

	public function __construct( ?string $schema_dir = null );

	/** @param array<string, mixed> $document @throws StateException Exit 1, message names the FIRST violation with its JSON pointer path, and says validation stopped there. See the amendment below. */
	public function validate( array $document, string $schema_name ): void;

	public static function schema_dir(): string;   // <agency-platform>/resources/schemas
	public static function schema_path( string $schema_name ): string;
}
```

`SCHEMA_PROMOTION_MANIFEST` is declared **here, by this task**, even though the schema file itself is Task 3's, so Task 3 never passes a magic string and never needs to edit this file. Validating against it before Task 3 ships that file fails cleanly with "JSON schema … was not found" — add a unit test for exactly that.

- [ ] **Step 1: Write the schema**

Create `web/app/mu-plugins/agency-platform/resources/schemas/state-bundle-v1.json`:

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "$id": "https://agency-platform.invalid/schemas/state-bundle-v1.json",
  "title": "Agency state bundle v1",
  "type": "object",
  "additionalProperties": false,
  "required": [
    "schemaVersion", "exportId", "exportedAtUtc", "siteUuid", "siteUrl",
    "environment", "wordpressVersion", "activeTheme", "providers",
    "stateHash", "hmacKeyId", "hmac"
  ],
  "properties": {
    "schemaVersion": { "const": 1 },
    "exportId": { "$ref": "#/definitions/uuid" },
    "exportedAtUtc": { "type": "string", "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$" },
    "siteUuid": { "$ref": "#/definitions/uuid" },
    "siteUrl": { "type": "string", "minLength": 1 },
    "environment": { "type": "string", "minLength": 1 },
    "wordpressVersion": { "type": "string", "minLength": 1 },
    "activeTheme": {
      "type": "object",
      "additionalProperties": false,
      "required": ["stylesheet", "version", "gitCommit"],
      "properties": {
        "stylesheet": { "type": "string", "minLength": 1 },
        "version": { "type": "string" },
        "gitCommit": { "type": ["string", "null"] }
      }
    },
    "providers": {
      "type": "object",
      "minProperties": 1,
      "additionalProperties": { "$ref": "#/definitions/provider" }
    },
    "stateHash": { "$ref": "#/definitions/sha256" },
    "hmacKeyId": { "type": "string", "minLength": 1 },
    "hmac": { "$ref": "#/definitions/sha256" }
  },
  "definitions": {
    "uuid": { "type": "string", "pattern": "^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$" },
    "sha256": { "type": "string", "pattern": "^[a-f0-9]{64}$" },
    "ownership": { "enum": ["git-baseline+db", "git-baseline+db-user-origin", "database", "database+uploads", "forbidden"] },
    "promotion": { "enum": ["promotable", "export-and-diff", "never-promote", "refuse"] },
    "provider": {
      "type": "object",
      "additionalProperties": false,
      "required": ["slug", "ownership", "promotion", "hasGitBaseline", "records"],
      "properties": {
        "slug": { "type": "string", "pattern": "^[a-z0-9-]+$" },
        "ownership": { "$ref": "#/definitions/ownership" },
        "promotion": { "$ref": "#/definitions/promotion" },
        "hasGitBaseline": { "type": "boolean" },
        "records": { "type": "array", "items": { "$ref": "#/definitions/record" } }
      }
    },
    "record": {
      "type": "object",
      "additionalProperties": false,
      "required": ["key", "objectId", "slug", "status", "modifiedGmt", "content", "contentHash", "references", "ownership", "promotion"],
      "properties": {
        "key": { "type": "string", "pattern": "^[a-z0-9-]+:[^\\s]+$" },
        "objectId": { "type": ["integer", "null"] },
        "slug": { "type": "string", "minLength": 1 },
        "status": { "type": "string", "minLength": 1 },
        "modifiedGmt": { "type": ["string", "null"] },
        "content": { "type": ["object", "array", "string", "number", "boolean", "null"] },
        "contentHash": { "$ref": "#/definitions/sha256" },
        "references": { "type": "array", "items": { "$ref": "#/definitions/reference" } },
        "ownership": { "$ref": "#/definitions/ownership" },
        "promotion": { "$ref": "#/definitions/promotion" }
      }
    },
    "reference": {
      "type": "object",
      "additionalProperties": false,
      "required": ["record", "provider", "blockName", "attribute", "value", "kind", "resolution", "policy", "targetKey", "targetHash", "targetIdentity"],
      "properties": {
        "record": { "type": "string", "minLength": 1 },
        "provider": { "type": "string", "minLength": 1 },
        "blockName": { "type": ["string", "null"] },
        "attribute": { "type": "string", "minLength": 1 },
        "value": { "type": ["string", "integer", "null"] },
        "kind": { "enum": ["navigation", "synced-pattern", "attachment", "site-logo", "gallery", "font-file", "plugin-block", "post-meta", "unknown-ref"] },
        "resolution": { "enum": ["resolved", "environment-specific", "unknown"] },
        "policy": { "type": "string" },
        "targetKey": { "type": ["string", "null"] },
        "targetHash": { "oneOf": [{ "$ref": "#/definitions/sha256" }, { "type": "null" }] },
        "targetIdentity": { "type": ["object", "null"] }
      }
    }
  }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/AgencyPlatform/State/SchemaValidatorTest.php`. It builds a minimal valid bundle document, asserts `validate()` accepts it, then mutates it four ways and asserts each is rejected with a message naming the offending field:

```php
<?php
/**
 * Every export must validate against resources/schemas/state-bundle-v1.json
 * (BLOCK_THEME_PROPOSAL.md §6). The schema is the contract Task 3's
 * promotion prepare step reads bundles through, so a malformed bundle must
 * fail loudly here rather than half-way through a promotion.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\SchemaValidator;
use AgencyPlatform\State\StateException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\SchemaValidator
 */
final class SchemaValidatorTest extends TestCase {

	/** @return array<string, mixed> */
	private function bundle(): array {
		return array(
			'schemaVersion'    => 1,
			'exportId'         => '11111111-2222-4333-8444-555566667777',
			'exportedAtUtc'    => '2026-08-02T09:00:00Z',
			'siteUuid'         => '99999999-2222-4333-8444-555566667777',
			'siteUrl'          => 'https://agency-starter.ddev.site',
			'environment'      => 'development',
			'wordpressVersion' => '7.0',
			'activeTheme'      => array(
				'stylesheet' => 'site-theme',
				'version'    => '0.1.0',
				'gitCommit'  => null,
			),
			'providers'        => array(
				'templates' => array(
					'slug'           => 'templates',
					'ownership'      => 'git-baseline+db',
					'promotion'      => 'promotable',
					'hasGitBaseline' => true,
					'records'        => array(
						array(
							'key'         => 'templates:page',
							'objectId'    => 12,
							'slug'        => 'page',
							'status'      => 'publish',
							'modifiedGmt' => '2026-08-01 10:00:00',
							'content'     => array( 'markup' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ),
							'contentHash' => str_repeat( 'a', 64 ),
							'references'  => array(),
							'ownership'   => 'git-baseline+db',
							'promotion'   => 'promotable',
						),
					),
				),
			),
			'stateHash'        => str_repeat( 'b', 64 ),
			'hmacKeyId'        => '2026-01',
			'hmac'             => str_repeat( 'c', 64 ),
		);
	}

	public function test_a_well_formed_bundle_validates(): void {
		( new SchemaValidator() )->validate( $this->bundle(), SchemaValidator::SCHEMA_STATE_BUNDLE );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_missing_required_wrapper_field_is_rejected(): void {
		$bundle = $this->bundle();
		unset( $bundle['stateHash'] );

		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/stateHash/' );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
	}

	public function test_an_unknown_wrapper_field_is_rejected(): void {
		$bundle                   = $this->bundle();
		$bundle['surpriseField']  = 'nope';

		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
	}

	public function test_a_bad_hash_shape_is_rejected(): void {
		$bundle              = $this->bundle();
		$bundle['stateHash'] = 'not-a-sha256';

		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
	}

	public function test_an_unknown_ownership_value_is_rejected(): void {
		$bundle = $this->bundle();
		$bundle['providers']['templates']['ownership'] = 'whatever';

		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
	}

	public function test_an_empty_provider_map_is_rejected(): void {
		$bundle              = $this->bundle();
		$bundle['providers'] = array();

		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
	}

	public function test_an_unknown_schema_name_is_a_hard_error(): void {
		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/no-such-schema/' );

		( new SchemaValidator() )->validate( $this->bundle(), 'no-such-schema' );
	}

	/**
	 * The promotion-manifest constant is declared by this task so the promotion
	 * track never passes a magic string, but the schema FILE is that track's to
	 * ship. Until it exists, validation must fail with a clear "not found"
	 * message rather than a confusing schema error.
	 */
	public function test_the_promotion_manifest_constant_exists_and_names_its_schema_file(): void {
		self::assertSame( 'promotion-manifest-v1', SchemaValidator::SCHEMA_PROMOTION_MANIFEST );
		self::assertStringEndsWith(
			'promotion-manifest-v1.json',
			SchemaValidator::schema_path( SchemaValidator::SCHEMA_PROMOTION_MANIFEST )
		);
	}

	public function test_a_reference_without_its_resolved_target_is_rejected(): void {
		$bundle    = $this->bundle();
		$reference = array(
			'record'     => 'templates:page',
			'provider'   => 'templates',
			'blockName'  => 'core/navigation',
			'attribute'  => 'ref',
			'value'      => 12,
			'kind'       => 'navigation',
			'resolution' => 'environment-specific',
			'policy'     => 'Navigation stays database-owned in v1.',
			// targetKey / targetHash / targetIdentity deliberately omitted.
		);

		$bundle['providers']['templates']['records'][0]['references'] = array( $reference );

		$this->expectException( StateException::class );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
	}
}
```

- [ ] **Step 3: Run it and watch it fail**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter SchemaValidatorTest
```

Expected: FAIL — `Class "AgencyPlatform\State\SchemaValidator" not found`.

- [ ] **Step 4: Write `SchemaValidator`**

Create `web/app/mu-plugins/agency-platform/src/State/SchemaValidator.php`:

```php
<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

use Swaggest\JsonSchema\Exception as JsonSchemaException;
use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

/**
 * Validates a state bundle (and, from the promotion track, a promotion
 * manifest) against the JSON Schema shipped in resources/schemas/.
 *
 * swaggest/json-schema is a runtime `require` dependency, not require-dev:
 * validation also runs on the production WordPress host during promotion
 * finalisation (BLOCK_THEME_PROPOSAL.md §6), where dev dependencies are not
 * installed.
 *
 * Documents arrive as PHP arrays and are converted to the stdClass shape the
 * validator expects by round-tripping through Normalizer::canonical_json():
 * that also guarantees the validated bytes are exactly the canonical bytes
 * everything else in this subsystem hashes and signs.
 */
final class SchemaValidator {

	public const SCHEMA_STATE_BUNDLE = 'state-bundle-v1';

	/**
	 * Declared here so the promotion track never passes a magic string. The
	 * schema FILE is that track's to ship; until it exists, validate() fails
	 * with a clear "schema not found" message.
	 */
	public const SCHEMA_PROMOTION_MANIFEST = 'promotion-manifest-v1';

	private string $schema_dir;

	public function __construct( ?string $schema_dir = null ) {
		$this->schema_dir = $schema_dir ?? self::schema_dir();
	}

	public static function schema_dir(): string {
		return dirname( __DIR__, 2 ) . '/resources/schemas';
	}

	public static function schema_path( string $schema_name ): string {
		return self::schema_dir() . '/' . $schema_name . '.json';
	}

	/**
	 * @param array<string, mixed> $document
	 * @throws StateException Exit 1 when the schema is missing or the document is invalid.
	 */
	public function validate( array $document, string $schema_name ): void {
		$path = $this->schema_dir . '/' . $schema_name . '.json';

		if ( ! is_file( $path ) ) {
			throw StateException::hard_error( sprintf( 'JSON schema "%s" was not found at %s.', $schema_name, $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a schema file shipped inside this mu-plugin; WP_Filesystem needs a credentials-bearing admin request context that CLI validation does not have.
		$raw = file_get_contents( $path );

		if ( false === $raw ) {
			throw StateException::hard_error( sprintf( 'JSON schema "%s" could not be read from %s.', $schema_name, $path ) );
		}

		try {
			$schema = Schema::import( json_decode( $raw, false, 512, JSON_THROW_ON_ERROR ) );
			$schema->in( json_decode( Normalizer::canonical_json( $document ), false, 512, JSON_THROW_ON_ERROR ) );
		} catch ( InvalidValue $invalid ) {
			throw StateException::hard_error(
				sprintf( 'The document does not match schema "%s": %s', $schema_name, $invalid->getMessage() ),
				$invalid
			);
		} catch ( JsonSchemaException | \JsonException $error ) {
			throw StateException::hard_error(
				sprintf( 'Schema "%s" could not be applied: %s', $schema_name, $error->getMessage() ),
				$error
			);
		}
	}
}
```

If `Swaggest\JsonSchema\Exception` does not exist under that exact FQCN in the installed version, check `vendor/swaggest/json-schema/src/` and use the real base exception class; do not catch bare `\Throwable`.

- [ ] **Step 5: Run the test to green**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter SchemaValidatorTest
```

Expected: PASS (9 tests). If `test_a_missing_required_wrapper_field_is_rejected` fails because the library's message does not name the field, widen the message assertion to `/required/` rather than weakening the schema.

- [ ] **Step 6: Run the fast gate and commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/resources/schemas/state-bundle-v1.json \
  web/app/mu-plugins/agency-platform/src/State/SchemaValidator.php \
  tests/Unit/AgencyPlatform/State/SchemaValidatorTest.php
git commit -m "feat(state): add the state-bundle-v1 schema and its validator"
```

---

### Task 5: Classification constants, the record value object, and the provider contract

**Files:**
- Create: `web/app/mu-plugins/agency-platform/src/State/Ownership.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/PromotionPolicy.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/DriftClassification.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/StateRecord.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/StateProvider.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/PromotionStrategy.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/PromotionStrategies.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/BaseStateProvider.php`
- Test: `tests/Unit/AgencyPlatform/State/StateRecordTest.php`
- Test: `tests/Unit/AgencyPlatform/State/PromotionStrategiesTest.php`

**Interfaces:**
- Consumes: `Normalizer::hash()`, `StateException`.
- Produces — **the cross-task contract Task 3 consumes**:

```php
final class Ownership {
	public const GIT_BASELINE_PLUS_DB             = 'git-baseline+db';
	public const GIT_BASELINE_PLUS_DB_USER_ORIGIN = 'git-baseline+db-user-origin';
	public const DATABASE                         = 'database';
	public const DATABASE_PLUS_UPLOADS            = 'database+uploads';
	public const FORBIDDEN                        = 'forbidden';
	/** @return list<string> */
	public static function all(): array;
}

final class PromotionPolicy {
	public const PROMOTABLE      = 'promotable';       // §5.4 "Promotable"
	public const EXPORT_AND_DIFF = 'export-and-diff';  // §5.4 "Export and diff; do not promote in v1"
	public const NEVER_PROMOTE   = 'never-promote';    // §5.4 "never promote to theme files"
	public const REFUSE          = 'refuse';           // §5.4 "Detect and refuse automatic promotion"
	/** @return list<string> */
	public static function all(): array;
}

final class DriftClassification {
	public const PROMOTABLE = 'promotable';
	public const DB_OWNED   = 'db-owned';
	public const FORBIDDEN  = 'forbidden';
	public const UNRESOLVED = 'unresolved';
	public const UNCHANGED  = 'unchanged';
	/** @return list<string> */
	public static function all(): array;
}

final class StateRecord {
	/**
	 * @param array<string, mixed>              $content
	 * @param list<array<string, mixed>>        $references
	 */
	public static function create(
		string $provider_slug,
		string $slug,
		?int $object_id,
		string $status,
		?string $modified_gmt,
		array $content,
		array $references,
		string $ownership,
		string $promotion
	): self;

	/** @param array<string, mixed> $record @throws StateException on a malformed record. */
	public static function from_array( array $record ): self;

	public function key(): string;            // "<provider>:<slug>"
	public function provider_slug(): string;
	public function slug(): string;
	public function object_id(): ?int;
	public function status(): string;
	public function modified_gmt(): ?string;
	/** @return array<string, mixed> */
	public function content(): array;
	public function content_hash(): string;
	/** @return list<array<string, mixed>> */
	public function references(): array;
	public function ownership(): string;
	public function promotion(): string;
	/** @return array<string, mixed> Bundle record shape (camelCase JSON keys). */
	public function to_array(): array;
}

/**
 * The complete §7.1 provider contract. Every bullet §7.1 lists has a home
 * here: stable slug, export behaviour, normalisation behaviour, stable record
 * key, diff behaviour, promotability, dependency/reference detection,
 * validation behaviour, and — through promotion_strategy() — prepare,
 * finalise/reset, and restore behaviour.
 */
interface StateProvider {
	/** §7.1 "Stable provider slug". */
	public function slug(): string;
	/** §7.1 "Stable record key": "<provider-slug>:<record-slug>". */
	public function record_key( string $record_slug ): string;

	public function ownership(): string;                 // an Ownership::* constant
	/** §7.1 "Whether it is promotable" — the policy. */
	public function promotion(): string;                 // a PromotionPolicy::* constant
	public function is_promotable(): bool;               // promotion() === PromotionPolicy::PROMOTABLE
	public function includes_content(): bool;            // true only for providers gated behind --include-content
	public function has_git_baseline(): bool;

	/** §7.1 "Normalisation behaviour". @param array<string,mixed> $content @return array<string,mixed> */
	public function normalize( array $content ): array;
	/** §7.1 "Dependency/reference detection". @param array<string,mixed> $content @return list<array<string,mixed>> */
	public function detect_references( array $content, string $record_key ): array;
	/** §7.1 "Diff behaviour". @return string One of 'added'|'removed'|'changed'|'unchanged'. */
	public function compare_records( ?StateRecord $current, ?StateRecord $target ): string;
	/** §7.1 "Validation behaviour". @return list<string> Human-readable problems; empty when valid. */
	public function validate( StateRecord $record ): array;

	/** §7.1 "Export behaviour". @return list<StateRecord> Live database records, sorted by key ascending (strcmp). */
	public function records(): array;
	/**
	 * Live single-record re-read; null when the record no longer exists.
	 * $with_references = false suppresses reference detection, which
	 * ReferenceResolver uses to break the scan -> resolve -> scan cycle.
	 */
	public function record( string $key, bool $with_references = true ): ?StateRecord;
	/** @return list<StateRecord> Git-baseline counterparts; empty when has_git_baseline() is false. */
	public function baseline_records(): array;

	/**
	 * §7.1 "Prepare behaviour when promotable", "Finalise/reset behaviour when
	 * promotable", "Restore behaviour" — delegated to a strategy so the
	 * promotion track can supply them without editing a provider file.
	 * Returns null when nothing is registered for this slug.
	 */
	public function promotion_strategy(): ?PromotionStrategy;
}

/**
 * The promotion delegation point. This task DEFINES it and ships the empty
 * registry; the promotion track IMPLEMENTS it, one strategy per promotable
 * provider slug, and registers them through the filter below. Nothing in
 * this task calls prepare()/reset()/restore().
 */
interface PromotionStrategy {
	/** The StateProvider slug this strategy serves. */
	public function provider_slug(): string;

	/**
	 * §7.5: write the record's normalised content to $target_path atomically.
	 *
	 * @return array{preparedPath: string, preparedHash: string, originalHash: string|null}
	 */
	public function prepare( StateRecord $record, string $target_path ): array;

	/** §7.8: remove the database override through WordPress APIs. */
	public function reset( StateRecord $record ): void;

	/** §7.9: recreate the database override from a backup payload. @param array<string,mixed> $backup */
	public function restore( StateRecord $record, array $backup ): void;

	/** §7.8 step 9: the semantic hash the resolved state must equal after reset(). */
	public function expected_post_reset_hash( StateRecord $record ): string;
}

/**
 * Slug-keyed registry of promotion strategies, extended through the
 * `agency_platform_promotion_strategies` filter. Ships EMPTY from this task:
 * with no strategy registered, promotion_strategy() returns null everywhere
 * and Release 2 is export-and-diff only, exactly as §13 requires.
 */
final class PromotionStrategies {
	public const FILTER = 'agency_platform_promotion_strategies';

	/** @return array<string, PromotionStrategy> keyed and sorted by provider slug. */
	public static function all(): array;
	public static function for_provider( string $provider_slug ): ?PromotionStrategy;
	public static function reset(): void;   // test seam
}

/**
 * Shared defaults so each provider stays small and final. Concrete providers
 * extend this and implement only slug(), ownership(), promotion(),
 * includes_content(), has_git_baseline(), records(), and baseline_records().
 */
abstract class BaseStateProvider implements StateProvider {
	public function record_key( string $record_slug ): string;        // slug() . ':' . $record_slug
	public function is_promotable(): bool;                            // promotion() === PromotionPolicy::PROMOTABLE
	public function normalize( array $content ): array;               // Normalizer::normalize_content()
	public function detect_references( array $content, string $record_key ): array;  // scans $content['markup'] when present, else array()
	public function compare_records( ?StateRecord $current, ?StateRecord $target ): string;  // hash comparison
	public function validate( StateRecord $record ): array;           // array()
	public function record( string $key, bool $with_references = true ): ?StateRecord;      // linear lookup over records()
	public function promotion_strategy(): ?PromotionStrategy;         // PromotionStrategies::for_provider( $this->slug() )
}
```

**Design note for the executing agent:** the contract is complete, but this task implements **none** of `PromotionStrategy`. `PromotionStrategies::all()` returns an empty array until the promotion track registers its strategies on the filter. That keeps §7.1 satisfied *and* respects the single-owner file rule: the promotion track adds behaviour without touching one provider file. `record()` also exists so that track can re-read a live record for its §7.8 concurrency check without reimplementing a provider's query.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/AgencyPlatform/State/StateRecordTest.php`:

```php
<?php
/**
 * Every bundle record carries the fields BLOCK_THEME_PROPOSAL.md §7.2
 * requires, and its content hash must depend only on the content values —
 * never on the order the content array happened to be built in — because
 * that hash is what the two-mode diff and every promotion concurrency check
 * compare.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\StateException;
use AgencyPlatform\State\StateRecord;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\StateRecord
 */
final class StateRecordTest extends TestCase {

	private function record(): StateRecord {
		return StateRecord::create(
			'templates',
			'page',
			12,
			'publish',
			'2026-08-01 10:00:00',
			array( 'markup' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ),
			array(),
			Ownership::GIT_BASELINE_PLUS_DB,
			PromotionPolicy::PROMOTABLE
		);
	}

	public function test_the_key_is_provider_slug_colon_record_slug(): void {
		self::assertSame( 'templates:page', $this->record()->key() );
		self::assertSame( 'templates', $this->record()->provider_slug() );
	}

	public function test_the_content_hash_is_the_canonical_hash_of_the_content(): void {
		$content = array( 'markup' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' );

		self::assertSame( Normalizer::hash( $content ), $this->record()->content_hash() );
	}

	public function test_the_content_hash_ignores_content_key_order(): void {
		$forward = StateRecord::create( 'content', 'page-1', 1, 'publish', null, array( 'a' => 1, 'b' => 2 ), array(), Ownership::DATABASE, PromotionPolicy::NEVER_PROMOTE );
		$reverse = StateRecord::create( 'content', 'page-1', 1, 'publish', null, array( 'b' => 2, 'a' => 1 ), array(), Ownership::DATABASE, PromotionPolicy::NEVER_PROMOTE );

		self::assertSame( $forward->content_hash(), $reverse->content_hash() );
	}

	public function test_to_array_uses_the_bundle_field_names(): void {
		self::assertSame(
			array( 'key', 'objectId', 'slug', 'status', 'modifiedGmt', 'content', 'contentHash', 'references', 'ownership', 'promotion' ),
			array_keys( $this->record()->to_array() )
		);
	}

	public function test_from_array_round_trips_to_array(): void {
		$restored = StateRecord::from_array( $this->record()->to_array() );

		self::assertSame( $this->record()->to_array(), $restored->to_array() );
	}

	public function test_from_array_rejects_a_record_whose_hash_does_not_match_its_content(): void {
		$record                = $this->record()->to_array();
		$record['contentHash'] = str_repeat( 'f', 64 );

		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/templates:page/' );

		StateRecord::from_array( $record );
	}

	public function test_create_rejects_an_unknown_ownership_value(): void {
		$this->expectException( StateException::class );

		StateRecord::create( 'templates', 'page', 12, 'publish', null, array(), array(), 'not-a-real-ownership', PromotionPolicy::PROMOTABLE );
	}

	public function test_create_rejects_a_slug_containing_the_key_separator(): void {
		$this->expectException( StateException::class );

		StateRecord::create( 'templates', 'pa:ge', 12, 'publish', null, array(), array(), Ownership::GIT_BASELINE_PLUS_DB, PromotionPolicy::PROMOTABLE );
	}
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter StateRecordTest
```

Expected: FAIL — `Class "AgencyPlatform\State\StateRecord" not found`.

- [ ] **Step 3: Write the three constant classes**

Each is a `final class` with a `private function __construct()` (the `Logger` / `ArchitectureScanner` house pattern), the constants from the Interfaces block, and an `all(): array` returning them as a `list<string>` for validation and for the CLI's `--format=table` legend.

- [ ] **Step 4: Write `StateRecord`**

Implement exactly the Interfaces-block signature. Rules:

- `create()` validates: `$provider_slug` matches `/^[a-z0-9-]+$/`, `$slug` is non-empty and contains no `:` or whitespace, `$ownership` is in `Ownership::all()`, `$promotion` is in `PromotionPolicy::all()`. Any violation → `StateException::hard_error()` naming the provider and slug.
- `create()` computes `content_hash` itself with `Normalizer::hash( $content )`; it is never passed in.
- `to_array()` returns the keys in exactly the order asserted by the test.
- `from_array()` requires all ten keys, re-derives the provider slug and record slug by splitting `key` on the first `:`, and **recomputes the content hash and compares it with `contentHash`**, throwing `StateException::hard_error()` naming the key on mismatch. That makes a hand-edited bundle record fail before Task 3 can act on it.
- Store `content` already `Normalizer::sort_recursive()`-ed so `to_array()` output is canonical.

- [ ] **Step 5: Write `StateProvider`, `PromotionStrategy`, `PromotionStrategies`, and `BaseStateProvider`**

Create the four files with exactly the members from the Interfaces block. `StateProvider`'s class-level docblock must map each method to the §7.1 bullet it satisfies, and state: the slug is stable and appears in every record key and in `--providers` / `--select`; `records()` returns records sorted by `key()` ascending using `strcmp` so exports are deterministic; `baseline_records()` returns the Git-side counterparts; prepare/finalise/restore are delegated to `PromotionStrategy` so another track can supply them without editing a provider file; and project plugins add providers through the `agency_platform_state_providers` filter without modifying `agency-platform`.

`PromotionStrategies::all()` builds an empty array, passes it through `apply_filters( self::FILTER, array() )`, rejects any value that is not a `PromotionStrategy` with `StateException::hard_error()` naming the key, re-keys by `provider_slug()`, `ksort`s, and memoises. Registrants must supply named classes, never closures (§4).

- [ ] **Step 6: Write the delegation test**

Create `tests/Unit/AgencyPlatform/State/PromotionStrategiesTest.php`:

```php
	protected function setUp(): void {
		parent::setUp();

		PromotionStrategies::reset();
		$GLOBALS['_test_filters'] = array();
	}

	public function test_no_strategy_is_registered_by_default(): void {
		self::assertSame( array(), PromotionStrategies::all(), 'Release 2 is export-and-diff only; promotion behaviour arrives on the filter.' );
		self::assertNull( PromotionStrategies::for_provider( 'templates' ) );
	}

	public function test_a_filtered_strategy_is_resolved_by_provider_slug(): void {
		add_filter( PromotionStrategies::FILTER, array( self::class, 'append_fake_strategy' ) );
		PromotionStrategies::reset();

		self::assertInstanceOf( PromotionStrategy::class, PromotionStrategies::for_provider( 'templates' ) );
	}

	public function test_a_non_strategy_value_from_the_filter_is_rejected(): void {
		add_filter( PromotionStrategies::FILTER, array( self::class, 'append_garbage' ) );
		PromotionStrategies::reset();

		$this->expectException( StateException::class );

		PromotionStrategies::all();
	}

	public function test_a_provider_reports_no_strategy_until_one_is_registered(): void {
		$provider = new FakeStateProvider();

		self::assertNull( $provider->promotion_strategy() );
	}
```

`append_fake_strategy()` registers a `Tests\Unit\AgencyPlatform\State\Doubles\FakePromotionStrategy` whose `provider_slug()` is `'templates'` and whose four behaviour methods throw `\LogicException` (they must never be called in this task). Create it next to `FakeStateProvider`. Extend `FakeStateProvider` to extend `BaseStateProvider`.

- [ ] **Step 7: Run both unit tests to green**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter StateRecordTest
ddev exec vendor/bin/phpunit --testsuite unit --filter PromotionStrategiesTest
```

Expected: PASS (8 tests, then 4 tests).

- [ ] **Step 8: Run the fast gate and commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Ownership.php \
  web/app/mu-plugins/agency-platform/src/State/PromotionPolicy.php \
  web/app/mu-plugins/agency-platform/src/State/DriftClassification.php \
  web/app/mu-plugins/agency-platform/src/State/StateRecord.php \
  web/app/mu-plugins/agency-platform/src/State/StateProvider.php \
  web/app/mu-plugins/agency-platform/src/State/PromotionStrategy.php \
  web/app/mu-plugins/agency-platform/src/State/PromotionStrategies.php \
  web/app/mu-plugins/agency-platform/src/State/BaseStateProvider.php \
  tests/Unit/AgencyPlatform/State/StateRecordTest.php \
  tests/Unit/AgencyPlatform/State/PromotionStrategiesTest.php \
  tests/Unit/AgencyPlatform/State/Doubles/
git commit -m "feat(state): add the full state provider contract and promotion delegation"
```

---

### Task 6: Reference scanner

> **Scope correction (orchestrator, 2026-08-05).** This task ships the PURE
> scanner only. `ReferenceResolver` and `ReferenceScanner::scan()` move to
> Task 9. The reason is a real circular dependency in the unmodified plan:
> `ReferenceResolver::resolve()` reads `StateRegistry::provider( … )`, and
> `StateRegistry` is Task 8, which in turn consumes `ReferenceScanner` from
> this task. Shipping `ReferenceResolver` here would reference a class that
> does not exist yet, and PHPStan level 6 fails that at this task's own
> `verify:fast` gate. Task 9 is where the resolver's integration test already
> lives, and it is the first task where every referenced provider exists.
>
> The `ReferenceResolver` contract, the eleven reference keys, and the
> resolution table stay documented BELOW because `scan_parsed()` must emit
> those keys with `null` targets. Read them; do not create the class here.

**Files:**
- Create: `web/app/mu-plugins/agency-platform/src/State/ReferenceScanner.php` (without `scan()`)
- Test: `tests/Unit/AgencyPlatform/State/ReferenceScannerTest.php`

**Interfaces:**
- Consumes: nothing beyond PHP. Every method this task ships is pure.
- Produces:

```php
final class ReferenceScanner {
	public const KIND_NAVIGATION     = 'navigation';
	public const KIND_SYNCED_PATTERN = 'synced-pattern';
	public const KIND_ATTACHMENT     = 'attachment';
	public const KIND_SITE_LOGO      = 'site-logo';
	public const KIND_GALLERY        = 'gallery';
	public const KIND_FONT_FILE      = 'font-file';
	public const KIND_PLUGIN_BLOCK   = 'plugin-block';
	public const KIND_POST_META      = 'post-meta';
	public const KIND_UNKNOWN_REF    = 'unknown-ref';

	public const RESOLUTION_RESOLVED     = 'resolved';
	public const RESOLUTION_ENVIRONMENT  = 'environment-specific';
	public const RESOLUTION_UNKNOWN      = 'unknown';

	// NOT IN THIS TASK — Task 9 adds this method together with
	// ReferenceResolver. Declaring it here would call a class that does not
	// exist yet and PHPStan would fail this task's own verify:fast gate.
	//
	// /** WordPress-coupled: parse_blocks(), scan_parsed(), then ReferenceResolver::resolve(). @return list<array<string, mixed>> */
	// public static function scan( string $markup, string $record_key ): array;

	/** Pure. Emits unresolved-target references; the resolver fills the target fields. @param list<array<string, mixed>> $blocks @return list<array<string, mixed>> */
	public static function scan_parsed( array $blocks, string $record_key ): array;

	/** Pure. @param array<string, mixed> $reference */
	public static function is_unresolved( array $reference ): bool;

	/** Pure. @param list<array<string, mixed>> $references @return list<array<string, mixed>> */
	public static function unresolved( array $references ): array;

	/** Pure. Deterministic ordering, independent of block-attribute map traversal. @param list<array<string, mixed>> $references @return list<array<string, mixed>> */
	public static function sort_references( array $references ): array;
}

/**
 * TASK 9 CREATES THIS CLASS, not Task 6. It is documented here because
 * scan_parsed() must emit the three target keys below as null for the
 * resolver to fill in later.
 *
 * WordPress-coupled companion that turns a raw reference into a resolvable
 * one: it looks up the referenced record and records its canonical key, its
 * normalised content hash, and just enough identifying metadata for a human
 * refusal report. §7.4's v1 navigation policy needs the exported navigation's
 * content hash at finalisation time, and a caller who exported
 * `--providers=templates` has no navigation provider in the bundle — so the
 * hash has to travel inside the reference itself.
 */
final class ReferenceResolver {
	/** @param list<array<string, mixed>> $references @return list<array<string, mixed>> */
	public static function resolve( array $references ): array;
}
```

Every reference is an array with exactly these keys, in this order (the first eight are §7.4's refusal-report fields; the last three are the resolved target):

```php
array(
	'record'         => 'templates:page',        // record/provider — the record side
	'provider'       => 'templates',             // record/provider — the provider side
	'blockName'      => 'core/navigation',       // block name
	'attribute'      => 'ref',                   // attribute
	'value'          => 12,                      // referenced ID/value (int|string|null)
	'kind'           => self::KIND_NAVIGATION,
	'resolution'     => self::RESOLUTION_ENVIRONMENT,
	'policy'         => 'Navigation stays database-owned in v1. …',
	'targetKey'      => 'navigation:primary',    // canonical record key of the referenced record, or null
	'targetHash'     => 'ab12…',                 // SHA-256 of the target's normalised content, or null
	'targetIdentity' => array( 'title' => 'Primary', 'slug' => 'primary', 'status' => 'publish' ), // or null
)
```

**Resolution rules for the three target fields** (`ReferenceResolver::resolve()`; all three are `null` when the target cannot be found, and `scan_parsed()` always emits them as `null`):

| kind | how the target is resolved | `targetIdentity` keys |
|---|---|---|
| `navigation` | `get_post( (int) $value )` when the post type is `wp_navigation` | `title`, `slug`, `status` |
| `synced-pattern` | `get_post( (int) $value )` when the post type is `wp_block` | `title`, `slug`, `status` |
| `attachment`, `gallery` | `get_post( (int) $value )` when the post type is `attachment` | `title`, `slug`, `mimeType`, `relativePath` |
| `site-logo` | **`(int) get_option( 'site_logo' )`** — the block carries no id, so the resolver supplies it and **overwrites `value` with that integer** (§7.4 requires the referenced ID). `value` stays `null` only when no site logo is set. | as `attachment` |
| `font-file` | not resolvable to a record | `null` |
| `plugin-block`, `post-meta`, `unknown-ref` | not resolvable to a record | `null` |

`targetHash` is `Normalizer::hash( $provider->record( $target_key )->content() )` — i.e. it is taken from the owning provider so it is byte-identical to the hash that provider would export. Never recompute it a different way.

Detection rules (spec §7.4 lists the categories; these are the concrete matchers):

| Match | kind | attribute | value | resolution |
|---|---|---|---|---|
| `core/navigation` with integer `attrs.ref` | `navigation` | `ref` | the id | `environment-specific` |
| `core/block` with integer `attrs.ref` | `synced-pattern` | `ref` | the id | `environment-specific` |
| `core/image`, `core/cover`, `core/media-text`, `core/video`, `core/audio`, `core/file` with integer `attrs.id` | `attachment` | `id` | the id | `environment-specific` |
| `core/gallery` with `attrs.ids` list — one reference per id | `gallery` | `ids` | the id | `environment-specific` |
| `core/site-logo` (the id lives in the `site_logo` option, not the markup) | `site-logo` | `site_logo` | `null` from `scan_parsed()`, replaced with the `site_logo` option's integer by `ReferenceResolver` | `environment-specific` |
| any string attribute ending `.woff`, `.woff2`, `.ttf`, `.otf`, or containing `/fonts/` | `font-file` | the attribute name | the string | `environment-specific` |
| any block whose namespace is not `core`, `agency`, or `woocommerce` | `plugin-block` | `blockName` | the block name | `unknown` |
| `attrs.metadata.bindings.*.source === 'core/post-meta'` | `post-meta` | `metadata.bindings.<attr>` | the bound meta key | `resolved` |
| `attrs.metadata.bindings.*.source` other than `core/post-meta` | `post-meta` | `metadata.bindings.<attr>` | the source name | `unknown` |
| any remaining attribute named `ref`, or ending in `Id`, `Ids`, or `_id`, whose value is an int or a numeric string | `unknown-ref` | the attribute name | the value | `unknown` |

Rules: recurse into `innerBlocks`; skip blocks with a null/empty `blockName` (freeform HTML) but still recurse into their inner blocks; `is_unresolved()` is `RESOLUTION_RESOLVED !== $reference['resolution']`.

**Ordering (decision 20).** Attribute order inside one block comes from map traversal, which is not a contract worth depending on for a hashed field. `scan_parsed()` therefore ends by returning `self::sort_references( $found )`, which sorts the whole list with `strcmp` on the tuple `blockName . "\0" . attribute . "\0" . (string) value . "\0" . kind`. The same list order must come out for the same markup on any host and any PHP build. `ReferenceResolver::resolve()` preserves that order (it only fills fields).

The `policy` string per kind (Task 3 surfaces these verbatim in its refusal report):

- navigation: `"Navigation stays database-owned in v1. Promote the template or part without the ref; finalisation must resolve exactly one navigation in the target environment whose normalised content hash matches the exported navigation, and must refuse otherwise (BLOCK_THEME_PROPOSAL.md §7.4)."`
- synced-pattern: `"Synced patterns (wp_block) stay database-owned in v1. Promotion of a record that references one is refused; no ID mapping is invented (BLOCK_THEME_PROPOSAL.md §7.4)."`
- attachment / gallery / site-logo: `"Media IDs are environment-specific. Promotion of a record that references one is refused in v1; export the media reference and remap it manually (BLOCK_THEME_PROPOSAL.md §5.4, §7.4)."`
- font-file: `"Font Library records and files stay database/filesystem-owned in v1. Promotion of a record that references a font file is refused (BLOCK_THEME_PROPOSAL.md §5.4)."`
- plugin-block: `"This block is registered by a plugin outside the core/agency/woocommerce namespaces. Confirm it is registered in the target environment before promoting the record."`
- post-meta (`core/post-meta`): `"Block bindings to core/post-meta travel with the record; no mapping needed."`
- post-meta (other source) and unknown-ref: `"Unrecognised reference. v1 invents no mappings: promotion of this record is refused until the reference is classified (BLOCK_THEME_PROPOSAL.md §7.4)."`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/AgencyPlatform/State/ReferenceScannerTest.php` covering, one test method each: a navigation `ref`; a `core/block` synced-pattern `ref`; a `core/image` id; a `core/gallery` producing one reference per id; a `core/cover` id; a `core/site-logo` with a null value; a font path attribute; a third-party-namespace block; a `core/post-meta` binding classified `resolved`; a bare unknown `someId` attribute classified `unknown`; a reference found inside `innerBlocks` two levels deep; and `unresolved()` returning only the non-`resolved` entries. Build the block trees by hand as the arrays `parse_blocks()` returns:

```php
	/**
	 * The array shape parse_blocks() produces, built by hand so this suite
	 * needs no WordPress. blockName is null for raw HTML, attrs is always an
	 * array, innerBlocks is always a list.
	 *
	 * @param array<string, mixed>       $attrs
	 * @param list<array<string, mixed>> $inner_blocks
	 * @return array<string, mixed>
	 */
	private function block( ?string $name, array $attrs = array(), array $inner_blocks = array() ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	public function test_a_navigation_ref_is_environment_specific(): void {
		$references = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/navigation', array( 'ref' => 12 ) ) ),
			'templates:page'
		);

		self::assertCount( 1, $references );
		self::assertSame( 'templates:page', $references[0]['record'] );
		self::assertSame( 'templates', $references[0]['provider'] );
		self::assertSame( 'core/navigation', $references[0]['blockName'] );
		self::assertSame( 'ref', $references[0]['attribute'] );
		self::assertSame( 12, $references[0]['value'] );
		self::assertSame( ReferenceScanner::KIND_NAVIGATION, $references[0]['kind'] );
		self::assertSame( ReferenceScanner::RESOLUTION_ENVIRONMENT, $references[0]['resolution'] );
		self::assertNotSame( '', $references[0]['policy'] );
		self::assertTrue( ReferenceScanner::is_unresolved( $references[0] ) );

		// scan_parsed() is pure: it always emits the three target fields as
		// null. ReferenceResolver fills them (integration-tested).
		self::assertNull( $references[0]['targetKey'] );
		self::assertNull( $references[0]['targetHash'] );
		self::assertNull( $references[0]['targetIdentity'] );
	}

	public function test_every_reference_carries_the_full_key_set_in_a_fixed_order(): void {
		$references = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/image', array( 'id' => 5 ) ) ),
			'templates:page'
		);

		self::assertSame(
			array( 'record', 'provider', 'blockName', 'attribute', 'value', 'kind', 'resolution', 'policy', 'targetKey', 'targetHash', 'targetIdentity' ),
			array_keys( $references[0] )
		);
	}

	// CORRECTED TWICE, 2026-08-05. Read this before changing the fixture.
	//
	// The ORIGINAL fixture used `customRef`, which matches no matcher at all
	// (the unknown-ref suffixes are Id / Ids / _id), so both orderings emitted
	// ONE identical reference and the assertion was vacuous — it passed
	// against a completely unsorted implementation. Measured: count=1.
	//
	// The FIRST correction proposed `id` + `mediaId` and was ALSO vacuous.
	// `core/cover` is in MEDIA_BLOCKS, so match_block_level() consumes `id`
	// BEFORE the attribute loop runs; `id` is therefore always emitted first
	// no matter how the attribute map is ordered. Proven by neutering
	// sort_references(): that fixture still produced ["id","mediaId"] both
	// ways and the test still passed.
	//
	// BOTH attributes must be emitted from INSIDE the attribute loop for
	// traversal order to matter. `mediaId` and `someId` are both unknown-ref
	// catch-alls, so neither is consumed at block level. Proven with sorting
	// disabled: ["mediaId","someId"] vs ["someId","mediaId"] — genuinely
	// different, so the test now fails when sorting is removed.
	public function test_reference_order_is_independent_of_attribute_map_order(): void {
		$forward = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/cover', array( 'mediaId' => 9, 'someId' => 3 ) ) ),
			'templates:page'
		);
		$reverse = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/cover', array( 'someId' => 3, 'mediaId' => 9 ) ) ),
			'templates:page'
		);

		self::assertCount( 2, $forward, 'A one-reference fixture makes this assertion vacuous.' );
		self::assertSame( array( 'mediaId', 'someId' ), array_column( $forward, 'attribute' ) );
		self::assertSame( $forward, $reverse, 'Attribute traversal order must not change a hashed field.' );
	}

	public function test_a_gallery_emits_one_reference_per_id(): void {
		$references = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/gallery', array( 'ids' => array( 7, 9 ) ) ) ),
			'templates:page'
		);

		self::assertSame( array( 7, 9 ), array_column( $references, 'value' ) );
		self::assertSame( array( 'ids', 'ids' ), array_column( $references, 'attribute' ) );
	}

	public function test_references_are_found_inside_nested_inner_blocks(): void {
		$references = ReferenceScanner::scan_parsed(
			array(
				$this->block(
					'core/group',
					array(),
					array( $this->block( 'core/columns', array(), array( $this->block( 'core/image', array( 'id' => 42 ) ) ) ) )
				),
			),
			'parts:site-header'
		);

		self::assertCount( 1, $references );
		self::assertSame( 42, $references[0]['value'] );
		self::assertSame( ReferenceScanner::KIND_ATTACHMENT, $references[0]['kind'] );
	}

	public function test_a_core_post_meta_binding_is_resolved_and_not_unresolved(): void {
		$references = ReferenceScanner::scan_parsed(
			array(
				$this->block(
					'core/paragraph',
					array( 'metadata' => array( 'bindings' => array( 'content' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'subtitle' ) ) ) ) )
				),
			),
			'content:page-1'
		);

		self::assertSame( ReferenceScanner::KIND_POST_META, $references[0]['kind'] );
		self::assertSame( ReferenceScanner::RESOLUTION_RESOLVED, $references[0]['resolution'] );
		self::assertSame( array(), ReferenceScanner::unresolved( $references ) );
	}
```

Write the remaining test methods in the same shape — one behaviour per method, asserting `kind`, `resolution`, `attribute`, and `value`.

- [ ] **Step 2: Run it and watch it fail**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter ReferenceScannerTest
```

Expected: FAIL — `Class "AgencyPlatform\State\ReferenceScanner" not found`.

- [ ] **Step 3: Write `ReferenceScanner`**

Implement the Interfaces block and the matcher table. **Every method in this task is pure** — no WordPress function call, no `parse_blocks()`, no `get_post()`. Derive `provider` by splitting `$record_key` on the first `:`. `scan_parsed()` emits all eleven reference keys in the documented order and always sets `targetKey`, `targetHash`, and `targetIdentity` to `null`; Task 9's resolver fills them.

Do **not** create `ReferenceResolver.php` and do **not** add `scan()`. Both are Task 9. Task 9's Step 3a carries their full specification, including the recursion guard.

`StateProvider::record()` already declares the `bool $with_references = true` parameter that breaks the resolver's cycle — Task 5 Step 1 puts it in the contract and Task 8's providers implement it. Nothing in this task changes that signature.

- [ ] **Step 4: Run the test to green**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter ReferenceScannerTest
```

Expected: PASS.

- [ ] **Step 5: Run the fast gate and commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/ReferenceScanner.php \
  tests/Unit/AgencyPlatform/State/ReferenceScannerTest.php
git commit -m "feat(state): add the pure block-markup reference scanner"
```

---

### Task 7: Git baseline reader, site identity, and the state directory

**Files:**
- Create: `web/app/mu-plugins/agency-platform/src/State/GitBaseline.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/SiteIdentity.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/StateDirectory.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/SiteUuidSanitizeStep.php`
- Test: `tests/Unit/AgencyPlatform/State/SiteUuidDecisionTest.php`
- Test: `tests/Unit/AgencyPlatform/State/GitBaselineRefsTest.php`
- Test: `tests/Integration/State/SiteUuidSanitizeTest.php`

**Interfaces:**
- Consumes: `EnvironmentConfig`, `StateException`, `Normalizer::normalize_block_markup()`.
- Produces:

```php
final class GitBaseline {
	public const SETTING_REPO_ROOT = 'AGENCY_REPO_ROOT';

	public function __construct( ?string $repo_root = null, ?string $theme_dir = null );

	public function repo_root(): string;   // AGENCY_REPO_ROOT, else autodetected upwards from ABSPATH
	public function theme_dir(): string;   // get_stylesheet_directory()
	public function head_commit(): ?string; // 40-char sha; null when there is no readable git metadata
	public function has_block_templates(): bool;

	/** The resolved git metadata directory for THIS checkout, or null. Worktree-aware. */
	public function git_dir(): ?string;
	/** The shared metadata directory (packed-refs, main refs), or null. Equals git_dir() outside a worktree. */
	public function git_common_dir(): ?string;
	/** Pure: parses a HEAD file's contents into either a sha or a ref name. @return array{sha: string|null, ref: string|null} */
	public static function parse_head( string $head_contents ): array;
	/** Pure: finds a ref's sha in packed-refs contents. */
	public static function parse_packed_refs( string $packed_refs, string $ref ): ?string;

	/** @return array<string, string> slug => normalised block markup, sorted by slug. */
	public function template_markup(): array;
	/** @return array<string, string> slug => normalised block markup, sorted by slug. */
	public function part_markup(): array;
	/** @return array<string, mixed>|null Decoded theme.json, null when unreadable. */
	public function theme_json(): ?array;
}

final class SiteIdentity {
	public const OPTION_UUID        = 'agency_platform_site_uuid';
	public const OPTION_ENVIRONMENT = 'agency_platform_site_uuid_environment';

	public static function uuid(): string;              // mints and stores on first read
	public static function uuid_environment(): ?string;
	public static function regenerate(): string;        // fresh uuid, stamped with the current environment
}

final class SiteUuidSanitizeStep {
	public function register(): void;                                   // adds the agency_platform_sanitize_steps filter
	/** @param array<string, callable> $steps @return array<string, callable> */
	public function append_step( array $steps ): array;                 // $steps['site_uuid'] = …
	/** @param array<string, mixed> $options @return list<string> */
	public static function sanitize_site_uuid( array $options ): array;
	/** Pure decision. */
	public static function should_regenerate( ?string $marker, string $environment ): bool;
}
```

**`should_regenerate()` truth table** (spec §7.7 — idempotent, so `wp agency sanitize` keeps its idempotency contract):

| stored marker | current environment | regenerate? | why |
|---|---|---|---|
| `null` (no UUID stored yet) | anything | `false` | nothing to regenerate; `uuid()` will mint one stamped with the current environment |
| `'production'` | `'production'` | `false` | production keeps its own UUID |
| `'production'` | anything else | **`true`** | a production UUID has landed outside production — the cloned-database case §7.7 describes |
| `''` (UUID present, marker missing — a pre-upgrade row) | anything | **`true`** | provenance unknown; regenerate once, then the marker makes it idempotent |
| any non-production marker | anything | `false` | already minted outside production; a second sanitize run must change nothing |

- [ ] **Step 1: Write the failing unit test for the decision**

Create `tests/Unit/AgencyPlatform/State/SiteUuidDecisionTest.php` with one test method per row of the truth table above, plus an explicit idempotency test:

```php
	public function test_a_production_uuid_seen_in_development_is_regenerated(): void {
		self::assertTrue( SiteUuidSanitizeStep::should_regenerate( 'production', 'development' ) );
	}

	public function test_a_second_run_after_regeneration_changes_nothing(): void {
		self::assertTrue( SiteUuidSanitizeStep::should_regenerate( 'production', 'staging' ) );
		self::assertFalse( SiteUuidSanitizeStep::should_regenerate( 'staging', 'staging' ) );
	}

	public function test_production_keeps_its_own_uuid(): void {
		self::assertFalse( SiteUuidSanitizeStep::should_regenerate( 'production', 'production' ) );
	}

	public function test_a_missing_marker_on_an_existing_uuid_is_regenerated_once(): void {
		self::assertTrue( SiteUuidSanitizeStep::should_regenerate( '', 'development' ) );
	}

	public function test_no_stored_uuid_means_nothing_to_regenerate(): void {
		self::assertFalse( SiteUuidSanitizeStep::should_regenerate( null, 'development' ) );
	}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter SiteUuidDecisionTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write `GitBaseline`**

Notes that matter:

- `repo_root()`: use `EnvironmentConfig::get( self::SETTING_REPO_ROOT )` when set. Otherwise walk up from `ABSPATH` at most six levels and return the first directory containing both `composer.json` and a `web` directory; fall back to `dirname( ABSPATH, 2 )` (Bedrock puts core at `<root>/web/wp/`). Always return a forward-slash path with no trailing slash.
- `theme_dir()`: the constructor argument when given, else `get_stylesheet_directory()`. **Never hard-code `web/app/themes/site-theme`** — Task 1's merged structure and a later client rename must keep working.
- **`git_dir()` must be worktree-aware.** The fixed branch model for this whole migration is one **git worktree per task**, and inside a worktree `<repo_root>/.git` is a **file**, not a directory, containing `gitdir: /abs/path/to/main/.git/worktrees/<name>`. A directory-only reader returns `null` for every commit on every task branch. Algorithm:
  1. `$candidate = $this->repo_root() . '/.git'`.
  2. `is_dir( $candidate )` → that is both `git_dir()` and `git_common_dir()`.
  3. `is_file( $candidate )` → read it, match `/^gitdir:\s*(.+)$/m`, trim. Resolve a relative path against `repo_root()`. That is `git_dir()`.
  4. `git_common_dir()` = the contents of `<git_dir>/commondir` (trimmed, resolved relative to `git_dir()`) when that file exists, else `git_dir()`.
  5. Neither → `null`.
- `head_commit()`: read `<git_dir()>/HEAD` with `file_get_contents` (phpcs:ignore + reason) and pass it to `parse_head()`. A detached HEAD yields a sha directly. A `ref: refs/heads/x` yields a ref name; look it up in this order: `<git_dir()>/<ref>` (loose ref in this worktree), then `<git_common_dir()>/<ref>` (loose ref in the shared dir), then `parse_packed_refs()` over `<git_common_dir()>/packed-refs`. Return the 40-hex sha or `null`. **Never shell out** — `proc_open`/`exec` are disabled on many production hosts, and this must work there.
- `parse_head()` and `parse_packed_refs()` are pure so the four cases below are unit-testable with fixture strings rather than a real repository. `parse_packed_refs()` must ignore comment lines (`#`) and peeled-tag lines (`^`).
- `template_markup()` / `part_markup()`: `glob( $dir . '/templates/*.html' )` / `glob( $dir . '/parts/*.html' )`, keyed by basename without the extension, value passed through `Normalizer::normalize_block_markup()`. Sort by key with `ksort`. **An empty result is normal and not an error** — the pre-Task-1 hybrid theme has no `.html` templates, and `state-diff` must still work there (every DB template then reads as `added`).
- `has_block_templates()`: `is_file( $this->theme_dir() . '/templates/index.html' )` — the spec's own block-theme test (§5.1).
- `theme_json()`: decode `<theme_dir>/theme.json`; `null` when missing or malformed.

- [ ] **Step 4: Write `SiteIdentity`**

```php
	public static function uuid(): string {
		$stored = get_option( self::OPTION_UUID, '' );

		if ( is_string( $stored ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $stored ) ) {
			return $stored;
		}

		return self::regenerate();
	}

	public static function regenerate(): string {
		$uuid = wp_generate_uuid4();

		// Non-autoloaded: read only by CLI state/promotion commands, never on
		// a front-end request.
		update_option( self::OPTION_UUID, $uuid, false );
		update_option( self::OPTION_ENVIRONMENT, wp_get_environment_type(), false );

		return $uuid;
	}
```

`uuid_environment()` returns `get_option( self::OPTION_ENVIRONMENT, '' )` as a string, or `null` when `OPTION_UUID` itself is absent (so `should_regenerate()` can tell "no UUID at all" from "UUID with no marker").

- [ ] **Step 5: Write `SiteUuidSanitizeStep`**

`register()` is one named-method `add_filter` — no closures (§4):

```php
	public function register(): void {
		add_filter( 'agency_platform_sanitize_steps', array( $this, 'append_step' ) );
	}

	public function append_step( array $steps ): array {
		$steps['site_uuid'] = array( self::class, 'sanitize_site_uuid' );

		return $steps;
	}
```

`sanitize_site_uuid()` returns exactly one WP-CLI summary line, matching the existing steps' style:

- no stored UUID → `'Site UUID: none stored; nothing to regenerate.'`
- regenerated → `sprintf( 'Site UUID: regenerated (a "%s" UUID was found in the "%s" environment).', $marker, $environment )`
- preserved → `sprintf( 'Site UUID: preserved (already minted in the "%s" environment).', $environment )`

- [ ] **Step 5b: Write the Git-reference unit test**

Create `tests/Unit/AgencyPlatform/State/GitBaselineRefsTest.php`. It builds fake `.git` trees under `sys_get_temp_dir()` so all four layouts the branch model can produce are covered, and unit-tests the two pure parsers directly:

```php
	public function test_parse_head_reads_a_detached_head_sha(): void {
		self::assertSame(
			array( 'sha' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678', 'ref' => null ),
			GitBaseline::parse_head( "a1b2c3d4e5f60718293a4b5c6d7e8f9012345678\n" )
		);
	}

	public function test_parse_head_reads_a_symbolic_ref(): void {
		self::assertSame(
			array( 'sha' => null, 'ref' => 'refs/heads/feat/bt-task-2-state-export-diff' ),
			GitBaseline::parse_head( "ref: refs/heads/feat/bt-task-2-state-export-diff\n" )
		);
	}

	public function test_parse_packed_refs_finds_a_branch_and_ignores_comments_and_peeled_tags(): void {
		$packed = "# pack-refs with: peeled fully-peeled sorted \n"
			. "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa refs/heads/main\n"
			. "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb refs/tags/v1\n"
			. "^cccccccccccccccccccccccccccccccccccccccc\n";

		self::assertSame( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', GitBaseline::parse_packed_refs( $packed, 'refs/heads/main' ) );
		self::assertNull( GitBaseline::parse_packed_refs( $packed, 'refs/heads/missing' ) );
	}

	public function test_a_plain_git_directory_with_a_loose_ref_resolves(): void {
		$root = $this->make_repo( array(
			'.git/HEAD'             => "ref: refs/heads/main\n",
			'.git/refs/heads/main'  => "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
		) );

		self::assertSame( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ( new GitBaseline( $root ) )->head_commit() );
	}

	public function test_a_packed_ref_resolves_when_no_loose_ref_exists(): void {
		$root = $this->make_repo( array(
			'.git/HEAD'        => "ref: refs/heads/main\n",
			'.git/packed-refs' => "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa refs/heads/main\n",
		) );

		self::assertSame( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ( new GitBaseline( $root ) )->head_commit() );
	}

	public function test_a_worktree_gitdir_file_resolves_through_commondir(): void {
		// The layout the migration's own branch model produces.
		$root = $this->make_repo( array(
			'main/.git/packed-refs'                              => "dddddddddddddddddddddddddddddddddddddddd refs/heads/feat/bt-task-2\n",
			'main/.git/worktrees/task-2/HEAD'                    => "ref: refs/heads/feat/bt-task-2\n",
			'main/.git/worktrees/task-2/commondir'               => "../..\n",
			'wt/.git'                                            => "gitdir: {ROOT}/main/.git/worktrees/task-2\n",
		) );

		$baseline = new GitBaseline( $root . '/wt' );

		self::assertSame( $root . '/main/.git/worktrees/task-2', $baseline->git_dir() );
		self::assertSame( $root . '/main/.git', $baseline->git_common_dir() );
		self::assertSame( 'dddddddddddddddddddddddddddddddddddddddd', $baseline->head_commit() );
	}

	public function test_a_detached_head_in_a_worktree_resolves(): void {
		$root = $this->make_repo( array(
			'main/.git/worktrees/task-2/HEAD'      => "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee\n",
			'main/.git/worktrees/task-2/commondir' => "../..\n",
			'wt/.git'                              => "gitdir: {ROOT}/main/.git/worktrees/task-2\n",
		) );

		self::assertSame( 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', ( new GitBaseline( $root . '/wt' ) )->head_commit() );
	}

	public function test_a_checkout_with_no_git_metadata_reports_null(): void {
		self::assertNull( ( new GitBaseline( $this->make_repo( array( 'composer.json' => '{}' ) ) ) )->head_commit() );
	}
```

`make_repo( array $files ): string` creates a unique temporary directory, writes each file (creating parent directories, replacing the literal `{ROOT}` token with the temporary directory's absolute path), registers a `tear_down` cleanup, and returns the path. The `test_a_checkout_with_no_git_metadata_reports_null` case is the production-host case (§7.7 transports manifests precisely because `.git` may be absent there).

- [ ] **Step 6: Create `StateDirectory`**

Create `web/app/mu-plugins/agency-platform/src/State/StateDirectory.php`:

```php
<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * Resolves and creates the protected state-artifact directory.
 */
final class StateDirectory {

	public const SETTING = 'AGENCY_STATE_DIR';

	private function __construct() {
		// Static-only utility class.
	}

	public static function path(): string {
		$repo_root  = ( new GitBaseline() )->repo_root();
		$configured = EnvironmentConfig::get( self::SETTING );

		if ( null === $configured ) {
			return $repo_root . '/var/agency-state';
		}

		$configured = rtrim( str_replace( '\\', '/', $configured ), '/' );

		if ( self::is_absolute( $configured ) ) {
			return $configured;
		}

		return $repo_root . '/' . ltrim( $configured, '/' );
	}

	public static function ensure(): string {
		$path = self::path();

		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			throw StateException::hard_error( sprintf( 'Could not create the state directory "%s". Set %s to a writable path.', $path, self::SETTING ) );
		}

		return $path;
	}

	private static function is_absolute( string $path ): bool {
		return str_starts_with( $path, '/' ) || 1 === preg_match( '#^[A-Za-z]:/#', $path );
	}
}
```

`GitBaseline` now exists, so `analyse` stays green. The default directory is outside `web/`. Relative overrides resolve against the repository root, not the current working directory.

- [ ] **Step 7: Run the unit test to green**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter SiteUuidDecisionTest
ddev exec vendor/bin/phpunit --testsuite unit --filter GitBaselineRefsTest
```

Expected: PASS (5 tests, then 8 tests).

- [ ] **Step 8: Write the integration test**

Create `tests/Integration/State/SiteUuidSanitizeTest.php`:

```php
<?php
/**
 * BLOCK_THEME_PROPOSAL.md §7.7: a production database cloned to staging or
 * local development carries the SAME agency_platform_site_uuid, which
 * weakens the AGENCY_TARGET_SITE_UUID protection promotion relies on. The
 * sanitize step regenerates it — but idempotently, so `wp agency sanitize`
 * keeps the idempotency contract every other step honours.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

use AgencyPlatform\Health\SanitizeSteps;
use AgencyPlatform\State\SiteIdentity;
use AgencyPlatform\State\SiteUuidSanitizeStep;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\SiteUuidSanitizeStep
 * @covers \AgencyPlatform\State\SiteIdentity
 */
final class SiteUuidSanitizeTest extends IntegrationTestCase {

	public function test_a_production_uuid_seen_outside_production_is_regenerated_once(): void {
		update_option( SiteIdentity::OPTION_UUID, '11111111-2222-4333-8444-555566667777', false );
		update_option( SiteIdentity::OPTION_ENVIRONMENT, 'production', false );

		SiteUuidSanitizeStep::sanitize_site_uuid( array() );

		$after_first = get_option( SiteIdentity::OPTION_UUID );

		self::assertNotSame( '11111111-2222-4333-8444-555566667777', $after_first, 'A production UUID must not survive into a non-production environment.' );
		self::assertSame( wp_get_environment_type(), get_option( SiteIdentity::OPTION_ENVIRONMENT ), 'The marker must record the environment the UUID was minted in.' );

		SiteUuidSanitizeStep::sanitize_site_uuid( array() );

		self::assertSame( $after_first, get_option( SiteIdentity::OPTION_UUID ), 'A second sanitize run must preserve the local UUID — sanitize is idempotent.' );
	}

	public function test_a_locally_minted_uuid_is_preserved(): void {
		$uuid = SiteIdentity::uuid();

		SiteUuidSanitizeStep::sanitize_site_uuid( array() );

		self::assertSame( $uuid, get_option( SiteIdentity::OPTION_UUID ) );
	}

	public function test_the_step_is_registered_on_the_sanitize_registry(): void {
		( new SiteUuidSanitizeStep() )->register();

		self::assertArrayHasKey( 'site_uuid', SanitizeSteps::steps(), 'The site-UUID step must reach `wp agency sanitize` through the agency_platform_sanitize_steps filter, not by editing AgencyCommands.' );
	}

	public function test_the_site_uuid_option_is_not_autoloaded(): void {
		SiteIdentity::uuid();

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only read of the autoload column, which no WordPress API exposes.
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", SiteIdentity::OPTION_UUID ) );

		self::assertNotSame( 'yes', $autoload, 'The site UUID is read only by CLI state commands; it must never be autoloaded on front-end requests.' );
	}
}
```

- [ ] **Step 9: Run the integration suite**

```bash
ddev composer test:integration
```

Expected: PASS. If `test_the_site_uuid_option_is_not_autoloaded` fails because the installed WordPress writes `off`/`auto` instead of `no`, keep the assertion as `assertNotSame( 'yes', … )` — that is why it is written negatively.

- [ ] **Step 10: Run the fast gate and commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/GitBaseline.php \
  web/app/mu-plugins/agency-platform/src/State/SiteIdentity.php \
  web/app/mu-plugins/agency-platform/src/State/StateDirectory.php \
  web/app/mu-plugins/agency-platform/src/State/SiteUuidSanitizeStep.php \
  tests/Unit/AgencyPlatform/State/SiteUuidDecisionTest.php \
  tests/Unit/AgencyPlatform/State/GitBaselineRefsTest.php \
  tests/Integration/State/SiteUuidSanitizeTest.php
git commit -m "feat(state): add git baseline reading and idempotent site-uuid sanitize"
```

---

### Task 8: Registry and the three Git-backed providers

**Files:**
- Create: `web/app/mu-plugins/agency-platform/src/State/StateRegistry.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/Providers/TemplatesState.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/Providers/TemplatePartsState.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/Providers/GlobalStylesState.php`
- Test: `tests/Unit/AgencyPlatform/State/StateRegistryResolveTest.php`
- Create: `tests/Integration/State/SeedsStateFixtures.php` (shared fixture trait, reused by Tasks 9, 11, 13)
- Test: `tests/Integration/State/ProviderRecordsTest.php` (created here, extended in Task 9)

**Interfaces:**
- Consumes: `StateProvider`, `StateRecord`, `Ownership`, `PromotionPolicy`, `Normalizer`, `ReferenceScanner`, `GitBaseline`.
- Produces:

```php
final class StateRegistry {
	public const FILTER = 'agency_platform_state_providers';

	/**
	 * The structural default set from BLOCK_THEME_PROPOSAL.md §6, declared in
	 * the canonical ASCENDING SLUG order this subsystem uses everywhere
	 * (registry, resolver, bundle providers map, tests). The master spec lists
	 * the same eight slugs in reading order; that is informational prose, not
	 * the wire order.
	 *
	 * @var list<string>
	 */
	public const STRUCTURAL_SLUGS = array( 'custom-css', 'fonts', 'global-styles', 'media-references', 'navigation', 'synced-patterns', 'template-parts', 'templates' );

	/** @return array<string, StateProvider> Keyed by slug, sorted by slug. */
	public static function providers(): array;
	public static function provider( string $slug ): ?StateProvider;
	/** @return list<string> */
	public static function slugs(): array;
	/**
	 * @param string|null $providers_option The raw --providers value, or null.
	 * @return list<string> Sorted, de-duplicated provider slugs.
	 * @throws StateException Exit 1 on an unknown slug or an empty result.
	 */
	public static function resolve( ?string $providers_option, bool $include_content ): array;
	/** Test seam: drops the memoised provider map. */
	public static function reset(): void;
}
```

`providers()` builds the built-in map, passes it through `apply_filters( self::FILTER, $providers )`, then: rejects any value that is not a `StateProvider` with `StateException::hard_error()` naming the offending key; re-keys by `$provider->slug()`; `ksort`s; memoises. The class docblock must state that project plugins add providers here without modifying `agency-platform` (spec §7.1), and that registrants must supply named classes, never closures.

`resolve()` rules:
- `null` → every registered provider whose `includes_content()` is `false` (so a third-party structural provider is exported by default too). Add `+ content providers` when `$include_content` is true.
- A non-null list → exactly those slugs, whitespace-trimmed, de-duplicated, sorted. An explicitly named content provider is included **even without `--include-content`** (explicit beats default). An unknown slug throws, and the message lists every valid slug.
- An empty result throws.

**Provider specifications**

**The `wp_theme` taxonomy query — get this exactly right.** WordPress's `tax_query` defaults `field` to `term_id`. Passing a stylesheet *slug* without `'field' => 'slug'` silently matches nothing, so `templates`, `template-parts`, and `global-styles` would all export zero records and every diff would look clean. Every affected query uses this block verbatim:

```php
			'tax_query'      => array(
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'slug',
					'terms'    => get_stylesheet(),
				),
			),
```

`TemplatesState` — slug `templates`:
- ownership `Ownership::GIT_BASELINE_PLUS_DB`, promotion `PromotionPolicy::PROMOTABLE`, `includes_content()` false, `has_git_baseline()` true.
- `records()`: `get_posts()` on post type `wp_template`, `post_status => array( 'publish', 'draft', 'private' )`, `posts_per_page => -1`, `no_found_rows => true`, `orderby => 'ID'`, plus the `tax_query` block above. Record slug = `post_name`; skip rows with an empty `post_name`. Content = `Normalizer::normalize_content( array( 'markup' => Normalizer::normalize_block_markup( $post->post_content ) ) )` — **markup only** (plan decision 3). `modified_gmt` = `$post->post_modified_gmt`. References = `ReferenceScanner::scan( $post->post_content, $key )`.
- `baseline_records()`: one record per entry of `GitBaseline::template_markup()`, `object_id` null, status `'baseline'`, `modified_gmt` null, same content shape.
- `record( string $key )`: split the key, re-run the same query filtered to that `post_name`, return the single record or null.
- `validate()`: returns `'Block markup does not round-trip through the WordPress parser.'` when `Normalizer::normalize_block_markup( $record->content()['markup'] ) !== $record->content()['markup']`; otherwise an empty list.

`TemplatePartsState` — slug `template-parts`: identical to `TemplatesState` but post type `wp_template_part` and baseline from `GitBaseline::part_markup()`.

`GlobalStylesState` — slug `global-styles`:
- ownership `Ownership::GIT_BASELINE_PLUS_DB_USER_ORIGIN`, promotion `PromotionPolicy::PROMOTABLE`, `has_git_baseline()` true.
- `records()`: `get_posts()` on post type `wp_global_styles`, statuses `publish` + `draft`, plus the `tax_query` block above. **Do not use `WP_Theme_JSON_Resolver`** — it is an internal Core API and belongs behind Task 3's adapter (spec §7.6). Exactly one record, slug `active` (so §6's `global-styles:active` selector resolves).
- **Content normalisation, in this exact order** (decision 5). Getting any step wrong makes a normal, uncustomised site report permanent drift:
  1. `json_decode( $post->post_content, true )`; a missing/blank/non-array result yields `array()`.
  2. **Strip both bookkeeping keys — `version` AND `isGlobalStylesUserThemeJSON`.** They are WordPress's own markers, present on every row including a brand-new one, and neither expresses user intent. Keeping `version` alone would make every uncustomised row differ from the empty baseline.
  3. **Strip every `css` key at every depth** (`styles.css`, `styles.blocks.<block>.css`, `styles.elements.<element>.css`). Additional CSS is the `custom-css` provider's record; leaving it here would classify the same bytes as both `promotable` and `forbidden` and would make a CSS-only edit look like a promotable Global Styles change.
  4. `Normalizer::prune_empty()` then `Normalizer::normalize_content()`.
  An uncustomised row therefore normalises to `array()`, exactly matching the baseline.
- `baseline_records()`: exactly one synthetic record, slug `active`, `object_id` null, status `'baseline'`, `content = array()` — the empty user origin (plan decision 5). This is why a customised Global Styles row reads as `changed` → drift → `promotable`, reproducing today's `DatabaseOverrideCheck` semantics through the generic differ, while an untouched site reads `unchanged`.
- References: `ReferenceScanner::scan_parsed( array(), $key )` is not meaningful here; instead scan the decoded content for font-file strings by walking it and emitting a `KIND_FONT_FILE` reference for every string value matching the font-path matcher. Implement that walk as a private method on this provider.
- `validate()`: returns `'Global Styles content is not valid JSON.'` when the raw `post_content` was non-blank but did not decode to an array.

- [ ] **Step 1: Write the failing registry unit test**

Create `tests/Unit/AgencyPlatform/State/StateRegistryResolveTest.php`. It must not touch the database, so it exercises `resolve()` only — provider constructors must therefore be side-effect free (no queries, no option reads):

```php
	protected function setUp(): void {
		parent::setUp();

		StateRegistry::reset();
		$GLOBALS['_test_filters'] = array();
	}

	public function test_the_default_set_is_the_structural_providers(): void {
		// Task 8 ships only these three providers. Task 9 restores the full
		// STRUCTURAL_SLUGS assertion after it registers the other five
		// structural providers.
		//
		// Every `content` assertion also lives in Task 9, not here. The
		// `content` provider is ContentState, which Task 9 creates, and
		// resolve() validates a named slug against the REGISTERED providers,
		// so `resolve( 'content', … )` is a hard error in Task 8 by design.
		self::assertSame( array( 'global-styles', 'template-parts', 'templates' ), StateRegistry::resolve( null, false ) );
	}

	public function test_the_default_set_never_contains_content(): void {
		self::assertNotContains( 'content', StateRegistry::resolve( null, false ) );
	}

	public function test_an_explicit_list_narrows_the_export(): void {
		self::assertSame( array( 'global-styles', 'templates' ), StateRegistry::resolve( 'templates, global-styles', false ) );
	}

	public function test_an_unknown_slug_is_a_hard_error_listing_the_valid_slugs(): void {
		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/nonsense.*templates/s' );

		StateRegistry::resolve( 'nonsense', false );
	}

	public function test_an_empty_selection_is_a_hard_error(): void {
		$this->expectException( StateException::class );

		StateRegistry::resolve( '  ,  ', false );
	}

	public function test_a_filtered_provider_joins_the_registry(): void {
		add_filter( StateRegistry::FILTER, array( self::class, 'append_fake_provider' ) );
		StateRegistry::reset();

		self::assertContains( 'fake', StateRegistry::slugs() );
		self::assertContains( 'fake', StateRegistry::resolve( null, false ) );
	}

	public function test_a_non_provider_value_from_the_filter_is_rejected(): void {
		add_filter( StateRegistry::FILTER, array( self::class, 'append_garbage' ) );
		StateRegistry::reset();

		$this->expectException( StateException::class );

		StateRegistry::providers();
	}
```

`append_fake_provider()` adds an instance of a small `Tests\Unit\AgencyPlatform\State\Doubles\FakeStateProvider` (create it under `tests/Unit/AgencyPlatform/State/Doubles/FakeStateProvider.php`, mirroring the existing `tests/Unit/SiteIntegrations/Doubles/` convention) whose `slug()` is `'fake'`, `includes_content()` is `false`, and whose `records()`/`baseline_records()` return `array()`. `append_garbage()` sets `$steps['broken'] = 'not-a-provider'`.

- [ ] **Step 2: Run it and watch it fail**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter StateRegistryResolveTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write `StateRegistry` and the three providers**

Build the registry with only these three providers — `STRUCTURAL_SLUGS` already names all eight, so `resolve( null, false )` must **not** read from that constant. `resolve()` derives the default set from the **registered** providers (`includes_content() === false`), sorted ascending; `STRUCTURAL_SLUGS` is the documented expectation the unit test checks once all eight exist. **In this task, temporarily assert the three implemented slugs in canonical order** — `array( 'global-styles', 'template-parts', 'templates' )` — and change the assertion to `StateRegistry::STRUCTURAL_SLUGS` in Task 9 Step 4. Note this explicitly in the test's docblock so the next reader is not confused.

Add one more registry unit test that pins the canonical ordering contract (decision 20):

```php
	public function test_structural_slugs_is_declared_in_canonical_ascending_order(): void {
		$sorted = StateRegistry::STRUCTURAL_SLUGS;
		sort( $sorted, SORT_STRING );

		self::assertSame( $sorted, StateRegistry::STRUCTURAL_SLUGS, 'The constant must already be in the order resolve() returns.' );
	}
```

- [ ] **Step 4: Run the registry unit test to green**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter StateRegistryResolveTest
```

Expected: PASS.

- [ ] **Step 5: Write the provider integration test**

Create `tests/Integration/State/ProviderRecordsTest.php` with, for each of the three providers: a seeded fixture, an assertion on the record key/slug/ownership/promotion, an assertion that markup is normalised (create the fixture with `\r\n` line endings and odd spacing, assert the exported markup is LF-only and byte-identical to `serialize_blocks( parse_blocks( … ) )`), and an assertion on references. Concretely, at minimum:

```php
	public function test_a_template_override_becomes_a_promotable_record(): void {
		$this->make_template( 'page', "<!-- wp:paragraph -->\r\n<p>Live edit</p>\r\n<!-- /wp:paragraph -->" );

		$records = ( new TemplatesState() )->records();

		self::assertCount( 1, $records );
		self::assertSame( 'templates:page', $records[0]->key() );
		self::assertSame( Ownership::GIT_BASELINE_PLUS_DB, $records[0]->ownership() );
		self::assertSame( PromotionPolicy::PROMOTABLE, $records[0]->promotion() );
		self::assertStringNotContainsString( "\r", $records[0]->content()['markup'] );
		self::assertSame( array( 'markup' ), array_keys( $records[0]->content() ) );
	}

	public function test_a_template_records_its_navigation_reference(): void {
		$this->make_template( 'home', '<!-- wp:navigation {"ref":31} /-->' );

		$references = ( new TemplatesState() )->record( 'templates:home' )->references();

		self::assertSame( ReferenceScanner::KIND_NAVIGATION, $references[0]['kind'] );
		self::assertSame( 31, $references[0]['value'] );
	}

	public function test_an_uncustomised_global_styles_row_matches_its_empty_baseline(): void {
		$this->make_global_styles( '{"version":3,"isGlobalStylesUserThemeJSON":true}' );

		$provider = new GlobalStylesState();

		self::assertSame( array(), $provider->records()[0]->content() );
		self::assertSame( $provider->baseline_records()[0]->content_hash(), $provider->records()[0]->content_hash() );
	}

	public function test_a_customised_global_styles_row_differs_from_its_baseline(): void {
		$this->make_global_styles( '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"color":{"background":"var(--wp--preset--color--base)"}}}' );

		$provider = new GlobalStylesState();

		self::assertNotSame( $provider->baseline_records()[0]->content_hash(), $provider->records()[0]->content_hash() );
	}

	public function test_global_styles_strips_custom_css_so_it_is_not_both_promotable_and_forbidden(): void {
		$this->make_global_styles( '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"css":"body{color:red}","blocks":{"core/group":{"css":"padding:0"}}}}' );

		$provider = new GlobalStylesState();

		self::assertSame( array(), $provider->records()[0]->content(), 'CSS-only edits belong to the custom-css provider; Global Styles must read as unchanged.' );
		self::assertSame( $provider->baseline_records()[0]->content_hash(), $provider->records()[0]->content_hash() );
	}

	public function test_the_theme_taxonomy_query_uses_the_stylesheet_slug(): void {
		// A wp_template row belonging to ANOTHER theme must never be exported.
		$foreign_id = self::factory()->post->create( array( 'post_type' => 'wp_template', 'post_name' => 'page', 'post_status' => 'publish' ) );
		wp_set_object_terms( $foreign_id, 'some-other-theme', 'wp_theme' );

		self::assertSame( array(), ( new TemplatesState() )->records(), 'Without field => slug the tax_query matches nothing at all; with it, only the active theme matches.' );

		$this->make_template( 'page', '<!-- wp:paragraph --><p>Ours</p><!-- /wp:paragraph -->' );

		self::assertSame( array( 'templates:page' ), array_map( static fn ( $record ) => $record->key(), ( new TemplatesState() )->records() ) );
	}
```

Put `make_template()`, `make_part()`, `make_navigation()`, `make_global_styles()`, and `entry()` in a shared trait at `tests/Integration/State/SeedsStateFixtures.php` (namespace `Tests\Integration\State`) and `use` it from this class — Tasks 9, 11, and 13 all need the same fixtures. Do **not** put it under `tests/support/`, which Task 1 also edits. Each `make_*()` creates the post with `self::factory()->post->create()` using the right post type and `post_name`, then attaches the theme term with `wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' )` for the `wp_template`, `wp_template_part`, and `wp_global_styles` types.

- [ ] **Step 6: Run the integration suite**

```bash
ddev composer test:integration
```

Expected: PASS.

- [ ] **Step 7: Run the fast gate and commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/StateRegistry.php \
  web/app/mu-plugins/agency-platform/src/State/Providers/ \
  tests/Unit/AgencyPlatform/State/StateRegistryResolveTest.php \
  tests/Unit/AgencyPlatform/State/Doubles/FakeStateProvider.php \
  tests/Integration/State/SeedsStateFixtures.php \
  tests/Integration/State/ProviderRecordsTest.php
git commit -m "feat(state): add the provider registry and the git-backed providers"
```

---

### Task 9: The six database-owned providers

**Files:**
- Create: `web/app/mu-plugins/agency-platform/src/State/Providers/NavigationState.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/Providers/SyncedPatternsState.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/Providers/ContentState.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/Providers/FontLibraryState.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/Providers/MediaReferencesState.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/Providers/CustomCssState.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/ReferenceResolver.php` (moved here from Task 6 — see Step 3a)
- Modify: `web/app/mu-plugins/agency-platform/src/State/ReferenceScanner.php` (add `scan()` only)
- **Modify: `web/app/mu-plugins/agency-platform/src/State/BaseStateProvider.php` (restore `detect_references()` — see Step 3c. NON-OPTIONAL.)**
- Modify: `web/app/mu-plugins/agency-platform/src/State/StateRegistry.php` (register the six)
- Modify: `tests/Unit/AgencyPlatform/State/StateRegistryResolveTest.php` (Task 8 Step 3's temporary assertion, plus the two `content` cases)
- Modify: `tests/Integration/State/ProviderRecordsTest.php`
- Test: `tests/Integration/State/ReferenceResolverTest.php` (new — the navigation provider now exists, so targets are resolvable)

**Interfaces:**
- Consumes: everything from Task 8, plus `ReferenceScanner` from Task 6.
- Produces: six more `StateProvider` implementations, `ReferenceResolver`, and `ReferenceScanner::scan()`. No other new public API.

**Provider specifications.** Five of the six are database-owned with no Git counterpart: `has_git_baseline()` returns `false` and `baseline_records()` returns `array()`. **`CustomCssState` is the exception** — see its row and the hard rules below.

| Class | slug | ownership | promotion | source | record slug | content |
|---|---|---|---|---|---|---|
| `NavigationState` | `navigation` | `DATABASE` | `EXPORT_AND_DIFF` | `wp_navigation`, statuses publish/draft | `post_name` (fall back to `nav-<ID>` when blank) | `array('markup' => normalised, 'title' => post_title)` |
| `SyncedPatternsState` | `synced-patterns` | `DATABASE` | `EXPORT_AND_DIFF` | `wp_block`, statuses publish/draft | `post_name` (fall back to `pattern-<ID>`) | `array('markup' => normalised, 'title' => post_title, 'syncStatus' => the `wp_pattern_sync_status` meta or '')` |
| `ContentState` | `content` | `DATABASE` | `NEVER_PROMOTE` | every `get_post_types( array( 'public' => true ), 'names' )` except `attachment`; statuses publish/draft/pending/private/future | `<post_type>-<ID>` | `array('postType','title','status','slug','parent','menuOrder','pageTemplate','authorId','hasPassword','markup')` |
| `FontLibraryState` | `fonts` | `DATABASE_PLUS_UPLOADS` | `EXPORT_AND_DIFF` | `wp_font_family` + `wp_font_face`, status publish | `<post_type>-<ID>` | `array('postType','title','settings' => decoded post_content)` |
| `MediaReferencesState` | `media-references` | `DATABASE_PLUS_UPLOADS` | `NEVER_PROMOTE` | attachments referenced by templates/parts/navigation/synced patterns + the `site_logo` option | `attachment-<ID>` | `array('mimeType','relativePath','width','height','title')` |
| `CustomCssState` | `custom-css` | `FORBIDDEN` | `REFUSE` | Global Styles CSS **and** the `custom_css` post type | `global-styles`, `custom-css-post` | `array('source','css','length')` — **`has_git_baseline()` is `true`; baseline is the same two records with `css => ''`** |

Hard rules for these six:

- **`ContentState` is the only provider with `includes_content() === true`.**
- **`ContentState` must never export a password, an email address, a session token, or an application password** (spec §7.3). Export `authorId` (an integer) but never the author's email; export `hasPassword` (a boolean) but never `post_password`. There is no other user-identifying field in the exported shape.
- `MediaReferencesState` scans a **fixed** source set — `wp_template`, `wp_template_part`, `wp_navigation`, `wp_block` markup plus `get_option( 'site_logo' )` — so it is independent of `--include-content` and of registry ordering (plan decision 15). Read metadata with `get_post_mime_type()` and `wp_get_attachment_metadata()`; `relativePath` is the metadata's `file` key. Never read or hash the file bytes.
- `CustomCssState` detects **both** §11.10 locations:
  1. Global Styles: decode the active theme's `wp_global_styles` `post_content` and collect every non-empty value under a `css` key at any depth (WordPress nests custom CSS under `styles.css` and under `styles.blocks.<block>.css`, so the search must be recursive). Implement that as a private pure method on this class — do **not** call into `AgencyPlatform\Health\DatabaseOverrideCheck`, which Task 13 deletes.
  2. `wp_get_custom_css_post()` — the Customizer's `custom_css` post type. Use the public API, never a raw query.
  Both records are **always emitted**, with `css => ''` when absent, so the record set is deterministic (plan decision 6).
- **`CustomCssState::has_git_baseline()` returns `true`,** and `baseline_records()` returns the same two record keys with `content = array( 'source' => …, 'css' => '', 'length' => 0 )`. Git owns the theme's stylesheets and client roles cannot author CSS, so *"no Additional CSS"* is the Git baseline. This is what makes the generic drift rule work with **no special case**: empty CSS is `unchanged` (no drift); any non-empty CSS is `changed` → drift, classified `forbidden` by the `REFUSE` promotion policy. Do not add a `counts_as_drift` branch for this provider.
- The `css` value goes through `Normalizer::normalize_content()` like every other string leaf, so a CRLF in a stylesheet is not drift (decision 22).

- [ ] **Step 1: Extend the provider integration test first**

Add to `tests/Integration/State/ProviderRecordsTest.php`, one method each:

```php
	public function test_navigation_is_database_owned_and_never_promoted(): void {
		$this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );

		$record = ( new NavigationState() )->records()[0];

		self::assertSame( 'navigation:primary', $record->key() );
		self::assertSame( Ownership::DATABASE, $record->ownership() );
		self::assertSame( PromotionPolicy::EXPORT_AND_DIFF, $record->promotion() );
		self::assertFalse( ( new NavigationState() )->has_git_baseline() );
	}

	public function test_content_records_carry_no_password_and_no_email(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_title'    => 'Protected',
				'post_password' => 'super-secret-password',
			)
		);

		$record = ( new ContentState() )->record( 'content:page-' . $page_id );

		self::assertTrue( $record->content()['hasPassword'] );
		self::assertStringNotContainsString( 'super-secret-password', wp_json_encode( $record->to_array() ) );
		self::assertArrayNotHasKey( 'postPassword', $record->content() );
	}

	public function test_media_references_are_derived_from_template_markup(): void {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/08/hero.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Hero',
			)
		);
		wp_update_attachment_metadata( $attachment_id, array( 'file' => '2026/08/hero.jpg', 'width' => 1440, 'height' => 900 ) );

		$this->make_template( 'media', sprintf( '<!-- wp:image {"id":%d} /-->', $attachment_id ) );

		$records = ( new MediaReferencesState() )->records();

		self::assertSame( 'media-references:attachment-' . $attachment_id, $records[0]->key() );
		self::assertArrayHasKey( 'mimeType', $records[0]->content() );
		self::assertArrayNotHasKey( 'bytes', $records[0]->content() );
	}

	public function test_custom_css_is_detected_in_global_styles_and_in_the_custom_css_post(): void {
		$this->make_global_styles( '{"version":3,"styles":{"css":"body{color:red}"}}' );
		wp_update_custom_css_post( '.legacy { color: blue; }' );

		$records = ( new CustomCssState() )->records();
		$by_key  = array_combine( array_map( static fn( $record ) => $record->key(), $records ), $records );

		self::assertStringContainsString( 'body{color:red}', $by_key['custom-css:global-styles']->content()['css'] );
		self::assertStringContainsString( '.legacy', $by_key['custom-css:custom-css-post']->content()['css'] );
		self::assertSame( PromotionPolicy::REFUSE, $by_key['custom-css:global-styles']->promotion() );
	}

	public function test_custom_css_records_exist_even_when_no_css_is_set(): void {
		$records = ( new CustomCssState() )->records();

		self::assertCount( 2, $records, 'Both custom-CSS records are always emitted so the record set stays deterministic.' );
		self::assertSame( '', $records[0]->content()['css'] );
	}

	public function test_no_custom_css_matches_the_no_css_git_baseline(): void {
		$provider = new CustomCssState();

		self::assertTrue( $provider->has_git_baseline(), 'Git owns the theme stylesheets; "no Additional CSS" IS the baseline.' );
		self::assertSame(
			array_map( static fn ( $record ) => $record->content_hash(), $provider->baseline_records() ),
			array_map( static fn ( $record ) => $record->content_hash(), $provider->records() ),
			'An untouched site must produce no custom-css drift at all.'
		);
	}
```

The attachment fixture deliberately uses `create_object()` plus `wp_update_attachment_metadata()` rather than `create_upload_object()`: it writes only database rows, so the integration suite never depends on a writable uploads directory.

- [ ] **Step 2: Run the new tests and watch them fail**

```bash
ddev composer test:integration
```

Expected: FAIL — the six provider classes do not exist.

- [ ] **Step 3: Write the six providers and register them**

Implement each to the table and hard rules above, then add all six to `StateRegistry`'s built-in map.

- [ ] **Step 3a: Write `ReferenceResolver` and `ReferenceScanner::scan()`**

Moved here from Task 6 by the orchestrator, because `ReferenceResolver` reads `StateRegistry` (Task 8) while Task 8 consumes `ReferenceScanner` (Task 6). This is the first task where every provider it can reach exists.

Create `web/app/mu-plugins/agency-platform/src/State/ReferenceResolver.php` to the contract, the eleven reference keys, and the resolution table documented in **Task 6's Interfaces block**. Then add the one missing method to `ReferenceScanner`, and nothing else in that file:

```php
	/** WordPress-coupled: parse_blocks(), scan_parsed(), then ReferenceResolver::resolve(). @return list<array<string, mixed>> */
	public static function scan( string $markup, string $record_key ): array {
		return ReferenceResolver::resolve( self::scan_parsed( parse_blocks( $markup ), $record_key ) );
	}
```

`ReferenceResolver` is the only WordPress-coupled part. It must resolve `targetHash` through the owning provider (`StateRegistry::provider( 'navigation' )->record( $target_key )`), never by re-hashing raw `post_content`, so the hash matches the bundle byte for byte. Guard against recursion: `ReferenceResolver` must not itself trigger a reference scan — the provider's `record()` builds references for the target too, which would recurse. Break the cycle by passing `false` for the `bool $with_references` parameter that `StateProvider::record()` already declares (Task 5):

```php
	/** Live single-record re-read; null when the record no longer exists.
	 *  $with_references = false suppresses reference detection, which
	 *  ReferenceResolver uses to break the scan -> resolve -> scan cycle. */
	public function record( string $key, bool $with_references = true ): ?StateRecord;
```

`test_resolving_a_reference_does_not_recurse_forever()` in Step 3b is the test that proves the guard works. It must be shown to fail for the right reason before the guard is added.

- [ ] **Step 3c: RESTORE `BaseStateProvider::detect_references()` — a landmine Task 5 was forced to leave**

Added by the orchestrator on 2026-08-05. **Do not skip this step. If you skip it, the subsystem ships silently broken and every gate stays green.**

Task 5's `Interfaces` block specified `detect_references()` as "scans `$content['markup']` when present, else `array()`". Task 5 could not implement that: the scan is `ReferenceScanner`, which did not exist yet, and referencing an unknown class fails PHPStan level 6 inside `verify:fast`. The Task 5 worker therefore shipped the only thing that could compile — an unconditional `return array();` — and reported it, which was correct.

`ReferenceScanner::scan()` exists as of Step 3a, so restore the real behaviour now:

```php
	/**
	 * @param array<string, mixed> $content
	 * @return list<array<string, mixed>>
	 */
	public function detect_references( array $content, string $record_key ): array {
		if ( ! isset( $content['markup'] ) || ! is_string( $content['markup'] ) || '' === $content['markup'] ) {
			return array();
		}

		return ReferenceScanner::scan( $content['markup'], $record_key );
	}
```

**Why this is dangerous rather than merely incomplete.** Left as `return array();`, every provider that inherits `BaseStateProvider` reports ZERO references. References are what carry §7.4's v1 navigation policy — the exported navigation's content hash has to travel inside the reference so a templates-only bundle can still be judged at finalisation. A bundle whose references are all empty is structurally valid, passes the schema, hashes deterministically, signs correctly, and is WRONG. Nothing in the current suite fails.

**Prove the restore is real.** Add an integration assertion that a markup-bearing record with a `core/navigation` reference returns a NON-EMPTY reference list through `BaseStateProvider`'s default path — not only through a provider that overrides it. Then temporarily restore `return array();`, confirm that assertion FAILS, record the exact failure text in your report, and put the scan back. An assertion that cannot be shown to fail for the right reason is not coverage.

- [ ] **Step 3b: Write the reference-resolver integration test**

Create `tests/Integration/State/ReferenceResolverTest.php`. This is the test that proves Task 3 can enforce the §7.4 v1 navigation policy from a templates-only bundle:

```php
	public function test_a_navigation_reference_carries_the_target_key_hash_and_identity(): void {
		$navigation_id = $this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );
		$this->make_template( 'home', sprintf( '<!-- wp:navigation {"ref":%d} /-->', $navigation_id ) );

		$reference = ( new TemplatesState() )->record( 'templates:home' )->references()[0];

		self::assertSame( 'navigation:primary', $reference['targetKey'] );
		self::assertSame(
			( new NavigationState() )->record( 'navigation:primary' )->content_hash(),
			$reference['targetHash'],
			'The reference hash must be byte-identical to the hash the navigation provider exports.'
		);
		self::assertSame( 'primary', $reference['targetIdentity']['slug'] );
		self::assertSame( 'publish', $reference['targetIdentity']['status'] );
	}

	public function test_a_templates_only_export_still_carries_the_navigation_hash(): void {
		$navigation_id = $this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );
		$this->make_template( 'home', sprintf( '<!-- wp:navigation {"ref":%d} /-->', $navigation_id ) );

		$bundle = $this->exported_document( array( 'templates' ) );

		self::assertArrayNotHasKey( 'navigation', $bundle['providers'] );
		self::assertNotNull( $bundle['providers']['templates']['records'][0]['references'][0]['targetHash'] );
	}

	public function test_a_site_logo_reference_gets_its_id_from_the_option(): void {
		$attachment_id = self::factory()->attachment->create_object( array( 'file' => '2026/08/logo.png', 'post_mime_type' => 'image/png' ) );
		update_option( 'site_logo', $attachment_id );
		$this->make_part( 'site-header', '<!-- wp:site-logo /-->' );

		$reference = ( new TemplatePartsState() )->record( 'template-parts:site-header' )->references()[0];

		self::assertSame( ReferenceScanner::KIND_SITE_LOGO, $reference['kind'] );
		self::assertSame( $attachment_id, $reference['value'], 'Section 7.4 requires the referenced ID; the site-logo block keeps it in an option.' );
		self::assertSame( 'media-references:attachment-' . $attachment_id, $reference['targetKey'] );
	}

	public function test_a_dangling_reference_resolves_to_null_targets_and_stays_unresolved(): void {
		$this->make_template( 'home', '<!-- wp:navigation {"ref":999999} /-->' );

		$reference = ( new TemplatesState() )->record( 'templates:home' )->references()[0];

		self::assertNull( $reference['targetKey'] );
		self::assertNull( $reference['targetHash'] );
		self::assertTrue( ReferenceScanner::is_unresolved( $reference ) );
	}

	public function test_resolving_a_reference_does_not_recurse_forever(): void {
		// A synced pattern that references itself is legal markup; the
		// with_references=false read inside the resolver is what stops it.
		$pattern_id = $this->make_synced_pattern( 'loop', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		wp_update_post( array( 'ID' => $pattern_id, 'post_content' => sprintf( '<!-- wp:block {"ref":%d} /-->', $pattern_id ) ) );

		$references = ( new SyncedPatternsState() )->record( 'synced-patterns:loop' )->references();

		self::assertCount( 1, $references );
		self::assertSame( 'synced-patterns:loop', $references[0]['targetKey'] );
	}
```

Add `make_synced_pattern()` to the `SeedsStateFixtures` trait, and make every `make_*()` helper return the created post ID.

- [ ] **Step 4: Restore the real registry assertion and add the two `content` cases**

In `tests/Unit/AgencyPlatform/State/StateRegistryResolveTest.php`, change Task 8 Step 3's temporary assertion back to:

```php
	public function test_the_default_set_is_the_structural_providers(): void {
		self::assertSame( StateRegistry::STRUCTURAL_SLUGS, StateRegistry::resolve( null, false ) );
	}
```

and delete the note in the test docblock about the temporary state, together with the comment inside this method about `content` belonging to Task 9.

**Add the two `content` assertions here.** They were deliberately held back from Task 8 because `ContentState` does not exist until this task, and `resolve()` validates a named slug against the registered providers:

```php
	public function test_include_content_adds_the_content_provider(): void {
		self::assertContains( 'content', StateRegistry::resolve( null, true ) );
	}

	public function test_an_explicitly_named_content_provider_is_honoured_without_the_flag(): void {
		self::assertSame( array( 'content' ), StateRegistry::resolve( 'content', false ) );
	}
```

- [ ] **Step 5: Run both suites to green**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter StateRegistryResolveTest
ddev composer test:integration
```

Expected: PASS.

- [ ] **Step 6: Run the fast gate and commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Providers/ \
  web/app/mu-plugins/agency-platform/src/State/StateRegistry.php \
  web/app/mu-plugins/agency-platform/src/State/ReferenceResolver.php \
  web/app/mu-plugins/agency-platform/src/State/ReferenceScanner.php \
  tests/Unit/AgencyPlatform/State/StateRegistryResolveTest.php \
  tests/Integration/State/SeedsStateFixtures.php \
  tests/Integration/State/ProviderRecordsTest.php \
  tests/Integration/State/ReferenceResolverTest.php
git commit -m "feat(state): add the database-owned state providers"
```

---

### Task 10: Exporter and bundle reader

**Files:**
- Create: `web/app/mu-plugins/agency-platform/src/State/StateExporter.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/StateBundle.php`
- Test: `tests/Unit/AgencyPlatform/State/StateBundleTest.php`
- Test: `tests/Integration/State/StateExportTest.php`
- Test: `tests/Integration/State/ExportSensitiveDataTest.php`

**Interfaces:**
- Consumes: `StateRegistry`, `StateRecord`, `HmacSigner`, `SchemaValidator`, `Normalizer`, `GitBaseline`, `SiteIdentity`, `StateDirectory`.
- Produces — **the cross-task contract Task 3 consumes for every bundle read**:

```php
final class StateExporter {
	public function __construct( ?HmacSigner $signer = null, ?SchemaValidator $validator = null, ?GitBaseline $git = null );

	/**
	 * @param list<string> $provider_slugs
	 * @return array<string, mixed> The signed, schema-validated bundle document.
	 * @throws StateException Exit 1 on any provider, signing, or validation failure.
	 */
	public function export( array $provider_slugs ): array;

	/** @return list<string> Provider validation warnings from the most recent export, sorted by record key then message. */
	public function validation_warnings(): array;

	/**
	 * Pure stateHash input. This excludes provider metadata and every bundle
	 * wrapper field, and returns only ascending provider slugs and ascending
	 * `StateRecord::to_array()` lists.
	 *
	 * @param array<string, array<string, mixed>> $providers
	 * @return array<string, list<array<string, mixed>>>
	 */
	public static function canonical_provider_records( array $providers ): array;

	/** @return string The §7.3 warning printed to STDERR before every export. */
	public static function sensitivity_warning(): string;
}

final class StateBundle {
	/** @param array<string, mixed> $document @throws StateException */
	public static function from_array( array $document ): self;
	/** @throws StateException Exit 1 on malformed JSON. */
	public static function from_json( string $json ): self;
	/** @param string $path A filesystem path, or '-' to read STDIN. @throws StateException */
	public static function from_file( string $path ): self;

	/**
	 * Read, schema-validate, verify the signature, and confirm stateHash — the
	 * one call Task 3 should use. Records stay unreadable until this succeeds.
	 *
	 * @throws StateException Exit 1 (malformed/invalid) or exit 4 (tamper).
	 */
	public static function load( string $path, ?HmacSigner $signer = null, ?SchemaValidator $validator = null ): self;

	/** @throws StateException Exit 4 when the signature does not verify. */
	public function verify_signature( HmacSigner $signer ): void;
	public function is_verified(): bool;

	public function schema_version(): int;
	public function export_id(): string;
	public function exported_at_utc(): string;
	public function site_uuid(): string;
	public function site_url(): string;
	public function environment(): string;
	public function wordpress_version(): string;
	/** @return array{stylesheet: string, version: string, gitCommit: string|null} */
	public function active_theme(): array;
	public function state_hash(): string;
	/** @return list<string> Sorted provider slugs present in this bundle. */
	public function provider_slugs(): array;
	/** @return array{ownership: string, promotion: string, hasGitBaseline: bool} @throws StateException Exit 4 when unverified. */
	public function provider_meta( string $provider_slug ): array;
	/** @return list<StateRecord> @throws StateException Exit 4 when unverified. */
	public function records( string $provider_slug ): array;
	/** @throws StateException Exit 4 when unverified. */
	public function record( string $key ): ?StateRecord;
	/** @return array<string, mixed> The raw document. */
	public function to_array(): array;
}
```

**Bundle assembly (`export()`), in order:**

1. `StateRegistry::provider( $slug )` for each requested slug; a slug with no provider is a hard error.
2. For each provider, build `array( 'slug' => …, 'ownership' => …, 'promotion' => …, 'hasGitBaseline' => …, 'records' => array_map( to_array, $provider->records() ) )`. Records must already be sorted by key ascending (`strcmp`); assert it and sort defensively.
3. Run `$provider->validate()` on every record. Store every returned problem string in the exporter's private `validation_warnings` list as `"<record-key>: <problem>"`, sorted by record key then message. Validation problems are warnings, not failures. `StateExporter` never writes streams. `StateCommandRunner::state_export()` reads `validation_warnings()` after a successful export and appends those lines to `StateCommandResult::$stderr`, after `sensitivity_warning()` and before returning the JSON-only `$stdout`. This gives CLI users the warnings without adding a warning field or any text to the signed bundle or JSON STDOUT.
4. `$providers` map sorted by slug (`ksort`).
5. `stateHash = hash( 'sha256', Normalizer::canonical_json( self::canonical_provider_records( $providers ) ) )` (plan decision 2). The helper produces only the sorted `provider-slug => list<StateRecord::to_array()>` record sets; it deliberately drops each provider node's `slug`, `ownership`, `promotion`, and `hasGitBaseline` metadata.
6. Assemble the wrapper: `schemaVersion => 1`, `exportId => wp_generate_uuid4()`, `exportedAtUtc => gmdate( 'Y-m-d\TH:i:s\Z' )`, `siteUuid => SiteIdentity::uuid()`, `siteUrl => home_url()`, `environment => wp_get_environment_type()`, `wordpressVersion => get_bloginfo( 'version' )`, `activeTheme => array( 'stylesheet' => get_stylesheet(), 'version' => (string) wp_get_theme()->get( 'Version' ), 'gitCommit' => $git->head_commit() )`, `providers`, `stateHash`.
7. `$signature = $signer->sign( $document, HmacSigner::PURPOSE_BUNDLE ); $document += $signature;`
8. `$validator->validate( $document, SchemaValidator::SCHEMA_STATE_BUNDLE );` — validate the **signed** document, so what is written is what was validated.
9. Return `Normalizer::normalize_content( $document )`.

**Serialisation rule (decision 23).** Whatever writes this document — a file, STDOUT, or a test fixture — must use `Normalizer::canonical_json_document()` and nothing else. No `wp_json_encode()`, no `JSON_PRETTY_PRINT`, no `PHP_EOL`. That guarantees a bundle exported on Windows, in DDEV, and on the production host is byte-identical for identical state, and that the bytes a consumer hashes are the bytes the signer signed.

**`sensitivity_warning()`** returns verbatim:

```
State bundles can contain customer content and internal site structure. Keep them out of Git (AGENCY_STATE_DIR is git-ignored), treat them as confidential, and delete them when the promotion they support has been confirmed. See BLOCK_THEME_PROPOSAL.md section 7.3.
```

**`StateBundle` invariants (plan decision 14):**

- `from_array()` schema-validates nothing by itself; it stores the document and checks only that `providers` is an array. `load()` is the full path: read → `from_json()` → `SchemaValidator::validate()` → `verify_signature()` → recompute `stateHash` with `StateExporter::canonical_provider_records( $document['providers'] )` and compare.
- `records()`, `record()`, and `provider_meta()` throw `StateException::tamper( 'The bundle signature has not been verified; refusing to read bundle content.' )` while `is_verified()` is false. This is the mechanical guarantee behind spec §9.5's "verify bundle HMAC before reading".
- A `stateHash` mismatch is `StateException::tamper()`, not a hard error — a bundle whose content no longer hashes to its recorded hash has been altered.
- `from_file( '-' )` reads `php://stdin`.

- [ ] **Step 1: Write the failing bundle unit test**

Create `tests/Unit/AgencyPlatform/State/StateBundleTest.php`. Build a bundle document by hand (reuse the fixture from `SchemaValidatorTest`, with a correct `stateHash` computed via `Normalizer::hash`-style canonicalisation and a real signature from a test `HmacSigner`), then assert:

```php
	public function test_records_are_unreadable_until_the_signature_is_verified(): void {
		$bundle = StateBundle::from_array( $this->signed_document() );

		self::assertFalse( $bundle->is_verified() );

		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/not been verified/' );

		$bundle->records( 'templates' );
	}

	public function test_records_are_readable_after_verification(): void {
		$bundle = StateBundle::from_array( $this->signed_document() );
		$bundle->verify_signature( $this->signer() );

		self::assertTrue( $bundle->is_verified() );
		self::assertSame( 'templates:page', $bundle->records( 'templates' )[0]->key() );
		self::assertSame( 'templates:page', $bundle->record( 'templates:page' )->key() );
		self::assertNull( $bundle->record( 'templates:missing' ) );
	}

	public function test_a_tampered_record_fails_verification_before_any_content_is_read(): void {
		$document = $this->signed_document();
		$document['providers']['templates']['records'][0]['content']['markup'] = '<!-- wp:paragraph --><p>Injected</p><!-- /wp:paragraph -->';

		$bundle = StateBundle::from_array( $document );

		$this->expectException( StateException::class );

		$bundle->verify_signature( $this->signer() );
	}

	public function test_load_rejects_a_state_hash_that_no_longer_matches_the_providers(): void {
		$path = tempnam( sys_get_temp_dir(), 'bundle' );
		$document              = $this->signed_document();
		$document['stateHash'] = str_repeat( '0', 64 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_write_file_put_contents -- unit fixture file for StateBundle::load(); no WordPress filesystem credentials context exists in this unit suite.
		file_put_contents( $path, wp_json_encode( $document ) );

		$this->expectException( StateException::class );

		try {
			StateBundle::load( $path, $this->signer() );
		} catch ( StateException $exception ) {
			self::assertSame( StateException::EXIT_TAMPER, $exception->exit_code() );
			throw $exception;
		} finally {
			unlink( $path );
		}
	}

	public function test_the_wrapper_metadata_is_exposed(): void {
		$bundle = StateBundle::from_array( $this->signed_document() );

		self::assertSame( 1, $bundle->schema_version() );
		self::assertSame( '11111111-2222-4333-8444-555566667777', $bundle->export_id() );
		self::assertSame( '2026-08-02T09:00:00Z', $bundle->exported_at_utc() );
		self::assertSame( '99999999-2222-4333-8444-555566667777', $bundle->site_uuid() );
		self::assertSame( 'development', $bundle->environment() );
		self::assertSame( 'site-theme', $bundle->active_theme()['stylesheet'] );
	}

	public function test_provider_slugs_are_sorted(): void {
		$document              = $this->signed_document();
		$document['providers'] = array( 'templates' => $document['providers']['templates'] ) + array( 'global-styles' => $this->empty_provider( 'global-styles' ) );

		self::assertSame( array( 'global-styles', 'templates' ), StateBundle::from_array( $document )->provider_slugs() );
	}
```

`signed_document()` builds the fixture and signs it with `signer()`; `signer()` returns `new HmacSigner( array( '2026-01' => str_repeat( 'k', 40 ) ), '2026-01' )`; `empty_provider( string $slug )` returns a provider node with `records => array()`. `signed_document()` must compute `stateHash` with `StateExporter::canonical_provider_records( $document['providers'] )` **before** signing, exactly as `StateExporter` does, or `load()` will correctly reject the fixture. Add a unit assertion that mutating each provider metadata field (`slug`, `ownership`, `promotion`, `hasGitBaseline`) leaves that canonical-record input and `stateHash` unchanged, while mutating any record field changes it.

- [ ] **Step 2: Run it and watch it fail**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter StateBundleTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write `StateExporter` and `StateBundle`**

Implement to the Interfaces block and the assembly/invariant lists above.

- [ ] **Step 4: Run the bundle unit test to green**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter StateBundleTest
```

Expected: PASS.

- [ ] **Step 5: Write the export integration test**

Create `tests/Integration/State/StateExportTest.php`. Set the HMAC keyring for the whole class by defining the constants in `set_up()` is impossible (constants are immutable), so inject a signer instead: `new StateExporter( new HmacSigner( array( '2026-01' => str_repeat( 'k', 40 ) ), '2026-01' ) )`. Cover:

```php
	public function test_the_default_export_covers_every_structural_provider_and_excludes_content(): void {
		$bundle = $this->export( StateRegistry::resolve( null, false ) );

		self::assertSame( StateRegistry::STRUCTURAL_SLUGS, array_keys( $bundle['providers'] ) );
		self::assertArrayNotHasKey( 'content', $bundle['providers'] );
	}

	public function test_include_content_adds_the_content_provider(): void {
		$bundle = $this->export( StateRegistry::resolve( null, true ) );

		self::assertArrayHasKey( 'content', $bundle['providers'] );
	}

	public function test_providers_narrows_the_export(): void {
		$bundle = $this->export( StateRegistry::resolve( 'templates,global-styles', false ) );

		self::assertSame( array( 'global-styles', 'templates' ), array_keys( $bundle['providers'] ) );
	}

	public function test_repeated_exports_of_unchanged_state_produce_an_identical_state_hash(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Stable</p><!-- /wp:paragraph -->' );

		$first  = $this->export( array( 'templates' ) );
		$second = $this->export( array( 'templates' ) );

		self::assertSame( $first['stateHash'], $second['stateHash'] );
		self::assertSame( $first['providers'], $second['providers'], 'Record ordering, per-record hashes, and normalised data must all be identical.' );
		self::assertNotSame( $first['exportId'], $second['exportId'], 'exportId is deliberately random and is NOT part of stateHash.' );
	}

	/**
	 * Section 7.2 states exactly which wrapper fields are outside stateHash.
	 * This asserts it directly instead of inferring it from two export runs.
	 */
	public function test_no_excluded_wrapper_field_participates_in_the_state_hash(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Stable</p><!-- /wp:paragraph -->' );

		$bundle   = $this->export( array( 'templates' ) );
		$expected = $bundle['stateHash'];

		$mutations = array(
			'exportId'         => '00000000-0000-4000-8000-000000000000',
			'exportedAtUtc'    => '1999-12-31T23:59:59Z',
			'siteUrl'          => 'https://somewhere-else.invalid',
			'siteUuid'         => '00000000-1111-4222-8333-444455556666',
			'environment'      => 'production',
			'wordpressVersion' => '0.0',
			'hmacKeyId'        => 'other-key',
			'hmac'             => str_repeat( 'f', 64 ),
		);

		foreach ( $mutations as $field => $value ) {
			$mutated           = $bundle;
			$mutated[ $field ] = $value;

			self::assertSame( $expected, Normalizer::hash( StateExporter::canonical_provider_records( $mutated['providers'] ) ), sprintf( '"%s" must not affect stateHash.', $field ) );
		}

		$mutated                            = $bundle;
		$mutated['activeTheme']['gitCommit'] = str_repeat( '9', 40 );

		self::assertSame( $expected, Normalizer::hash( StateExporter::canonical_provider_records( $mutated['providers'] ) ), 'activeTheme.gitCommit must not affect stateHash.' );

		foreach ( array( 'slug', 'ownership', 'promotion', 'hasGitBaseline' ) as $field ) {
			$mutated                                      = $bundle;
			$mutated['providers']['templates'][ $field ] = 'hasGitBaseline' === $field ? false : 'changed-metadata';

			self::assertSame( $expected, Normalizer::hash( StateExporter::canonical_provider_records( $mutated['providers'] ) ), sprintf( 'Provider metadata "%s" must not affect stateHash.', $field ) );
		}
	}

	public function test_the_written_bundle_is_canonical_bytes_with_one_trailing_lf(): void {
		$bundle = $this->export( array( 'templates' ) );
		$json   = Normalizer::canonical_json_document( $bundle );

		self::assertStringEndsWith( "}\n", $json );
		self::assertStringNotContainsString( "\r", $json );
		self::assertSame( $json, Normalizer::canonical_json_document( json_decode( $json, true ) ), 'Encoding must be a fixed point.' );
	}

	public function test_a_changed_record_changes_the_state_hash(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->' );
		$before = $this->export( array( 'templates' ) );

		$this->make_template( 'page', '<!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->' );
		$after = $this->export( array( 'templates' ) );

		self::assertNotSame( $before['stateHash'], $after['stateHash'] );
	}

	public function test_the_bundle_validates_against_the_schema_and_verifies_its_signature(): void {
		$bundle = $this->export( StateRegistry::resolve( null, false ) );

		( new SchemaValidator() )->validate( $bundle, SchemaValidator::SCHEMA_STATE_BUNDLE );
		$this->signer()->verify( $bundle, HmacSigner::PURPOSE_BUNDLE );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_bundle_signature_is_not_accepted_as_a_manifest_signature(): void {
		$this->expectException( StateException::class );

		$this->signer()->verify( $this->export( array( 'templates' ) ), HmacSigner::PURPOSE_MANIFEST );
	}
```

- [ ] **Step 6: Write the sensitive-data integration test**

Create `tests/Integration/State/ExportSensitiveDataTest.php` — the concrete proof of spec §7.3's exclusion list:

```php
	public function test_no_user_email_session_token_or_application_password_reaches_the_bundle(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'client_editor',
				'user_email' => 'private.person@real-example.com',
			)
		);
		update_user_meta( $user_id, 'session_tokens', array( 'token-abc123' => array( 'expiration' => time() + 3600 ) ) );
		update_user_meta( $user_id, '_application_passwords', array( array( 'name' => 'agent', 'password' => 'app-pass-xyz789' ) ) );

		$page_id = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_author'   => $user_id,
				'post_password' => 'page-secret-987',
			)
		);

		$json = wp_json_encode( $this->export( StateRegistry::resolve( null, true ) ) );

		self::assertStringNotContainsString( 'private.person@real-example.com', $json );
		self::assertStringNotContainsString( 'token-abc123', $json );
		self::assertStringNotContainsString( 'app-pass-xyz789', $json );
		self::assertStringNotContainsString( 'page-secret-987', $json );
		self::assertStringContainsString( 'content:page-' . $page_id, $json, 'The record itself must still be exported — only the sensitive fields are excluded.' );
	}
```

- [ ] **Step 7: Run the integration suite**

```bash
ddev composer test:integration
```

Expected: PASS.

- [ ] **Step 8: Run the fast gate and commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/StateExporter.php \
  web/app/mu-plugins/agency-platform/src/State/StateBundle.php \
  tests/Unit/AgencyPlatform/State/StateBundleTest.php \
  tests/Integration/State/StateExportTest.php \
  tests/Integration/State/ExportSensitiveDataTest.php
git commit -m "feat(state): add the deterministic signed export bundle and its reader"
```

---

### Task 11: Two-mode differ

**Files:**
- Create: `web/app/mu-plugins/agency-platform/src/State/StateDiffer.php`
- Test: `tests/Unit/AgencyPlatform/State/StateDifferCompareTest.php`
- Test: `tests/Integration/State/StateDiffGitModeTest.php`
- Test: `tests/Integration/State/StateDiffBundleModeTest.php`

**Interfaces:**
- Consumes: `StateRegistry`, `StateRecord`, `StateBundle`, `DriftClassification`, `ReferenceScanner`, `PromotionPolicy`.
- Produces:

```php
final class StateDiffer {
	public const MODE_GIT    = 'git';
	public const MODE_BUNDLE = 'bundle';

	/**
	 * @param array<string, StateProvider>|null $providers Null resolves the
	 *        production registry once. Tests inject a provider map, including
	 *        providers constructed with a temporary GitBaseline.
	 */
	public function __construct( ?GitBaseline $git = null, ?array $providers = null );

	public function diff_against_git( array $provider_slugs ): array;

	/**
	 * @param list<string> $provider_slugs
	 * @return array<string, mixed>
	 * @throws StateException Exit 1 when the bundle belongs to another site.
	 */
	public function diff_against_bundle( array $provider_slugs, StateBundle $bundle ): array;

	/**
	 * Pure. The whole classification contract, unit-testable without WordPress.
	 *
	 * @param list<StateRecord>                                                                  $current
	 * @param list<StateRecord>                                                                  $target
	 * @param array<string, array{ownership: string, promotion: string, hasGitBaseline: bool}>   $provider_meta
	 * @param list<string>                                                                        $database_override_keys
	 * @return list<array<string, mixed>> Diff entries, sorted by key ascending.
	 */
	public static function compare( array $current, array $target, string $mode, array $provider_meta, array $database_override_keys = array() ): array;

	/**
	 * Pure. Builds the EFFECTIVE current state for Git mode: the Git baseline
	 * records, with any database override of the same key replacing its
	 * baseline counterpart, plus every database record that has no baseline
	 * counterpart. Sorted by key ascending.
	 *
	 * @param list<StateRecord> $baseline
	 * @param list<StateRecord> $database
	 * @return list<StateRecord>
	 */
	public static function overlay( array $baseline, array $database ): array;

	/** Pure. @param array<string, mixed> $report */
	public static function has_drift( array $report ): bool;

	/** Pure. @param list<array<string, mixed>> $entries @return array<string, int> */
	public static function summarize( array $entries ): array;
}
```

**The overlay is the whole point of Git mode (decision 18, spec §5.3).** `diff_against_git()` is:

```php
	public function diff_against_git( array $provider_slugs ): array {
		$current                = array();
		$target                 = array();
		$meta                   = array();
		$database_override_keys = array();

		foreach ( $provider_slugs as $slug ) {
			$provider = $this->provider( $slug );   // hard error when unknown
			$baseline = $provider->baseline_records();
			$database = $provider->records();
			$database_override_keys = array_merge( $database_override_keys, array_map( static fn ( StateRecord $record ): string => $record->key(), $database ) );

			$meta[ $slug ] = array(
				'ownership'      => $provider->ownership(),
				'promotion'      => $provider->promotion(),
				'hasGitBaseline' => $provider->has_git_baseline(),
			);

			$current = array_merge( $current, self::overlay( $baseline, $database ) );
			$target  = array_merge( $target, $baseline );
		}

		sort( $database_override_keys, SORT_STRING );
		$database_override_keys = array_values( array_unique( $database_override_keys ) );

		return self::report( self::compare( $current, $target, self::MODE_GIT, $meta, $database_override_keys ), self::MODE_GIT, null, array() );
	}
```

The constructor is the required registry/provider seam. When `$providers` is null, it takes one sorted map from `StateRegistry::providers()` and validates that each key matches `StateProvider::slug()`. It then uses a private `provider( string $slug ): StateProvider` for **all** gathers. It must not call `StateRegistry::provider()` after construction. A GitBaseline test creates one `$git = new GitBaseline( null, $temporary_theme_dir )`, creates `TemplatesState` and `TemplatePartsState` with that same `$git`, and injects `array( 'templates' => $templates, 'template-parts' => $parts )` into `new StateDiffer( $git, $providers )`. This makes the temporary baseline and the provider data explicit, and avoids tests that depend on static-only registry lookup or write into the shipped theme.

Without `overlay()`, a clean block theme — every template present as `templates/*.html` in Git and **no** `wp_template` row in the database — would report every single template as `removed`, classify it as drift, and make `state-diff` and `check-overrides --fail-on-drift` exit non-zero on a completely healthy site. That is exactly backwards: §5.3 says runtime truth is "the active Git baseline **plus** current database state", so a slug with no override contributes its Git baseline record to the current side and reads `unchanged`.

`diff_against_bundle()` does **not** overlay. Both of its sides are database records (the bundle stores database records, because those are what the promotion track promotes), so `removed` there genuinely means "an override that existed at export time has since been deleted" — real post-export drift.

**Diff entry shape** (exact keys, exact order):

```php
array(
	'key'                  => 'templates:page',
	'provider'             => 'templates',
	'slug'                 => 'page',
	'status'               => 'changed',      // added | removed | changed | unchanged
	'classification'       => 'promotable',   // a DriftClassification::* value
	'countsAsDrift'        => true,
	'currentHash'          => '…'|null,
	'targetHash'           => '…'|null,
	'hasDatabaseOverride'  => true,           // a wp_* row exists for this key right now
	'unresolvedReferences' => array(),        // ReferenceScanner entries, unresolved only
)
```

**`status`:** `added` (in `$current` only), `removed` (in `$target` only), `changed` (in both, `content_hash()` differs), `unchanged` (in both, hashes equal). After the overlay, `removed` cannot occur in Git mode at all — it is a bundle-mode-only status.

**`hasDatabaseOverride`** is set by `compare()` from the explicit gatherer input, not inferred from the overlaid records. `diff_against_git()` collects the keys of `$provider->records()` before `overlay()` and passes that sorted, de-duplicated `list<string>` as `$database_override_keys`. `diff_against_bundle()` similarly passes the live current database-record keys. For every entry, including a Git-only baseline entry, `hasDatabaseOverride` is exactly `in_array( $entry_key, $database_override_keys, true )`. `check-overrides` reports it so an operator can still see "this template has a database override" even when that override is byte-identical to Git.

**`classification`** (§11.10's five values), evaluated in this order:
1. `status === 'unchanged'` → `UNCHANGED`.
2. provider promotion is `REFUSE` → `FORBIDDEN`.
3. provider promotion is `PROMOTABLE` **and** the record has at least one unresolved reference → `UNRESOLVED`.
4. provider promotion is `PROMOTABLE` → `PROMOTABLE`.
5. otherwise (`EXPORT_AND_DIFF`, `NEVER_PROMOTE`) → `DB_OWNED`.

**`countsAsDrift`** (spec §6's two-mode rule) — three lines, **no per-provider special cases** (decision 19):
- `classification === UNCHANGED` → `false`.
- `MODE_GIT` → the provider's `hasGitBaseline`. Database-owned providers are informational and do **not** count as Git drift, exactly as §6 requires. Custom CSS *does* count, because decision 6 gives it a real `has_git_baseline() === true` baseline of "no CSS" rather than a special case here.
- `MODE_BUNDLE` → `true` for every non-unchanged entry, including database-owned providers — otherwise a navigation or content change made after export would be missed (§6).

**Report shape:**

```php
array(
	'schemaVersion'  => 1,
	'mode'           => 'git'|'bundle',
	'generatedAtUtc' => '2026-08-02T09:00:00Z',
	'siteUuid'       => '…',
	'source'         => null | array( 'exportId' => …, 'exportedAtUtc' => …, 'stateHash' => … ),
	'summary'          => array( 'drift' => 0, 'promotable' => 0, 'dbOwned' => 0, 'forbidden' => 0, 'unresolved' => 0, 'unchanged' => 0 ),
	'skippedProviders' => array(),   // bundle mode: requested providers absent from the bundle. Always present, empty in Git mode.
	'entries'          => array(),
)
```

`summarize()` returns exactly the six `summary` keys above; `drift` is the count of entries with `countsAsDrift === true` and the other five are per-classification counts.

**Bundle-mode rules:** compare only providers present in **both** the requested set and `$bundle->provider_slugs()`; a requested provider missing from the bundle produces a `skippedProviders` entry in the report and a STDERR warning from the command layer, never drift. Gather each live side through the same injected private `provider()` seam, collect its `records()` keys as the explicit `database_override_keys`, and pass them to `compare()`. `$bundle->site_uuid() !== SiteIdentity::uuid()` throws `StateException::hard_error()` naming both UUIDs (plan decision 8). `diff_against_bundle()` must call `$bundle->records()` — which throws unless the bundle was verified — so an unverified bundle can never be diffed.

- [ ] **Step 1: Write the failing compare unit test**

Create `tests/Unit/AgencyPlatform/State/StateDifferCompareTest.php`. Build `StateRecord`s directly (no WordPress) and assert every cell of the classification and drift matrix. At minimum:

```php
	/** @return array<string, array{ownership: string, promotion: string, hasGitBaseline: bool}> */
	private function meta(): array {
		return array(
			'templates'  => array( 'ownership' => Ownership::GIT_BASELINE_PLUS_DB, 'promotion' => PromotionPolicy::PROMOTABLE, 'hasGitBaseline' => true ),
			'navigation' => array( 'ownership' => Ownership::DATABASE, 'promotion' => PromotionPolicy::EXPORT_AND_DIFF, 'hasGitBaseline' => false ),
			'custom-css' => array( 'ownership' => Ownership::FORBIDDEN, 'promotion' => PromotionPolicy::REFUSE, 'hasGitBaseline' => true ),
		);
	}

	public function test_an_identical_record_is_unchanged_and_is_not_drift(): void {
		$entries = StateDiffer::compare(
			array( $this->template( 'page', 'same' ) ),
			array( $this->template( 'page', 'same' ) ),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( 'unchanged', $entries[0]['status'] );
		self::assertSame( DriftClassification::UNCHANGED, $entries[0]['classification'] );
		self::assertFalse( $entries[0]['countsAsDrift'] );
	}

	public function test_a_changed_template_is_promotable_drift_in_git_mode(): void {
		$entries = StateDiffer::compare(
			array( $this->template( 'page', 'after' ) ),
			array( $this->template( 'page', 'before' ) ),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( 'changed', $entries[0]['status'] );
		self::assertSame( DriftClassification::PROMOTABLE, $entries[0]['classification'] );
		self::assertTrue( $entries[0]['countsAsDrift'] );
		self::assertNotSame( $entries[0]['currentHash'], $entries[0]['targetHash'] );
	}

	public function test_a_template_absent_from_git_is_added_and_is_drift(): void {
		$entries = StateDiffer::compare(
			array( $this->template( 'page', 'only-in-db' ) ),
			array(),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( 'added', $entries[0]['status'] );
		self::assertTrue( $entries[0]['countsAsDrift'] );
		self::assertNull( $entries[0]['targetHash'] );
	}

	public function test_a_template_present_only_in_the_bundle_is_removed_and_is_drift(): void {
		$entries = StateDiffer::compare(
			array(),
			array( $this->template( 'page', 'was-exported' ) ),
			StateDiffer::MODE_BUNDLE,
			$this->meta()
		);

		self::assertSame( 'removed', $entries[0]['status'] );
		self::assertTrue( $entries[0]['countsAsDrift'], 'An override deleted after export is real post-export drift.' );
		self::assertNull( $entries[0]['currentHash'] );
	}

	public function test_overlay_fills_every_git_slug_that_has_no_database_override(): void {
		$baseline = array( $this->template( 'page', 'from-git' ), $this->template( 'single', 'from-git-too' ) );
		$database = array( $this->template( 'page', 'overridden' ) );

		$current = StateDiffer::overlay( $baseline, $database );

		self::assertSame( array( 'templates:page', 'templates:single' ), array_map( static fn ( $record ) => $record->key(), $current ) );
		self::assertSame( 'overridden', $current[0]->content()['markup'], 'A database override replaces its baseline counterpart.' );
		self::assertSame( 'from-git-too', $current[1]->content()['markup'], 'A slug with no override keeps the Git baseline.' );
	}

	public function test_overlay_keeps_a_database_record_that_has_no_git_counterpart(): void {
		$current = StateDiffer::overlay( array(), array( $this->template( 'custom-page', 'db-only' ) ) );

		self::assertCount( 1, $current );
		self::assertSame( 'templates:custom-page', $current[0]->key() );
	}

	/**
	 * The regression this overlay exists to prevent: a healthy block theme has
	 * every template in Git and NO wp_template row. Comparing raw database
	 * records against Git would mark every template `removed` and exit 2.
	 */
	public function test_a_git_only_template_set_produces_no_drift_at_all(): void {
		$baseline = array( $this->template( 'page', 'shipped' ), $this->template( 'single', 'shipped-too' ) );

		$entries = StateDiffer::compare(
			StateDiffer::overlay( $baseline, array() ),
			$baseline,
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( array( 'unchanged', 'unchanged' ), array_column( $entries, 'status' ) );
		self::assertSame( 0, StateDiffer::summarize( $entries )['drift'] );
		self::assertFalse( $entries[0]['hasDatabaseOverride'] );
	}

	public function test_compare_marks_only_explicit_database_override_keys(): void {
		$entries = StateDiffer::compare(
			array( $this->template( 'page', 'same' ), $this->template( 'single', 'same' ) ),
			array( $this->template( 'page', 'same' ), $this->template( 'single', 'same' ) ),
			StateDiffer::MODE_GIT,
			$this->meta(),
			array( 'templates:page' )
		);

		self::assertTrue( $entries[0]['hasDatabaseOverride'] );
		self::assertFalse( $entries[1]['hasDatabaseOverride'] );
	}

	public function test_a_changed_navigation_is_db_owned_and_is_NOT_git_drift(): void {
		$entries = StateDiffer::compare(
			array( $this->navigation( 'primary', 'after' ) ),
			array( $this->navigation( 'primary', 'before' ) ),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( DriftClassification::DB_OWNED, $entries[0]['classification'] );
		self::assertFalse( $entries[0]['countsAsDrift'], 'Database-owned providers have no Git counterpart and must never register as Git drift.' );
	}

	public function test_a_changed_navigation_IS_drift_in_bundle_mode(): void {
		$entries = StateDiffer::compare(
			array( $this->navigation( 'primary', 'after' ) ),
			array( $this->navigation( 'primary', 'before' ) ),
			StateDiffer::MODE_BUNDLE,
			$this->meta()
		);

		self::assertTrue( $entries[0]['countsAsDrift'], 'A navigation change made after export must be caught as post-export drift.' );
	}

	public function test_a_promotable_record_with_an_unresolved_reference_is_classified_unresolved(): void {
		$reference = array(
			'record'     => 'templates:page',
			'provider'   => 'templates',
			'blockName'  => 'core/block',
			'attribute'  => 'ref',
			'value'      => 8,
			'kind'       => ReferenceScanner::KIND_SYNCED_PATTERN,
			'resolution' => ReferenceScanner::RESOLUTION_ENVIRONMENT,
			'policy'     => 'Synced patterns stay database-owned in v1.',
		);

		$entries = StateDiffer::compare(
			array( $this->template( 'page', 'after', array( $reference ) ) ),
			array( $this->template( 'page', 'before' ) ),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( DriftClassification::UNRESOLVED, $entries[0]['classification'] );
		self::assertTrue( $entries[0]['countsAsDrift'] );
		self::assertCount( 1, $entries[0]['unresolvedReferences'] );
	}

	public function test_non_empty_custom_css_is_forbidden_drift_in_both_modes(): void {
		foreach ( array( StateDiffer::MODE_GIT, StateDiffer::MODE_BUNDLE ) as $mode ) {
			$entries = StateDiffer::compare(
				array( $this->custom_css( 'global-styles', 'body{color:red}' ) ),
				array( $this->custom_css( 'global-styles', '' ) ),
				$mode,
				$this->meta()
			);

			self::assertSame( DriftClassification::FORBIDDEN, $entries[0]['classification'], $mode );
			self::assertTrue( $entries[0]['countsAsDrift'], $mode );
		}
	}

	public function test_empty_custom_css_is_unchanged_and_is_not_drift(): void {
		$entries = StateDiffer::compare(
			array( $this->custom_css( 'global-styles', '' ) ),
			array( $this->custom_css( 'global-styles', '' ) ),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( DriftClassification::UNCHANGED, $entries[0]['classification'] );
		self::assertFalse( $entries[0]['countsAsDrift'] );
	}

	public function test_entries_are_sorted_by_key(): void {
		$entries = StateDiffer::compare(
			array( $this->template( 'search', 'x' ), $this->template( 'archive', 'y' ) ),
			array(),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		self::assertSame( array( 'templates:archive', 'templates:search' ), array_column( $entries, 'key' ) );
	}

	public function test_summarize_counts_every_classification_and_has_drift_agrees(): void {
		$entries = StateDiffer::compare(
			array( $this->template( 'page', 'after' ), $this->navigation( 'primary', 'after' ) ),
			array( $this->template( 'page', 'before' ), $this->navigation( 'primary', 'before' ) ),
			StateDiffer::MODE_GIT,
			$this->meta()
		);

		$summary = StateDiffer::summarize( $entries );

		self::assertSame( 1, $summary['drift'] );
		self::assertSame( 1, $summary['promotable'] );
		self::assertSame( 1, $summary['dbOwned'] );
		self::assertTrue( StateDiffer::has_drift( array( 'entries' => $entries ) + array( 'summary' => $summary ) ) );
	}
```

`template()`, `navigation()`, and `custom_css()` are private helpers that build a `StateRecord` via `StateRecord::create()` with the right provider slug, ownership, and promotion policy; the optional third argument of `template()` is the references list.

- [ ] **Step 2: Run it and watch it fail**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter StateDifferCompareTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write `StateDiffer`**

Implement `compare()` first (pure), then the two WordPress-coupled gatherers on top of it.

- [ ] **Step 4: Run the unit test to green**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter StateDifferCompareTest
```

Expected: PASS.

- [ ] **Step 5: Write the Git-mode integration test**

Create `tests/Integration/State/StateDiffGitModeTest.php`:

```php
	public function test_a_clean_install_reports_no_drift(): void {
		$report = ( new StateDiffer() )->diff_against_git( StateRegistry::resolve( null, false ) );

		self::assertFalse( StateDiffer::has_drift( $report ), 'A site with no overrides and no custom CSS must exit 0.' );
	}

	/**
	 * The Critical regression guard. Runs only once Task 1 has merged and the
	 * theme actually ships templates/*.html. This is a required Release 2
	 * condition, so a missing block-theme baseline is a failure, never a skip.
	 * Re-run this after the Task 13 rebase.
	 */
	public function test_a_shipped_block_theme_with_no_database_overrides_reports_no_drift(): void {
		$baseline = ( new GitBaseline() );

		self::assertTrue( $baseline->has_block_templates(), 'The required block-theme baseline is absent: templates/index.html must exist after Task 1 has merged.' );

		self::assertNotSame( array(), $baseline->template_markup(), 'Sanity: the block theme must ship template files.' );
		self::assertSame( array(), ( new TemplatesState() )->records(), 'Sanity: a fresh install has no wp_template rows.' );

		$report = ( new StateDiffer() )->diff_against_git( array( 'templates', 'template-parts' ) );

		self::assertFalse(
			StateDiffer::has_drift( $report ),
			'Every Git template with no database override must read `unchanged`, not `removed`. Without the overlay this exits 2 on a healthy site.'
		);
		self::assertSame(
			array(),
			array_values( array_filter( $report['entries'], static fn ( array $entry ): bool => 'removed' === $entry['status'] ) ),
			'`removed` is a bundle-mode status; it can never appear in Git mode.'
		);
	}

	public function test_a_byte_identical_database_override_is_reported_but_is_not_drift(): void {
		$markup = '<!-- wp:paragraph --><p>Same as Git</p><!-- /wp:paragraph -->';
		$this->write_baseline_template( 'page', $markup );   // see the helper note below
		$this->make_template( 'page', $markup );

		$report = ( new StateDiffer() )->diff_against_git( array( 'templates' ) );
		$entry  = $this->entry( $report, 'templates:page' );

		self::assertSame( 'unchanged', $entry['status'] );
		self::assertFalse( $entry['countsAsDrift'] );
		self::assertTrue( $entry['hasDatabaseOverride'], 'The operator must still be able to see that a row exists.' );
	}

	public function test_a_database_template_override_is_promotable_drift(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Client edit</p><!-- /wp:paragraph -->' );

		$report = ( new StateDiffer() )->diff_against_git( array( 'templates' ) );

		self::assertSame( DriftClassification::PROMOTABLE, $this->entry( $report, 'templates:page' )['classification'] );
		self::assertTrue( StateDiffer::has_drift( $report ) );
	}

	public function test_a_navigation_record_is_reported_but_is_not_drift(): void {
		$this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );

		$report = ( new StateDiffer() )->diff_against_git( array( 'navigation' ) );

		self::assertSame( DriftClassification::DB_OWNED, $this->entry( $report, 'navigation:primary' )['classification'] );
		self::assertFalse( StateDiffer::has_drift( $report ) );
	}

	public function test_custom_css_in_global_styles_is_forbidden_drift(): void {
		$this->make_global_styles( '{"version":3,"styles":{"css":"body{color:red}"}}' );

		$report = ( new StateDiffer() )->diff_against_git( array( 'custom-css' ) );

		self::assertSame( DriftClassification::FORBIDDEN, $this->entry( $report, 'custom-css:global-styles' )['classification'] );
		self::assertTrue( StateDiffer::has_drift( $report ) );
	}

	public function test_custom_css_in_the_custom_css_post_type_is_forbidden_drift(): void {
		wp_update_custom_css_post( '.legacy { color: blue; }' );

		$report = ( new StateDiffer() )->diff_against_git( array( 'custom-css' ) );

		self::assertSame( DriftClassification::FORBIDDEN, $this->entry( $report, 'custom-css:custom-css-post' )['classification'] );
		self::assertTrue( StateDiffer::has_drift( $report ) );
	}

	public function test_a_customised_global_styles_row_is_promotable_drift(): void {
		$this->make_global_styles( '{"version":3,"styles":{"color":{"background":"var(--wp--preset--color--base)"}}}' );

		$report = ( new StateDiffer() )->diff_against_git( array( 'global-styles' ) );

		self::assertSame( DriftClassification::PROMOTABLE, $this->entry( $report, 'global-styles:active' )['classification'] );
		self::assertTrue( StateDiffer::has_drift( $report ) );
	}
```

`entry( array $report, string $key ): array` is a private helper that finds the entry with that key and fails the test when it is absent.

`write_baseline_template( string $slug, string $markup ): void` is a `SeedsStateFixtures` helper that gives the test a controllable Git baseline without writing into the real theme directory: it creates a temporary theme directory containing `templates/<slug>.html`. The test then constructs one `$git = new GitBaseline( null, $temporary_theme_dir )`, constructs matching providers with that same `$git`, and injects their map into the differ:

```php
	$git       = new GitBaseline( null, $temporary_theme_dir );
	$providers = array( 'templates' => new TemplatesState( $git ) );
	$differ    = new StateDiffer( $git, $providers );
```

and thread it into every provider that reads a baseline. Providers therefore take an optional `?GitBaseline` constructor argument too, defaulting to `new GitBaseline()`. Never write into `web/app/themes/site-theme/` from a test — that directory belongs to another task.

The injected provider map is mandatory in this temporary-GitBaseline test. Add an integration assertion that a byte-identical DB row has `hasDatabaseOverride === true` and a Git-only row has `hasDatabaseOverride === false`; this proves that Git-mode gathering forwards the explicit override-key metadata through `compare()`.

- [ ] **Step 6: Write the bundle-mode integration test**

Create `tests/Integration/State/StateDiffBundleModeTest.php`:

```php
	public function test_no_drift_immediately_after_an_export(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Baseline</p><!-- /wp:paragraph -->' );
		$this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );

		$report = ( new StateDiffer() )->diff_against_bundle( StateRegistry::resolve( null, false ), $this->exported_bundle() );

		self::assertFalse( StateDiffer::has_drift( $report ), 'Nothing changed between the export and the diff, so there is no post-export drift.' );
	}

	public function test_a_navigation_change_after_export_counts_as_drift(): void {
		$bundle = $this->exported_bundle();
		$this->update_navigation( 'primary', '<!-- wp:navigation-link {"label":"Changed"} /-->' );

		$report = ( new StateDiffer() )->diff_against_bundle( StateRegistry::resolve( null, false ), $bundle );

		self::assertTrue( StateDiffer::has_drift( $report ) );
	}

	public function test_a_content_change_after_export_counts_as_drift_when_content_was_exported(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->' ) );
		$bundle  = $this->exported_bundle( StateRegistry::resolve( null, true ) );

		wp_update_post( array( 'ID' => $page_id, 'post_content' => '<!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->' ) );

		$report = ( new StateDiffer() )->diff_against_bundle( StateRegistry::resolve( null, true ), $bundle );

		self::assertTrue( StateDiffer::has_drift( $report ) );
		self::assertSame( 'changed', $this->entry( $report, 'content:page-' . $page_id )['status'] );
	}

	public function test_a_provider_missing_from_the_bundle_is_skipped_not_drift(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Only templates were exported</p><!-- /wp:paragraph -->' );
		$bundle = $this->exported_bundle( array( 'templates' ) );

		$this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );

		$report = ( new StateDiffer() )->diff_against_bundle( array( 'templates', 'navigation' ), $bundle );

		self::assertContains( 'navigation', $report['skippedProviders'] );
		self::assertFalse( StateDiffer::has_drift( $report ), 'A provider that was never exported cannot be post-export drift.' );
	}

	public function test_a_bundle_from_another_site_is_a_hard_error(): void {
		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/siteUuid|site UUID/' );

		( new StateDiffer() )->diff_against_bundle( array( 'templates' ), $this->bundle_from_another_site() );
	}

	public function test_an_unverified_bundle_cannot_be_diffed(): void {
		$this->expectException( StateException::class );

		( new StateDiffer() )->diff_against_bundle( array( 'templates' ), StateBundle::from_array( $this->exported_document() ) );
	}
```

Private helpers this class needs:

- `exported_document( ?array $slugs = null ): array` — runs `( new StateExporter( $this->signer() ) )->export( $slugs ?? StateRegistry::resolve( null, false ) )`.
- `exported_bundle( ?array $slugs = null ): StateBundle` — `StateBundle::from_array( $this->exported_document( $slugs ) )` followed by `verify_signature( $this->signer() )`.
- `bundle_from_another_site(): StateBundle` — takes `exported_document()`, replaces `siteUuid` with a different UUID, **re-signs it** with `$this->signer()` (otherwise the test would fail on the signature rather than on the site check it is meant to prove), and verifies it.
- `signer(): HmacSigner` — `new HmacSigner( array( '2026-01' => str_repeat( 'k', 40 ) ), '2026-01' )`.
- `entry( array $report, string $key ): array` — as in the Git-mode test.
- `make_template()`, `make_navigation()`, `make_global_styles()` — reuse the `Tests\Integration\State\SeedsStateFixtures` trait created in Task 8 Step 5.

- [ ] **Step 7: Run the integration suite**

```bash
ddev composer test:integration
```

Expected: PASS.

- [ ] **Step 8: Run the fast gate and commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/StateDiffer.php \
  tests/Unit/AgencyPlatform/State/StateDifferCompareTest.php \
  tests/Integration/State/StateDiffGitModeTest.php \
  tests/Integration/State/StateDiffBundleModeTest.php
git commit -m "feat(state): add the two-mode state differ"
```

---

### Task 12: WP-CLI command surface and subsystem wiring

**Files:**
- Create: `web/app/mu-plugins/agency-platform/src/State/StateCommandResult.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/StateCommandRunner.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/CliOutput.php`
- Create: `web/app/mu-plugins/agency-platform/src/Cli/StateCommands.php`
- Create: `web/app/mu-plugins/agency-platform/src/State/StateSubsystem.php`
- Test: `tests/Unit/AgencyPlatform/State/StateCommandsContractTest.php`
- Test: `tests/Integration/State/StateCommandRunnerTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces:

```php
/**
 * The outcome of one command run: the exit code plus the two streams,
 * captured rather than written. This is the seam that makes the exit-code and
 * stream contracts (BLOCK_THEME_PROPOSAL.md section 6) testable in PHPUnit,
 * where WP_CLI is not loaded at all.
 */
final class StateCommandResult {
	public function __construct(
		public readonly int $exit_code,
		public readonly string $stdout,
		public readonly string $stderr
	);
}

/**
 * Every command body lives here, free of WP_CLI. StateCommands (and the
 * deprecated check-overrides alias) are thin adapters that run a method and
 * emit its result. $stdin_reader is injectable so `--source=-` input is
 * testable without a real pipe.
 *
 * No public method throws: a StateException is caught and turned into a
 * StateCommandResult carrying its exit_code() and its message on STDERR. That
 * is what makes exit codes 1 and 4 assertable.
 */
final class StateCommandRunner {
	/** @param callable():string|null $stdin_reader null reads php://stdin. */
	public function __construct( ?callable $stdin_reader = null, ?GitBaseline $git = null, ?HmacSigner $signer = null );

	/** @param array<string, mixed> $assoc_args */
	public function state_export( array $assoc_args ): StateCommandResult;
	/** @param array<string, mixed> $assoc_args */
	public function state_diff( array $assoc_args ): StateCommandResult;
	/** @param array<string, mixed> $assoc_args */
	public function check_overrides( array $assoc_args ): StateCommandResult;
}

final class CliOutput {
	/** Machine-readable payload — STDOUT only. */
	public static function stdout( string $text ): void;          // WP_CLI::line()
	/** Diagnostics, warnings, progress — STDERR only. */
	public static function notice( string $text ): void;          // WP_CLI::warning()
	/** Writes a captured result to the real streams, then halts with its exit code. */
	public static function emit( StateCommandResult $result ): void;
	/** Halts with an explicit code after any payload has been written. */
	public static function halt( int $exit_code ): void;
}

final class StateCommands {
	public function register(): void;

	/** @return array<string, array{0: class-string, 1: string}> command name => callable. */
	public static function commands(): array;

	/** @param array<int, string> $args @param array<string, mixed> $assoc_args */
	public static function state_export( array $args, array $assoc_args = array() ): void;
	/** @param array<int, string> $args @param array<string, mixed> $assoc_args */
	public static function state_diff( array $args, array $assoc_args = array() ): void;

	/** Pure. @param array<string, mixed> $report */
	public static function diff_exit_code( array $report ): int;   // 0 or 2
	/** Pure. @param array<string, mixed> $report @return string A fixed-width table. */
	public static function format_table( array $report ): string;
}

final class StateSubsystem {
	public function register(): void;   // StateCommands + SiteUuidSanitizeStep
}
```

Each `StateCommands` method is therefore two lines, and so is the deprecated alias in Task 13:

```php
	public static function state_export( array $args, array $assoc_args = array() ): void {
		CliOutput::emit( ( new StateCommandRunner() )->state_export( $assoc_args ) );
	}
```

**`register()` follows the `AgencyCommands` precedent exactly** — guarded so it never touches `WP_CLI` outside a WP-CLI request, because `Plugin::boot()` runs on every web request:

```php
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		foreach ( self::commands() as $name => $callable ) {
			\WP_CLI::add_command( $name, $callable );
		}
	}
```

`StateSubsystem::register()` is what `Plugin.php` gets one line for (Task 13):

```php
	public function register(): void {
		( new SiteUuidSanitizeStep() )->register();
		( new StateCommands() )->register();
	}
```

The sanitize step is registered unconditionally (not behind the WP-CLI guard) so the `agency_platform_sanitize_steps` filter carries it whenever `wp agency sanitize` runs, exactly like `SiteCommerce\Health\CommerceSanitizeStep`.

**`wp agency state-export` contract:**

```
## OPTIONS

--output=<path>
: Where to write the bundle. `-` writes the bundle JSON to STDOUT.
  Required. There is no default output path.

[--providers=<list>]
: Comma-separated provider slugs. Default: every structural provider
  (templates, template-parts, global-styles, navigation, synced-patterns,
  fonts, media-references, custom-css).

[--include-content]
: Also export page/post/CPT content. Off by default: a full content export
  can be very large and carries client content that structural work does not
  need.
```

Behaviour (all inside `StateCommandRunner::state_export()`): reject a missing or blank `--output` with `StateException::hard_error()` before export. Put `StateExporter::sensitivity_warning()` on STDERR first; resolve providers; export; append every `StateExporter::validation_warnings()` line to STDERR; then either write the file atomically (`Normalizer::canonical_json_document()` to a temp file in the same directory, then `rename()`) and put the result envelope on STDOUT, or put the bundle document on STDOUT for `--output=-`. Provider validation warnings are never a JSON field and never text on STDOUT. Exit `0`. Any `StateException` becomes a `StateCommandResult` carrying its `exit_code()` with the message on STDERR and an **empty** STDOUT — a failed run never emits a partial payload.

Result envelope (STDOUT, `--output=<path>` case):

```json
{"output":"/app/var/agency-state/state-….json","exportId":"…","exportedAtUtc":"…","stateHash":"…","providers":{"templates":3,"template-parts":2,"global-styles":1}}
```

**`wp agency state-diff` contract:**

```
## OPTIONS

[--source=<state-bundle>]
: Compare against an exported bundle instead of the Git baseline. `-` reads
  the bundle JSON from STDIN. Without it, current database state is compared
  against the Git baseline files and database-owned providers are
  informational only.

[--providers=<list>]
: Comma-separated provider slugs to compare.

[--include-content]
: Also compare page/post/CPT content.

[--format=<format>]
: table or json. Default: table.
```

Behaviour (all inside `StateCommandRunner::state_diff()`): validate `--format` against `table`/`json` and hard-error on anything else; resolve providers; with `--source`, `StateBundle::load()` (which verifies the signature before anything is read) then `diff_against_bundle()`, else `diff_against_git()`; put the table or `Normalizer::canonical_json_document( $report )` on STDOUT; put every `skippedProviders` entry on STDERR; exit `StateCommands::diff_exit_code( $report )` — `0` no drift, `2` drift. A `StateException` produces its own code with an empty STDOUT (1 hard error, 4 tamper).

- [ ] **Step 1: Write the failing contract unit test**

Create `tests/Unit/AgencyPlatform/State/StateCommandsContractTest.php`:

```php
	public function test_the_registered_command_names_are_exactly_the_two_state_commands(): void {
		self::assertSame( array( 'agency state-export', 'agency state-diff' ), array_keys( StateCommands::commands() ) );
	}

	public function test_every_registered_command_is_a_callable_static_method(): void {
		foreach ( StateCommands::commands() as $name => $callable ) {
			self::assertIsCallable( $callable, $name . ' must be a named static method, never a closure.' );
		}
	}

	public function test_a_report_with_no_drift_exits_zero(): void {
		self::assertSame( 0, StateCommands::diff_exit_code( $this->report( false ) ) );
	}

	public function test_a_report_with_drift_exits_two(): void {
		self::assertSame( 2, StateCommands::diff_exit_code( $this->report( true ) ) );
	}

	public function test_the_table_names_every_entry_and_its_classification(): void {
		$table = StateCommands::format_table( $this->report( true ) );

		self::assertStringContainsString( 'templates:page', $table );
		self::assertStringContainsString( DriftClassification::PROMOTABLE, $table );
	}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter StateCommandsContractTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write `StateCommandResult`, `StateCommandRunner`, `CliOutput`, `StateCommands`, and `StateSubsystem`**

Implementation notes:

- `CliOutput::stdout()` uses `\WP_CLI::line()` (STDOUT). `notice()` uses `\WP_CLI::warning()` (STDERR). Never use `\WP_CLI::log()` in this subsystem — it writes to STDOUT and would corrupt the machine-readable payload.
- `CliOutput::emit()` writes `$result->stderr` line by line through `\WP_CLI::warning()`, then `$result->stdout` verbatim through `\WP_CLI::line()` (only when non-empty), then `\WP_CLI::halt( $result->exit_code )`. Declare its return type `void`, not `never` — the wp-cli stub package does not mark `halt()` as never-returning and PHPStan level 6 would reject it.
- **All command logic lives in `StateCommandRunner`**, which never touches `WP_CLI` and never throws out of a public method. It catches `StateException` and returns a `StateCommandResult` with that exception's `exit_code()` and its message on STDERR. An unexpected `\Throwable` becomes exit `1`.
- Reading `--source=-`: the injected `$stdin_reader`, defaulting to `static fn (): string => (string) file_get_contents( 'php://stdin' )` with `// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI STDIN has no WP_Filesystem equivalent.` immediately before the call. Writing: `file_put_contents()` of `Normalizer::canonical_json_document( $bundle )` to `$path . '.tmp-' . uniqid()`, then `rename()`, with `// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_write_file_put_contents -- atomic CLI artifact write; WP_Filesystem needs credentials and cannot preserve this rename contract.` immediately before the write. Also add the same required ignore with a test-fixture reason before each direct `file_get_contents()` or `file_put_contents()` in the unit and integration examples in this plan.
- `--format` accepts only `table` and `json`; anything else is a `StateException::hard_error()` naming both valid values (exit 1).
- `format_table()` is pure string building — no `WP_CLI\Utils\format_items()`, so it stays unit-testable without WP-CLI.

- [ ] **Step 4: Run the unit test to green**

```bash
ddev exec vendor/bin/phpunit --testsuite unit --filter StateCommandsContractTest
```

Expected: PASS.

- [ ] **Step 4b: Write the real command-contract integration test**

Create `tests/Integration/State/StateCommandRunnerTest.php`. This is the test that actually proves §6's exit-code and stream contract; the unit test only covers the pure helpers.

```php
	private function runner( string $stdin = '' ): StateCommandRunner {
		return new StateCommandRunner(
			static fn (): string => $stdin,
			null,
			new HmacSigner( array( '2026-01' => str_repeat( 'k', 40 ) ), '2026-01' )
		);
	}

	public function test_export_to_stdout_writes_only_json_and_exits_zero(): void {
		$result = $this->runner()->state_export( array( 'output' => '-', 'providers' => 'templates' ) );

		self::assertSame( 0, $result->exit_code );
		self::assertIsArray( json_decode( $result->stdout, true ), 'STDOUT must be parseable JSON and nothing else.' );
		self::assertStringEndsWith( "\n", $result->stdout );
	}

	public function test_the_sensitivity_warning_goes_to_stderr_never_stdout(): void {
		$result = $this->runner()->state_export( array( 'output' => '-' ) );

		self::assertStringContainsString( 'customer content', $result->stderr );
		self::assertStringNotContainsString( 'customer content', $result->stdout );
	}

	public function test_provider_validation_warnings_go_only_to_stderr(): void {
		add_filter( StateRegistry::FILTER, array( self::class, 'append_warning_provider' ) );
		StateRegistry::reset();

		$result = $this->runner()->state_export( array( 'output' => '-', 'providers' => 'warning-provider' ) );

		self::assertSame( 0, $result->exit_code );
		self::assertStringContainsString( 'warning-provider:sample: Deliberate validation warning.', $result->stderr );
		self::assertStringNotContainsString( 'Deliberate validation warning.', $result->stdout );
		self::assertStringNotContainsString( 'Deliberate validation warning.', json_encode( json_decode( $result->stdout, true ) ) );
	}

	public function test_export_to_a_file_writes_canonical_bytes_and_prints_an_envelope(): void {
		$path   = StateDirectory::ensure() . '/test-bundle.json';
		$result = $this->runner()->state_export( array( 'output' => $path, 'providers' => 'templates' ) );

		self::assertSame( 0, $result->exit_code );
		self::assertFileExists( $path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the CLI artifact in an integration assertion; no WP_Filesystem credentials context is available.
		$written = file_get_contents( $path );

		self::assertStringNotContainsString( "\r", $written );
		self::assertSame( $written, Normalizer::canonical_json_document( json_decode( $written, true ) ) );

		$envelope = json_decode( $result->stdout, true );

		self::assertSame( $path, $envelope['output'] );
		self::assertArrayHasKey( 'stateHash', $envelope );

		unlink( $path );
	}

	public function test_an_unknown_provider_exits_one_with_the_message_on_stderr(): void {
		$result = $this->runner()->state_export( array( 'output' => '-', 'providers' => 'nonsense' ) );

		self::assertSame( 1, $result->exit_code );
		self::assertStringContainsString( 'nonsense', $result->stderr );
		self::assertSame( '', $result->stdout, 'A failed run must not emit a partial payload.' );
	}

	public function test_missing_hmac_configuration_exits_one(): void {
		$result = ( new StateCommandRunner( null, null, new HmacSigner( array(), '2026-01' ) ) )->state_export( array( 'output' => '-' ) );

		self::assertSame( 1, $result->exit_code );
		self::assertStringContainsString( 'AGENCY_PROMOTION_HMAC_KEYS', $result->stderr );
	}

	public function test_a_missing_output_is_a_hard_error_with_no_json_stdout(): void {
		$result = $this->runner()->state_export( array( 'providers' => 'templates' ) );

		self::assertSame( 1, $result->exit_code );
		self::assertStringContainsString( '--output', $result->stderr );
		self::assertSame( '', $result->stdout );
	}

	public function test_diff_exits_zero_with_no_drift_and_two_with_drift(): void {
		self::assertSame( 0, $this->runner()->state_diff( array( 'format' => 'json' ) )->exit_code );

		$this->make_template( 'page', '<!-- wp:paragraph --><p>Client edit</p><!-- /wp:paragraph -->' );

		$result = $this->runner()->state_diff( array( 'format' => 'json' ) );

		self::assertSame( 2, $result->exit_code );
		self::assertTrue( json_decode( $result->stdout, true )['summary']['drift'] > 0 );
	}

	public function test_diff_json_format_writes_only_json(): void {
		$result = $this->runner()->state_diff( array( 'format' => 'json' ) );

		self::assertIsArray( json_decode( $result->stdout, true ) );
	}

	public function test_diff_table_format_writes_a_human_table(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Edit</p><!-- /wp:paragraph -->' );

		$result = $this->runner()->state_diff( array( 'format' => 'table' ) );

		self::assertStringContainsString( 'templates:page', $result->stdout );
		self::assertNull( json_decode( $result->stdout, true ) );
	}

	public function test_an_invalid_format_exits_one(): void {
		$result = $this->runner()->state_diff( array( 'format' => 'yaml' ) );

		self::assertSame( 1, $result->exit_code );
		self::assertStringContainsString( 'json', $result->stderr );
	}

	public function test_source_dash_reads_a_bundle_from_stdin(): void {
		$bundle = $this->runner()->state_export( array( 'output' => '-', 'providers' => 'templates' ) )->stdout;

		$result = $this->runner( $bundle )->state_diff( array( 'source' => '-', 'providers' => 'templates', 'format' => 'json' ) );

		self::assertSame( 0, $result->exit_code );
		self::assertSame( 'bundle', json_decode( $result->stdout, true )['mode'] );
	}

	public function test_a_tampered_bundle_on_stdin_exits_four(): void {
		$bundle           = json_decode( $this->runner()->state_export( array( 'output' => '-', 'providers' => 'templates' ) )->stdout, true );
		$bundle['siteUrl'] = 'https://attacker.invalid';

		$result = $this->runner( Normalizer::canonical_json_document( $bundle ) )->state_diff( array( 'source' => '-', 'format' => 'json' ) );

		self::assertSame( 4, $result->exit_code );
		self::assertSame( '', $result->stdout );
	}

	public function test_provider_scoping_reaches_the_report(): void {
		$result = $this->runner()->state_diff( array( 'providers' => 'templates', 'format' => 'json' ) );

		self::assertSame( array( 'templates' ), array_values( array_unique( array_column( json_decode( $result->stdout, true )['entries'], 'provider' ) ) ) );
	}

	public function test_include_content_reaches_the_report(): void {
		self::factory()->post->create( array( 'post_type' => 'page' ) );

		$entries = json_decode( $this->runner()->state_diff( array( 'include-content' => true, 'format' => 'json' ) )->stdout, true )['entries'];

		self::assertContains( 'content', array_column( $entries, 'provider' ) );
	}
```

`append_warning_provider()` is a named static test helper. It adds a test-local `WarningStateProvider` (declared in `StateCommandRunnerTest.php`) with slug `warning-provider`, one deterministic `warning-provider:sample` record, and `validate()` returning `array( 'Deliberate validation warning.' )`. Reset the registry and remove the filter in test teardown. This pins the warning channel without using a production closure or adding any text to the JSON bundle.

- [ ] **Step 4c: Run the integration suite**

```bash
ddev composer test:integration
```

Expected: PASS.

- [ ] **Step 5: Prove the wiring in the integration suite**

Add to `tests/Integration/State/SiteUuidSanitizeTest.php`:

```php
	public function test_the_subsystem_registers_the_sanitize_step(): void {
		( new \AgencyPlatform\State\StateSubsystem() )->register();

		self::assertArrayHasKey( 'site_uuid', SanitizeSteps::steps() );
	}
```

`Plugin::boot()` does not construct `StateSubsystem` until Task 13, so this test constructs it directly.

```bash
ddev composer test:integration
```

Expected: PASS.

- [ ] **Step 6: Manual smoke test inside DDEV**

```bash
ddev exec bash -c 'AGENCY_PROMOTION_HMAC_KEYS='"'"'{"2026-01":"local-development-key-at-least-32-chars"}'"'"' AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID=2026-01 wp agency state-export --output=- --providers=templates 2>/dev/null | head -c 200'
```

This will not work until Task 13 registers the commands in `Plugin.php`. Run it at the end of Task 13 instead and record the output there.

- [ ] **Step 7: Run the fast gate and commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/StateCommandResult.php \
  web/app/mu-plugins/agency-platform/src/State/StateCommandRunner.php \
  web/app/mu-plugins/agency-platform/src/State/CliOutput.php \
  web/app/mu-plugins/agency-platform/src/State/StateSubsystem.php \
  web/app/mu-plugins/agency-platform/src/Cli/StateCommands.php \
  tests/Unit/AgencyPlatform/State/StateCommandsContractTest.php \
  tests/Integration/State/StateCommandRunnerTest.php \
  tests/Integration/State/SiteUuidSanitizeTest.php
git commit -m "feat(state): add the state-export and state-diff WP-CLI commands"
```

---

### Task 13: Rebase-gated wiring and the check-overrides alias

> **HARD GATE. Do not start this task until Task 1 of the migration (`feat/bt-task-1-theme-and-editing`) has merged into `feat/block-theme-fse-migration`.** These are the only edits this whole plan makes to Task-1-owned files, and they are last on purpose: `Plugin.php` and `AgencyCommands.php` have exactly one owner per change (spec §15), and editing them before Task 1 merges guarantees a conflict.

**Proposal §15 ownership-table exception, recorded for this sequenced work only:** Unit 2 owns exactly one fully qualified `StateSubsystem` registration line in `Plugin.php`, after Task 1 merges and before Unit 3 adds its own separately sequenced registration. This plan records the exception; it does not edit `BLOCK_THEME_PROPOSAL.md` or the tracking file. No `use` import, reorder, or other `Plugin.php` change is allowed.

**SECOND ownership transfer, decided and recorded by the orchestrator on 2026-08-05 — `AgencyCommands.php` and its unit test.**

The tracking file grants Unit 1 "all Release 1 behavior in … `src/Cli/AgencyCommands.php`" and separately grants Unit 2 "deletion of `DatabaseOverrideCheck.php`". Those two grants cannot both hold. `AgencyCommands.php:7` imports `DatabaseOverrideCheck` and line 53 constructs it, so deleting the class without editing that file makes every `wp agency check-overrides` invocation fatal. The plan needed an explicit decision rather than an implied one.

**The decision: transfer, do not defer.** Unit 2 owns, in the single commit that deletes the class:

1. `AgencyCommands::check_overrides()` — its body, its docblock, and the two `use` lines. The signature is already correct and does not change.
2. `AgencyCommands::drift_is_failure()` and `AgencyCommands::drift_summary_lines()` — deleted. Both are pure helpers over the `DatabaseOverrideCheck` report shape (`overrides` / `expected` / `synced_patterns`), which nothing produces once the class is gone.
3. `tests/Unit/AgencyPlatform/CheckOverridesReportTest.php` — deleted. **This file is an orchestrator finding, not part of the original audit.** All four of its tests call only those two helpers, and its `report()` fixture hand-builds the same dead shape. Leaving it would keep a passing unit test for a report nothing produces; deleting the helpers without it makes the unit suite fatal on `Call to undefined method`.

Nothing else in `AgencyCommands.php` may change: `sanitize()`, `verify_env()`, and `register()` are untouched. Deferring the alias rewrite was rejected because it would ship a knowingly fatal command, and deferring the deletion was rejected because §11.10's drift reporting then has two competing implementations across a unit boundary. Unit 1 is merged, so this transfer creates no live conflict. The orchestrator records it in the tracking file's shared-file ownership section in the same wave as this correction.

**Files:**
- Modify: `web/app/mu-plugins/agency-platform/src/Plugin.php` (**one** array line)
- Modify: `web/app/mu-plugins/agency-platform/src/Cli/AgencyCommands.php` (`check_overrides()`, the two drift helpers, and the imports — ownership transferred above)
- Modify: `web/app/mu-plugins/agency-platform/src/Health/SanitizeSteps.php` (one docblock line — ownership transferred by the coordinator)
- Modify: `tests/Integration/Environment/EnvironmentSafetyTest.php` (ownership transferred by the coordinator)
- Modify: `scripts/check-database-overrides` (header comment only)
- Delete: `web/app/mu-plugins/agency-platform/src/Health/DatabaseOverrideCheck.php`
- Delete: `tests/Unit/AgencyPlatform/DatabaseOverrideClassifyTest.php`
- Delete: `tests/Unit/AgencyPlatform/CheckOverridesReportTest.php` (ownership transferred above)
- Test: `tests/Integration/State/StateCommandRunnerCheckOverridesTest.php` (new — the renamed runner test, Step 3)
- Test: `tests/Integration/State/CheckOverridesAliasTest.php` (new — the real `wp agency check-overrides` test, Step 3c)

**Verified inventory of every `DatabaseOverrideCheck` reference in the repository** (checked at planning time — re-run the grep in Step 5 to confirm nothing new appeared):

| File | Kind | Action in this task |
|---|---|---|
| `src/Health/DatabaseOverrideCheck.php` | the class | delete |
| `src/Cli/AgencyCommands.php:7` | `use` import | remove |
| `src/Cli/AgencyCommands.php:53` | `new DatabaseOverrideCheck()` | replace with the runner (**line 34 at planning time; Unit 1's `d0da3f3` moved it to 53**) |
| `src/Health/SanitizeSteps.php:24` | docblock prose only | reword |
| `tests/Unit/AgencyPlatform/DatabaseOverrideClassifyTest.php` | whole file | delete |
| `tests/Integration/Environment/EnvironmentSafetyTest.php:7,16,26,139` | docblock, `use`, `@covers`, one test method | rewrite the method against `StateDiffer` |

Without the last two rows the planned grep fails and the integration suite fatals on a missing class. There is **no** compatibility adapter: the class goes, and every reference goes with it in this one commit.

**Interfaces:**
- Consumes: `StateDiffer`, `StateRegistry`, `StateSubsystem`.
- Produces: nothing new — this task only wires what already exists.

- [ ] **Step 1: Verify the orchestrator-created branch still has the required integration base**

```bash
git branch --show-current
git status --porcelain
git merge-base --is-ancestor feat/block-theme-fse-migration HEAD
ddev composer verify:fast
ddev composer test:integration
```

Expected: branch `feat/bt-task-2-state-export-diff`, empty status, ancestry exit `0`, and both suites green. If the branch or ancestry check fails, STOP and report it to the orchestrator. If Task 1's theme conversion broke a state test, fix the owned state code here — `GitBaseline` derives its paths from `get_stylesheet_directory()`, and `templates/*.html` / `parts/*.html` now exist where they did not before. Any test failure at this point is a real integration bug, not a merge artifact.

**Confirm the required regression guard runs and passes.** `StateDiffGitModeTest::test_a_shipped_block_theme_with_no_database_overrides_reports_no_drift()` fails if `templates/index.html` is absent. After this rebase the block theme exists, so it must execute:

```bash
ddev exec bash -c 'WP_INTEGRATION=1 vendor/bin/phpunit --testsuite integration --filter test_a_shipped_block_theme_with_no_database_overrides_reports_no_drift --testdox'
```

Expected: one test, **passed**. A failure means either the required block-theme conversion did not land or the Git-mode overlay is broken and `state-diff` would report drift on a healthy site. Do not continue after a failure.

- [ ] **Step 2: Register the subsystem in `Plugin.php`**

Add **exactly one line** to the `$providers` array, fully qualified. Do **not** add a `use` import — the fixed cross-task boundary permits one registration line and nothing else, and an import would be a second changed line in a file another task owns:

```php
			new AgencyCommands(),
			new \AgencyPlatform\State\StateSubsystem(),
		);
```

`git diff web/app/mu-plugins/agency-platform/src/Plugin.php` must show exactly one added line and zero removed lines. If it shows more, undo and try again.

- [ ] **Step 3: Write the failing runner test**

> **Orchestrator correction, 2026-08-05 — this test was misnamed and its claim was false.** The original step called this file `CheckOverridesAliasTest.php` and said it "drives the **real** alias logic — exit codes, streams, and all". It does not. Every assertion below calls `$this->runner()->check_overrides( … )`, which is `StateCommandRunner`. It never calls `AgencyCommands::check_overrides()`, never registers a WP-CLI command, and never reaches `CliOutput::emit()`. An alias that was deleted, misspelled, wired to the wrong runner method, or fatal on a missing import would pass all five of these tests. That is the same silent-pass failure class as Unit 1's `pages` pattern category and its wrong-docroot install proof.
>
> The file is therefore **renamed** to `tests/Integration/State/StateCommandRunnerCheckOverridesTest.php` and keeps exactly the assertions below. It is a good runner test; it is simply not an alias test. Step 3c adds the real one. Its `@covers` tag names `StateCommandRunner`, which was already true.

Create `tests/Integration/State/StateCommandRunnerCheckOverridesTest.php`. Because Task 12 moved every command body into `StateCommandRunner`, this test pins the runner's contract — exit codes and streams — without WP-CLI being loaded. The assertions are about the command contract, not just a report array:

```php
	public function test_an_override_alone_does_not_fail_the_command(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Client edit</p><!-- /wp:paragraph -->' );

		$result = $this->runner()->check_overrides( array() );

		self::assertSame( 0, $result->exit_code, 'Overrides are expected now; the default run must never fail because one exists.' );
		self::assertStringContainsString( 'templates:page', $result->stdout );
		self::assertStringContainsString( 'deprecated', $result->stderr );
	}

	public function test_fail_on_drift_turns_the_same_run_into_exit_one(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Client edit</p><!-- /wp:paragraph -->' );

		self::assertSame( 1, $this->runner()->check_overrides( array( 'fail-on-drift' => true ) )->exit_code );
	}

	public function test_a_clean_site_exits_zero_with_and_without_the_flag(): void {
		self::assertSame( 0, $this->runner()->check_overrides( array() )->exit_code );
		self::assertSame( 0, $this->runner()->check_overrides( array( 'fail-on-drift' => true ) )->exit_code );
	}

	public function test_the_report_covers_content_changes(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'About us' ) );

		$result = $this->runner()->check_overrides( array() );

		self::assertStringContainsString( 'content:page-' . $page_id, $result->stdout, 'Section 11.10 requires content changes in the report.' );
		self::assertSame( 0, $result->exit_code, 'Content is database-owned, so it can never be Git drift.' );
	}

	public function test_a_content_change_alone_never_trips_fail_on_drift(): void {
		self::factory()->post->create( array( 'post_type' => 'page' ) );

		self::assertSame( 0, $this->runner()->check_overrides( array( 'fail-on-drift' => true ) )->exit_code );
	}
```

Keep the two classification assertions below as well, so the §11.10 vocabulary stays pinned:

```php
<?php
/**
 * BLOCK_THEME_PROPOSAL.md §11.10: database overrides are now expected, so
 * `wp agency check-overrides` is a deprecated, informational drift report
 * built on StateDiffer. It must not fail merely because a legitimate
 * override exists; only --fail-on-drift may produce a non-zero exit.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

use AgencyPlatform\State\DriftClassification;
use AgencyPlatform\State\StateDiffer;
use AgencyPlatform\State\StateRegistry;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\StateCommandRunner
 */
final class CheckOverridesAliasTest extends IntegrationTestCase {

	public function test_a_published_template_override_is_reported_as_promotable_not_as_corruption(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Client edit</p><!-- /wp:paragraph -->' );

		$report = ( new StateDiffer() )->diff_against_git( StateRegistry::resolve( null, false ) );
		$entry  = $this->entry( $report, 'templates:page' );

		self::assertSame( DriftClassification::PROMOTABLE, $entry['classification'] );
		self::assertTrue( StateDiffer::has_drift( $report ) );
	}

	public function test_synced_patterns_and_navigation_stay_informational(): void {
		$this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );

		$report = ( new StateDiffer() )->diff_against_git( StateRegistry::resolve( null, false ) );

		self::assertFalse( StateDiffer::has_drift( $report ), 'Database-owned providers alone must never make check-overrides report drift.' );
	}
}
```

`make_template()`, `make_navigation()`, and `entry()` come from the `Tests\Integration\State\SeedsStateFixtures` trait created in Task 8 Step 5.

- [ ] **Step 3c: Write the REAL alias test — the one that fails when the alias is broken**

Added by the orchestrator on 2026-08-05. Step 3 pins the runner. Nothing above it executes the command a human actually types, so nothing above it can tell you that `wp agency check-overrides` still works. This step closes that hole and it is a required gate, not an optional extra.

Create `tests/Integration/State/CheckOverridesAliasTest.php`. It shells out to real WP-CLI inside DDEV, so it exercises `AgencyCommands::register()`, the `WP_CLI::add_command` registration, the `check_overrides()` body, `CliOutput::emit()`, and the process exit code as one path:

```php
	/**
	 * @return array{exit_code: int, stdout: string, stderr: string}
	 */
	private function wp_cli( string ...$arguments ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- CLI-only test harness; proc_open is the only way to observe a real WP-CLI exit code and both streams separately.
		…
	}

	public function test_the_registered_command_runs_and_reports_without_failing(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Client edit</p><!-- /wp:paragraph -->' );

		$result = $this->wp_cli( 'agency', 'check-overrides' );

		self::assertSame( 0, $result['exit_code'], 'The default run must never fail because a client edited a template.' );
		self::assertStringContainsString( 'templates:page', $result['stdout'] );
		self::assertStringContainsString( 'deprecated', $result['stderr'] );
	}

	public function test_the_registered_command_honours_fail_on_drift(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Client edit</p><!-- /wp:paragraph -->' );

		self::assertSame( 1, $this->wp_cli( 'agency', 'check-overrides', '--fail-on-drift' )['exit_code'] );
	}
```

**Both flag cases are required.** A single case would pass against a command wired to ignore `$assoc_args` entirely.

**Prove it is not vacuous before you commit it.** Comment out the `\WP_CLI::add_command( 'agency check-overrides', … )` line, run this file, and record the exact failure text in your report. Then restore the line and confirm it passes. A test that cannot be shown to fail for the right reason is not evidence. If WP-CLI is unreachable from the integration suite, that is a FAILED gate: assert the failure directly and stop with evidence. Do not convert it to a PHPUnit skip — Global Constraints and the tracking file both forbid turning a required case into a skip.

- [ ] **Step 4: Rewire `check_overrides()`**

**The signature is already correct on this branch; only the BODY changes.** (Orchestrator correction, 2026-08-05. This step previously claimed the method "takes no arguments at all". That was true when the plan was written and is false on `feat/block-theme-fse-migration`: Unit 1's Task 11 shipped `d0da3f3`, which gave `check_overrides()` the signature below at `AgencyCommands.php:52`, added the `--fail-on-drift` flag, and added the pure helpers `drift_is_failure()` at line 86 and `drift_summary_lines()` at line 94. Verify with `git show HEAD:web/app/mu-plugins/agency-platform/src/Cli/AgencyCommands.php` before you start; do not "fix" a signature that is already right.)

Keep the signature and the `phpcs:ignore` line exactly as they are. Replace the method body with one line:

```php
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $args is required by the WP-CLI command signature (positional args precede $assoc_args); this command takes no positional arguments.
	public static function check_overrides( array $args, array $assoc_args = array() ): void {
		CliOutput::emit( ( new StateCommandRunner() )->check_overrides( $assoc_args ) );
	}
```

`drift_is_failure()` and `drift_summary_lines()` exist only to serve the old `DatabaseOverrideCheck` report shape, which this task deletes. Delete both, and delete `tests/Unit/AgencyPlatform/CheckOverridesReportTest.php` with them — all four of its tests call only those two helpers. Run `grep -rn "drift_is_failure\|drift_summary_lines" --include=*.php .` first and confirm the only remaining hits are the two definitions and that one test file. If anything else appears, stop and report it instead of widening the deletion.

Flag parsing lives in `StateCommandRunner::check_overrides()` and is exactly `$fail_on_drift = ! empty( $assoc_args['fail-on-drift'] );` — WP-CLI normalises `--fail-on-drift` to the key `fail-on-drift`.

`StateCommandRunner::check_overrides()` behaviour:

- Builds `$report = ( new StateDiffer( $this->git ) )->diff_against_git( StateRegistry::resolve( null, true ) );` — **`true`, include content.** §11.10 requires the report to cover "templates, parts, Global Styles, navigation, synced patterns, fonts, **and content** changes". Content is database-owned, so it can never make the exit code non-zero in Git mode; including it costs only report lines. Say so in the docblock, and point large sites at `wp agency state-diff --providers=…` for a narrower run.
- Writes the per-classification counts, every drifting entry, and every entry whose `hasDatabaseOverride` is true to **STDOUT** (this command is human-facing and is deliberately **not** part of the machine-readable STDOUT contract — say so in the docblock).
- Writes `check-overrides is deprecated. Use \`wp agency state-diff\` for the machine-readable report.` to **STDERR**.
- No drift → exit `0` with `No drift against the Git baseline.`
- Drift **without** `--fail-on-drift` → exit `0` with `%d record(s) differ from the Git baseline. Database overrides are expected under the block-theme editing model; this report is informational.`
- Drift **with** `--fail-on-drift` → exit `1` with `%d record(s) differ from the Git baseline (--fail-on-drift).` on STDERR (plan decision 9 — deliberately 1, not 2, so no Task-1 test assertion needs changing).

Update the method docblock to state that overrides are expected, that the command is a deprecated alias for `state-diff`, that it includes content by default, and that the detection now runs through `AgencyPlatform\State\StateDiffer`. Remove the `use AgencyPlatform\Health\DatabaseOverrideCheck;` import and add `use AgencyPlatform\State\CliOutput;` and `use AgencyPlatform\State\StateCommandRunner;`.

- [ ] **Step 4b: Retire the two remaining `DatabaseOverrideCheck` references**

In `src/Health/SanitizeSteps.php`, the class docblock's last paragraph ends with "…mirroring DatabaseOverrideCheck's classify()/run() split." Replace that clause so it names a class that still exists:

```
 * Pure helpers (the synthetic address builders and the user-query shape) are
 * split out from the WordPress-coupled step bodies so they can be unit-tested
 * without a database, mirroring the pure/WordPress-coupled split the state
 * subsystem uses (AgencyPlatform\State\StateDiffer::compare() and its
 * database-reading callers).
```

In `tests/Integration/Environment/EnvironmentSafetyTest.php`, make four edits and nothing else:

1. File docblock: replace "and DatabaseOverrideCheck reports a clean baseline on a fresh install" with "and a fresh install reports no drift against the Git baseline".
2. Replace `use AgencyPlatform\Health\DatabaseOverrideCheck;` with `use AgencyPlatform\State\StateDiffer;` and `use AgencyPlatform\State\StateRegistry;`.
3. Replace `@covers \AgencyPlatform\Health\DatabaseOverrideCheck` with `@covers \AgencyPlatform\State\StateDiffer`.
4. Replace the whole test method:

```php
	public function test_a_fresh_install_reports_no_drift_against_the_git_baseline(): void {
		$report = ( new StateDiffer() )->diff_against_git( StateRegistry::resolve( null, false ) );

		self::assertFalse(
			StateDiffer::has_drift( $report ),
			'A fresh install has no client overrides and no Additional CSS, so nothing may register as drift.'
		);
	}
```

- [ ] **Step 5: Delete the superseded check**

```bash
git rm web/app/mu-plugins/agency-platform/src/Health/DatabaseOverrideCheck.php \
  tests/Unit/AgencyPlatform/DatabaseOverrideClassifyTest.php \
  tests/Unit/AgencyPlatform/CheckOverridesReportTest.php
ddev exec bash -c 'rg -n --glob "*.php" "DatabaseOverrideCheck" web tests scripts; status=$?; if [ "$status" -eq 0 ]; then exit 1; fi; if [ "$status" -eq 1 ]; then exit 0; fi; exit "$status"'
ddev exec bash -c 'rg -n --glob "*.php" "drift_is_failure|drift_summary_lines" web tests scripts; status=$?; if [ "$status" -eq 0 ]; then exit 1; fi; if [ "$status" -eq 1 ]; then exit 0; fi; exit "$status"'
```

`CheckOverridesReportTest.php` is deleted here under the ownership transfer recorded at the top of this task: it tests only the two drift helpers Step 4 removes, over the report shape this step deletes.

Expected: `rg` returns no matches and the DDEV command exits `0`, because Step 4 and Step 4b removed the four remaining references. Any match makes the command fail. Inspect the reported file; if it is not listed in this task's inventory table, stop and report it rather than editing that file.

- [ ] **Step 6: Update the wrapper script's header**

In `scripts/check-database-overrides`, replace the header comment with wording that matches the new semantics. The `exec` lines do not change:

```bash
# Reports database state that differs from the Git baseline (templates,
# template parts, Global Styles, navigation, synced patterns, fonts, media
# references, and Additional CSS). Thin wrapper around the deprecated
# `wp agency check-overrides` WP-CLI command (see the agency-platform
# mu-plugin) so it is runnable the same way from a host or CI as from inside
# the container.
#
# Database overrides are EXPECTED under the block-theme editing model: this
# reports drift and exits 0 by default. Pass --fail-on-drift to make drift a
# non-zero exit for a CI gate. For a machine-readable report, prefer
# `wp agency state-diff --format=json`.
```

- [ ] **Step 7: Run every gate**

```bash
ddev composer verify:fast
ddev composer test:integration
```

Expected: both green.

- [ ] **Step 8: Record the documentation wording this task deliberately does not change**

This task owns no file under `docs/` or `ops/`, so the §11.10 wording sweep is handed to Task 4's documentation phase. **Do not edit these files.** Copy this list verbatim into the planner report and into the task's completion note:

| File | Line (at planning time) | Stale claim to replace |
|---|---|---|
| `docs/architecture.md` | 82–84 | "Database-resident *structural* overrides … are forbidden and detected by `wp agency check-overrides`" — overrides are now expected and promotable. |
| `docs/architecture.md` | 97 | The `check-overrides` description must say "reports drift" and mention `wp agency state-export` / `state-diff`. |
| `docs/editing-strictness.md` | 22 | "`wp agency check-overrides` fails if a …" — it no longer fails by default. |
| `docs/validation-scenarios.md` | 236–245 | The expected-failure transcript still shows `Error: Database overrides found — Git owns templates/template-parts.` |
| `ops/update-process.md` | 39 | Step 7 should call `wp agency check-overrides --fail-on-drift` (or `state-diff`) if the deploy is meant to gate on drift. |
| `ops/restore.md` | 47–48 | "runs clean (or reports only the overrides you expect)" — reword to drift language. |
| `ops/backup.md` | 13–14 | "any database template/style overrides `wp agency check-overrides` would report" — add the state-bundle/`AGENCY_STATE_DIR` note. |

Task 1 owns the first four and may already have updated them; verify after the rebase and report anything still stale. Task 4 owns the `ops/` three.

- [ ] **Step 9: Smoke-test the real command surface**

```bash
ddev exec bash -c 'export AGENCY_PROMOTION_HMAC_KEYS='"'"'{"2026-01":"local-development-key-at-least-32-chars"}'"'"'; export AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID=2026-01; wp agency state-export --output=- --providers=templates,global-styles > /tmp/bundle.json 2>/tmp/bundle.err; echo "exit=$?"; head -c 300 /tmp/bundle.json; echo; cat /tmp/bundle.err'
ddev exec bash -c 'export AGENCY_PROMOTION_HMAC_KEYS='"'"'{"2026-01":"local-development-key-at-least-32-chars"}'"'"'; export AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID=2026-01; wp agency state-diff --format=json > /tmp/diff.json 2>/dev/null; echo "exit=$?"'
ddev exec wp agency check-overrides; echo "exit=$?"
```

Confirm and record: the bundle JSON is on STDOUT and the sensitivity warning is on STDERR; `state-diff` exits 0 with no drift and 2 after you seed a template override; `check-overrides` exits 0 even with an override present; `wp agency check-overrides --fail-on-drift` exits 1 with one present.

- [ ] **Step 10: Confirm nothing sensitive is staged**

```bash
git status --porcelain
git diff --cached --stat
```

Expected: no file under `var/`, no `*.json` bundle, no `.env`.

- [ ] **Step 11: Commit**

```bash
git add web/app/mu-plugins/agency-platform/src/Plugin.php \
  web/app/mu-plugins/agency-platform/src/Cli/AgencyCommands.php \
  web/app/mu-plugins/agency-platform/src/Health/SanitizeSteps.php \
  tests/Integration/Environment/EnvironmentSafetyTest.php \
  scripts/check-database-overrides \
  tests/Integration/State/StateCommandRunnerCheckOverridesTest.php \
  tests/Integration/State/CheckOverridesAliasTest.php
ddev composer verify:fast
git commit -m "feat(state): wire the state subsystem and rebuild check-overrides on the differ"
```

---

## Definition of done — Release 2 gate (`BLOCK_THEME_PROPOSAL.md` §13)

Tick every line with evidence, not assertion.

- [ ] **State export covers every provider listed in the brief, with `--providers` / `--include-content` scoping.** Evidence: `tests/Integration/State/StateExportTest.php` asserts the default set equals `StateRegistry::STRUCTURAL_SLUGS`, that `content` is absent by default and present with `--include-content`, and that `--providers` narrows it.
- [ ] **Deterministic `stateHash`.** Evidence: `test_repeated_exports_of_unchanged_state_produce_an_identical_state_hash` asserts identical `stateHash`, identical provider record sets (ordering, per-record hashes, normalised data), and a differing `exportId`; `test_no_excluded_wrapper_field_participates_in_the_state_hash` mutates every bundle wrapper field and every provider metadata field (`slug`, `ownership`, `promotion`, `hasGitBaseline`) directly; `StateBundleTest` proves any record-field change changes the hash; `NormalizerTest` pins `1` vs `1.0`, negative zero, non-finite rejection, `serialize_precision` independence, Unicode, slashes, CRLF, and reordered maps; `test_reference_order_is_independent_of_attribute_map_order` pins reference ordering.
- [ ] **Canonical output bytes.** Evidence: `test_the_written_bundle_is_canonical_bytes_with_one_trailing_lf` and `test_export_to_a_file_writes_canonical_bytes_and_prints_an_envelope` assert one LF ending, no CR, and encode idempotence.
- [ ] **HMAC-signed bundles with a keyring and a separate purpose prefix.** Evidence: `HmacSignerTest` (13 cases incl. rotation, unknown key id, empty keyring hard failure, and both cross-purpose replay directions) plus the integration assertions that a real export verifies under `PURPOSE_BUNDLE` and fails under `PURPOSE_MANIFEST`.
- [ ] **`state-diff` two-mode contract with correct exit codes.** Evidence: `StateDifferCompareTest` for the classification/drift matrix, `StateDiffGitModeTest` (database-owned providers are not Git drift), `StateDiffBundleModeTest` (a post-export navigation change IS drift), and `StateCommandRunnerTest::test_diff_exits_zero_with_no_drift_and_two_with_drift`.
- [ ] **A healthy shipped block theme reports no drift.** Evidence: `StateDiffGitModeTest::test_a_shipped_block_theme_with_no_database_overrides_reports_no_drift` passes after the Task 13 rebase (Task 13 Step 1); a missing `templates/index.html` fails the test. The pure `test_a_git_only_template_set_produces_no_drift_at_all` and the two `overlay()` tests give additional coverage.
- [ ] **Real command exit and stream contracts.** Evidence: `StateCommandRunnerTest` asserts required `--output` (including `--output=-`), `--source=-`, exit `0`/`1`/`2`/`4`, JSON-only STDOUT, STDERR-only sensitivity and provider-validation diagnostics, an invalid `--format`, provider scoping, and `--include-content`; `CheckOverridesAliasTest` drives the real alias body for both `--fail-on-drift` states.
- [ ] **Schema validation with `swaggest/json-schema` in `require`.** Evidence: `composer.json`'s `require` block; `SchemaValidatorTest`; the integration assertion that every export validates.
- [ ] **`wp agency sanitize` regenerates the site UUID idempotently on non-production imports.** Evidence: `SiteUuidDecisionTest` (truth table) and `SiteUuidSanitizeTest` (regenerate once, preserve on re-run, registered through the filter, non-autoloaded option).
- [ ] **§11.10 drift reporting.** Evidence: all five `DriftClassification` values are asserted; custom CSS is detected in **both** Global Styles and the `custom_css` post type; the report covers content (`test_the_report_covers_content_changes`); `check-overrides` exits 0 with an override present and 1 only with `--fail-on-drift`.
- [ ] **The §7.1 provider contract is complete.** Evidence: `StateProvider` declares export, normalisation, stable slug, stable record key, diff, promotability, reference detection, validation, and — through `promotion_strategy()` — prepare, finalise/reset, and restore. `PromotionStrategiesTest` proves the delegation point exists, ships empty, resolves by slug, and rejects a non-strategy value.
- [ ] **`DatabaseOverrideCheck` is fully retired.** Evidence: the DDEV-safe `rg -n --glob "*.php" "DatabaseOverrideCheck" web tests scripts` absence check exits `0` only when it returns no matches, and `ddev composer test:integration` is green with the class deleted.
- [ ] **§7.3 sensitive-data handling.** Evidence: `ExportSensitiveDataTest` proves no user email, session token, application password, or post password reaches the bundle; `.gitignore` contains exactly `/var/agency-state/`; the export prints `StateExporter::sensitivity_warning()` and provider validation warnings to STDERR only.
- [ ] **§7.4 reference detection.** Evidence: `ReferenceScannerTest` covers every kind in the matcher table and the fixed key set; `ReferenceResolverTest` proves a navigation reference carries `targetKey`, a provider-identical `targetHash`, and `targetIdentity`, that a site-logo reference gets its ID from the `site_logo` option, that a templates-only export still carries the navigation hash (which is what makes §7.4's v1 navigation policy enforceable later), that a dangling reference stays unresolved, and that resolution does not recurse.
- [ ] **No state bundle, backup payload, secret, or customer data is committed; `git status` is clean.** Evidence: Task 13 Step 10.
- [ ] **Full gate green:** `ddev composer verify:fast` and `ddev composer test:integration` both pass on the rebased branch.

**Explicitly NOT in this task's scope** (Task 3 of the migration owns them, and no line above depends on them): `promote-overrides` in any form, promotion manifests, locking, heartbeat, backups, rollback, `scripts/promote-overrides`, the Theme JSON adapter, and Global Styles *promotion*. Global Styles here is export-and-diff only, exactly as §13's Release 4 note requires.
