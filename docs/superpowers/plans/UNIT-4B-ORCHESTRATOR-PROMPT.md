# Unit 4B orchestrator prompt — block-theme migration, final closure

Continue the block-theme migration in `C:\Users\Aleksandar\Projects\wordpress-template`.

You are Claude Opus 5 and the ONLY orchestrator. Units 0, 1, 2, 3A, 3B and 4A
are merged. Execute Unit 4B: **Phase B, Tasks B1 to B8** — the runbook, the
operations contracts, the documentation sweep, the two proofs, the validation
matrix, and the required final report.

## Read first, in this order

1. `AGENTS.md`
2. **`docs/superpowers/plans/EXECUTION-EFFICIENCY-NOTES.md`** — the operating
   manual this engagement built. Every section exists because something cost real
   time. §1 is tooling to reuse rather than rebuild, §5 is what each gate costs,
   §8b is the rule that has now caught SIX release-blocking defects, and
   **§12 is new from Unit 4A and contains the three things most likely to bite
   you in the first hour**.
3. `docs/superpowers/plans/2026-08-02-block-theme-tracking.md` — the status table
   and the LAST forty entries. The file is large because its lines are long, so
   do not read it whole.
4. Your plan: `docs/superpowers/plans/2026-08-02-block-theme-task-4-commerce-hardening.md`,
   Tasks B1 to B8. Extract BY HEADING, never by line number.

**Do NOT read whole plan files into your context.** The plan is now ~4,100 lines.

## Your scope, and what comes after it

Eight tasks: B1 branch and gate verification plus the merged-surface inventory,
B2 `docs/state-reconciliation.md`, B3 the operations contracts under `ops/`,
B4 the documentation consistency sweep, B5 the fresh-clone proof, B6 the full
promotion proof, B7 the full validation matrix, B8 the required final report and
closure.

**Unit 4B is the last EXECUTION unit, but it is not the last work.** After 4B
merges into `feat/block-theme-fse-migration`, the tracking file's
"Final direct-to-main gate" still requires: a final review run **once**, in a
COLD Claude Opus 5 subagent rather than in the orchestrator session, over the
complete diff from the recorded base SHA; accepted fixes and their review; then
the merge of the integration branch into local `main` and `git push origin main`.
Several of that gate's items — the §12 validation matrix, the fresh-clone proof,
the promotion proof — are executed inside your B5 to B7, so plan for the final
gate to be mostly review and merge rather than fresh execution.

Unit 4B owns the state runbook, `README.md`, `ops/**`, the remaining
documentation and operations sweep, the proof records, and the final report.

## Exact state at handoff

- Integration branch `feat/block-theme-fse-migration` at `59862ac`, pushed.
- Unit 4A merged as `5148cfd` plus the regression merge `71fef0c`, CI-green,
  status `merged` in the table.
- Your branch `feat/bt-task-4-final-hardening` exists, worktree at
  `C:\Users\Aleksandar\Projects\wt\bt-task-4b`, created from `59862ac`, clean.
- DDEV project `agency-starter` is running FROM THAT WORKTREE, approot
  `/mnt/c/Users/Aleksandar/Projects/wt/bt-task-4b`. The database volume survived
  the handover — **327 `wp_posts` rows**. Do not move the project.
- Unit 4A baseline carried forward: deptrac 0 violations, architecture 44 / 408
  became **49 / 465**, unit 424 / 901, integration **451 / 2105**, commerce
  integration **24 / 117**.

### THE WORKTREE IS NOT BOOTSTRAPPED — three steps, not two

```
wsl -d Ubuntu -e bash -lc "cd /mnt/c/Users/Aleksandar/Projects/wt/bt-task-4b && ddev composer install --no-interaction --prefer-dist"
wsl -d Ubuntu -e bash -lc "cd /mnt/c/Users/Aleksandar/Projects/wt/bt-task-4b && ddev exec bash scripts/setup"
cd /c/Users/Aleksandar/Projects/wt/bt-task-4b && npx -y npm@10 ci
```

