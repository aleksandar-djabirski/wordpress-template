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
4. Confirm the rollback in production the same way you'd confirm any
   deploy (smoke test, `wp agency verify-env`).

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

## 5. After the incident

Write down what happened, when, the trigger, the fix, and one concrete
prevention step (a new architecture test, a new monitor, a process change)
— an incident with no follow-up is a guarantee of a repeat.
