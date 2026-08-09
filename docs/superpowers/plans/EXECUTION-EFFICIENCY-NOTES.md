# Execution efficiency notes — block-theme migration

**Purpose:** make each remaining unit cost less wall-clock time and fewer tokens
than the one before it. This file is NOT a history. The tracking file
(`2026-08-02-block-theme-tracking.md`) records what happened and why. This file
records only what the NEXT orchestrator should DO DIFFERENTLY, and what to reuse
rather than rediscover.

**Audience:** the orchestrator of Unit 4B, and, through the brief it
writes, every worker and reviewer agent.

**Rule for maintaining this file:** an entry earns its place only if it changes a
future decision. If something cost time once and cannot recur, do not record it
here — record it in the tracking file and leave this file short. Delete entries
that stop applying.

Started by the Unit 3A orchestrator. Append; do not rewrite history.

---

## 1. Reuse this tooling. Do not rebuild it.

Unit 3A built a small delivery pipeline after three separate delivery failures.
It works. Reuse it verbatim.

| File | What it does |
|---|---|
| `<scratchpad>/extract-section.sh` | Extracts one plan section BY HEADING into a file. Never uses line numbers. |
| `<scratchpad>/make-brief.sh` | Writes the full brief to `<worktree>/.brief/<id>.md` and emits a short, quote-free prompt file that points at it. Warns above 28,000 bytes. |
| `<worktree>/.brief/shared.md` | The static two thirds of every brief: environment, shared constraints, hard rules, gate and commit policy, required report. Written once per unit. |
| `<scratchpad>/notes<NN>.md` | Per-task orchestrator notes that override the plan text. |

`.brief/` is hidden from git through `.git/info/exclude`. **Linked worktrees read
the COMMON git directory's exclude file**, so the entry must go in
`<main checkout>/.git/info/exclude`, not the per-worktree one. Verify with
`git check-ignore -v .brief/shared.md` and confirm `git status --porcelain` is
empty. Remove the entry when the engagement ends.

### The three delivery failures these files prevent

1. **A worker that opens the plan file dies.** The plan is 3,700 lines. Two runs
   aborted with `reason: "unknown"` and zero tokens at exactly the step where the
   worker would have read it. Never point a worker at the plan file. Inline its
   section.
2. **Prompt content longer than ~30,000 bytes fails.** `invoke_opencode.ps1`
   appends the whole prompt to the process argument list, and Windows
   `CreateProcess` caps the command line at 32,767 characters. The failure text
   is `The filename or extension is too long`.
3. **Double quotes are stripped from anything on the command line.** This is the
   expensive one — see §2.

---

## 2. The single most expensive failure so far, and the cheap check that stops it

**What happened.** Every `"` was removed from a brief between the file on disk
and the worker. The worker received `{slug:site-header}` where the plan said
`{"slug":"site-header"}`. It implemented a decoder for a markup form WordPress
never emits, shipped 249 lines instead of 90, and passed every gate:
`verify:fast` exit 0, deptrac 0, 44 architecture, 377 unit, 167 integration.
The work had to be reverted and redone. Cost: roughly one full task, twice.

**Why no gate could catch it.** The tests were internally consistent with the
corrupted input. Break-and-restore proofs were real and passed. Green means
"the code matches its tests", never "the input was correct".

**The cheap fix, now in `.brief/shared.md`.** Content no longer travels on the
command line at all. But transports break again, so ALSO require an
input-integrity echo in the report:

> Quote back, byte for byte, the first code fixture in your brief. If it
> contains JSON, the keys must be double-quoted. If they are not, STOP and
> report it instead of implementing it.

That is a few tokens. It would have caught this immediately.

**Generalise it:** any time a worker's discrepancy report describes the plan as
saying something you do not believe it says, check the plan yourself before
accepting the work. Both of Unit 3A's worst problems were found this way and by
nothing else.

---

## 3. What to put in a worker brief so it stops exploring

Workers burn most of their tokens on discovery, not on writing code. Every fact
you supply is exploration you do not pay for. Supply these:

- **Exact signatures of everything the task consumes**, copied from the merged
  source, not from the plan. The plan has been wrong about a signature four
  times. State "verified in the merged code — do not re-derive".
- **The name of the existing test that already does the hard setup.** Workers
  spent whole tool calls hunting for how to build a signed bundle. Naming
  `tests/Integration/State/StateExportTest.php` and the `SeedsStateFixtures`
  trait removes that entirely.
