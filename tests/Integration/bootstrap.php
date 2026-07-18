<?php
/**
 * Bootstrap for the `integration` PHPUnit suite: boots a real WordPress
 * test environment via wp-phpunit/wp-phpunit, loads the agency-platform
 * mu-plugin plus the site-core and site-integrations plugins (the base
 * profile), and activates site-theme.
 *
 * Required only by tests/bootstrap.php, and only when WP_INTEGRATION=1 (set
 * by the `test:integration` Composer script) — see that file. Requires a
 * reachable MySQL/MariaDB test database (tests/wp-tests-config.php); run via
 * `ddev composer test:integration` (DDEV) or CI, never directly on a host
 * with no database. If the database is unreachable, this fails fast with an
 * actionable message instead of letting wp-phpunit's installer print a raw
 * connection-refused stack trace partway through booting WordPress.
 *
 * site-commerce is deliberately NOT loaded here: this suite exercises only
 * the base profile (client_editor role, testimonials, fake lead delivery) —
 * WooCommerce/site-commerce gets its own dedicated integration bootstrap and
 * test profile, kept separate so the base profile can be proven to run
 * (and be tested) without WooCommerce installed at all.
 */

declare(strict_types=1);

/**
 * Reads an environment variable, falling back to $fallback when it is unset
 * or empty. A named helper (rather than `getenv( $name ) ?: $fallback`
 * inline everywhere) so this file never needs the short ternary operator,
 * which the project's phpcs ruleset disallows.
 */
function agency_starter_integration_env( string $name, string $fallback ): string {
	$value = getenv( $name );

	if ( false === $value || '' === $value ) {
		return $fallback;
	}

	return $value;
}

/**
 * Confirms the configured test database is reachable, exiting with one
 * clear, actionable message instead of a raw connection failure if it is
 * not. Reads the same WP_TESTS_DB_* environment variables (and DDEV-shaped
 * db/db/db defaults) as tests/wp-tests-config.php, so a "yes" here means
 * the real wp-phpunit install that follows can actually connect too.
 */
function agency_starter_integration_require_database(): void {
	if ( ! extension_loaded( 'mysqli' ) ) {
		agency_starter_integration_fail_fast( 'the mysqli PHP extension is not loaded' );
	}

	$host     = agency_starter_integration_env( 'WP_TESTS_DB_HOST', 'db' );
	$user     = agency_starter_integration_env( 'WP_TESTS_DB_USER', 'db' );
	$password = agency_starter_integration_env( 'WP_TESTS_DB_PASSWORD', 'db' );

	// mysqli reports failures as a thrown mysqli_sql_exception by default
	// since PHP 8.1 (mysqli.default_report =
	// MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT). MYSQLI_REPORT_OFF restores
	// the pre-8.1 behaviour (a plain `false` return, nothing thrown) for
	// just this probe; mysqli_connect() itself still emits a PHP E_WARNING
	// on failure regardless of the report mode, which @ silences here so a
	// refused connection produces exactly one clear message below instead
	// of an uncaught-exception stack trace plus a raw connection warning.
	//
	// $wpdb doesn't exist yet at this point in the boot sequence (this
	// function's entire job is to check the database is reachable BEFORE
	// wp-phpunit tries to boot WordPress) — the raw mysqli_* calls below are
	// this file's one deliberate, necessary exception to "use $wpdb", not an
	// oversight.
	mysqli_report( MYSQLI_REPORT_OFF ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- see comment above: $wpdb does not exist yet at this point in the boot sequence.

	$connection = @mysqli_connect( $host, $user, $password ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_connect, WordPress.PHP.NoSilencedErrors.Discouraged -- $wpdb does not exist yet (see comment above); mysqli_connect() warns on failure even under MYSQLI_REPORT_OFF, and this function's own fail-fast message (below) is the intended, actionable replacement for that warning.

	if ( false === $connection ) {
		$connect_error = mysqli_connect_error(); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_connect_error -- see comment above: $wpdb does not exist yet at this point in the boot sequence.
		$reason        = ( null === $connect_error || '' === $connect_error ) ? 'unknown error' : $connect_error;

		agency_starter_integration_fail_fast( sprintf( 'could not connect to %s@%s (%s)', $user, $host, $reason ) );
	}

	mysqli_close( $connection ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_close -- see comment above: $wpdb does not exist yet at this point in the boot sequence.

	// Restore PHP 8.1+'s default (exceptions on error) for the rest of the
	// suite — wp-phpunit and WordPress core expect the normal reporting mode.
	mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- see comment above: $wpdb does not exist yet at this point in the boot sequence.
}

function agency_starter_integration_fail_fast( string $reason ): void {
	$message = "\nIntegration tests require a reachable database (" . $reason . ").\n"
		. 'Run them inside DDEV (`ddev composer test:integration`) or CI, where a MySQL/MariaDB'
		. " service is available — never directly on a bare host.\n\n";

	fwrite( STDERR, $message ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing a fail-fast message to STDERR in a CLI test bootstrap, before WordPress (and WP_Filesystem) has loaded; there is no WP_Filesystem alternative for a stream that isn't a file on disk.

	exit( 1 );
}

/**
 * Loads the base-profile production code: the agency-platform mu-plugin,
 * then site-core, then site-integrations (which supplies site-core's
 * `site_core_lead_delivery` filter — see that plugin's own docblock).
 * site-commerce is intentionally excluded; see this file's docblock.
 */
function agency_starter_integration_load_plugins(): void {
	$web_app = dirname( __DIR__, 2 ) . '/web/app';

	require $web_app . '/mu-plugins/agency-platform/agency-platform.php';
	require $web_app . '/plugins/site-core/site-core.php';
	require $web_app . '/plugins/site-integrations/site-integrations.php';
}

/**
 * Registers web/app/themes as a theme root so site-theme (which lives
 * outside WordPress core's default wp-content/themes, per this project's
 * Bedrock layout) can be found and activated below.
 */
function agency_starter_integration_register_theme_directory(): void {
	register_theme_directory( dirname( __DIR__, 2 ) . '/web/app/themes' );
}

/**
 * Forces site-theme active regardless of the (freshly installed, default)
 * `template`/`stylesheet` options — the standard wp-phpunit pattern for
 * activating a specific theme in a test environment without writing to the
 * options table. Registered against both filters since a classic/hybrid
 * theme like site-theme is its own template and its own stylesheet (no
 * child theme).
 *
 * @param string $incoming Unused: the value WordPress would otherwise use.
 */
function agency_starter_integration_use_site_theme( string $incoming ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $incoming is required to match WordPress's `template`/`stylesheet` filter signature.
	return 'site-theme';
}

agency_starter_integration_require_database();

$wp_phpunit_dir = agency_starter_integration_env( 'WP_PHPUNIT__DIR', dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit' );

require_once $wp_phpunit_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', 'agency_starter_integration_load_plugins' );
tests_add_filter( 'setup_theme', 'agency_starter_integration_register_theme_directory' );
tests_add_filter( 'template', 'agency_starter_integration_use_site_theme' );
tests_add_filter( 'stylesheet', 'agency_starter_integration_use_site_theme' );

require $wp_phpunit_dir . '/includes/bootstrap.php';
