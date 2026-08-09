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
   production, run `bash scripts/sanitize-database` (`wp agency sanitize`).
   It runs an ordered, idempotent, **baseline** set of steps — for
   non-administrators, scrubs email/URL, display name, `user_nicename` (the
   public author slug), and profile meta (first/last name, nickname, bio);
   scrubs commenter email/URL, author name, IP, and user agent (comment bodies
   are left intact — a per-project editorial call); revokes sessions and
   application passwords fleet-wide; and sets `blog_public` to discourage
   indexing. `user_login` and comment content are deliberately preserved. The
   step set is extensible via the `agency_platform_sanitize_steps` filter: with
   `site-commerce` + WooCommerce active, an extra step anonymizes WooCommerce
   order PII (classic postmeta and HPOS `wc_orders`/`wc_order_addresses`,
   including captured IP/user-agent/customer-note/transaction id), registered
   customers' billing/shipping account meta, payment tokens, and sessions. Pass
   `--include-admins` to sanitize administrator email/URL too (off by default
   so agency staff logins and password resets stay usable on a local import).
   Never skip sanitize when production data lands anywhere non-production.
   **Warning:** this is a baseline scrub of the starter's core and commerce PII,
   not arbitrary third-party plugins — review every installed plugin for its own
   PII tables/meta before treating any sanitized dump as safe to share (this is
   a launch gate: see `ops/launch-checklist.md`).
   Sanitize also regenerates the site's stable identifier
   (`agency_platform_site_uuid`) the first time a production database is seen
   outside production, so the restored copy can never satisfy a promotion
   manifest that targets production. The regeneration is idempotent — a second
   sanitize run on the same non-production copy preserves the local identifier.
7. If the restore is a rollback of a deployment that included a promotion,
   read `docs/state-reconciliation.md`'s recovery procedure BEFORE re-applying
   database overrides. `wp agency promote-overrides --rollback` refuses any
   record a newer promotion or a client edit has since changed; that refusal is
   correct and must be resolved by decision, not by force.

## Restore smoke-test checklist

Run this after every restore — scheduled test or real incident:

- [ ] Site loads (homepage, a representative page, a representative post).
- [ ] Admin login works with a known account.
- [ ] `ddev wp agency state-diff` runs and its report matches expectations.
      It is INFORMATIONAL by default — legitimate database overrides are normal
      and do not fail it. Use `--fail-on-drift` only where a non-zero exit is
      genuinely wanted (for example a CI gate). `wp agency check-overrides`
      still works as a compatibility alias, but it prints a deprecation warning
      pointing at `state-diff`, which is also the machine-readable form.
- [ ] Create the ignored state directory, then export a smoke-test bundle:
      `mkdir -p var/agency-state` followed by
      `ddev wp agency state-export --output=var/agency-state/restore-smoke-bundle.json`.
      The command writes a signed bundle and prints only its export envelope.
- [ ] Verify that bundle with
      `ddev wp agency state-diff --source=var/agency-state/restore-smoke-bundle.json --format=json`.
      Run this immediately after export. Exit `0` means no drift. Exit `2`
      means that state changed after export. The command verifies the bundle's
      signature and state hash before it reads the bundle.
- [ ] `ddev wp agency promotion-backups list` runs and shows only backups you
      expect for this environment.
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
