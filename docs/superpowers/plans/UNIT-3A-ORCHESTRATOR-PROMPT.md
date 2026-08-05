# Unit 3A orchestrator prompt — block-theme migration, promotion lifecycle

Continue the block-theme migration in `C:\Users\Aleksandar\Projects\wordpress-template`.

You are Claude Opus 5 and the ONLY orchestrator. Units 0, 1 and 2 are merged.
Execute Unit 3A: Tasks 1 to 21 of the promotion-lifecycle plan.

## Read first, in this order

1. `AGENTS.md`
2. `docs/superpowers/plans/2026-08-02-block-theme-tracking.md` — the whole Log
   section. It is the authoritative record and already contains every
   correction, ruling, environment fact and open item summarised below.
3. Your plan: `docs/superpowers/plans/2026-08-02-block-theme-task-3-promotion-lifecycle.md`

**Do NOT read whole plan files into your context.** Your plan is 3,641 lines.
Point each worker at its own task section BY GREPPING FOR ITS HEADING, not by
hardcoded line numbers — Unit 2 lost a task to a stale line range after its own
plan edits shifted the numbering. Read only the hunks you must judge yourself.

## Your scope

**Unit 3A is Tasks 1 to 21 ONLY.** Task 21 starts at line 3201. Tasks 22 and 23
(Theme JSON adapter, Global Styles promotion) are Unit 3B on a separate branch
and a separate gate. Do not start them.

## Exact state at handoff

- Integration branch `feat/block-theme-fse-migration` at `fb24141`, pushed.
- Unit 2 merged (`bd2b233` plus CI fix `71e6df7`), status `merged` in the table.
- Your branch `feat/bt-task-3-promotion-lifecycle` already exists, worktree at
  `C:\Users\Aleksandar\Projects\wt\bt-task-3`, created from `fb24141`, clean.
- DDEV project `agency-starter` is already running FROM THAT WORKTREE, approot
  `/mnt/c/Users/Aleksandar/Projects/wt/bt-task-3`. The database volume survived
  the handover — 357 `wp_posts` rows are present, so no reinstall is needed.
  Do not move the project.
- Unit 2 baseline carried forward: deptrac 0 violations, architecture 44 / 407,
  unit 300 / 678, integration 159 / 662, `test:integration:cli` 2 / 18.

### THE WORKTREE IS NOT BOOTSTRAPPED — both gaps bit Unit 2, fix them first

- **No `vendor/`.** Run
  `ddev composer install --no-interaction --prefer-dist` before your first gate.
- **No `.env`, and no `node_modules/`.** Run `bash scripts/setup` inside DDEV.
  It is idempotent, development-guarded, and never clobbers an existing `.env`
  or an installed site.

**Do the `.env` one NOW, not when a task needs it.** Unit 2 discovered `.env`
was missing only at Task 12, ten tasks in, because `verify:fast` needs no
database and the integration suite uses wp-phpunit's separate `wordpress_test`
database. A fully green unit sat on top of a site returning HTTP 000. Your unit
drives real WP-CLI far more than Unit 2 did, so this will bite sooner and harder.

Verify with `ddev exec wp option get blogname` before you delegate anything.

## Model policy — identical to Unit 2, follow it exactly

| Role | Model | When |
|---|---|---|
| Orchestration, gates, plan corrections, diff review | you, Opus 5 | always |
| Implementation | `opencode-go/deepseek-v4-flash`, variant `max`, agent `build` | ALL tasks |
| Bounded review | `gpt-5.6-luna`, reasoning `xhigh` | HIGH-RISK tasks only, see below |
| Unit review | `gpt-5.6-luna`, reasoning `xhigh` | once, at the end |

**Codex is the scarcest resource — review only these tasks:** 4 (manifest store,
signature-first verification), 9 (navigation policy and reference refusal), 12
(per-record-key locking, mutex and heartbeat), 13 (protected chunked backups),
14 (`PromotionFinalizer`), 15 (confirm and rollback), 21 (CI job and the
sequenced `Plugin.php` registration). That is 7 bounded reviews plus 1 unit
review for 21 tasks.

For every other task **you** are the reviewer: read the diff yourself, run the
gates yourself, and escalate to Codex only if you find something you cannot
settle. Reading a 200-line diff costs far less than a Codex call.

