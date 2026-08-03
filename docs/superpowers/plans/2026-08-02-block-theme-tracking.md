# Block-theme migration — execution tracking

Master specification: `BLOCK_THEME_PROPOSAL.md`.

The detailed implementation remains in the four task-plan files in this directory. This file defines the authoritative execution order, branch boundaries, shared-file ownership, review policy, and final merge policy. When a detailed plan conflicts with this file, this file controls execution and the orchestrator must correct the detailed plan before continuing.

The `cinq-wp` back-port remains outside this engagement. Do not create or change `cinq-wp` files.

## Status table

| Unit | Plan file and scope | Gate | Depends on | Branch | Status |
|---|---|---|---|---|---|
| 0 | Planning pack, clean base, integration branch | Execution ready | — | `feat/block-theme-fse-migration` | merged |
| 1 | `2026-08-02-block-theme-task-1-theme-and-editing.md` | Release 1 | 0 | `feat/bt-task-1-theme-and-editing` | in-progress |
| 2 | `2026-08-02-block-theme-task-2-state-export-diff.md` | Release 2 | 1 | `feat/bt-task-2-state-export-diff` | planned |
| 3A | `2026-08-02-block-theme-task-3-promotion-lifecycle.md`, Tasks 1–21 | Release 3 | 2 | `feat/bt-task-3-promotion-lifecycle` | planned |
| 3B | `2026-08-02-block-theme-task-3-promotion-lifecycle.md`, Tasks 22–23 | Release 4 | 3A | `feat/bt-task-3-global-styles` | planned |
| 4A | `2026-08-02-block-theme-task-4-commerce-hardening.md`, Phase A | Commerce profile | 3B | `feat/bt-task-4-commerce-hardening` | planned |
| 4B | `2026-08-02-block-theme-task-4-commerce-hardening.md`, Phase B | Final closure | 4A | `feat/bt-task-4-final-hardening` | planned |

Statuses: `planned` → `in-progress` → `in-review` → `merged`. Use `blocked` only with the exact blocker and evidence.

## Unit 0 — execution readiness

The orchestrator completes every item before Unit 1 starts:

1. Record the current local `main` SHA and `origin/main` SHA.
2. Review the five local commits that are ahead of `origin/main`. Preserve them. Do not reset or discard them.
3. Fix the accidental `README.md` text insertion. Commit it separately only when the corrected line differs from `HEAD`. When the repair restores the tracked line exactly, record the no-op and do not create an empty commit.
4. Commit `BLOCK_THEME_PROPOSAL.md`, the four task plans, this tracking file, and the execution handoff prompt.
5. Confirm `git status --porcelain` is empty.
6. Create `feat/block-theme-fse-migration` from the recorded local `main` SHA.
7. Push the integration branch because the migration-baseline workflow must run from GitHub.
8. Confirm DDEV, Docker, Node 22 or newer, npm 10 for lock generation, Composer through DDEV, authenticated Windows Git access through Git Credential Manager, and GitHub Actions access through the connected GitHub app. Do not require a second GitHub CLI token when the existing Windows and app connections provide the required operations.
9. Confirm the task runner can open and inspect downloaded PNG artifacts.
10. Confirm the Claude Code command and the requested Opus 5 model are callable before the final-review gate. If they are unavailable, report the blocker before Unit 1 starts. Do not claim that another model performed the requested independent review.

## Execution order

Run the units in the status-table order. Do not run implementation units in parallel.

This repository uses the fixed DDEV project name `agency-starter`. Parallel worktrees would otherwise share or replace one DDEV project and database. Only one task worktree may own the DDEV project at a time.

Before a new unit starts:

1. Stop the prior worktree's DDEV project with `ddev stop`.
2. Merge the prior unit into `feat/block-theme-fse-migration`.
3. Run the prior unit's integration-branch gate.
4. Create the next branch and worktree from the updated integration branch.
5. Start DDEV from that worktree only.

Do not use `ddev delete` against the main checkout. A plan step that needs a destructive fresh-install proof must use its named disposable proof project or a verified disposable task worktree.

