# Unit 4A orchestrator prompt — block-theme migration, commerce hardening

Continue the block-theme migration in `C:\Users\Aleksandar\Projects\wordpress-template`.

You are Claude Opus 5 and the ONLY orchestrator. Units 0, 1, 2, 3A and 3B are
merged. Execute Unit 4A: **Phase A, Tasks A1 to A8** of the commerce-hardening
plan.

## Read first, in this order

1. `AGENTS.md`
2. **`docs/superpowers/plans/EXECUTION-EFFICIENCY-NOTES.md`** — the operating
   manual this engagement built. Every section exists because something cost
   real time. §1 is tooling to reuse rather than rebuild, §5 is what each gate
   costs, §8b is the rule that has now caught FIVE release-blocking defects, and
   **§11 is new from Unit 3B and contains three things that will bite you in the
   first hour**.
3. `docs/superpowers/plans/2026-08-02-block-theme-tracking.md` — the status table
   and the LAST forty entries. The file is ~80,000 tokens because its lines are
   long, so do not read it whole.
4. Your plan: `docs/superpowers/plans/2026-08-02-block-theme-task-4-commerce-hardening.md`,
   Tasks A1 to A8 (lines 212 to 2293, but extract BY HEADING, never by line
   number).

**Do NOT read whole plan files into your context.** Extract a task's section by
heading using the tooling in efficiency-notes §1.

## Your scope

**Eight tasks, and this is the unit where commerce becomes required.** Phase A
derives the commerce block templates that Units 1 to 3B were exempted from
providing.

- A1 workspace and commerce ground truth, A2 re-green the commerce suites,
  A3 derive the commerce block templates and retire the classic directory,
  A4 prove them in the commerce profile, A5 commerce patterns and Mini-Cart,
  A6 `scripts/enable-commerce` seeding, A7 commerce Playwright coverage,
  A8 documentation, generated index and the Phase A gate.

Unit 4A owns commerce templates, commerce patterns, commerce tests,
`scripts/enable-commerce`, `CommerceBoundaryTest.php`, and the commerce routing
documentation. Unit 1 owns the PHP-symbol wording in `WooCommerceIsolationTest`;
you own the HTML block-markup boundary. Add no WooCommerce allow-list entry
unless the scanner proves a new scanned PHP symbol needs one.

## Exact state at handoff

- Integration branch `feat/block-theme-fse-migration` at `d53d658`, pushed.
- Unit 3B merged as `92b33f5` and CI-green, status `merged` in the table.
- Your branch `feat/bt-task-4-commerce-hardening` exists, worktree at
  `C:\Users\Aleksandar\Projects\wt\bt-task-4`, created from `d53d658`, clean.
- DDEV project `agency-starter` is running FROM THAT WORKTREE, approot
  `/mnt/c/Users/Aleksandar/Projects/wt/bt-task-4`. The database volume survived
  the handover — 341 `wp_posts` rows. Do not move the project.
- Unit 3B baseline carried forward: deptrac 0 violations, architecture 44 / 408,
  unit 424 / 901, integration **451 / 2075**.

### THE WORKTREE IS NOT BOOTSTRAPPED — and it takes THREE steps, not two

This gap has now bitten four units running, and Unit 3B found that the recipe
every previous handoff gave was incomplete.

```
wsl -d Ubuntu -e bash -lc "cd /mnt/c/Users/Aleksandar/Projects/wt/bt-task-4 && ddev composer install --no-interaction --prefer-dist"
wsl -d Ubuntu -e bash -lc "cd /mnt/c/Users/Aleksandar/Projects/wt/bt-task-4 && ddev exec bash scripts/setup"
cd /c/Users/Aleksandar/Projects/wt/bt-task-4 && npx -y npm@10 ci
```

**The third step is required and `npm ci` alone will FAIL.** `scripts/setup`
installs `node_modules` INSIDE the Linux container, which leaves zero Windows
`.cmd` shims, so `npm run lint` and every Playwright script die with
`'wp-scripts' is not recognized`. And plain `npm ci` fails on this host because
local npm is 11.x while the committed lock was written by npm 10 — it reports
`Missing: @parcel/watcher-android-arm64@2.5.6 from lock file` and refuses.
`npm ci` never writes the lock, so the npm 10 route is safe. See §11.1.

Start `composer install` in the BACKGROUND as your first action and read the
tracking log while it runs. Verify with `ddev exec wp option get blogname`
before you delegate anything.

## Model policy — unchanged

