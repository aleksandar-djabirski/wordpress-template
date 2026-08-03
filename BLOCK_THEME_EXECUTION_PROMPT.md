# Block Theme Migration Execution Prompt

You are the GPT-5.6 Sol high-reasoning orchestrator for this repository:

`C:\Users\Aleksandar\Projects\wordpress-template`

Complete the block-theme migration from start to finish. Do not stop after planning. Continue through implementation, reviews, fixes, verification, merge, and the authorized direct push to `main`. Stop only for a hard blocker that you cannot correct safely.

## Read first

Read these files in full before you change the repository:

1. `AGENTS.md`
2. `BLOCK_THEME_PROPOSAL.md`
3. `docs/superpowers/plans/2026-08-02-block-theme-tracking.md`
4. `docs/superpowers/plans/2026-08-02-block-theme-task-1-theme-and-editing.md`
5. `docs/superpowers/plans/2026-08-02-block-theme-task-2-state-export-diff.md`
6. `docs/superpowers/plans/2026-08-02-block-theme-task-3-promotion-lifecycle.md`
7. `docs/superpowers/plans/2026-08-02-block-theme-task-4-commerce-hardening.md`

The tracking file controls execution order, branch boundaries, shared-file ownership, model use, review gates, and the final merge policy. If a detailed task plan conflicts with the tracking file or current repository facts, correct the plan before you execute the affected step. Record the correction in the tracking log.

Use ASD-STE100 Simplified Technical English for all prose replies. Follow all repository commands and ownership rules in `AGENTS.md`.

## Current repository facts to verify

The handoff was prepared with these facts:

- Current branch: `main`.
- Local `main` is five commits ahead of `origin/main`.
- Preserve those five commits.
- `README.md` has an accidental insertion in the first playbook item: `Projects\wordpress-template\BLOCK_THEME_PROPOSAL.md` appears after `**New client**`.
- `BLOCK_THEME_PROPOSAL.md` and `docs/superpowers/` are untracked.
- The integration branch does not yet exist.

Verify every fact. Do not discard or overwrite any user change. Do not use `git reset --hard`, destructive checkout commands, force push, or broad delete commands.

## Unit 0: make the plan executable

Complete the Unit 0 checklist in the tracking file.

Use a small `apply_patch` edit to restore the README text to `1. **New client** → ...`. Commit that repair separately.

Commit the complete planning pack, including this prompt. Confirm a clean status. Create `feat/block-theme-fse-migration` from the resulting local `main`. Push the integration branch. Do not push `main` yet.

Confirm all required tools before Unit 1. This includes DDEV, Docker, Node, npm, GitHub CLI authentication, GitHub Actions access, PNG artifact inspection, GPT-5.6 Luna max, and Claude Code Opus 5.

If GPT-5.6 Luna max is not callable, use GPT-5.6 Sol high for implementation and record the fallback. Do not claim that Luna ran. If Claude Code Opus 5 is not callable, stop before Unit 1 and report that exact blocker because the independent final review is required.

## Execution order

Run these units in this exact serial order:

1. Unit 1 on `feat/bt-task-1-theme-and-editing`.
2. Unit 2 on `feat/bt-task-2-state-export-diff`.
3. Unit 3A on `feat/bt-task-3-promotion-lifecycle`.
4. Unit 3B on `feat/bt-task-3-global-styles`.
5. Unit 4A on `feat/bt-task-4-commerce-hardening`.
6. Unit 4B on `feat/bt-task-4-final-hardening`.

Only one task worktree may run DDEV at one time. Stop the prior DDEV project before you create and start the next worktree. Do not run implementation units in parallel.

The Sol orchestrator creates each branch and worktree from the updated integration branch. The worker verifies the branch, clean status, and integration ancestry. The worker must not create or rebase its own branch unless the orchestrator first changes the tracking plan for a verified reason.

Only the Sol orchestrator edits `docs/superpowers/plans/2026-08-02-block-theme-tracking.md`. Workers return commit SHAs, changed files, test results, proof paths, and blockers. Workers do not edit the tracking file.

## Implementation model policy

Use GPT-5.6 Luna with max reasoning for each bounded implementation task when it is callable. Give Luna:

- one task-plan section;
- explicit file ownership;
- required tests;
- the exact definition of done;
- a rule to preserve other changes;
- a rule to return its commit SHA, diff summary, tests, and blockers.