## Shared-file ownership

Only the orchestrator edits this tracking file. Workers return status, commit SHAs, tests, and blockers to the orchestrator. Workers do not commit this file from task branches.

The existing cross-task ownership grants remain:

- Unit 1 owns the role and editor policy, all Release 1 behavior in `web/app/mu-plugins/agency-platform/src/Plugin.php` and `src/Cli/AgencyCommands.php`, `scripts/setup`, the base `.github/workflows/ci.yml` conversion, `tests/support/BlockIndexGenerator.php`, the `WooCommerceIsolationTest` wording, and the narrow base capability-matrix change in `tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest.php`.
- Unit 2 owns `.env.example` entries for state export, `Health/SanitizeSteps.php`, `EnvironmentSafetyTest.php`, deletion of `DatabaseOverrideCheck.php`, and its sequenced one-line `Plugin.php` registration.
- Unit 3A owns the promotion Playwright script, promotion CI steps, and its sequenced one-line `Plugin.php` registration.
- Unit 3B owns only the Theme JSON adapter, `PromotionStrategyRegistrarTest.php`, and the Global Styles promotion surface named in Tasks 22–23.
- Unit 4A owns commerce templates, commerce patterns, commerce tests, `scripts/enable-commerce`, `CommerceBoundaryTest.php`, and the commerce routing documentation. Unit 1 owns the PHP-symbol wording in `WooCommerceIsolationTest.php`; Unit 4A owns the HTML block-markup boundary. Add no WooCommerce allow-list entry unless the scanner proves that a new scanned PHP symbol needs one.
- Unit 4B owns the state runbook, `README.md`, `ops/**`, the remaining documentation and operations sweep, the proof records, and the final report. Unit 1 documentation is limited to `AGENTS.md`, `docs/architecture.md`, `docs/editing-strictness.md`, `docs/ownership-rules.md`, `docs/adding-a-block.md`, `docs/validation-scenarios.md`, `docs/generated-block-index.md`, and `docs/block-theme-migration-baseline.md`.

`PromotionStrategy`, `PromotionStrategies`, and `SchemaValidator::SCHEMA_PROMOTION_MANIFEST` are Unit 2 interfaces. Unit 3 implements and registers the strategies.

## Model and review policy

The primary session uses Claude Code Opus 5 as the orchestrator. The user made this change on 2026-08-03 after the earlier `gpt-5.6-sol` session stopped. Opus 5 owns orchestration, integration decisions, plan and tracking corrections, final verification, and the single final independent review. Opus 5 does not perform bounded implementation work while a Codex worker is callable.

Record the model and the reasoning level for every delegated task in the log below. Never record a model that did not run.

When `gpt-5.6-luna` is callable, use it with max reasoning for bounded implementation steps. Give it one plan task, its owned files, the exact acceptance checks, and the required report. Run code-writing workers in the exact task worktree with `codex exec -m gpt-5.6-luna -c 'model_reasoning_effort="max"' -C <worktree>`. Keep the returned thread identifier so a bounded fix can resume the same task context. If any runner reports a lower reasoning level, stop that code-writing task and replace it with the exact CLI invocation. The Sol orchestrator reviews every returned diff and runs the required gates. If Luna is not callable, the Sol session performs the work itself and records that fallback. Never claim that Luna ran when it did not.

Use Terra high for each bounded task review and for every accepted review-fix wave, including fixes from the final Opus review. Keep the Terra input limited to the task brief, the review findings, the affected diff, and the required tests. The Sol orchestrator reviews every Terra fix diff and runs the affected gates. This replaces the earlier instruction to use Luna for accepted review fixes. Read-only plan audits that produced no code do not need a second run when the app displayed a lower reasoning level.

Run a Sol high-reasoning review at the end of each execution unit. Do not merge a unit with unresolved critical or important findings.

After Unit 4B is merged into the integration branch, the Opus 5 orchestrator runs its final review exactly once over the complete diff from the recorded base SHA to `feat/block-theme-fse-migration`. Save its report outside tracked customer-state paths. Classify every finding as accepted or rejected with evidence.

