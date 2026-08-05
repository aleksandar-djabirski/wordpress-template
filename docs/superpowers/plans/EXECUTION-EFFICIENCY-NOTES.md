# Execution efficiency notes — block-theme migration

**Purpose:** make each remaining unit cost less wall-clock time and fewer tokens
than the one before it. This file is NOT a history. The tracking file
(`2026-08-02-block-theme-tracking.md`) records what happened and why. This file
records only what the NEXT orchestrator should DO DIFFERENTLY, and what to reuse
rather than rediscover.

**Audience:** the orchestrator of Unit 3B, 4A and 4B, and, through the brief it
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
- **Do not poll background tasks.** You are notified when they finish. Each poll
  costs a turn and usually returns nothing. Unit 3A wasted several turns this
  way. If you must wait, do useful preparation instead — write the NEXT task's
  notes and pre-generate its brief.
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
