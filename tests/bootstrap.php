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
 * file has to pick a path at runtime. It gates on the WP_INTEGRATION
 * environment variable rather than sniffing `--testsuite` out of
 * $_SERVER['argv']: the `test:integration` Composer script sets
 * WP_INTEGRATION=1 via `@putenv` before invoking phpunit (see
 * composer.json), so this stays correct regardless of how the `integration`
 * testsuite ends up selected (name, group filter, a re-run of one file,
 * ...) — an argv string match would silently stop matching the moment any
 * of those change.
 */

declare(strict_types=1);

$is_integration_suite = '1' === getenv( 'WP_INTEGRATION' );

// The `architecture`/`unit` suites run without a live WordPress install, so
// load minimal WordPress function stubs before Composer's autoloader is put
// to use — the `integration` suite gets a real WordPress test environment
// instead (via tests/Integration/bootstrap.php) and must never see these.
if ( ! $is_integration_suite ) {
	require_once __DIR__ . '/support/wp-stubs.php';
	require_once __DIR__ . '/support/wp-error-stub.php';
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( $is_integration_suite ) {
	require_once __DIR__ . '/Integration/bootstrap.php';
}
