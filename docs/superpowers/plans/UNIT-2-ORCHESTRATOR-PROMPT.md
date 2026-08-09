# Unit 2 orchestrator prompt — block-theme migration, state export/diff

Continue the block-theme migration in `C:\Users\Aleksandar\Projects\wordpress-template`.

You are Claude Opus 5 and the ONLY orchestrator. Unit 1 is merged. Execute Unit 2.

## Read first, in this order

1. `AGENTS.md`
2. `docs/superpowers/plans/2026-08-02-block-theme-tracking.md` — the whole Log
   section. It is the authoritative record and already contains every
   correction, environment fact and open item summarised below.
3. Your plan: `docs/superpowers/plans/2026-08-02-block-theme-task-2-state-export-diff.md`

**Do NOT read whole plan files into your context.** Unit 2's plan alone is 4,789
lines and the four plans total ~19,000. Point each worker at its own task
section by line range and read only the hunks you must judge yourself. This is
what keeps you alive across 13 tasks.

## Exact state at handoff

- Integration branch `feat/block-theme-fse-migration` at `72ba796`, pushed.
- Unit 1 merged (`12e44e4`), status `merged` in the tracking table.
- Your branch `feat/bt-task-2-state-export-diff` already exists, worktree at
  `C:\Users\Aleksandar\Projects\wt\bt-task-2`, created from `72ba796`, clean.
- DDEV project `agency-starter` is already running FROM THAT WORKTREE. The site
  answers HTTP 200. Do not move it.
- **The worktree has no `vendor/` yet.** Run
  `ddev composer install --no-interaction --prefer-dist` before your first gate.
- Unit 1 baseline carried forward: deptrac 0 violations, architecture 44,
  unit 182, integration 80, e2e 42 passed / 32 skipped / 0 failed,
  accessibility 6 passed, all 8 parity cases passing.

## Model policy — DIFFERENT from Unit 1, follow it exactly

Budget reality: Anthropic is abundant, OpenCode flash is cheap and disposable,
Codex is the scarcest. Spend accordingly.

| Role | Model | When |
|---|---|---|
| Orchestration, gates, plan corrections, diff review | you, Opus 5 | always |
| Implementation | `opencode-go/deepseek-v4-flash`, variant `max`, agent `build` | ALL 13 tasks |
| Bounded review | `gpt-5.6-luna`, reasoning `xhigh` | HIGH-RISK tasks only, see below |
| Unit review | `gpt-5.6-luna`, reasoning `xhigh` | once, at the end |

**Codex is expensive — review only these tasks:** 3 (HMAC signer), 4 (schema
validator), 10 (exporter/bundle reader), 11 (differ), 13 (CLI wiring +
alias). That is 5 bounded reviews plus 1 unit review, not 13.

For every other task **you** are the reviewer: read the diff yourself, run the
gates yourself, and only escalate to Codex if you find something you cannot
settle. Reading a 200-line diff costs you far less than a Codex call costs the
user.

Record the model and reasoning level of every delegated call in the tracking
log. Never record a model that did not run. A differing observed model is a
FAILED invocation, not a result.

### Invoking OpenCode (bulk implementation)

Write the prompt to a file first, never inline. One line, splatted:

```powershell
$p = @{ PromptFile='<scratchpad>/task.md'; WorkingDirectory='C:\Users\Aleksandar\Projects\wt\bt-task-2'; Model='opencode-go/deepseek-v4-flash'; Variant='max'; Agent='build'; Auto=$true; AllowAuto=$true }; $r = & "$env:USERPROFILE\.codex\skills\opencode-cli\scripts\invoke_opencode.ps1" @p; "MODEL: $($r.ObservedModel)/$($r.ObservedVariant)"; "SESSION: $($r.SessionId)"; "CHANGED: $($r.ChangedFiles -join ', ')"; "----"; $r.Result
```

Print `$r.Result`, never `$r`. Never read the artifact NDJSON — it destroys the
token saving that motivated delegating. `ChangedFiles` is the whole dirty
worktree, not the worker's diff: take a `git status` snapshot before every run
and attribute only the delta.

Runs exceed 10 minutes; the tool will background them. Watch the worktree, not
the output file — the wrapper writes nothing until it returns.

### Invoking Codex (selective review)

```
codex exec -m gpt-5.6-luna -c 'model_reasoning_effort="xhigh"' -c 'sandbox_mode="danger-full-access"' -C "C:\Users\Aleksandar\Projects\wt\bt-task-2" "$(cat brief.md)"
```

`codex exec resume <session-id>` does NOT accept `-C`; the working root comes
from the shell's cwd, and pass `-c 'model="gpt-5.6-luna"'` to keep the model.

## The plan is DEFECTIVE — corrections you must make before Task 1

A read-only audit (`gpt-5.6-terra` high) found six defects. **Fix the plan and
commit the corrections separately with evidence, before delegating Task 1.**

