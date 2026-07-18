<?php
/**
 * PHPUnit bootstrap for the agency-starter test suites.
 *
 * The `architecture` and `unit` suites run without a live WordPress
 * install and only need Composer's autoloader. The `integration` suite
 * needs a real WordPress test environment (wp-phpunit/wp-phpunit); that
 * bootstrap is added by a later task. Until then, this file guards the
 * difference so `composer test:architecture` and `composer test:unit` stay
 * green without WordPress installed, and integration tests fail loudly
 * (missing bootstrap) rather than silently pretending to pass.
 */

declare(strict_types=1);

// PHPUnit puts the CLI arguments it was invoked with in $_SERVER['argv'];
// detect `--testsuite integration` (or `--testsuite=integration`) so we can
// require a WordPress-aware bootstrap once a later task adds one.
$is_integration_suite = false;

foreach ( $_SERVER['argv'] ?? array() as $index => $arg ) {
	if ( '--testsuite' === $arg && isset( $_SERVER['argv'][ $index + 1 ] ) ) {
		$is_integration_suite = str_contains( (string) $_SERVER['argv'][ $index + 1 ], 'integration' );
		break;
	}

	if ( str_starts_with( (string) $arg, '--testsuite=' ) ) {
		$is_integration_suite = str_contains( $arg, 'integration' );
		break;
	}
}

// The `architecture`/`unit` suites run without a live WordPress install, so
// load minimal WordPress function stubs before Composer's autoloader is put
// to use — the `integration` suite gets a real WordPress test environment
// instead (via tests/Integration/bootstrap.php) and must never see these.
if ( ! $is_integration_suite ) {
	require_once __DIR__ . '/support/wp-stubs.php';
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( $is_integration_suite ) {
	$integration_bootstrap = __DIR__ . '/Integration/bootstrap.php';

	if ( is_file( $integration_bootstrap ) ) {
		require_once $integration_bootstrap;
	}

	// Else: no WordPress test bootstrap exists yet. Integration tests are
	// added in a later task alongside tests/Integration/bootstrap.php.
}