- **The exact command incantation.** See §4.
- **Which suite to run and when.** See §5.
- **The owned-file list, and the instruction to report BEFORE touching anything
  else.** Workers obey this and it is cheap. Two tasks stopped correctly on a
  red gate caused by a file outside their grant, which is the outcome you want.
- **The "what were you tempted to change outside scope" question.** This is the
  highest-yield line in the whole report format. It surfaced the only real
  shipped-code defect in Task 1 and the corrupted-input catastrophe in Task 3.
  Keep it. Read the answer.

---

## 4. Environment facts that every worker rediscovers at your expense

Put all of these in `.brief/shared.md` once. Each one cost multiple wasted tool
calls per task before it was written down.

- `ddev` is not on the Windows PATH. Every command is
  `wsl -d Ubuntu -e bash -lc "cd /mnt/c/Users/Aleksandar/Projects/wt/<wt> && ddev <cmd>"`.
- **Quoting dies through `wsl → bash → ddev exec → php`.** A `--filter` with `|`
  or `(` breaks. `$?` is eaten by PowerShell. The reliable pattern is to write a
  small `.sh` file in the worktree, run `ddev exec bash file.sh`, and delete it
  before committing.
- **Composer swallows PHPUnit and phpcs output when a step fails.** To see a real
  failure, run `ddev exec vendor/bin/phpunit …` or
  `ddev exec vendor/bin/phpcs --report=full` directly. Every worker hit this.
- **Git does not work inside the DDEV container.** In a worktree `.git` is a file
  pointing at a Windows path outside the mount. Run git natively on Windows. Any
  test needing a repository must `git init` a throwaway one, with
  `GIT_CONFIG_GLOBAL=/dev/null` and `GIT_CONFIG_SYSTEM=/dev/null`.
- **Run `phpcbf` on new files before `phpcs`.** Array-alignment warnings are
  auto-fixable and this repo fails on warnings. Workers fixed them by hand
  repeatedly. One `phpcbf` call replaces that.
- **Sniff codes must be read from real phpcs output.** A mismatched
  `phpcs:ignore` is silently inert. This has now recurred five times across two
  units. Never copy a sniff code from the plan. Two specific traps, both
  confirmed by running phpcs, not by reading it:
  - **The plan's `WordPress.WP.AlternativeFunctions.file_system_operations_*`
    wildcard does not work.** `phpcs:ignore` is an exact-match key lookup with no
    wildcard expansion. Use the real per-function codes:
    `file_system_operations_fopen`, `_fwrite`, `_fclose`, `_chmod`,
    `rename_rename`, `unlink_unlink`,
    `file_get_contents_file_get_contents`.
  - **`json_decode` has no WPCS alternative-function sniff, so a
    `WordPress.WP.AlternativeFunctions.json_decode_json_decode` suppression is
    inert and should not be written.** `json_encode` DOES have one
    (`json_encode_json_encode`) and needs the suppression. `fflush` needs none.
- **Test a suppression in both directions.** Delete it and confirm the warning
  appears (it is load-bearing), and count the suppressions against the warnings
  (an excess means one is inert). Unit 3A shipped two inert suppressions that a
  green phpcs run could never have revealed.
- `python3` does not exist in Git Bash on this host. Use `node`, `php`, or the
  editing tools for scripted text edits. `perl -0pi -e` needs heavy escaping and
  fails silently on a bad pattern — check that the edit landed. For a
  break-and-restore edit, the editing tools are the reliable choice; two
  scripted attempts silently did nothing and produced a false "the test still
  passes" reading.
- **PHPUnit's result cache can replay a stale OK and make a break look inert.**
  `phpunit.xml` sets `cacheResultFile`, so a break-and-restore run can report
  green from cache and produce a FALSE "this test cannot fail" reading — which,
  under the rule in §8a, would wrongly condemn a perfectly good test. Delete
  `.phpunit.cache` before every break run and before the final green run. It is
  gitignored, so removing it changes nothing tracked.
- **`chmod()` is a NO-OP on the DDEV mount.** It returns `true` and changes
  nothing, and directories are created 0777 whatever mode you pass. So any
  assertion about file or directory permissions is VACUOUS on this host and must
  not be written. Verified with a probe. Permission-hardening code is still
  correct to ship — it matters on a real production filesystem — but it cannot
  be proven here, and a test that appears to prove it is worse than no test.

---

## 5. Gate economics — where the wall-clock actually goes

Approximate costs on this host:

| Command | Cost | When |
|---|---|---|
| `vendor/bin/phpunit --filter <One>Test` | seconds | The inner development loop. Use this constantly. |
| `ddev composer verify:fast` | ~90 s | Once, immediately before each commit. Required. |
| `ddev composer test:integration` | ~10 s plus boot | Only when the task touched database-backed code. |
| `ddev composer verify` | ~2 min | Baseline at unit start, and at the unit gate. Not per task. |
| `ddev composer install` | ~10 min | Once per worktree. Start it in the background FIRST. |
| `bash scripts/setup` | ~10 min | Once per worktree. Start it as soon as install finishes. |

**Rules that save real time:**

- Do NOT run `verify:fast` to find out whether one test passes. Run the filtered
  test. `verify:fast` is a commit gate, not a development loop.
- Do NOT run `test:integration` for a task that creates only pure classes.
  Tasks 1 and 2 of Unit 3A correctly needed only `verify:fast`.
- Do NOT re-run a suite that a previous step already proved green and that your
  change cannot affect. State that reasoning instead of re-running.
- The full `verify` at unit start is worth it once: it is what proves your
  baseline matches the handoff, and a drifting baseline is very expensive to
  diagnose later.

**Bootstrap in parallel.** `composer install` and `scripts/setup` together take
about twenty minutes and block everything. Start `composer install` in the
background as the FIRST action of the session, then read the tracking log and
audit the plan while it runs. Unit 3A did this and lost nothing.

---

## 6. Orchestrator token discipline

- **Do not read the tracking file whole.** It is 496 lines but roughly 74,000
  tokens because the lines are long. Read the status table, then `grep` for what
  you need, then read only the last twenty entries. The handoff prompt should
  already carry every ruling you need.
- **Do not read the plan file whole.** Extract sections by heading. Read a
  section once, when you write its notes, not again at review time.
- **Do not poll background tasks — but make sure they can actually notify you.**
  This cost more wall-clock than any other process defect in Unit 3A, because
  the orchestrator kept waiting for signals that could never arrive.

  **The rule: launch EVERYTHING with the tool's own `run_in_background: true`.**
  Only then does the harness track the process and re-invoke you when it exits.

  The trap: the Unit 3A handoff prescribed launching Codex reviews detached with
  `nohup … &` so the 600-second tool timeout could not kill them. That works, but
  the wrapping call returns instantly and **nothing tracks the detached process,
  so no notification is ever sent**. `run_in_background: true` solves BOTH
  problems at once — it survives the timeout AND notifies. Use it instead of
  `nohup`.

  If you have already launched something untracked, wrap it in a tracked waiter
  rather than polling:

  ```
  until grep -q "tokens used" /tmp/codex-<name>.out; do sleep 15; done; echo DONE
  ```

  run with `run_in_background: true`. `tokens used` is Codex's terminal marker.
  Never grep for verdict strings — they appear in your own brief echoed back.

- While something IS running, do useful preparation rather than waiting: write
  the NEXT task's notes and pre-generate its brief. Never narrate waiting.
- **Pre-generate briefs in batches.** Generating four briefs costs one tool call.
- **Review the diff, not the repository.** Ask for `git show --stat`, then read
  only the files where a defect would actually hide.
- **Keep tracking entries factual and shorter than Unit 3A's.** They are the
  authoritative record, but a future orchestrator pays to read them. Prefer one
  dense entry per defect over four narrative ones.

---

## 7. When to spend a Codex review, and how to make it cheap

Codex is the scarcest budget. Unit 3A's allocation is 7 bounded reviews plus 1
unit review for 21 tasks. That ratio is right. Guidance:

- Spend a review where **a silent failure is plausible and expensive**: signature
  verification order, locking, backups, anything that writes files, anything that
  decides an exit code.
- Do NOT spend one on a task whose failure mode is a loud test failure.
- **The orchestrator reads every other diff itself.** Reading a 200-line diff
  costs far less than a Codex call.
- Give the reviewer the task brief, the diff, and ONE sharp question. The
  highest-yield question in both units has been: **"what does this assert that no
  test would catch if it were false?"**
- Add, for anything security- or determinism-critical: **"prove it by executing
  the shipped code, not by reading it."** Unit 3A's paired-block bug was found by
  a five-line probe script and was invisible to review. The Task 4 review found
  all three of its findings the same way, including a HIGH one the orchestrator's
  own attack tests had missed.
- **Name the specific attack surfaces in the question.** The Task 4 review brief
  listed "absolute paths, relative paths, `..` segments, symlinks, a trailing
  slash, a path whose PARENT is created before the guard runs, the temporary
  file, and the rename target". Two of the three findings came from the last
  three items on that list — the ones a reviewer would be least likely to invent
  unprompted.