**BLOCKER — Task 8, plan lines 2655-2668.** `test_include_content_adds_the_content_provider()`
expects `content` in `StateRegistry::resolve( null, true )`, but Task 8 creates
only three providers (line 2714) and `ContentState` first exists in Task 9
(lines 2831, 2961). Task 8's own unit gate (line 2729) must fail. Move those two
assertions to Task 9.

**BLOCKER — Task 13, plan lines 4479, 4627-4649.** The plan modifies
`src/Cli/AgencyCommands.php`, which **Unit 1 owns**; Unit 2's grant is one
sequenced `Plugin.php` registration line only. Worse, deleting
`DatabaseOverrideCheck` while the alias still constructs it
(`AgencyCommands.php:53`) makes the alias fatal. Decide the ownership transfer
explicitly and record it, or defer both the alias rewrite and the deletion.

**IMPORTANT — Task 6 ↔ Task 8 circular dependency, lines 1850, 2099-2105, 2550,
2559.** Task 6 says "Consumes: nothing beyond PHP" then requires
`ReferenceResolver` to call `StateRegistry::provider()` (Task 8), while Task 8
consumes `ReferenceScanner` (Task 6). **The unmodified plan has no valid task
order.** Fix: keep only `ReferenceScanner` in Task 6 and move `ReferenceResolver`
to Task 9, where its integration test already belongs.

**IMPORTANT — Task 13, lines 4629-4638.** Claims `check_overrides()` "takes no
arguments at all". False on this branch: Unit 1 already gave it
`array $args, array $assoc_args = array()` (`AgencyCommands.php:52`) and added
`drift_is_failure()` and `drift_summary_lines()` (lines 86, 94). Delete the
stale claim.

**IMPORTANT — Global Constraints line 42 vs Task 1 lines 232-239.** Constraints
say Task 3 appends its own `.env.example` entries; Task 1 already adds both HMAC
settings and Task 3 neither lists nor stages that file. Make Task 1 the sole
owner and delete the constraint sentence.

**IMPORTANT — Task 13, lines 4536-4559.** A test claimed to "drive the real
alias logic" calls `$this->runner()->check_overrides()` — not
`AgencyCommands::check_overrides()`, no registered WP-CLI command, no
`CliOutput::emit()`. A broken alias passes it. Rename it a runner test and add a
real DDEV `wp agency check-overrides` test covering both flag cases.

### Corrected task order

`1 → 2 → 3 → 4 → 5 → 6 (scanner only) → 7 → 8 → 9 (resolver + providers) → 10 → 11 → 12 → 13`

### deptrac risk flagged by the audit

No plan step names `SiteCore`, `SiteIntegrations`, `SiteCommerce` or
`SiteTheme` — good. One risk: Task 3 line 1012 suggests
`AgencyPlatform\Logging\Logger::redact()`. deptrac permits intra-layer
references, but Unit 2's stated limit is WordPress core and PHP only. Remove it
or record an explicit exception.

## Hard rules — state these in every worker brief

- **`deptrac.yaml` declares `AgencyPlatform: []`** — any class under
  `web/app/mu-plugins/agency-platform/src/` may depend on NO project layer at
  all. Only WordPress core and PHP. Unit 2's entire subsystem lives there. This
  is your most likely repeated failure.
- No closures in `add_action`/`add_filter` in production code — named class
  methods only.
- No `components/`, `layouts/`, `inc/`, `includes/`, `helpers/`, `misc/`,
  `common/`, `lib/`, `utils/` directory in the theme or any plugin.
- Text domain `agency-platform` for that mu-plugin.
- No `@phpstan-ignore` in production code — fix the cause. Unit 1 hit this: six
  suppressions were added to shipping code to accommodate an incomplete TEST
  stub. The fix was to correct the stub.
- `docs/generated-block-index.md` must byte-match `php scripts/generate-block-index`.
- CSS colours must be design tokens; stylelint enforces a BEM class pattern.
- WooCommerce symbols only in `site-commerce/`, `site-theme/woocommerce/`,
  `tests/commerce/`, or a reviewed allow-list entry.

## Ownership — do not cross

Unit 2 owns: the state subsystem, `.env.example` state entries,
`Health/SanitizeSteps.php`, `EnvironmentSafetyTest.php`, deletion of
`DatabaseOverrideCheck.php`, and ONE sequenced provider-registration line in
`Plugin.php`.

Unit 2 does NOT own `Cli/AgencyCommands.php` or any other `Plugin.php` edit —
Unit 1 does. See the Task 13 blocker.

Unit 2 must DEFINE `PromotionStrategy`, `PromotionStrategies` and
`SchemaValidator::SCHEMA_PROMOTION_MANIFEST`. Unit 3 implements them. If Unit 2
ships without them, Unit 3 stalls immediately — verify they exist before the
unit review.

## Environment facts

