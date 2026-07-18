<?php
/**
 * wp-phpunit test configuration for the `integration` PHPUnit suite.
 *
 * Referenced via the WP_PHPUNIT__TESTS_CONFIG environment variable (set in
 * phpunit.xml); vendor/wp-phpunit/wp-phpunit's own wp-tests-config.php
 * requires this file directly (see that file — it is a fixed redirector,
 * "DO NOT EDIT", that composer owns).
 *
 * This file plays the role Bedrock's web/wp-config.php normally plays for a
 * live site. It is NOT loaded through Bedrock's own
 * config/application.php + config/environments/*.php split: wp-phpunit
 * loads WordPress core directly via `require ABSPATH . 'wp-settings.php'`
 * (see tests/Integration/bootstrap.php), bypassing Bedrock's wp-config.php
 * entirely. Every constant a live site would otherwise get from that chain
 * that the integration suite depends on must therefore be defined here
 * explicitly instead — this file is the sample wp-tests-config-sample.php
 * shape (DB_*, table_prefix, WP_TESTS_*, ABSPATH) plus the few
 * agency-starter-specific constants the guardrail classes under test read
 * directly (WP_ENVIRONMENT_TYPE).
 *
 * DB_* defaults match DDEV's `db` MariaDB service (see .ddev/config.yaml);
 * they only apply on hosts that never set the WP_TESTS_DB_* environment
 * variables — a bare CI runner should set them explicitly rather than rely
 * on the DDEV-shaped fallback.
 *
 * agency_starter_integration_env() (the getenv()-with-a-fallback helper
 * used below) is defined by tests/Integration/bootstrap.php, the only file
 * that ever loads this one (directly or, as here, via wp-phpunit's own
 * wp-tests-config.php) — see that file's docblock.
 */

declare(strict_types=1);

define( 'DB_NAME', agency_starter_integration_env( 'WP_TESTS_DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', agency_starter_integration_env( 'WP_TESTS_DB_USER', 'db' ) );
define( 'DB_PASSWORD', agency_starter_integration_env( 'WP_TESTS_DB_PASSWORD', 'db' ) );
define( 'DB_HOST', agency_starter_integration_env( 'WP_TESTS_DB_HOST', 'db' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// wp-phpunit's own install/bootstrap code (vendor/wp-phpunit/wp-phpunit)
// expects a real, non-namespaced $table_prefix global — that is the whole
// point of this file, mirroring WP core's and Bedrock's own wp-config.php.
$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- required by wp-phpunit's bootstrap; see comment above.

// example.org / example.com is WordPress core's own test-suite convention
// (see WP core's wp-tests-config-sample.php) — never a real domain, so a
// misconfigured test run can never resolve to production infrastructure.
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Agency Starter Integration Tests' );

define( 'WP_PHP_BINARY', agency_starter_integration_env( 'WP_PHP_BINARY', 'php' ) );

define( 'WPLANG', '' );

// Bedrock's own web/wp-config.php is never loaded for the integration suite
// (see this file's docblock) — ABSPATH must point straight at WordPress
// core instead of going through Bedrock's ABSPATH wiring.
define( 'ABSPATH', dirname( __DIR__ ) . '/web/wp/' );

define( 'WP_DEBUG', true );

// Env-detection safety net: wp_get_environment_type() falls back to this
// constant when no WP_ENVIRONMENT_TYPE server/env var is present — mirrors
// Bedrock's own WP_ENV -> WP_ENVIRONMENT_TYPE wiring in
// config/application.php, which this file otherwise bypasses (see above).
// tests/Integration/Environment/EnvironmentSafetyTest.php asserts this
// resolves to 'development' — never 'production' — so lead delivery,
// FileModGuard, and every other environment-gated guardrail behave exactly
// as they would on a real developer machine, never as they would in
// production.
define( 'WP_ENVIRONMENT_TYPE', 'development' );

// Deliberately NOT defined here: DISALLOW_FILE_MODS. Leaving it undefined
// (WordPress core's own default) is what EnvironmentSafetyTest's FileModGuard
// coverage depends on — see that test for the full reasoning.

// wp-phpunit's own bootstrap (includes/bootstrap.php) always swaps in
// MockPHPMailer before any test runs, regardless of anything defined here —
// wp_mail() can never send a real email from this suite. No extra
// mail-sending constant is needed on top of that.