- **A review is worth it even when the orchestrator has already attacked the
  code.** Unit 3A wrote five attack tests against `write()` and they all passed,
  because `write()` was correctly guarded. The escape was in
  `write_canonical()`, which had been reasoned safe "by construction" and was
  therefore never attacked. Reasoning about which paths need attacking is what
  fails; attack all of them.

---

## 8. Plan defects: expect them, and correct them before dispatch

Unit 2 found 25. Unit 3A found 9 in its first four tasks. The cheapest moment to
find one is BEFORE a worker starts, because a defect found after the fact costs
the worker run plus the review plus the fix.

The pre-dispatch audit that pays for itself, per task, in a few minutes:

1. **Check every symbol the section names against the merged source.** Four of
   Unit 3A's defects were wrong signatures or wrong return types. One was a fatal
   PHP declaration-compatibility error that made the task's gate red by
   construction.
2. **Ask whether the declared gate can pass.** Three defects across two units were
   gates that were red by construction — a task asserting on something a later
   task creates, or on an environment that does not exist here.
3. **Ask what the task writes to disk, and whether the path is guarded.** Two
   critical defects across two units were unguarded write paths.
4. **Ask which assertion would still pass if the code did nothing.** That is the
   dominant defect family of this engagement.
5. **Ask whether the rule is tested against a FIXTURE or against the real
   artefact the release depends on.** This is now the single highest-yield
   question in the audit — see §8b.

## 8b. Test the shipped artefact, not a fixture built to satisfy the rule

Unit 3A found two CRITICAL, release-blocking defects with exactly this shape:

- A ref-less `core/navigation` block produced no exported reference, so the
  policy refused the shipped `parts/site-header.html`.
- The promotion validator compared a NORMALISING function's output to its raw
  input, so it refused `templates/page.html`, `templates/index.html` and
  `parts/site-header.html` — the entire shipped theme.

Both were invisible for the same reason: **every test used a fixture written to
satisfy the rule**, while the real file the release depends on failed it. In the
second case a worker even had to keep its fixture artificially simple to get
past the bug, and only found it because it reported the workaround honestly.

Both would have surfaced first at the Task 16 vertical slice — six to ten tasks
after the cause — as a mysterious end-to-end failure.

**The countermeasure, applied twice and now standing policy:** for any rule that
governs shipped content, write at least one test that drives the SHIPPED file
through the REAL code path. Not a fixture, not a synthetic reference, not a
simplified copy. Both regression tests in this unit read the real theme file and
run the real scanner, resolver, policy or validator.

Unit 4A owns commerce templates and Unit 3B owns the Global Styles surface. Both
will produce shipped artefacts governed by exactly these rules. Write the
shipped-artefact test FIRST there.

## 8a. Two worker behaviours to correct in the brief

Both cost a full review cycle in Unit 3A and both are cheap to prevent.

- **A worker that reports "the test cannot detect this" and ships the change
  anyway.** The Task 5 worker changed a path parent count, ran a break, observed
  that the suite stayed green, wrote in its report that the test "cannot detect a
  production-side count change", and committed. Tell workers explicitly: if your
  own break leaves the suite green, the TEST is the defect. Fix the test, or stop
  and report — never ship the change on the strength of a test you have just
  proven blind.
- **A worker "correcting" the plan on arithmetic it has not executed.** The same
  count was correct in the plan. Tell workers to PROVE an off-by-one with a
  three-line probe before changing it, and to paste the probe output. The probe
  here would have been
  `echo dirname('/…/src/State/Promotion', 7), dirname('/…/src/State/Promotion', 8);`
  and it settles the question in seconds.

**Assert the value production uses, never an equivalent expression.** The Task 5
test recomputed `dirname( ReflectionClass::getFileName(), 8 )` while production
used `dirname( __DIR__, 8 )`. A class file path is one level deeper than
`__DIR__`, so the two counts legitimately differ, and the test agreed with itself
while disagreeing with production. The fix was a named seam —
`GitRepository::default_root()` — following `SchemaValidator::default_schema_dir()`,
which already existed in this codebase. When a test cannot reach the value, add
the seam rather than recomputing it.

---

## 9. Known-good decisions — do not re-litigate

- `phpcs.xml` needs NO change for promotion code. The
  `WordPress.Security.EscapeOutput.ExceptionNotEscaped` exclusion on
  `agency-platform/src/State/*` DOES cover `src/State/Promotion/*`, because PHPCS
  expands `*` to `.*`. Proven with three probe classes including a control
  outside the scope that correctly errored. The Unit 3A handoff prompt claimed
  the opposite and was wrong.