The third step is required. `scripts/setup` installs `node_modules` INSIDE the
container, leaving zero Windows `.cmd` shims, so `npm run lint` and every
Playwright script die with `'wp-scripts' is not recognized`. Plain `npm ci`
fails here because local npm is 11.x against an npm-10 lock. `npm ci` never
writes the lock, so the npm 10 route is safe.

Start `composer install` in the BACKGROUND as your first action and read the
tracking log while it runs. Verify with `ddev exec wp option get blogname`.

### YOUR SNAPSHOTS ARE GONE, AND THAT IS NORMAL

`ddev snapshot --list` from your worktree reports **No snapshots**. DDEV stores
snapshots in `<project>/.ddev/db_snapshots/`, which lives in the WORKTREE, not in
the database volume. Unit 4A's `base-profile` and `pre-cleanup-4a` are still on
disk at `C:\Users\Aleksandar\Projects\wt\bt-task-4\.ddev\db_snapshots\`.

Copy them if you want 4A's states, or take your own. **Take a fresh
`base-profile` snapshot before you install WooCommerce for anything**, because
`SWITCH TO BASE` restores by that name.

## Model policy — unchanged

| Role | Model | When |
|---|---|---|
| Orchestration, gates, plan corrections, diff review | you, Opus 5 | always |
| Implementation | `opencode-go/deepseek-v4-flash`, variant `max`, agent `build` | every task |
| Bounded review | `gpt-5.6-luna`, reasoning `xhigh` | B5 and B6 at minimum |
| Unit review | `gpt-5.6-luna`, reasoning `xhigh` | once, at the end |
| FINAL review | cold Claude Opus 5 subagent | after 4B merges, once |

Record the model and reasoning level of every delegated call. A differing
observed model is a FAILED invocation, not a result.

`codex exec` needed NO permission rule in Unit 4A, correcting the Unit 3B
expectation — probe it rather than assuming. Always invoke it with `< /dev/null`
or it can block on stdin.

**When a dispatch fails, PROBE before re-dispatching.** Unit 4A's Task A6
dispatch died with `APIError 400 — Model is unavailable`, `isRetryable: false`,
and a 705-byte event stream. A five-word probe prompt through the same wrapper
returned `PROBE-OK` in seconds, proving a transient rather than a model-policy
failure. That distinction matters: a model-policy failure is one of the three
things you must stop for.

## What Unit 4A learned that will save you the most

1. **Budget an hour of execution probing BEFORE the first worker.** Unit 4A's
   pre-start audit found twelve plan defects in about ninety minutes, two of them
   release-blocking, plus one blocking environmental defect that made the commerce
   profile unreachable. Ask, in order: what does the task write, what rule governs
   it, does the REAL shipped artefact satisfy that rule today, and can the declared
   gate actually go green?
2. **Aim the whole-unit review at the artefact with NO automated test.** Unit 4A's
   review found all three of its defects in `scripts/enable-commerce`, the one file
   with no PHPUnit coverage by design. Every tested surface came back clean. Your
   equivalents are `ops/**`, `README.md`, and the proof procedures — prose and
   scripts that no suite executes.
3. **A finding is not closed until every CALLER is checked.** Unit 4A's most
   expensive mistake: a worker reported that host-side execution broke
   `scripts/enable-commerce`, the orchestrator fixed its own switching procedure
   and moved on, and CI — which invokes the same script the same broken way on a
   line already read — failed the required commerce gate.
4. **Ask every worker what it was tempted to change outside its grant.** That line
   produced real findings on five separate Unit 4A tasks, including a worker
   catching its own vacuous assertion.
5. **Read CI step-level outcomes, never the job conclusion.** In Unit 4A the
   conclusion said "failure" while the truth was "the commerce gate never ran" —
   steps 16 and 17 were `skipped` behind a failed step 15. That is a different and
   far more actionable fact.

## Hard rules the architecture tests enforce

Regenerate `.brief/shared.md` per efficiency-notes §1; Unit 4A's version is a
good starting point and lives in `C:\Users\Aleksandar\Projects\wt\bt-task-4\.brief\shared.md`.
The ones most likely to bite Phase B:

- No closures in `add_action`/`add_filter`; named class methods only.
- No `@phpstan-ignore` in production code.
- Sniff codes are version-specific and a mismatched `phpcs:ignore` is SILENTLY
  INERT. **Test every suppression in both directions.** Unit 4A hit this twice,
  once from a defect the ORCHESTRATOR introduced in a correction.
- `docs/generated-block-index.md` must byte-match `php scripts/generate-block-index`.
- CSS colours must be design tokens.
- `chmod()` is a NO-OP on this DDEV mount. Never assert on permissions.
- Git does NOT work inside the container from a worktree.
- **`$?` is DESTROYED by PowerShell.** In PowerShell `$?` is a BOOLEAN, so
  `echo "EXIT=$?"` inside a double-quoted string — including one passed on to
  `bash -lc` — yields `EXIT=True`. Use single quotes, or read the log.

## Local gate reality — read before you trust a red suite

- **`commerce-e2e` is now a REQUIRED check.** Its exemption EXPIRED at Unit 4A and
  must never be claimed again. CI proved it green: run `31251995466`, with
  `Enable the commerce profile`, `Commerce integration suite` and
  `Commerce e2e journeys` all success.
- **The commerce e2e is FLAKY locally and that is environmental.** Four Unit 4A
  runs gave 18/0, 16/2, one 1-failure and 17/1; every failure was the `reauth=1`
  login race in `shop-manager-admin.spec.ts` at `tests/e2e/helpers/auth.ts:27`.
  `playwright.config.ts` sets `retries: process.env.CI ? 2 : 0` — locally zero,
  CI two. The admin file is 7/7 serially. **Do not change the shared script or the
  config to mask it.**
- The base `npm run test:e2e` shows 10–13 `reauth=1` failures. Environmental,
  proven on pure HEAD.
- **Local `npm run test:visual` is NOT a reliable gate on this host.** The
  carried-over database has drifted from the baselines. CI on a fresh database is
  the authority. Only a `ci-capture/visual-baselines` ref may regenerate them.
- **Never chain a gate behind a pipe.** Redirect to a file and read the log.
- **Composer swallows PHPUnit output on success AND on failure.** Run
  `ddev exec vendor/bin/phpunit …` directly to see a real result.
- **Run `scripts/enable-commerce` from either side now** — Unit 4A fixed the
  host/container temp-file defect. But CI runs it host-side, so if you ever change
  it, prove it host-side too.

## Commit and gate policy

- `ddev composer verify:fast` before EVERY commit.
- `ddev composer test:integration` when the change touches database-backed code.
- Commerce adds `ddev composer test:integration:commerce` and
  `COMMERCE=1 npm run test:e2e:commerce`. Neither runs in base `verify`.
- **No commit while the commerce profile is active** — `verify:fast` starts with
  `composer validate --strict` and `composer audit`, which would run against the
  ephemeral WooCommerce require.
- `SWITCH TO BASE`'s step 1 is now TWO lines (`git restore --source=HEAD --
  composer.json composer.lock`, then `ddev composer install`) and its assertion 5
  checks the Composer files, not a pristine worktree. Unit 4A corrected both;
  the originals were red by construction.
- No commit lands on a gate the plan already knows is red. Diagnose; never weaken
  a test. A required test may NEVER become a skip.
- Never `git reset`, `git checkout --`, `git clean`, `git rebase`, or force
  anything. Workers never push; you own all remote operations.
- Workers never touch the tracking file. Only you edit it.
- Correct a plan defect when you find one, commit the correction separately with
  the evidence, and log it. Unit 2 found 25, Unit 3A 19, Unit 3B 19, Unit 4A 15
  plus one environmental.

## CI reality

- CI triggers on `main` and `ci-capture/**`. Pushing a feature branch runs
  NOTHING. `workflow_dispatch` through the REST API is preferred because it avoids
  a duplicate cancelled run:

```
curl -X POST -H "Authorization: Bearer $TOKEN" \
  https://api.github.com/repos/aleksandar-djabirski/wordpress-template/actions/workflows/315781594/dispatches \
  -d '{"ref":"ci-capture/<name>"}'
```

The Git Credential Manager token carries the `workflow` scope; read it with
`git credential fill`. Delete the ref after verification.

- **ALWAYS read step-level outcomes, not the job conclusion.** Reading the
  conclusion would have given the wrong answer FOUR times now.
- Query runs by **commit SHA**, not by branch.
- The composer QUIC flake is not fixed. Re-running the failed job is the remedy.

## Carried-forward items — these are YOURS

- **Dependabot has grown to 7 vulnerabilities on the default branch (5 high, 2
  moderate)**, up from 3 high on 2026-08-04. npm-side; `composer audit` passes.
  You own the sweep, and the trend is wrong.
- **`settings.spacing.custom` in the shipped `theme.json` is not a valid v3
  property and core drops it.** Dead configuration; whoever owns `theme.json`
  should remove it.
- **`test_the_pre_reset_hash_is_recorded_in_the_manifest`** still uses a
  `/^[0-9a-f]{64}$/` shape assertion. Redundant-but-weak; tidy it.
- **`rollbackRefusalReason` stays null on the restored-hash-mismatch path** while
  `refused` records populate it. Carried since Unit 3A.
- **`npm run lint:js` covers ONLY `web/app/themes/site-theme/blocks`.** Every
  Playwright spec under `tests/` is never linted; Playwright's own compilation is
  the sole check. Pre-existing, not introduced by 4A.
- **`AGENTS.md` overstates one rule**: it claims Git-owned templates carry "no
  hard-coded `ref` and no inline `style` attribute". The `ref` half is enforced;
  there is NO inline-style assertion anywhere in the suite.
- **The commerce templates ship to every project built from this starter.** On a
  storeless site WordPress lists all six, a `client_editor` can open and edit
  them, and `page-cart` renders header plus content plus footer with no cart UI.
  The Unit 4A review raised this; it was rejected as a defect because it
  contradicts the plan's fixed decision 2, and recorded as a PRODUCT decision for
  you: decide what a non-commerce client should see in the Site Editor.
- **Nothing verifies the committed commerce templates still match upstream.**
  `CommerceBlockTemplatesTest` compares slug SETS, not content, and runs only when
  WooCommerce is installed, so an upstream markup change rots the copies silently.
  The proposed design is a pinned normalized snapshot compared without the plugin.
  Deferred to you because it means committing duplicates of six upstream files and
  owning a sync policy.
- **`order-confirmation.html` carries one byte upstream does not** — a trailing
  LF, plan-mandated, documented in `docs/adding-commerce-behaviour.md`. A
  re-derivation with `sed > file` will drop it again.
- **The `base-profile` snapshot from Unit 4A is not fully commerce-free.** It
  still contains the legacy shortcode cart/checkout pages (#16/#17) and the
  `shop-manager` / `test-customer` users. No assertion tests for those. Decide
  whether to re-seed the local database — the drift argument now has two
  independent supports.

## Unit gate, then the final gate

Run every suite the plan names INCLUDING the two commerce suites, run the
`gpt-5.6-luna` xhigh unit review, apply accepted findings, re-run affected suites
plus `verify:fast`, confirm `git status --porcelain` is empty, merge into
`feat/block-theme-fse-migration` with `--no-ff`, update and commit the tracking
file there, push, and verify CI via a `ci-capture/*` ref **by reading step-level
outcomes**, then delete the ref.

Then run the **cold Opus 5 final review** over the complete diff from the
recorded base SHA, apply accepted fixes, complete the tracking file's
"Final direct-to-main gate" checklist, merge into local `main`, and
`git push origin main`. Do not force-push.

**Append what you learn to `EXECUTION-EFFICIENCY-NOTES.md`.** Only entries that
would change a future decision; delete ones that stop applying.

## Start here

1. Start `ddev composer install` in the background.
2. Read the efficiency notes (especially §12), then the tracking log's last forty
   entries.
3. Finish bootstrapping, all THREE steps; verify `ddev exec wp option get blogname`.
4. Take a fresh `base-profile` snapshot before touching WooCommerce.
5. Run `ddev composer verify` to establish your real baseline counts.
6. Audit Tasks B1 to B8 for defects BEFORE starting, with execution probes, and
   commit the corrections separately with evidence. Every unit so far has found
   between 15 and 25; assume yours is not cleaner.
7. Give a short state report, then execute and continue without asking for
   routine confirmation.

Stop only for a real blocker, a required user decision, or a model-policy
failure.