Known reduction in independence: Opus 5 now writes no implementation code but does orchestrate the work it later reviews. The earlier plan gave the final review to a model that had seen none of the execution. The user accepted this reduction when it made Opus 5 the orchestrator. The per-unit Terra and Sol reviews are the compensating independent gates, and every unit still passes a Sol review by a model that did not write the diff.

Use Terra high for accepted fixes. Use Sol high as the fallback. Then run a Sol high-reasoning review of the fix diff. The final Opus review is not a substitute for this post-fix review.

## Required CI inputs

Task 1's two image workflows are orchestrator gates. The workflow files must provide an agent-runnable push trigger in addition to `workflow_dispatch` when no authenticated workflow-dispatch tool is available. The orchestrator uses Windows Git Credential Manager to push the exact trigger ref and the connected GitHub app to inspect runs and download artifacts. It inspects every PNG, updates the metadata, and commits the accepted files. No second GitHub CLI token or separate human action is required.

Promotion CI uses a fixed or generated test-only HMAC key inside the isolated CI job. It does not depend on a repository secret. Production still requires `AGENCY_PROMOTION_HMAC_KEYS` and `AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID` from the host secret store.

A required test must fail when its environment is unavailable. It must not skip and leave a green release gate. Profile-selection skips and viewport-selection skips remain allowed when another job or project executes the required case.

## Unit merge gate

For each unit:

1. Run `ddev composer verify:fast` before every commit.
2. Run every additional suite named by the unit plan.
3. Run the Sol unit review.
4. Apply accepted findings.
5. Re-run the affected suites and `ddev composer verify:fast`.
6. Confirm `git status --porcelain` is empty.
7. Merge the unit into `feat/block-theme-fse-migration` without squashing away useful checkpoints.
8. Update this file on the integration branch with status, commit range, and gate results.

## Final direct-to-main gate

The orchestrator may push to `main` directly. It must not force-push.

Before the push:

1. Fetch `origin`.
2. If `origin/main` changed from the recorded base, integrate it into the integration branch and repeat the full final verification and final Sol review.
3. Complete the Opus review, accepted fixes, and Sol fix review.
4. Run every command in `BLOCK_THEME_PROPOSAL.md` section 12 for the base and commerce profiles.
5. Complete the fresh-clone proof and the isolated-target promotion proof.
6. Confirm all required GitHub Actions jobs pass without a required-test skip.
7. Confirm no bundle, manifest instance, backup payload, secret, `.env`, or customer state is tracked.
8. Confirm `git status --porcelain` is empty.
9. Merge `feat/block-theme-fse-migration` into local `main`.
10. Push with `git push origin main`.
11. Report the pushed SHA and all verification evidence.

## Log