- `StateDirectory::resolve_output()` is the ONLY web-root guard. Use it for state
  artifacts — bundles, manifests, backups. Do NOT use it for theme files:
  prepared templates legitimately live under `web/app/themes/`, and that path
  needs a theme-directory containment guard instead. These are different rules
  and both are real.
- `GitBaseline::repo_root()` does not shell out to git, so `resolve_output()`
  works inside the container even though git does not.
- `WP_Upgrader::create_lock()` and `release_lock()` exist as static methods in the
  pinned WordPress 7.0.2.
- Never rebase. Ancestry deviations in this engagement have been caused by
  orchestrator docs-only commits. Verify with
  `git diff HEAD...feat/block-theme-fse-migration -- ':!docs/'` before believing
  one is real.
- `commerce-e2e` is exempt through Unit 3B and becomes required at Unit 4A. Each
  merging unit must restate the exemption in its own merge entry.

---

## 10. CI economics

- CI fires only on `main` and `ci-capture/**`. Pushing a feature branch runs
  nothing. Verify with a `ci-capture/<name>` ref, read the run, delete the ref.
- A ref named `ci-capture/visual-baselines` REGENERATES baselines. Any other name
  verifies against the committed ones.
- **Always read step-level outcomes, not the job conclusion.** Steps carry an
  implicit `success()`, so a failure at dependency install marks e2e,
  accessibility, visual and parity as SKIPPED rather than failed. A run can
  report failure while the gates that matter never executed.
- The composer QUIC flake fetching WordPress core is unfixed and defeats all
  three retries. Re-running the failed job is the remedy. Caching the core
  archive is Unit 4B's work and would remove a recurring multi-minute loss.
- Use `--workers=1` for every Playwright run on this host. Concurrent
  `client_editor` logins bounce with `reauth=1`.
- CI round trips are about twelve minutes. Fix the environment locally rather
  than iterating through CI. Unit 1 lost hours to this before installing DejaVu
  fonts locally.

---

## 11. Added by Unit 3B

### 11.1 Bootstrapping a worktree takes THREE steps, not two

The handoff prompts for Units 2, 3A and 3B all listed `ddev composer install`
then `ddev exec bash scripts/setup`. That is incomplete. `scripts/setup` runs
`npm ci` INSIDE the Linux container, which creates `node_modules/.bin` as Unix
symlinks with **zero Windows `.cmd` shims**. `npm run build` still works because
it invokes `node` directly, but `npm run lint` and every Playwright script die
with `'wp-scripts' is not recognized as an internal or external command`.

Add a third step, and use npm 10 explicitly:

```
cd <worktree> && npx -y npm@10 ci
```

**Plain `npm ci` FAILS on this host.** Local npm is 11.x; the committed lock was
written by npm 10, and npm 11 reads it as out of sync on optional
platform-specific packages (`Missing: @parcel/watcher-android-arm64@2.5.6 from
lock file`) and refuses with EUSAGE. `AGENTS.md` warns about the forward
direction — a lock written by a newer npm breaking CI's npm 10 — this is the
same hazard pointed backwards. `npm ci` never writes the lock, so the npm 10
route is safe and leaves `package-lock.json` untouched.

### 11.2 Never chain a gate behind a pipe

`npm run lint 2>&1 | tail -25 && npm run build` reports **exit 0 even when lint
fails**, because the pipeline's status is `tail`'s. This masked a real lint
failure twice in one session. Redirect to a file and capture the code:

```
npm run lint > /tmp/lint.log 2>&1; echo "EXIT=$?"; tail -25 /tmp/lint.log
```

The same trap applies to `ddev composer verify | tail`.

### 11.3 Check the dev site's STATE before believing a Playwright result

Unit 3B ran all three Playwright suites against a dev site whose Global Styles
post held a leftover `#101010` background. Result: visual 4 failed (80% of pixels
on the home page), accessibility 6 failed (every violation naming
`background color: #101010`). After resetting the post, accessibility was
**6 passed** and the home-page diff fell from 80% to 2%.

Two lessons. First, orchestrator probes and the e2e suite both write to the dev
site's `wp_global_styles` row and do not always restore it — the integration
suite does not, because it runs against the separate `wordpress_test` database.
Second, before attributing any Playwright failure to code, dump the state the
test actually renders. One `wp eval-file` probe replaces a long argument.

### 11.4 The carried-over DDEV database has drifted from the visual baselines

