<?php
/**
 * Shared getenv()-with-a-fallback helper for the `integration` suite.
 *
 * Lives in its own file (rather than inline in tests/Integration/bootstrap.php)
 * because tests/wp-tests-config.php also needs it, and that config file is
 * loaded in TWO different processes:
 *
 *   1. The main PHPUnit process, via tests/Integration/bootstrap.php.
 *   2. A short-lived child process wp-phpunit spawns to install the test
 *      database: vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php runs
 *      `system( WP_PHP_BINARY . ' install.php ' . $config_file )`, and
 *      install.php require_once's the config file directly. That child process
 *      never loads tests/Integration/bootstrap.php, so a helper defined only
 *      there is undefined when wp-tests-config.php runs under install.php — a
 *      fatal "Call to undefined function agency_starter_integration_env()",
 *      which is the exact failure this file exists to prevent.
 *
 * require_once'ing it from both tests/Integration/bootstrap.php and
 * tests/wp-tests-config.php keeps it defined exactly once in either process.
 */

declare(strict_types=1);

/**
 * Reads an environment variable, falling back to $fallback when it is unset
 * or empty. A named helper (rather than `getenv( $name ) ?: $fallback`
 * inline everywhere) so callers never need the short ternary operator, which
 * the project's phpcs ruleset disallows.
 */
function agency_starter_integration_env( string $name, string $fallback ): string {
	$value = getenv( $name );

	if ( false === $value || '' === $value ) {
		return $fallback;
	}

	return $value;
}