| Role | Model | When |
|---|---|---|
| Orchestration, gates, plan corrections, diff review | you, Opus 5 | always |
| Implementation | `opencode-go/deepseek-v4-flash`, variant `max`, agent `build` | every task |
| Bounded review | `gpt-5.6-luna`, reasoning `xhigh` | A3, A4 and A6 at minimum |
| Unit review | `gpt-5.6-luna`, reasoning `xhigh` | once, at the end |

Record the model and reasoning level of every delegated call. A differing
observed model is a FAILED invocation, not a result.

**Codex needs a permission rule.** `codex exec` is blocked by the Claude Code
auto-mode classifier until `Bash(codex exec:*)` is allowed in settings. Unit 3B
lost a cycle to this and the orchestrator cannot add the rule itself. Confirm it
early, before you reach the review gate.

**Always invoke `codex exec` with `< /dev/null`.** Without it the process can
block on `Reading additional input from stdin...` and sit there indefinitely —
Unit 3B lost two hours to exactly that, with an empty output buffer and no
notification.

## What Unit 3B learned that will save you the most

1. **Budget an hour of execution probing BEFORE the first worker.** Unit 3B's
   pre-start audit found nineteen plan defects, three of them release-blocking,
   in about forty minutes — and every one would otherwise have cost a worker run
   plus a review plus a fix. Ask, in order: what does the task write, what rule
   governs it, does the REAL shipped artefact satisfy that rule TODAY, and can
   the declared gate actually go green? **You own commerce templates, which are
   shipped artefacts governed by exactly these rules.**
2. **Dispatch ONE task per worker invocation.** The Unit 3B handoff advised
   batching two tasks into one run; that run was killed after 26 minutes leaving
   an empty output buffer and NO artifacts, and the only evidence was the
   worktree. Recovery was cheap only because the partial work was read before
   re-dispatching.
3. **A worker refusing to commit on a red gate is CORRECT.** It happened again
   in Unit 3B and the plan was wrong again.
4. **Ask every worker what it was tempted to change outside its grant.** That
   line has now produced a real finding on four separate tasks across two units.
5. **Spend the whole-unit review.** For the second consecutive unit it found
   critical customer-content defects that per-commit reviews could not see,
   because the proof required reading a file that was not in the diff.

## Hard rules the architecture tests enforce

Regenerate `.brief/shared.md` per efficiency-notes §1. The ones most likely to
bite Phase A:

- WooCommerce symbols may appear ONLY in `site-commerce/`,
  `site-theme/woocommerce/`, `tests/commerce/`, or a reviewed entry in
  `tests/Architecture/woocommerce-allowlist.php`.
- `deptrac.yaml` declares `AgencyPlatform: []`; `SiteCommerce → SiteCore,
  SiteCoreContracts` only.
- No closures in `add_action`/`add_filter`; named class methods only.
- No `@phpstan-ignore` in production code.
- Sniff codes are version-specific and a mismatched `phpcs:ignore` is SILENTLY
  INERT. **Test every suppression in both directions** — Unit 3B found two of
  its own were inert and removed them.
- CSS colours must be design tokens; `docs/generated-block-index.md` must
  byte-match `php scripts/generate-block-index` — you add patterns, so this WILL
  need regenerating.
- `chmod()` is a NO-OP on this DDEV mount. Never assert on permissions.
- Git does NOT work inside the container from a worktree.

## Local gate reality — read before you trust a red suite

- **`commerce-e2e` STOPS BEING EXEMPT IN THIS UNIT.** It has been an information
  check for Units 1 to 3B and becomes REQUIRED for you. Its current failure is
  `.woocommerce-order` not found in `tests/commerce/e2e/checkout.spec.ts` —
  Task A3 derives the templates that fix it. Your merge entry must record that
  the exemption has EXPIRED, not restate it.
- **Check the dev site's state before believing any local Playwright result.**
  Unit 3B's visual and accessibility suites failed hard against a dev site whose
  Global Styles post held a leftover background colour; after resetting it,
  accessibility was 6 passed and the home-page pixel diff fell from 80% to 2%.
- **Local `npm run test:visual` is NOT a reliable gate on this host.** The
  carried-over database has drifted from the baselines (the demo page renders
  1521px against an 1899px baseline). CI on a fresh database is the authority,
  and it passed for Unit 3B. Do not regenerate baselines from a local run; only
  a `ci-capture/visual-baselines` ref may regenerate them.
- The local full `npm run test:e2e` shows 10–13 `reauth=1` login failures.
  Environmental, proven on pure HEAD.
- **Never chain a gate behind a pipe.** `npm run lint | tail && npm run build`
  reports exit 0 even when lint fails. Redirect to a file and capture `$?`.