Even with Global Styles clean, the local visual suite still differs: home desktop
2%, mobile 23%, and the demo page renders 1521px tall against an 1899px baseline
— a structural difference, not antialiasing. The database volume has been carried
across four units and mutated by every e2e run; the baselines were captured
against a fresh install in CI. **Local visual is therefore NOT a reliable gate on
this host; CI on a fresh database is the authority.** Do not spend time chasing
it, and do not regenerate baselines from a local run — a `ci-capture/visual-baselines`
ref is the only correct way to regenerate. Unit 4B should decide whether to
re-seed the local database.

### 11.5 A worker invocation can be killed leaving NO artifacts

One `opencode` run was killed by the harness after about 26 minutes. The output
buffer was **empty**, and the artifact directory contained only the three
pre-flight stderr files — no `stdout.ndjson`, no `session.json`. The ONLY
evidence of ~750 lines of good work was the worktree itself.

So: **do not batch several plan tasks into one invocation.** The Unit 3B handoff
advised batching Tasks 22 and 23 into a single run; that advice assumes the run
completes. Dispatch one task per invocation and let each commit land. Recovery is
then cheap — inspect the worktree, write a resume prompt that names the exact
state, and the worker finishes rather than restarts.

When recovering, READ THE PARTIAL WORK BEFORE RE-DISPATCHING. The killed run had
left a deliberate `// BREAK:` in place mid-proof; running the suite showed 12 of
13 green with the 13th failing exactly as its break intended. Telling the resume
worker that turned a restart into a five-minute finish.

### 11.6 Theme JSON facts that cost this unit its whole audit budget

All verified by execution against WordPress 7.0.2. They are cited in the Task 22
and 23 CORRECTION blocks and should not be re-derived.

- **`WP_Theme_JSON::get_raw_data()` is NOT theme.json input shape.** It keys every
  preset node by ORIGIN (`spacingSizes => {"theme":[…]}`). Writing it back as
  `theme.json` produces `Undefined array key "slug"` warnings from
  `class-wp-theme-json.php:3451` and mis-registers every preset.
  **`get_data()` is the input-shape API** — it flattens presets to lists in origin
  order and is idempotent.
- **`wp_get_global_settings()` returns origin-keyed presets too.** Any
  before/after comparison across a promotion will therefore report false drift,
  because promotion legitimately moves a user preset from the `custom` origin to
  the `theme` origin. Compare
  `WP_Theme_JSON_Resolver::get_merged_data()->get_data()` instead.
- **`WP_Theme_JSON_Resolver::$theme_json_file_cache` survives
  `clean_cached_data()` AND `wp_clean_theme_json_cache()`.** It is a
  `protected static` keyed by file path. A process that resolved before
  `theme.json` changed keeps resolving the OLD file. Clearing it needs guarded
  reflection.
- The shipped `theme.json` loses `settings.appearanceTools` (core expands it) and
  `settings.spacing.custom` (not a valid v3 property) through `WP_Theme_JSON`, so
  any "every input key survives" check refuses the shipped theme.

### 11.7 The pre-dispatch execution probe is the cheapest instrument here

Unit 3A found its two release-blocking defects AFTER the worker runs, each
costing a worker run plus a review plus a fix. Unit 3B found THREE of the same
species BEFORE dispatch, in about forty minutes of probing, by asking §8b's
question first and then answering it with `wp eval-file` instead of by reading.

**Budget an hour of probing before the first worker of any unit that governs a
shipped artefact.** Ask it in this order: what does the task write, what rule
governs it, does the REAL shipped file satisfy that rule today, and can the
declared gate actually go green? Unit 4A owns commerce templates and inherits
exactly this shape.

---

## 12. Added by Unit 4A

### 12.1 A finding is not closed until every CALLER is checked

This was Unit 4A's most expensive mistake and it was entirely avoidable.

A worker reported that running `scripts/enable-commerce` from the WSL host made
its new step silently abort: `mktemp` writes to the host `/tmp`, and the
in-container `ddev wp` cannot read that path, with stderr discarded by the
step's own `2>/dev/null`. The orchestrator recorded it, changed its OWN switching
procedure to `ddev exec bash scripts/enable-commerce`, and moved on.

**CI invokes the same script the same broken way**, at `ci.yml:511`, on a line
the orchestrator had already read. The required `commerce-e2e` gate then failed
at seeding, and the two commerce suites never ran.

