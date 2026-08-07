# Unit 3B orchestrator prompt — block-theme migration, Global Styles promotion

Continue the block-theme migration in `C:\Users\Aleksandar\Projects\wordpress-template`.

You are Claude Opus 5 and the ONLY orchestrator. Units 0, 1, 2 and 3A are merged.
Execute Unit 3B: **Tasks 22 and 23 ONLY** of the promotion-lifecycle plan.

## Read first, in this order

1. `AGENTS.md`
2. **`docs/superpowers/plans/EXECUTION-EFFICIENCY-NOTES.md`** — the operating
   manual this engagement built. It is short and every section exists because
   something cost real time. §1 is tooling to reuse rather than rebuild, §5 is
   what each gate costs and when to run it, §8b is the rule that caught three
   release-blocking defects.
3. `docs/superpowers/plans/2026-08-02-block-theme-tracking.md` — the Log
   section. Read the status table and the LAST forty entries; the whole file is
   ~74,000 tokens because its lines are long, so do not read it whole.
4. Your plan: `docs/superpowers/plans/2026-08-02-block-theme-task-3-promotion-lifecycle.md`,
   Tasks 22 and 23.

**Do NOT read whole plan files into your context.** Extract a task's section BY
HEADING using the tooling in efficiency-notes §1, never by line number.

## Your scope — this is a SMALL unit

**Two tasks. Task 23 depends on Task 22.** There is no parallelism to find here;
do not go looking for it. Batch both into ONE worker invocation with two
separate commits — that is the saving available, and Unit 3A used it repeatedly.

- **Task 22** — `ThemeJsonAdapter`, with an injectable, fail-closed capability
  probe (§7.6).
- **Task 23** — `GlobalStylesPromotionStrategy` and the
  resolved-output-equivalence gate. This is the Release 4 gate.

You also own the MODIFICATION of `tests/Integration/Promotion/PromotionStrategyRegistrarTest.php`
that adds the third strategy. Unit 3A created that file and owns its Release 3
assertions; do not rewrite them.

## Exact state at handoff

- Integration branch `feat/block-theme-fse-migration` at `c99423a`, pushed.
- Unit 3A merged and CI-green, status `merged` in the table.
- Your branch `feat/bt-task-3-global-styles` exists, worktree at
  `C:\Users\Aleksandar\Projects\wt\bt-task-3b`, created from the updated
  integration branch, clean.
- DDEV project `agency-starter` is running FROM THAT WORKTREE, approot
  `/mnt/c/Users/Aleksandar/Projects/wt/bt-task-3b`. The database volume survived
  the handover — 401 `wp_posts` rows. Do not move the project.
- Unit 3A baseline carried forward: deptrac 0 violations, architecture 44 / 408,
  unit 424 / 901, integration 419 / 1796, cli-integration 10 / 41.

### THE WORKTREE IS NOT BOOTSTRAPPED — this has bitten three units running

No `vendor/`, no `.env`, no `node_modules/`. Before your first gate:

```
wsl -d Ubuntu -e bash -lc "cd /mnt/c/Users/Aleksandar/Projects/wt/bt-task-3b && ddev composer install --no-interaction --prefer-dist"
wsl -d Ubuntu -e bash -lc "cd /mnt/c/Users/Aleksandar/Projects/wt/bt-task-3b && ddev exec bash scripts/setup"
```

Start `composer install` in the BACKGROUND as your first action and read the
tracking log while it runs — together these take about twenty minutes.

Verify with `ddev exec wp option get blogname` before you delegate anything.

## Model policy — unchanged

| Role | Model | When |
|---|---|---|
| Orchestration, gates, plan corrections, diff review | you, Opus 5 | always |
| Implementation | `opencode-go/deepseek-v4-flash`, variant `max`, agent `build` | both tasks |
| Bounded review | `gpt-5.6-luna`, reasoning `xhigh` | Task 23 only |
| Unit review | `gpt-5.6-luna`, reasoning `xhigh` | once, at the end |

Task 23 is the Release 4 gate and writes Global Styles to disk — review it.
Task 22 is an adapter behind a fail-closed probe; review it yourself.

Record the model and reasoning level of every delegated call. A differing
observed model is a FAILED invocation, not a result.

Invocation forms are in efficiency-notes §1. **Launch everything with the tool's
own `run_in_background: true`** — `nohup` leaves the process untracked and no
notification ever arrives, which cost this engagement real time.

## What Unit 3A learned that will save you the most

1. **Test the SHIPPED artefact, not a fixture built to satisfy the rule**
   (§8b). Three release-blocking defects had this exact shape. Task 23 writes
   `theme.json` — so drive the REAL shipped `theme.json` through the REAL
   adapter, not a fixture you shaped to pass.
2. **If your own break leaves the suite green, the TEST is the defect.** Fix the
   test or stop and report; never ship on the strength of a test just proven
   blind. Delete `.phpunit.cache` before every break run — a stale cache can
   replay green and make a good test look blind.
3. **Prove it by running, not by reading.** Every critical defect in Unit 3A was
   found by executing the shipped code. A five-line probe costs seconds; a CI
   round trip costs twelve minutes.
4. **A worker refusing to commit on a red gate is CORRECT.** It happened ten
   times in Unit 3A and the plan was wrong every time.
5. **Ask every worker what it was tempted to change outside its grant.** That
   one question produced the highest-value finding of three separate tasks.

## Hard rules — state these in every worker brief

They are in `.brief/shared.md`, which efficiency-notes §1 tells you how to
regenerate. The ones most likely to bite Task 22/23:

- `deptrac.yaml` declares `AgencyPlatform: []`. No project-layer dependencies.
- No closures in `add_action`/`add_filter`; named class methods only.
- **No `@phpstan-ignore` in production code — fix the cause.**
- Sniff codes are version-specific and a mismatched `phpcs:ignore` is SILENTLY
  INERT. Read the real phpcs output. The plan's
  `WordPress.WP.AlternativeFunctions.file_system_operations_*` WILDCARD does not
  work; use the per-function codes listed in efficiency-notes §4.
- **`chmod()` is a NO-OP on this DDEV mount.** Never assert on permissions.
- Git does NOT work inside the container from a worktree. Any test needing a
  repository must build a throwaway one.
- CSS colours must be design tokens; `docs/generated-block-index.md` must
  byte-match `php scripts/generate-block-index`.

## What Unit 3A built that you consume

- `PreparablePromotionStrategy` — your Global Styles strategy implements it.
  Note the CORRECTION recorded in the plan: it adds `stage()`, and the inherited
  `PromotionStrategy::prepare()` REFUSES on purpose. Do not "fix" that.
- `PromotionStrategyRegistrar::RELEASE_3_SLUGS` — Task 23 adds the third slug.
  A test currently asserts `global-styles` has NO strategy; that is the
  Release 3/Release 4 boundary and Task 23 is what legitimately flips it.
- `PromotionFinalizer` has a DEFERRED-HASH branch for a strategy whose expected
  post-reset hash can only be computed on the target. Global Styles is that
  strategy — `defers_expected_hash()` returns true for it.
- `CanonicalJsonFileWriter` — staged canonical-JSON writes. Use it; do not write
  a second JSON write path.
- `StateDirectory::resolve_output()` guards state artifacts and REFUSES anything
  under `web/`. `theme.json` lives under `web/`, so this is NOT the guard for
  it — use theme-directory containment, exactly as `PreparedFileWriter` does.

## Carried-forward items, neither blocking

- `rollbackRefusalReason` stays null on the restored-hash-mismatch path while
  `refused`-status records populate it. The reason is still carried in the
  refusal report, so nothing is lost; it is an asymmetry in the operator's audit
  record. Fix it if you touch that code, otherwise leave it for Unit 4B.
- The local full `npm run test:e2e` shows 11–13 `reauth=1` login failures on
  this host's DDEV database. PROVEN environmental — they reproduce on pure HEAD.
  CI uses a fresh database and is unaffected.

## Commit and gate policy

- `ddev composer verify:fast` before EVERY commit.
- `ddev composer test:integration` when the change touches database-backed code.
- `ddev composer test:integration:cli` runs only in the DDEV e2e CI job.
- No commit lands on a gate the plan already knows is red. Diagnose; never
  weaken a test. A required test may NEVER become a skip.
- Never `git reset`, `git checkout --`, `git clean`, `git rebase`, or force
  anything. Workers never push; you own all remote operations.
- Workers never touch the tracking file. Only you edit it.
- Correct a plan defect when you find one, commit the correction separately with
  the evidence, and log it. Unit 2 found 25; Unit 3A found 19 plus two critical
  data-loss paths.

## CI reality

- **CI triggers on `main` and `ci-capture/**` — but the push trigger became
  UNRELIABLE at the end of Unit 3A.** The ref was verifiably correct, the
  workflow active, and no run was created. **Use `workflow_dispatch` through the
  REST API instead**; the Git Credential Manager token carries the `workflow`
  scope:

```
curl -X POST -H "Authorization: Bearer $TOKEN" \
  https://api.github.com/repos/aleksandar-djabirski/wordpress-template/actions/workflows/315781594/dispatches \
  -d '{"ref":"ci-capture/<name>"}'
```

- **ALWAYS read step-level outcomes, not the job conclusion.** A failure early
  in the e2e job marks every later step SKIPPED, so a run can report failure
  while the gate you care about never executed. This happened twice in Unit 3A.
- Query runs by **commit SHA**, not by branch — the branch listing returned
  stale results repeatedly.
- **`commerce-e2e` is KNOWN RED and formally exempted** for Units 1 to 3B; it
  becomes required at Unit 4A. **Your merge entry must restate it.**
- The composer QUIC flake is not fixed. Re-running the failed job is the remedy.

## Unit gate, then hand off

Run every suite the plan names, run the ONE `gpt-5.6-luna` xhigh unit review,
apply accepted findings, re-run affected suites plus `verify:fast`, confirm
`git status --porcelain` is empty, merge into `feat/block-theme-fse-migration`
with `--no-ff`, update and commit the tracking file there, push, verify CI via a
`ci-capture/*` ref **by reading step-level outcomes**, delete the ref, and
restate the `commerce-e2e` exemption in your merge entry.

Then prepare the Unit 4A handoff — `ddev stop --unlist agency-starter`, create
`feat/bt-task-4-commerce-hardening` and its worktree from the updated
integration branch, start DDEV there, write the Unit 4A orchestrator prompt —
and stop. Units share one DDEV project and must never run in parallel.

**Append what you learn to `EXECUTION-EFFICIENCY-NOTES.md`.** Only entries that
would change a future decision; delete ones that stop applying.

## Start here

1. Start `ddev composer install` in the background.
2. Read the efficiency notes, then the tracking log's last forty entries.
3. Finish bootstrapping; verify `ddev exec wp option get blogname` answers.
4. Run `ddev composer verify` to establish your real baseline counts.
5. Audit Tasks 22 and 23 for defects BEFORE starting, and commit the corrections
   separately with evidence. Unit 3A's pre-start audit found six; assume yours
   is not cleaner.
6. Give a short state report, then execute and continue without asking for
   routine confirmation.

Stop only for a real blocker, a required user decision, or a model-policy
failure.