- DDEV runs inside **WSL2**, not Windows:
  `wsl -d Ubuntu -e bash -lc "cd /mnt/c/Users/Aleksandar/Projects/wt/bt-task-2 && ddev <cmd>"`
- npm runs natively on Windows. Regenerate locks with `npx -y npm@10 install`;
  plain `npm ci` fails on this host (npm 11 vs an npm 10 lock).
- **DejaVu fonts are now installed on this Windows host**, so `test:parity`
  desktop tracks CI within ~0.06 points and mobile within ~0.13. Local parity is
  now meaningful. `test:visual` remains Linux-CI-authoritative — never run
  `--update-snapshots` on this host.
- **Use `--workers=1` for every Playwright run.** Concurrent `client_editor`
  logins bounce off `wp-login.php` with `reauth=1` on this 16-core host.
- `gh` is NOT installed. The Git Credential Manager token for
  `aleksandar-djabirski` works against the REST API with `repo` and `workflow`
  scopes:
  `TOKEN=$(printf "protocol=https\nhost=github.com\n\n" | git credential fill | grep '^password=' | cut -d= -f2-)`
- CI capture refs: push `HEAD:refs/heads/ci-capture/<name>`. A ref named
  `ci-capture/visual-baselines` REGENERATES baselines; any other `ci-capture/*`
  name runs the suites against the committed ones. Delete the ref after use.
- `composer install` in CI now retries 3× — Unit 1 lost five runs to a transient
  `curl error 56 / QUIC` fetching WordPress core.

## CI gate reality

- **`commerce-e2e` is KNOWN RED and formally exempted** for Units 1–3B; it
  becomes required again at Unit 4A. The exception, its evidence and its expiry
  are recorded in the tracking file. **Your merge entry must restate it.** Do not
  try to fix commerce.
- **A CI step-ordering defect is still open.** Steps carry an implicit
  `success()`, so a failure at dependency install marks e2e, accessibility,
  visual and parity **skipped** rather than failed — a run can report failure
  while the gates that matter never ran. **Always read step-level outcomes, not
  the job conclusion.** The fix (`!cancelled() && <expr>`) belongs to Unit 4B.

## Process lessons from Unit 1 — these cost real time

- **Poll long-running workers directly.** A Codex process once finished its work
  while the wrapping shell never exited; two hours were lost waiting for a signal
  that never came. Check `git log`, `git status --porcelain`, and whether the
  report file exists.
- **Verify, do not accept reports.** Two of Unit 1's most expensive defects were
  SILENT — a pattern registered under a category WordPress core does not define,
  and a fresh-install proof pointed at the wrong docroot so its asset checks
  proved nothing. Neither would ever fail a test. Both surfaced only because a
  review was told to query live state rather than read files. **Ask every
  reviewer: what does this assert that no test would catch if it were false?**
- **A worker refusing to commit on a red gate is CORRECT behaviour.** It happened
  five times in Unit 1 and every time the plan was wrong, not the worker.
- **When an environment limit slows you down, fix the environment.** The
  orchestrator accepted 12-minute CI round-trips for parity measurement until
  the user asked why DejaVu could not simply be installed. It could, in five
  minutes, from a package already in WSL.
- **Workers widen scope and report afterwards.** Five did in Unit 1. State the
  owned-file list explicitly and require reporting BEFORE changing anything else.
  Watch for scratch files left in guarded directories.

## Commit and gate policy

- `ddev composer verify:fast` before EVERY commit.
- `ddev composer verify` for anything touching the database.
- No commit lands on a gate the plan already knows is red. If a gate is red,
  diagnose — do not weaken the test.
- A required test may NEVER become a skip. A missing environment is a FAILED
  gate. Only viewport- and profile-selection skips are allowed, and only when
  another project executes the case.
- Never `git reset`, `git checkout --`, `git clean`, `git rebase`, or force
  anything. Workers never push; you own all remote operations.
- Workers never touch `2026-08-02-block-theme-tracking.md`. Only you edit it.
  Commit it at integration checkpoints.
- Correct a plan defect when you find one, commit the correction separately with
  the evidence, and log it.

## Unit gate, then hand off

Run every suite the plan names, run the ONE `gpt-5.6-luna` xhigh unit review,
apply accepted findings, re-run affected suites plus `verify:fast`, confirm
`git status --porcelain` is empty, merge into `feat/block-theme-fse-migration`
with `--no-ff`, update and commit the tracking file there, push, and confirm the
required GitHub Actions jobs are green apart from the exempted `commerce-e2e`.

Then stop and report. Do not start Unit 3A — units share one DDEV project and
must never run in parallel.

## Start here

1. Read the tracking log and confirm the state above with git.
2. Run `ddev composer install` in the worktree, then `ddev composer verify` to
   establish your real baseline counts.
3. Commit the six plan corrections above, with evidence, before any
   implementation.
4. Give a short state report, then execute Task 1 and continue without asking
   for routine confirmation.

Stop only for a real blocker, a required user decision, or a model-policy
failure.
