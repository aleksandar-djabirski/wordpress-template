# Backup Contract

This starter doesn't ship a backup mechanism — hosting varies per project —
but every project built from it must satisfy this contract before going
live. See `ops/restore.md` for why a backup that has never been restored
doesn't count.

## What must be backed up

- **Database**: the full WordPress database (`wp db export` or the host's
  equivalent). This is the only home for content that Git doesn't own —
  posts, pages, testimonials, WooCommerce orders/products (commerce
  profile), user accounts, and the database template, template-part, and
  Global Styles overrides clients make in the Site Editor — expected state
  under the source-of-truth model, not corruption (see
  `docs/state-reconciliation.md`).
- **Uploads**: `web/app/uploads/` (media library). Never covered by Git —
  `.gitignore` excludes it by design.
- **`.env`**: back up separately, encrypted, and restricted — it holds
  database credentials and auth salts. Losing it is a secrets-rotation
  event, not just a data-loss event; never store it alongside routine
  content backups with the same access controls.
- **Promotion backups are not a substitute for database backups.** The
  protected promotion backups `wp agency promote-overrides --finalize` writes
  cover only the records one promotion touched, and only until they are pruned.
  They are a rollback mechanism only until the promotion is confirmed — not a
  backup (see `docs/state-reconciliation.md`).

## What does NOT need backing up

Code, theme templates, `theme.json` tokens, block/pattern definitions, and
configuration all live in Git — a code deploy from the repository restores
them. Backing these up outside Git is redundant and a drift risk (a second
copy that can silently diverge from `main`).

## Contract every project must meet

- **Frequency**: database backups at least daily; uploads backups at least
  daily (or continuously, if the storage backend supports it).
- **Retention**: at least 14 daily + 4 weekly snapshots, so a problem
  noticed days later is still recoverable.
- **Location**: stored off the origin server/host — a backup that lives
  only next to the data it protects is not a backup.
- **Encryption**: at rest and in transit, given the database contains user
  PII (see `scripts/sanitize-database` for scrubbing PII before a copy
  leaves production).
- **Access**: restricted to the people/systems that actually need restore
  capability — not the whole team.
- **Verification**: every backup's existence and non-zero size checked
  automatically after each run; a silent backup failure is worse than an
  absent backup, because it looks like coverage that isn't there.

## What must NOT be backed up into the repository

State bundles (`wp agency state-export`) and promotion manifests contain
customer content and internal site structure. They default to
`var/agency-state/`, which is gitignored, and must stay out of Git and out of
any shared artifact store without the same access controls as a database
backup. Treat a leaked bundle as a data incident.

## Before a risky change

Take an ad hoc, explicitly-labeled backup before: a major WordPress/plugin
version bump, a database-affecting migration, a promotion finalisation, or a
manual production hotfix — don't rely solely on the next scheduled snapshot.
