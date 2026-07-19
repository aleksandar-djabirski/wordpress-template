# Launch Checklist

The go-live gate for a project built from this starter. Every item is a
deliberate decision or a proven-working safeguard — not an aspiration. Record
the outcome (who, when, and the choice made) in the project's change log;
"we'll do it later" is not a passing state for anything below.

## Editing model

- [ ] **Editing-strictness dial explicitly chosen and recorded.** The default
      is loose: customers compose pages from the block allow-list freely. Decide
      per project whether to tighten it (trim the allow-list, `templateLock` a
      post type, or drop page capabilities) and write down which dial you picked
      and why — see `docs/editing-strictness.md`. Not choosing is itself a
      choice; make it on purpose.
- [ ] **Commerce clients: shop-manager scope reviewed.** `client_shop_manager`
      carries `manage_woocommerce`, which grants access to WooCommerce settings
      (core parity, not a starter decision). Confirm that scope is acceptable
      for this client, or trim it per project. See `docs/editing-strictness.md`.

## Data safety

- [ ] **Backups implemented AND a restore drill completed.** A backup is not
      valid until restoration from it has been tested — meeting `ops/backup.md`
      is only half the gate; you must also complete the restore procedure and
      smoke-test in `ops/restore.md` at least once before launch.
- [ ] **Production DB sanitize audited.** `wp agency sanitize` is a BASELINE
      scrub: core PII (users, comments, sessions, app passwords) plus, with the
      commerce profile active, known WooCommerce order and registered-customer
      fields. It does NOT know about third-party plugins' own PII tables/meta —
      audit every installed plugin before treating any dump as safe to share,
      and never move production data anywhere non-production without running it.
- [ ] **`wp agency verify-env` green on staging.** Confirms the non-production
      invariants hold (outbound webhooks disabled; MailGuard active unless a
      test mailbox is deliberately opted in).

## Environment

- [ ] **Secrets live in the host's secret store, never the repo.** `.env` is
      untracked; auth salts and credentials are set per environment.
- [ ] **DNS, TLS, and monitoring live.** Certificates valid and auto-renewing;
      uptime and error monitoring wired up per `ops/monitoring.md`.