The Sol high orchestrator must inspect every returned diff. It must run the required gates itself. It must correct unsafe, incomplete, or out-of-scope work before merge.

If delegation is not available for a bounded step, Sol high performs that step. Do not substitute another model and report it as Luna.

## Unit gate

For every unit:

1. Follow the unit plan in order.
2. Run `ddev composer verify:fast` before each commit.
3. Run all additional PHP, integration, npm, Playwright, visual, accessibility, and commerce suites required by that plan.
4. Treat a missing required environment as a failed gate. Do not convert a required test into a skip.
5. Run a Sol high review of the complete unit diff against the master proposal and repository rules.
6. Fix all accepted critical and important findings.
7. Re-run affected tests and `ddev composer verify:fast`.
8. Confirm a clean status.
9. Merge the unit into `feat/block-theme-fse-migration`.
10. Run the integration-branch gate.
11. Update the tracking file on the integration branch.
12. Push the integration branch and confirm its required GitHub Actions jobs are green.

Do not start the next unit until this gate passes.

## Image and browser gates

The Task 1 baseline and parity image workflows are orchestrator gates. Start the workflows with GitHub CLI or the GitHub UI. Download every artifact. Open and inspect every PNG. Check the metadata and browser version. Commit only accepted files. Do not request a separate human review when you can inspect the artifacts.

Run the Task 1 Site Editor browser gates with browser automation. Record evidence for every required editor action and frontend result.

## Promotion and commerce gates

Promotion CI must use a test-only HMAC key inside the isolated job. Do not require a repository secret for the test. Production key values remain outside Git.

Run the complete isolated-adapter promotion proof. The isolated DDEV target is the accepted full proof target for this hosting-independent starter. Prove template, template-part, and Global Styles promotion. Prove tamper refusal, deploy-commit mismatch refusal, confirmation, automatic rollback, superseded-record rollback refusal, and resolved-output equivalence.

Run base tests only after the full `SWITCH TO BASE` assertions pass. Run commerce tests only after `SWITCH TO COMMERCE`. Restore the base profile before final base verification. Never certify the base profile from a commerce-enabled database.

## One independent final review

After Unit 4B is merged into `feat/block-theme-fse-migration`, run Claude Code Opus 5 exactly once over the complete diff from the Unit 0 recorded base SHA to the integration branch.

Give the reviewer the master proposal, tracking file, repository `AGENTS.md`, all task plans, the complete diff, and the verification evidence. Ask it to report only actionable findings. Require severity, file and line, violated requirement, evidence, and a proposed correction. Save the report outside tracked customer-state paths.

Do not run Claude Opus earlier. Do not run it a second time.

Classify every finding with evidence. Use GPT-5.6 Luna max for accepted fixes when callable. Use Sol high as the recorded fallback. Then run one Sol high review of the fix diff and re-run every affected gate plus the complete final verification matrix.

## Final direct push to main

The user authorizes a direct push to `main`. Do not create a pull request unless a repository protection rule makes it mandatory. Never force-push.

Before the final push:

1. Fetch `origin`.
2. Confirm whether `origin/main` changed from the recorded Unit 0 base.
3. If it changed, integrate it into `feat/block-theme-fse-migration` and repeat the full final verification and Sol review.
4. Confirm the Claude Opus review, accepted fixes, and Sol fix review are complete.
5. Run the full base and commerce matrices in the proposal.
6. Complete the fresh-clone and isolated-adapter promotion proofs.
7. Confirm every required GitHub Actions job is green.
8. Confirm no required test skipped.
9. Confirm no state bundle, manifest instance, backup payload, secret, real `.env`, or customer data is tracked.
10. Confirm `git status --porcelain` is empty.
11. Merge `feat/block-theme-fse-migration` into local `main`.
12. Push with `git push origin main`.

If branch protection rejects the push, stop. Report the exact rejection and the smallest required user action. Do not bypass protection.

## Terminal report

Do not finish after a partial unit. Continue automatically while a safe next step exists.

At completion, report:

- the pushed `main` SHA;
- every created commit;
- files added, changed, and deleted;
- unit and release-gate results;
- the Claude Opus findings and disposition;
- the Luna fixes or the recorded Sol fallback;
- all local and CI verification results;
- visual parity metrics;
- promotion and rollback proof results;
- commerce results;
- known limitations;
- confirmation that no customer state or secrets were committed.
