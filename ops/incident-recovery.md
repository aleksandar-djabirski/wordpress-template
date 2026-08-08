# Incident Recovery

How to respond once something is actually broken — as opposed to
`ops/monitoring.md` (how you find out) and `ops/backup.md`/`ops/restore.md`
(how you get data back). Hosting-agnostic: adapt the specific commands to
the project's actual host, but keep the sequence.

## 1. Establish blast radius

Before touching anything: is this a code problem (a deploy), a data
problem (database/content), or an infrastructure problem (host, DNS,
certificate)? The next steps diverge sharply, and acting before this is
clear risks compounding the incident (e.g. restoring a database when the
real problem was a bad deploy).

## 2. Code rollback

For a bad deploy (the common case after `ops/update-process.md`'s step 8):

1. Identify the last known-good commit/tag on `main`.
2. Redeploy that revision through the project's normal deploy pipeline —
   `git revert` the offending commit(s) rather than force-pushing history,
   so the rollback itself is reviewable and the incident's cause stays in
   history.
3. Re-run `ddev composer verify:fast` (or the project's CI) against the
   reverted state before it goes live again, even under time pressure —
   a rushed rollback that's also broken doubles the incident.
4. Confirm the rollback in production with a smoke test and the host's
   production health checks. Do not use `wp agency verify-env` as a production
   check. It only checks non-production safety invariants. In production it
   warns and exits successfully without checking those invariants.

## 3. Database restore

For data corruption/loss: follow `ops/restore.md` in full, including its
smoke-test checklist — do not shortcut the checklist because it's an
emergency; a restore that "looks fine" but silently breaks something else
just converts one incident into two.

## 4. Sanitized staging debug flow

For anything you need to reproduce against real data without risking
production or exposing PII to whoever's debugging:

1. Take (or reuse a recent) production database export.
2. Restore it to an isolated staging/scratch environment
   (`ops/restore.md`, steps 1–5).
3. Run `bash scripts/sanitize-database` (`wp agency sanitize`) — an ordered,
   idempotent, step-based scrub: non-administrator user emails/URLs,
   commenter emails/URLs, all sessions and application passwords, and
   `blog_public` set to 0. It is extensible via the
   `agency_platform_sanitize_steps` filter, so with `site-commerce` +
   WooCommerce active it also anonymizes WooCommerce order PII. Use
   `--include-admins` to include administrator email/URL. Never debug against
   an un-sanitized production copy outside the actual production environment,
   and remember sanitize does not know about arbitrary third-party plugins'
   PII tables — audit those separately before sharing the copy.
4. Reproduce and fix the issue there; verify the fix with
   `ddev composer verify:fast` plus the relevant Playwright suite before
   it goes anywhere near production.
5. Discard the sanitized environment (or keep it as a standing sanitized
   staging site, refreshed on the same cadence as `ops/backup.md`'s
   restore-test cadence) — don't let a one-off debug copy become an
   unmonitored, unpatched, forgotten install.

## 5. A promotion made the site wrong

1. Do not hand-edit the database. Run
   `wp agency promote-overrides --rollback --manifest=<path>` — it restores the
   original records in dependency-safe order and verifies hashes afterwards.
2. **Confirm is the point of no return.** `--rollback` after `--confirm` is
   refused with `Promotion <id> is already confirmed; rollback is refused after
   confirm.` and exits 1. That is safety, not an oversight: retention may prune
   the backups at any moment after confirm, so a rollback that appeared to work
   could silently restore nothing. Roll back BEFORE confirming — between
   `--finalize` and `--confirm` is the window in which a promotion can still be
   undone; after confirm the recovery path is a database restore
   (`ops/restore.md`), not a rollback. The backups themselves do survive
   confirm (`wp agency promotion-backups list` still shows them) — it is the
   rollback that is refused, not the backup that is gone.
3. Rollback REFUSES any record that a newer promotion or a client edit has
   changed since finalisation, and any deleted record that has since been
   re-created. Those refusals protect newer work — resolve each one by
   decision, never by forcing the restore.
4. A partial rollback exits non-zero and names every record it could not
   restore. Treat that as an open incident until each named record is resolved.
5. Full procedure and command lines: `docs/state-reconciliation.md`.

## 6. After the incident

Write down what happened, when, the trigger, the fix, and one concrete
prevention step (a new architecture test, a new monitor, a process change)
— an incident with no follow-up is a guarantee of a repeat.