Record the model and reasoning level of every delegated call in the tracking
log. Never record a model that did not run. A differing observed model is a
FAILED invocation, not a result.

### Invoking OpenCode (bulk implementation)

Write the prompt to a file first, never inline. One line, splatted:

```powershell
$p = @{ PromptFile='<scratchpad>/task.md'; WorkingDirectory='C:\Users\Aleksandar\Projects\wt\bt-task-3'; Model='opencode-go/deepseek-v4-flash'; Variant='max'; Agent='build'; Auto=$true; AllowAuto=$true }; $r = & "$env:USERPROFILE\.codex\skills\opencode-cli\scripts\invoke_opencode.ps1" @p; "MODEL: $($r.ObservedModel)/$($r.ObservedVariant)"; "SESSION: $($r.SessionId)"; "----"; $r.Result
```

Print `$r.Result`, never `$r`. Never read the artifact NDJSON.

**The wrapper fails transiently.** Twice in Unit 2 it returned
`OpenCode response did not finish successfully` having written nothing; both
retries succeeded from a clean tree. Retry once, then investigate.

**The wrapper's report is lost if the tool timeout kills it.** The Bash/PowerShell
tool caps at 600 s and long tasks exceed it. When that happens the commit may
still have landed — judge the WORKTREE, not the signal, and reconstruct any lost
test-first evidence yourself rather than skipping it. Unit 2 did exactly that for
Task 4.

### Invoking Codex (selective review)

Launch it DETACHED so the tool timeout cannot kill it, and capture the FULL
output — piping through `tail` discards the model banner the policy requires:

```bash
cd <worktree> && nohup codex exec -m gpt-5.6-luna \
  -c 'model_reasoning_effort="xhigh"' -c 'sandbox_mode="danger-full-access"' \
  -C "C:\Users\Aleksandar\Projects\wt\bt-task-3" "$(cat brief.md)" > out.txt 2>&1 &
```

Watch for the literal terminal marker `tokens used`. Do NOT grep for verdict
strings — they appear in your own brief echoed back and will fire a false
positive. Do NOT use `pgrep` for liveness; it cannot see Windows processes from
Git Bash. Check liveness with PowerShell `Get-Process codex`.

`codex exec resume <session-id>` does NOT accept `-C`; cwd comes from the shell.

## What Unit 2 learned that will save you the most time

**1. Eleven of Unit 2's twenty-five defects were tests that could not fail.**
That is the dominant failure mode of this engagement, not a curiosity. Require a
recorded break-and-restore proof for every guard: break the production
behaviour, observe the test fail, record the exact text, restore, confirm
byte-identical. A green suite is not evidence.

**2. Reading confirms intent; running confirms behaviour.** Four of Unit 2's
worst defects were invisible to code review and visible only by executing the
shipped code — a vacuous determinism probe, a `serialize_precision` restore that
passed while broken, a defeatable web-root guard, and an `--output` flag that
wrote customer content into the public web root. Probe the built artefact
directly wherever a property is security- or determinism-critical.

**3. Ask every reviewer: what does this assert that no test would catch if it
were false?** That single question produced the highest-value findings in both
units.

**4. A worker refusing to commit on a red gate is CORRECT.** It happened nine
times in Unit 2 and the plan was wrong every time. State the owned-file list
explicitly and require reporting BEFORE any out-of-scope change.

**5. Sniff codes are version-specific and a mismatched `phpcs:ignore` is
SILENTLY INERT.** This recurred three times in Unit 2. Tell workers to read the
real phpcs output for the exact code, never to trust the plan's, and never to
broaden a suppression to make it match.

**6. Fix the environment rather than grinding against it.** Unit 1 lost hours to
12-minute CI round-trips before installing DejaVu locally; Unit 2 lost a task to
a missing `.env`.

## Hard rules — state these in every worker brief

- **`deptrac.yaml:56` declares `AgencyPlatform: []`** — any class under
  `web/app/mu-plugins/agency-platform/src/` may depend on NO project layer. Only
  WordPress core and PHP, plus the recorded intra-layer `Logging\Logger`
  exception. Your whole subsystem lives there.
