# Update Process

How dependency and WordPress-core updates flow from "available" to
"running in production" — for one project, and consistently across a
fleet of client projects built from this starter. Hosting-agnostic: this
describes the process contract, not a specific host's deploy mechanism.

## Automated update flow (Dependabot, `.github/dependabot.yml`)

Dependabot opens grouped, weekly (Monday) PRs — grouped so a WordPress
core bump and an unrelated dev-tool bump don't get tangled in one review:

- **Composer**: `wordpress` (`roots/*`, `wpackagist-*`) and `dev-tools`
  (phpstan, phpcs, deptrac, phpunit, and related packages) as separate
  groups.
- **npm**: `wordpress-tooling` (`@wordpress/*`) and `testing` (Playwright,
  axe-core, stylelint, eslint) as separate groups.
- **github-actions**: ungrouped, one PR per action.

Dependabot *security* updates (immediate PRs for newly-disclosed
vulnerabilities) are a separate GitHub repository setting, independent of
this weekly schedule — enable it per project.

## The 9-step flow (per update, per project)

1. **Dependabot (or a manual bump) opens a PR** against `main`.
2. **CI runs** (`ci.yml`): `php-qa`, `frontend`, `integration`, `e2e` —
   the same gates every other PR passes.
3. **Read the changelog/diff** for what changed, not just that CI passed —
   especially for WordPress core, WooCommerce (commerce profile), and any
   package with a major-version bump.
4. **Merge** once CI is green and the change is reviewed as safe.
5. **Deploy to staging first** — never straight to production, even for a
   green CI run; CI proves the code works in isolation, not against this
   project's real content/configuration.
6. **Smoke-test staging**: `npm run test:e2e` / `test:accessibility`
   against the staging URL, plus a manual pass over any area the update
   touched (e.g. the block editor after a `@wordpress/*` bump).
7. **Run `wp agency verify-env` and `wp agency check-overrides` on
   staging** — an update should never silently introduce a database
   override or weaken an environment-safety invariant.
8. **Promote to production** through the project's normal deploy
   pipeline, at a low-traffic window for anything beyond a patch bump.
9. **Monitor** for the period after deploy defined in
   `ops/monitoring.md` (error rate, uptime, and — for a WordPress/plugin
   major bump — a manual admin-area check) before considering the update
   complete.

## Scheduled maintenance (`.github/workflows/scheduled-maintenance.yml`)

Runs weekly independent of any push/PR: a `composer audit` +
`npm audit --omit=dev --audit-level=high` gate, and a
`GeneratedIndexFreshnessTest` re-run to catch drift that a scheduled
tooling change (not a code push) might introduce. A failure here should be
triaged the same week, not deferred to the next dependency PR.

## Cadence

Weekly for routine dependency PRs; immediately for a security update
(Dependabot security PRs skip the weekly grouping by design); quarterly, at
minimum, for a deliberate WordPress core minor/major version review even
if no PR forced it.
