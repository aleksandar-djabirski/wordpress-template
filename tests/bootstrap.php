<?php
/**
 * PHPUnit bootstrap for the agency-starter test suites.
 *
 * The `architecture` and `unit` suites run without a live WordPress
 * install and only need Composer's autoloader. The `integration` suite
 * needs a real WordPress test environment (wp-phpunit/wp-phpunit) — booted
 * by tests/Integration/bootstrap.php instead.
 *
 * PHPUnit 9 has no per-testsuite bootstrap option, so this single shared
 * file has to pick a path at runtime. It gates on environment variables
 * rather than sniffing `--testsuite` out of $_SERVER['argv']: the
 * `test:integration` / `test:integration:commerce` Composer scripts set
 * WP_INTEGRATION=1 / WP_COMMERCE_INTEGRATION=1 via `@putenv` before invoking
 * phpunit (see composer.json), so this stays correct regardless of how the
 * suite ends up selected (name, group filter, a re-run of one file, ...) —
 * an argv string match would silently stop matching the moment any of those
 * change.
 *
 * Two real-WordPress paths exist: the base `integration` suite
 * (tests/Integration/bootstrap.php — no WooCommerce, proves the base profile)
 * and the `commerce-integration` suite (tests/commerce/Integration/bootstrap.php
 * — loads WooCommerce + site-commerce). They are mutually exclusive and never
 * run in the same process.
 */

declare(strict_types=1);

$is_commerce_integration = '1' === getenv( 'WP_COMMERCE_INTEGRATION' );
$is_integration_suite    = '1' === getenv( 'WP_INTEGRATION' ) || $is_commerce_integration;

// The `architecture`/`unit` suites run without a live WordPress install, so
// load minimal WordPress function stubs before Composer's autoloader is put
// to use — the real-WordPress suites (`integration`, `commerce-integration`)
// get a real WordPress test environment instead and must never see these.
if ( ! $is_integration_suite ) {
	require_once __DIR__ . '/support/wp-stubs.php';
	require_once __DIR__ . '/support/wp-error-stub.php';
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( $is_commerce_integration ) {
	require_once __DIR__ . '/commerce/Integration/bootstrap.php';
} elseif ( $is_integration_suite ) {
	require_once __DIR__ . '/Integration/bootstrap.php';
}