- No closures in `add_action`/`add_filter` in production code.
- No `components/`, `layouts/`, `inc/`, `includes/`, `helpers/`, `misc/`,
  `common/`, `lib/`, `utils/` directory anywhere.
- Text domain `agency-platform`.
- **No `@phpstan-ignore` in production code — fix the cause.** In Unit 2 a
  "PHPStan forces a suppression" report twice turned out to be a missing seam;
  fixing the cause removed the complaint.
- No direct SQL where a WordPress API exists.
- Outbound HTTP only in the integration layers — the promotion subsystem makes
  none.
- `phpcs.xml` is ORCHESTRATOR-OWNED. Unit 2 scoped
  `WordPress.Security.EscapeOutput.ExceptionNotEscaped` off
  `agency-platform/src/State/*` only. **If your promotion code lives in a new
  directory, that exclusion does NOT cover it** — decide deliberately whether to
  extend the scope, and prove any change stays scoped by planting a probe
  outside it.
- CSS colours must be design tokens; `docs/generated-block-index.md` must
  byte-match `php scripts/generate-block-index`.

## What Unit 2 built that you consume

`AgencyPlatform\State\*` is merged and verified. Your seam:

- `PromotionStrategy`, `PromotionStrategies` — DEFINED by Unit 2, **implemented
  and registered by you**. `PromotionStrategies::all()` returns empty until you
  register through `agency_platform_promotion_strategies`. Verified working: a
  live probe registered two fakes and got them back keyed and sorted.
- `SchemaValidator::SCHEMA_PROMOTION_MANIFEST` — declared with value
  `promotion-manifest-v1`. **The schema FILE is yours to ship.** Validating
  against it today fails cleanly with a not-found error naming the path.
- `HmacSigner` — `PURPOSE_MANIFEST` already exists and cross-purpose replay is
  proven blocked in both directions. Reuse it verbatim; do not re-implement.
- `StateBundle` — refuses every accessor before signature verification (all 14
  content accessors throw exit 4, proven by reflection over every public method).
- `StateDirectory::resolve_output()` — the SHARED public guard that canonicalises
  a path, rejects the web root and resolves symlinks. **Use it for every file you
  write.** Unit 2's `--output` leak happened precisely because a second write
  path bypassed the guard. Do not write a second copy of that rule.
- Exit codes: 1 hard error, 2 drift, 3 lock, 4 tamper, via
  `StateException::exit_code()`. Assert by named constant, never a bare integer.

## Environment facts

- DDEV runs inside **WSL2**, not Windows:
  `wsl -d Ubuntu -e bash -lc "cd /mnt/c/Users/Aleksandar/Projects/wt/bt-task-3 && ddev <cmd>"`
- `ddev` is NOT on the Windows PATH.
- npm runs natively on Windows. Regenerate locks with `npx -y npm@10 install`;
  plain `npm ci` fails on this host (npm 11 vs an npm 10 lock).
- DejaVu fonts are installed on this host, so `test:parity` desktop tracks CI
  within ~0.06 points and mobile within ~0.13. `test:visual` remains
  Linux-CI-authoritative — never run `--update-snapshots` here.
- **Use `--workers=1` for every Playwright run.** Concurrent `client_editor`
  logins bounce off `wp-login.php` with `reauth=1` on this 16-core host.
- Nested shell quoting through `wsl -> bash -> ddev exec -> php` mangles `$`
  and quotes. Write the script to a file and pipe it in base64-encoded.
- `gh` is NOT installed. The Git Credential Manager token works against the REST
  API with `repo` and `workflow` scopes:
  `TOKEN=$(printf "protocol=https\nhost=github.com\n\n" | git credential fill | grep '^password=' | cut -d= -f2-)`

## CI reality

- **CI triggers only on `main` and `ci-capture/**`.** Pushing your feature
  branch fires NOTHING. To verify, push `HEAD:refs/heads/ci-capture/<name>`,
  read the run, then delete the ref. A ref named `ci-capture/visual-baselines`
  REGENERATES baselines; any other name verifies against the committed ones.
- **ALWAYS read step-level outcomes, not the job conclusion.** Unit 2's first
  CI run reported `failure` while its End-to-end job had genuinely PASSED, and
  its real failure was elsewhere. Steps carry an implicit `success()`, so a
  failure at dependency install marks e2e, accessibility, visual and parity
  **skipped** rather than failed. A run can report failure while the gates that
  matter never executed. The `!cancelled()` fix belongs to Unit 4B.