When a worker hands you a mechanism, grep for every caller of the thing before
you close it. The fix was to make the script work from either side — a
repository-relative temp path under the gitignored `var/agency-state/`, which
resolves identically on the host and in the container — not to change the one
caller you happened to notice.

### 12.2 Aim the whole-unit review at the artefact with NO automated test

Unit 4A's whole-unit review found THREE defects and all three were in
`scripts/enable-commerce` — the single artefact in the unit with no PHPUnit
coverage, by design, because the commerce suite runs against wp-phpunit's
throwaway database the script never touches. Every tested surface came back
clean.

The three were a `wp post delete --force` that fired on any page merely
CONTAINING a shortcode, a silent overwrite of a customised template-part
override, and an idempotency predicate that was a bare substring (`mini-cart`)
so that a paragraph mentioning it made the script skip and report success.

Prose and shell scripts are where the defects hide, because no suite executes
them. Point the review there explicitly.

### 12.3 Probe before re-dispatching a failed worker

A dispatch failed with a 705-byte event stream containing one record:
`APIError 400 — Error from provider (Console Go): Upstream request failed: Model
is unavailable`, `isRetryable: false`. The worktree was untouched.

Instead of re-running the whole task blind, a five-word probe prompt through the
same wrapper returned `PROBE-OK` on the correct model in seconds. That
distinguishes a transient outage from a model-policy failure, which is one of the
three conditions the handoff says to STOP for. Cost: about a minute.

### 12.4 `$?` is destroyed by PowerShell

In PowerShell `$?` is a BOOLEAN. Any double-quoted string containing
`echo "EXIT=$?"` — including one you are passing on to `bash -lc` — expands to
`EXIT=True` or `EXIT=False` before bash ever sees it, whatever the real exit code
was. A worker spent part of a run reading `EXIT=True` from failing commands.

Use a single-quoted string, escape it as `\$?`, or avoid the question entirely by
redirecting to a log and reading the log. This is a SECOND, independent hazard
from §11.2's pipe problem: redirecting to a file fixes the pipe, not this.

### 12.5 DDEV snapshots do not follow the project across worktrees

`ddev snapshot --list` from a new worktree reports **No snapshots**, even though
the database volume itself survives `ddev stop --unlist` intact. Snapshots live
in `<project>/.ddev/db_snapshots/`, which is inside the WORKTREE, not in the
volume.

So a handoff carries the DATA but not the SNAPSHOTS. Copy the `.zst` files from
the previous worktree if you want the old states, and take a fresh
`base-profile` snapshot before installing WooCommerce for anything, because
`SWITCH TO BASE` restores by that name.

Two related DDEV facts, both verified: `ddev snapshot list` is NOT a subcommand —
listing is the flag `--list` — and `ddev snapshot --name=<existing>` REFUSES with
`snapshot … already exists` rather than overwriting. Use
`ddev snapshot --cleanup --name <name> -y` first.

### 12.6 The carried-over database is now a repeat offender

Unit 3B found it had drifted from the visual baselines. Unit 4A found it made the
commerce profile **unreachable**: a previous run's 2 products, 2 variations, 9
legacy `shop_order` rows, 1 coupon and 14 `wp_wc_orders` rows left HPOS already
enabled with 5 unsynced orders, so `wp wc hpos enable` failed a pre-check for a
setting that was already on and `scripts/enable-commerce` aborted at step 3.

Worth knowing for any future cleanup: **`wp post delete --force` is NOT
sufficient for legacy `shop_order` rows** while HPOS is on with compatibility
mode off — WooCommerce intercepts the deletion and the `wp_posts` rows survive.
Direct SQL was required, plus clearing `wp_wc_orders`, `wp_wc_order_addresses`,
`wp_wc_order_operational_data` and `wp_wc_orders_meta`.

Unit 4B should decide whether to re-seed. The argument now has two independent
supports.

### 12.7 Two Playwright assertion shapes that cannot fail

Both were caught in Unit 4A, one by the orchestrator's audit and one by a worker
on its own work.

- **`toHaveCount(1)` on a `.first()` locator ALWAYS passes**, because `.first()`
  yields exactly one element by construction. A worker wrote this as a scoping
  guard, its own break run stayed green, and it correctly declared the test the
  defect rather than shipping it. Assert on the raw set instead.
- **A role locator whose name regex is too loose silently matches the wrong
  control.** `getByRole( 'button', { name: /cart/i } )` matches "Add to cart", so
  a Mini-Cart assertion on a product page passes whether or not a Mini-Cart
  exists. Scope it to the container element and prove the scoping on a page that
  also holds the decoy.