- **Composer swallows PHPUnit output on success AND on failure.** To see a real
  result, run `ddev exec vendor/bin/phpunit …` directly.

## Commit and gate policy

- `ddev composer verify:fast` before EVERY commit.
- `ddev composer test:integration` when the change touches database-backed code.
- Commerce adds two suites: `ddev composer test:integration:commerce` and
  `COMMERCE=1 npm run test:e2e:commerce`. Neither runs in base `verify`.
- No commit lands on a gate the plan already knows is red. Diagnose; never
  weaken a test. A required test may NEVER become a skip.
- Never `git reset`, `git checkout --`, `git clean`, `git rebase`, or force
  anything. Workers never push; you own all remote operations.
- Workers never touch the tracking file. Only you edit it.
- Correct a plan defect when you find one, commit the correction separately with
  the evidence, and log it. Unit 2 found 25, Unit 3A 19, Unit 3B 19.

## CI reality

- CI triggers on `main` and `ci-capture/**`. The push trigger works again — it
  created a run for Unit 3B's capture ref — but `workflow_dispatch` through the
  REST API is still preferred because it avoids a duplicate cancelled run:

```
curl -X POST -H "Authorization: Bearer $TOKEN" \
  https://api.github.com/repos/aleksandar-djabirski/wordpress-template/actions/workflows/315781594/dispatches \
  -d '{"ref":"ci-capture/<name>"}'
```

The Git Credential Manager token carries the `workflow` scope; read it with
`git credential fill`.

- **ALWAYS read step-level outcomes, not the job conclusion.** Reading the
  conclusion would have given the wrong answer three times now.
- Query runs by **commit SHA**, not by branch.
- The composer QUIC flake is not fixed. Re-running the failed job is the remedy.

## Carried-forward items

- Known minor: `test_the_pre_reset_hash_is_recorded_in_the_manifest` still uses a
  `/^[0-9a-f]{64}$/` shape assertion. The exact-value property is pinned
  elsewhere, so this is redundant-but-weak coverage. Unit 4B may tidy it.
- `rollbackRefusalReason` stays null on the restored-hash-mismatch path while
  `refused` records populate it. Carried since Unit 3A. Unit 4B.
- `settings.spacing.custom` in the shipped `theme.json` is not a valid v3
  property and core drops it. Dead configuration; whoever owns `theme.json`
  should remove it.
- **Dependabot has grown to 7 vulnerabilities on the default branch (5 high, 2
  moderate)**, up from 3 high on 2026-08-04. npm-side; `composer audit` passes.
  Unit 4B owns the sweep, but the trend is wrong.
- One UNREPRODUCED `ddev composer verify` failure was recorded at the end of
  Unit 3B: exit 1 with PHPStan completing and zero PHPUnit output, followed by
  three green runs. Cause unknown. Recognise the shape rather than rediscovering
  it.

## Unit gate, then hand off

Run every suite the plan names INCLUDING the two commerce suites, run the
`gpt-5.6-luna` xhigh unit review, apply accepted findings, re-run affected suites
plus `verify:fast`, confirm `git status --porcelain` is empty, merge into
`feat/block-theme-fse-migration` with `--no-ff`, update and commit the tracking
file there, push, verify CI via a `ci-capture/*` ref **by reading step-level
outcomes**, delete the ref, and **record that the `commerce-e2e` exemption has
expired and commerce is now green**.

Then prepare the Unit 4B handoff — `ddev stop --unlist agency-starter`, create
`feat/bt-task-4-final-hardening` and its worktree from the updated integration
branch, start DDEV there, write the Unit 4B orchestrator prompt — and stop.
Units share one DDEV project and must never run in parallel.

**Append what you learn to `EXECUTION-EFFICIENCY-NOTES.md`.** Only entries that
would change a future decision; delete ones that stop applying.

## Start here

1. Start `ddev composer install` in the background.
2. Read the efficiency notes (especially §11), then the tracking log's last forty
   entries.
3. Finish bootstrapping, all THREE steps; verify `ddev exec wp option get blogname`.
4. Confirm `Bash(codex exec:*)` is permitted before you need it.
5. Run `ddev composer verify` to establish your real baseline counts.
6. Audit Tasks A1 to A8 for defects BEFORE starting, with execution probes, and
   commit the corrections separately with evidence. Unit 3B's pre-start audit
   found nineteen; assume yours is not cleaner.
7. Give a short state report, then execute and continue without asking for
   routine confirmation.

Stop only for a real blocker, a required user decision, or a model-policy
failure.
