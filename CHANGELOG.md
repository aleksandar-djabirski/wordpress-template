# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-07-18

Initial release of the agency starter.

### Added

- Bedrock-structured WordPress on DDEV (PHP 8.3, MariaDB 10.11), configured via `.env`.
- `agency-platform` mu-plugin: `client_editor` / `client_shop_manager` roles, block-editor and site-editor lockdown, application-password restriction, file-modification guard, database-override detection, structured logging, and the `wp agency check-overrides|sanitize|verify-env` WP-CLI commands.
- `site-core` plugin: the `testimonial` custom post type, the `SiteCore\Contracts\*` public API (`Testimonials`, `LeadDelivery`), and lead-submission handling via the `site_core_lead_delivery` filter.
- `site-integrations` plugin: environment-aware lead-delivery resolution — a safe in-process fake everywhere except production with a configured webhook, and a `wp_remote_post`-based webhook implementation with timeout and PII-free logging.
- `site-commerce` plugin: a WooCommerce-gated skeleton that activates its providers only when WooCommerce is present.
- `site-theme`: a hybrid classic/block theme — root template delegates, `templates/`, reusable `parts/` (header, footer), the `agency/reference-callout` reference dynamic block, a locked landing-page pattern, global design tokens (`theme.json`), and a WooCommerce template-override contract (`woocommerce/README.md`).
- Architecture enforcement: 8 PHPUnit test classes covering directory structure, theme-bootstrap thinness, block manifests, WooCommerce isolation, hook ownership, integration boundaries, global asset rules, and generated-documentation freshness; Deptrac layer rules; PHPCS, PHPStan (level 6), Stylelint (design-token enforcement), and ESLint.
- Test suites: architecture, unit, integration (PHPUnit), and end-to-end, visual-regression, and accessibility suites (Playwright).
- CI: a PHP QA gate, a frontend build/lint/drift-check gate, a database-backed integration gate, and a full DDEV + Playwright end-to-end gate; a PR dependency-review gate; a weekly scheduled dependency-audit and index-freshness workflow; grouped Dependabot updates.
- Operational scripts: `setup`, `verify`, `generate-block-index`, `check-database-overrides`, `sanitize-database`, `verify-environment`, `rename-project`.
- Documentation: agent guidance (`AGENTS.md`), architecture, ownership rules, how-to guides for blocks/integrations/commerce, validation scenarios, MCP policy, and hosting-agnostic operational contracts (`ops/`).