- **The composer QUIC flake is not fixed.** `curl error 56 ... QUIC connection
  has been shut down` fetching WordPress core defeated all three retries in
  Unit 2's run `31016415792`. Re-running the failed jobs is the remedy. Caching
  the core archive is Unit 4B's.
- **`commerce-e2e` is KNOWN RED and formally exempted** for Units 1 to 3B; it
  becomes required at Unit 4A. **Your merge entry must restate it.** Do not try
  to fix commerce.
- Task 20 is ancestry-gated and Task 21 adds the CI job plus the sequenced
  `Plugin.php` line. Unit 2's ancestry gate reported non-ancestry purely because
  of orchestrator docs-only tracking commits; verify with
  `git diff HEAD...feat/block-theme-fse-migration -- ':!docs/'` before treating
  it as real, and **never rebase** — a Unit 2 reviewer demanded one and was
  overruled on exactly that evidence.

## Ownership — do not cross

Unit 3A owns the promotion subsystem, the promotion Playwright script, the
promotion CI steps, `scripts/promote-overrides`, `src/Cli/PromotionCommands.php`,
`resources/schemas/promotion-manifest-v1.json`, and ONE sequenced
`Plugin.php` registration line.

Unit 3A does NOT own `Cli/AgencyCommands.php`, the Unit 2 state subsystem, or
any other `Plugin.php` edit. Unit 3B owns Tasks 22–23 only. Unit 4A owns
commerce. Unit 4B owns `README.md`, `ops/**` and the final documentation sweep.

If a task needs a file outside your grant, decide the transfer EXPLICITLY and
record it in the tracking file — Unit 2 did this three times (`AgencyCommands`,
`phpcs.xml`, and the CI/phpunit change for the alias suite) and each is a
worked example.

## Commit and gate policy

- `ddev composer verify:fast` before EVERY commit.
- `ddev composer verify` for anything touching the database.
- `ddev composer test:integration:cli` exists now and runs ONLY in the DDEV e2e
  CI job. If you add a test needing real WP-CLI, it belongs there, not in the
  wp-phpunit `integration` suite — that job has no `wp` binary.
- No commit lands on a gate the plan already knows is red. Diagnose; never
  weaken a test.
- A required test may NEVER become a skip. A missing environment is a FAILED
  gate.
- Never `git reset`, `git checkout --`, `git clean`, `git rebase`, or force
  anything. Workers never push; you own all remote operations.
- Workers never touch `2026-08-02-block-theme-tracking.md`. Only you edit it.
  Commit it at integration checkpoints.
- Correct a plan defect when you find one, commit the correction separately with
  the evidence, and log it. Unit 2 found 25.

## Unit gate, then hand off

Run every suite the plan names, run the ONE `gpt-5.6-luna` xhigh unit review,
apply accepted findings, re-run affected suites plus `verify:fast`, confirm
`git status --porcelain` is empty, merge into `feat/block-theme-fse-migration`
with `--no-ff`, update and commit the tracking file there, push, trigger CI via
a `ci-capture/*` ref, confirm the required jobs are green **by reading
step-level outcomes**, delete the ref, and restate the `commerce-e2e` exemption
in your merge entry.

Then prepare the Unit 3B handoff — `ddev stop --unlist agency-starter`, create
`feat/bt-task-3-global-styles` and its worktree from the updated integration
branch, start DDEV there — and stop. Do not start Unit 3B. Units share one DDEV
project and must never run in parallel.

## Start here

1. Read the tracking log and confirm the state above with git.
2. Bootstrap the worktree: `ddev composer install`, then `bash scripts/setup`,
   then verify `ddev exec wp option get blogname` answers.
3. Run `ddev composer verify` to establish your real baseline counts.
4. Audit your plan for defects BEFORE Task 1 and commit the corrections
   separately with evidence. Unit 2's audit found six before starting and
   nineteen more during execution; assume yours is not cleaner.
5. Give a short state report, then execute Task 1 and continue without asking
   for routine confirmation.

Stop only for a real blocker, a required user decision, or a model-policy
failure.
