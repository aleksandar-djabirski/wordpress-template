<?php
/**
 * Bootstrap for the `commerce-integration` PHPUnit suite: boots a real
 * WordPress test environment via wp-phpunit/wp-phpunit exactly like
 * tests/Integration/bootstrap.php, but ALSO loads WooCommerce and activates
 * the site-commerce plugin so its providers boot — the commerce profile.
 *
 * This is the deliberate counterpart to tests/Integration/bootstrap.php, which
 * proves the BASE profile runs with no WooCommerce at all. Keeping the two
 * bootstraps (and suites) separate is what lets a single repo prove both: the
 * base gate (`test:integration`) never loads WooCommerce, and this gate
 * (`test:integration:commerce`) only runs once a project has installed it (via
 * scripts/enable-commerce). Required only by tests/bootstrap.php, and only when
 * WP_COMMERCE_INTEGRATION=1 (set by the `test:integration:commerce` Composer
 * script) — see that file.
 *
 * HPOS: WooCommerce's High-Performance Order Storage is enabled BEFORE
 * WC_Install::install() runs, because WC_Install::get_schema() only emits the
 * wc_orders / wc_order_addresses table schema when HPOS is enabled at install
 * time (see includes/class-wc-install.php). Enabling it here means orders in
 * this suite live in those tables — the exact storage backend
 * SiteCommerce\Health\CommerceSanitizeStep's HPOS branch scrubs, so this suite
 * is the first runtime exercise of that (previously untested) SQL.
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/Integration/env-helper.php';

/**
 * Confirms the configured test database is reachable, failing fast with one
 * actionable message rather than a raw connection error. A trimmed copy of
 * tests/Integration/bootstrap.php's check, prefixed `_commerce_` so the two
 * bootstraps never collide if both are somehow loaded in one process (they are
 * not: tests/bootstrap.php requires exactly one, keyed on the env guard).
 */