- 2026-08-02: Initial four-plan planning and correction wave completed.
- 2026-08-03: Execution controls corrected. Work is now serialized because all worktrees otherwise share the `agency-starter` DDEV project. Release 3 and Release 4 now use separate branches and status gates. The orchestrator owns tracking. Required CI tests may not silently skip. Final delivery uses a reviewed direct push to `main` without force.
- 2026-08-03: Unit 0 recorded local `main` `18dfa90f8f319b78373ca752faf9cb8f1a097d48` and `origin/main` `c6e0b537c9b60c0f6355428908abf1614e6cc27f`. The five local commits are preserved. A new high-severity Composer advisory made the existing `verify:fast` baseline fail because `wp-coding-standards/wpcs` was locked at 3.4.0. Unit 0 adds a separate security lock update to 3.4.1 before the README and planning-pack commits.
- 2026-08-03: The accidental README text was an uncommitted working-tree insertion. The required one-line repair restored the tracked `HEAD` text exactly, so `git diff -- README.md` is empty. Unit 0 does not create an empty repair commit.
- 2026-08-03: The model policy was corrected after the Codex app displayed lower reasoning on read-only Luna plan audits. Code-writing tasks now use the local Codex CLI with explicit `gpt-5.6-luna` and max reasoning in the exact worktree. Terra high owns bounded reviews and all accepted review fixes. Sol high remains the unit and fix-diff reviewer. The exact Luna max CLI call completed successfully before Unit 1.
- 2026-08-03: Terra high corrected all four implementation plans after the read-only audit wave. The corrections fix unit ownership, commit gates, required-environment failures, deterministic state hashing, provider seams, staged promotion contracts, raw Theme JSON writes, lock release, commerce profile safety, exact fresh-clone inputs, wrapper verification, and the complete Global Styles proof. Sol reviewed the corrected cross-plan controls before the planning-pack commit.
- 2026-08-03: Unit 0 pushed `feat/block-theme-fse-migration` at `92df012d484b6604f82399890826df01e2864b46` through Windows Git Credential Manager and confirmed GitHub Actions read access through the connected GitHub app. This matches the existing Claude Code access path. The execution does not copy the Windows Git credential into a separate CLI store. Task 1 must add agent-runnable push triggers for its two image gates while retaining `workflow_dispatch` for maintainers.
- 2026-08-03: Orchestrator handover. The `gpt-5.6-sol` session stopped after Unit 0. Claude Code Opus 5 is now the orchestrator by user instruction. Unit 0 is confirmed complete and merged. No later unit had authorization from the stopped session, so execution restarts at Unit 1.
- 2026-08-03: Environment re-verified for the new orchestrator. DDEV v1.25.3 runs inside WSL2 Ubuntu, not on Windows; the `agency-starter` project is registered at the `/mnt/c/...` approot of this checkout. Every PHP and Composer command therefore runs as `wsl -d Ubuntu -e bash -lc "cd /mnt/c/Users/Aleksandar/Projects/wordpress-template && ddev composer <script>"`. Docker 29.4.3, Node v24.16.0, and npm 11.16.0 are on Windows. npm lock regeneration still uses `npx -y npm@10 install` because CI runs npm 10.
- 2026-08-03: GitHub access re-verified. `gh` is not installed. The Git Credential Manager token for `aleksandar-djabirski` returns HTTP 200 from the REST API with repository admin permission and carries the `repo` and `workflow` scopes. The orchestrator can therefore start a workflow dispatch, list runs, and download artifacts over REST, and can push a trigger ref with Windows Git. The repository is public and its default branch is `main`.
- 2026-08-03: Baseline gate re-run before any Unit 1 work. `ddev composer verify:fast` passed on `92df012` with deptrac at 0 violations, PHPStan clean, 22 architecture tests and 121 unit tests green.
- 2026-08-03: Task 1 plan corrected for the image gates, per the Required CI inputs rule. `on.push.branches` gains only `ci-capture/**`. `migration-baseline-capture` and the four visual-baseline steps now accept both `workflow_dispatch` and their dedicated capture ref. `e2e` and `commerce-e2e` skip the migration capture ref so the push costs no wasted WordPress boot. The parity step excludes both capture refs. Capture branches are disposable, are never merged, and no job commits a PNG.
- 2026-08-03: Model policy confirmed against the local Codex CLI 0.145.0 model cache. `gpt-5.6-luna`, `gpt-5.6-terra`, and `gpt-5.6-sol` all exist and all support `high`, `xhigh`, and `max`. Their defaults are medium, medium, and low, so every call must set the level explicitly. The bundled Codex plugin helper caps `--effort` at `xhigh` and cannot express `max`. The limit is in the helper script only. The model accepts `max`. The user decided to keep `max`, so bounded implementation uses the already-recorded direct CLI form `codex exec -m gpt-5.6-luna -c 'model_reasoning_effort="max"' -C <worktree>`. This is the same installed Codex toolchain, not a substitute model. A probe run on 2026-08-03 returned the banner `model: gpt-5.6-luna` and `reasoning effort: max`. Every delegated call records that banner as its proof.
- 2026-08-03: Unit 1 starts. Implementation uses `gpt-5.6-luna` at max. Bounded task review and all accepted review fixes use `gpt-5.6-terra` at high. The unit review uses `gpt-5.6-sol` at high. Every delegated call is logged below with its model and level.