Related, and cheap: when the value you care about lives in an `aria-label` rather
than the visible text, assert `toHaveAccessibleName`, and assert BOTH states of a
transition. A test that only checks the "1" state passes even if the control
always says 1.

### 12.8 `$template->theme` does not distinguish a theme template from a plugin one

`get_block_template()` fills `theme` with the ACTIVE STYLESHEET even for
plugin-provided templates. Proven with one template overridden and one not:

    archive-product | origin=NULL     | source=theme  | theme=site-theme
    single-product  | origin='plugin' | source=plugin | theme=site-theme

So an assertion of the form `assertSame( get_stylesheet(), $template->theme )`
passes with NO override present. `source` and `origin` are the discriminating
fields. This was the plan's only test that an override takes effect, and it could
not fail.

---

## 13. Added after the final review

### 13.1 PHPStan has a result cache and can give a false green

A local PHPStan run reported `No errors`, while CI reported 49 errors in files
that the fix wave did not touch. `phpstan clear-result-cache` reproduced CI
exactly. Clear the PHPStan result cache before any gate that you intend to
trust. The existing PHPUnit `cacheResultFile` warning does not cover this tool.

### 13.2 Test stubs must match the real WordPress symbols

PHPStan scans `tests/`, so a narrower test stub shadows
`php-stubs/wordpress-stubs` for the whole analysis. A `WP_Post` stub with only
`ID` and `post_type` caused 49 undefined-property errors in production state
providers. The same defect affected `WP_REST_Request::get_method()` and the
untyped `wp_die()` title and response-code contract. A stub must be an accurate
stand-in. It must not be stricter than WordPress.

### 13.3 Parallel agents must partition symbols as well as files

Seven agents had disjoint file lists but still collided on the
`WP_Block_Type_Registry` symbol. One declared the class and another used
`class_alias`. The unit suite passed according to load order, but PHPStan did
not. Partition shared symbols as well as paths, and require PHPStan as a
per-agent gate. A unit and architecture pass is not sufficient for parallel
work.

### 13.4 Base e2e runs are not idempotent

Two Unit 1-owned specs can destroy the navigation that later tests need. A
fresh-clone first run passed, but the repaired-navigation, full-suite,
repair-navigation, smoke-only sequence exposed the loss. Treat the worktree
state as a test input and repair it before a repeat run.

### 13.5 Measure the work product during an opencode run

An empty artifact directory does not prove that an opencode run is hung. The
reliable liveness signal is whether the work product is growing. Inspect the
work product before dispatching a replacement.

### 13.6 Three of the four closed limitations, and why the fourth was refused

Run after the ship, as four parallel `gpt-5.6-luna` xhigh packages.

**Closed.** The backup-index mutex on the delete path; the non-idempotent e2e
suite; and the `lint:js` scope.

**Refused: the versioned HMAC.** Its finding was REAL and the agent proved it —
its own test demonstrates the old lossy normalisation collapsing two distinct
documents onto one signature — and its design was the right shape: sign as v2,
verify v1 and v2, so manifests in retention keep working with no migration.

It was still refused, and the reasoning is the transferable part. Reverting its
production file ALONE cleared both unit failures, including the one the agent
had attributed to a different package. And it had downgraded two documented
exit codes: tamper **4 to 1**, and lock conflict **3 to 1**. Exit 4 is stated in
the runbook, in `docs/validation-scenarios.md` and in the promotion proof.

**An unreviewed change to a signing format, made after the final review, that
breaks tamper detection on its first run, is not worth shipping to close a
malleability nothing is exploiting.** The finding is now better documented than
before — demonstrated exploitable, with a working design and a passing test in
the record — which makes the follow-up cheap for someone with a proper review
cycle. Deferring twice, with better evidence each time, beats shipping once
without review.

### 13.7 Widening a linter can break an architecture guard

`lint:js` had never covered `tests/`. Widening it made prettier want to reflow
the `ParityPage` literals in `tests/parity/helpers/parity.ts` onto several
lines. `MigrationBaselineGuardTest` matches each of them with a SINGLE-LINE
`/m` regex, and its failure message says exactly why: "A computed value or
object spread can bypass the ratio ceiling."

So a blanket auto-fix would have disabled a guard that exists to stop anyone
quietly loosening the visual-diff threshold. The fix is four scoped
`prettier-ignore` comments, with the rule left enabled everywhere else.

Before widening any linter or formatter over a directory it has never touched,
ask which tests READ those files as text. Architecture tests that parse source
with a regex are invisible to a formatter and will not warn you.
