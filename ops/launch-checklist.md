# Launch Checklist

The go-live gate for a project built from this starter. Every item is a
deliberate decision or a proven-working safeguard — not an aspiration. Record
the outcome (who, when, and the choice made) in the project's change log;
"we'll do it later" is not a passing state for anything below.

## Editing model

- [ ] **Site Editor posture understood and recorded.** Client roles have full
      visual Site Editor control within the approved block system: templates,
      template parts, navigation, Global Styles, and page composition. They
      cannot switch or install themes/plugins, edit files, use the code editor,
      insert HTML or Shortcode blocks, or edit Additional CSS. Confirm the
      client has been told what they can and cannot change, and record any
      per-project tightening (`docs/editing-strictness.md`).
- [ ] **Revision-history trade-off communicated.** Promotion resets the database
      override it promotes, so Site Editor revision history for that record may
      become unreachable in the editor afterwards. Confirm the client knows this
      before the first promotion — see
      `docs/state-reconciliation.md#revision-history-trade-off`.
- [ ] **Commerce clients: shop-manager scope reviewed.** `client_shop_manager`
      carries `manage_woocommerce`, which grants access to WooCommerce settings
      (core parity, not a starter decision). Confirm that scope is acceptable
      for this client, or trim it per project. See
      `docs/editing-strictness.md#commerce-role-dial` for the exact cap to drop
      and what it does (and does not) cost.

## State reconciliation

- [ ] **HMAC keyring provisioned in every environment that signs or verifies.**
      `AGENCY_PROMOTION_HMAC_KEYS` (a JSON keyring) and
      `AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID` are set in local/CI preparation and
      on the production host. A missing or empty keyring is a hard failure by
      design — there is no fallback to WordPress salts, which differ per
      environment. Record where the keys live and who can rotate them.
- [ ] **`AGENCY_TARGET_SITE_UUID` recorded for production**, and a rotation plan
      exists (add the new key, advance the signing key id, keep the old key
      until outstanding manifests and backups expire).
- [ ] **Promotion rehearsed once on staging.** Run the full proof in
      `docs/state-reconciliation.md#verification-proofs` end to end —
      export → diff → prepare → commit → seal → deploy → finalise → verify →
      confirm, plus one deliberate verification failure that auto-rolls back.
      A promotion system that has never been rehearsed is not a launch-ready
      system.
- [ ] **Promotion backup retention decided.** Confirm the retention window and
      that `wp agency promotion-backups prune` is scheduled or owned by a named
      person.
- [ ] **`var/agency-state/` is not served by the web server** and is excluded
      from any deployment artifact that leaves the host.

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
- [ ] **No local seed artifacts in the production database.** The local
      bootstrap scripts (`scripts/setup`, `scripts/enable-commerce`) create
      deterministic, publicly-known accounts (`admin`, `client-editor`,
      `shop-manager`, `test-customer` — each with its username as its password),
      fixture products ("Test Simple Product", "Test Variable Product"), and
      coupon `TESTCOUPON`, and a site-header template-part database override
      carrying the Mini-Cart block. These land in production only if someone
      ran a local script against the wrong database; the scripts now refuse to
      run outside `development`/`local`, so getting them there is an explicit
      act. Confirm none are present before go-live, and remove any that are.

## Environment

- [ ] **Secrets live in the host's secret store, never the repo.** `.env` is
      untracked; auth salts and credentials are set per environment.
- [ ] **DNS, TLS, and monitoring live.** Certificates valid and auto-renewing;
      uptime and error monitoring wired up per `ops/monitoring.md`.