function agency_starter_commerce_require_database(): void {
	if ( ! extension_loaded( 'mysqli' ) ) {
		agency_starter_commerce_fail_fast( 'the mysqli PHP extension is not loaded' );
	}

	$host     = agency_starter_integration_env( 'WP_TESTS_DB_HOST', 'db' );
	$user     = agency_starter_integration_env( 'WP_TESTS_DB_USER', 'db' );
	$password = agency_starter_integration_env( 'WP_TESTS_DB_PASSWORD', 'db' );

	mysqli_report( MYSQLI_REPORT_OFF ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- $wpdb does not exist yet at this point in the boot sequence (mirrors tests/Integration/bootstrap.php).

	$connection = @mysqli_connect( $host, $user, $password ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_connect, WordPress.PHP.NoSilencedErrors.Discouraged -- $wpdb does not exist yet; the fail-fast below is the intended, actionable replacement for the raw warning.

	if ( false === $connection ) {
		$connect_error = mysqli_connect_error(); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_connect_error -- $wpdb does not exist yet at this point in the boot sequence.
		$reason        = ( null === $connect_error || '' === $connect_error ) ? 'unknown error' : $connect_error;

		agency_starter_commerce_fail_fast( sprintf( 'could not connect to %s@%s (%s)', $user, $host, $reason ) );
	}

	mysqli_close( $connection ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_close -- $wpdb does not exist yet at this point in the boot sequence.

	mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- restore PHP 8.1+'s default for the rest of the suite.
}

function agency_starter_commerce_fail_fast( string $reason ): void {
	$message = "\nCommerce integration tests require a reachable database (" . $reason . ").\n"
		. 'Run them inside DDEV (`ddev composer test:integration:commerce`) or CI, where a'
		. " MySQL/MariaDB service is available — never directly on a bare host.\n\n";

	fwrite( STDERR, $message ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test bootstrap, before WP_Filesystem exists; STDERR is not a file on disk.

	exit( 1 );
}

/**
 * Loads the base-profile production code (agency-platform, site-core,
 * site-integrations) PLUS WooCommerce and site-commerce. Order matters:
 * WooCommerce must be included before site-commerce so
 * SiteCommerce\Plugin::maybe_boot()'s class_exists('WooCommerce') guard sees it
 * and takes the booted branch.
 */
function agency_starter_commerce_load_plugins(): void {
	$web_app = dirname( __DIR__, 3 ) . '/web/app';

	require $web_app . '/mu-plugins/agency-platform/agency-platform.php';
	require $web_app . '/plugins/site-core/site-core.php';
	require $web_app . '/plugins/site-integrations/site-integrations.php';

	$woocommerce = $web_app . '/plugins/woocommerce/woocommerce.php';

	if ( ! is_file( $woocommerce ) ) {
		fwrite( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test bootstrap, before WP_Filesystem exists.
			STDERR,
			"\nWooCommerce is not installed (web/app/plugins/woocommerce/woocommerce.php missing).\n"
			. "Run `bash scripts/enable-commerce` before the commerce integration suite.\n\n"
		);
		exit( 1 );
	}

	require $woocommerce;
	require $web_app . '/plugins/site-commerce/site-commerce.php';
}

/**
 * Enables HPOS then installs WooCommerce's database tables into the wp-phpunit
 * test database. Runs on `setup_theme` (after WooCommerce's classes are loaded
 * but before any test), so every test sees a fully-installed store whose orders
 * are backed by the HPOS tables. WC_Install::install() runs once here, outside
 * the per-test transaction, so the schema persists for the whole suite while
 * each test's data still rolls back.
 */
function agency_starter_commerce_install_woocommerce(): void {
	// Enable HPOS (and its feature flag) BEFORE install so get_schema() emits
	// the wc_orders/wc_order_addresses tables and they become the authoritative
	// order store. Data-sync is left off: there is no legacy postmeta data to
	// mirror in a fresh test store.
	update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );
	update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );

	if ( class_exists( '\WC_Install' ) ) {
		\WC_Install::install();
	}

	// Reload roles so WooCommerce's own roles (customer, shop_manager) and the
	// caps its install grants are visible to tests — the standard wp-phpunit
	// WooCommerce pattern (see WP core ticket #28374).
	$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- documented wp-phpunit pattern for reloading roles after a plugin install.
	wp_roles();
}

/**
 * Registers web/app/themes as a theme root so site-theme can be activated —
 * identical to the base integration bootstrap.
 */
function agency_starter_commerce_register_theme_directory(): void {
	register_theme_directory( dirname( __DIR__, 3 ) . '/web/app/themes' );
}

/**
 * Forces site-theme active regardless of the freshly-installed default —
 * identical to the base integration bootstrap.
 *
 * @param string $incoming Unused: the value WordPress would otherwise use.
 */
function agency_starter_commerce_use_site_theme( string $incoming ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required to match WordPress's `template`/`stylesheet` filter signature.
	return 'site-theme';
}

agency_starter_commerce_require_database();

$wp_phpunit_dir = agency_starter_integration_env( 'WP_PHPUNIT__DIR', dirname( __DIR__, 3 ) . '/vendor/wp-phpunit/wp-phpunit' );

require_once $wp_phpunit_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', 'agency_starter_commerce_load_plugins' );
// Priority 11 so this runs AFTER site-theme is registered/activated by the two
// filters below have taken effect and WooCommerce's classes are loaded.
tests_add_filter( 'setup_theme', 'agency_starter_commerce_register_theme_directory' );
tests_add_filter( 'setup_theme', 'agency_starter_commerce_install_woocommerce', 11 );
tests_add_filter( 'template', 'agency_starter_commerce_use_site_theme' );
tests_add_filter( 'stylesheet', 'agency_starter_commerce_use_site_theme' );

require $wp_phpunit_dir . '/includes/bootstrap.php';
