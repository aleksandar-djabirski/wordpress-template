# Restore Contract

**A backup is not valid until restoration from it has been tested.** An
unverified backup is a hope, not a guarantee — this contract exists so
"we have backups" is a demonstrated fact, not an assumption.

## Restore procedure

1. Provision an isolated environment (a scratch DDEV instance or disposable
   staging site) — never restore directly on top of a live environment
   you might still need.
2. Restore the database (`wp db import <dump>` or the host's equivalent).
3. Restore `web/app/uploads/` from the matching backup.
4. Deploy the matching code revision (Git tag/commit the backup was taken
   against) — a database from one release restored against a different
   release's code can mismatch on template/CPT/meta expectations.
5. Run `ddev composer install && npm ci && npm run build` if restoring
   from a bare code checkout rather than a pre-built deploy artifact.
6. If the restored database came from production and the target isn't
   production, run `bash scripts/sanitize-database` — it scrubs
   non-administrator emails/URLs, revokes sessions and application
   passwords fleet-wide, and sets `blog_public` to discourage indexing.
   Never skip this when production data lands anywhere non-production.

## Restore smoke-test checklist

Run this after every restore — scheduled test or real incident:

- [ ] Site loads (homepage, a representative page, a representative post).
- [ ] Admin login works with a known account.
- [ ] `ddev wp agency check-overrides` runs clean (or reports only the
      overrides you expect — a restore can resurrect stale database
      template rows).
- [ ] Media referenced by recent content actually resolves (proves the
      uploads restore, not just the database restore, succeeded).
- [ ] WooCommerce profile only: a test order/checkout flow completes.
- [ ] `ddev wp agency verify-env` passes if the target is non-production.
- [ ] Record the test date, who ran it, and the backup's age/source in
      this environment's change log — an untested backup older than your
      restore-test cadence should be treated as unverified.

## Cadence

Test a full restore at least quarterly, and immediately after any change
to the backup mechanism itself (a new host, a new backup tool/schedule).
Treat a failed restore test as an incident, not a to-do item — see
`ops/incident-recovery.md`.
