<?php

declare(strict_types=1);

namespace SiteTheme\Support;

/**
 * Resolves and renders the theme's "parts" — non-editable chrome
 * (site-header, site-footer) that lives outside the block editor's reach,
 * as opposed to blocks/ (editable UI) and templates/ (per-request layout).
 *
 * Every method here is pure path/manifest logic with no WordPress calls of
 * its own, so this class is fully unit-testable without bootstrapping
 * WordPress (see tests/Unit/SiteTheme/PartsTest.php) — only the *.php part
 * template that render() requires (e.g. parts/site-header/site-header.php)
 * touches WordPress template tags.
 */
final class Parts {

	/**
	 * Single source of truth for which parts exist. Both render() (fail
	 * soft on anything not listed here) and
	 * \SiteTheme\Bootstrap\ThemeBootstrap::enqueue_assets() (which
	 * co-located css/js to look for) key off this list.
	 *
	 * @var string[]
	 */
	public const MANIFEST = array( 'site-header', 'site-footer' );

	private function __construct() {
		// Static-only API; never instantiated.
	}

	/**
	 * Includes a part's PHP template. Unknown part names fail soft (log +
	 * return) rather than fataling the whole page — a typo in a
	 * Parts::render() call must never be able to take down header.php /
	 * footer.php on a live site.
	 */
	public static function render( string $part ): void {
		if ( ! in_array( $part, self::MANIFEST, true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional one-line fail-soft diagnostic, not leftover debug code; see method docblock.
			error_log( sprintf( 'SiteTheme\Support\Parts::render(): unknown part "%s".', $part ) );

			return;
		}

		require self::directory( $part ) . "/{$part}.php";
	}

	/**
	 * Returns the css/js asset paths (relative to the theme root, suitable
	 * for appending to get_template_directory()/get_template_directory_uri())
	 * that actually exist on disk for a part, keyed by extension. A part
	 * with no JS (e.g. site-footer) simply omits the 'js' key rather than
	 * pointing enqueue_assets() at a file that doesn't exist.
	 *
	 * @return array<string, string>
	 */
	public static function assets( string $part ): array {
		if ( ! in_array( $part, self::MANIFEST, true ) ) {
			return array();
		}

		$assets    = array();
		$directory = self::directory( $part );

		foreach ( array( 'css', 'js' ) as $extension ) {
			if ( is_file( "{$directory}/{$part}.{$extension}" ) ) {
				$assets[ $extension ] = "parts/{$part}/{$part}.{$extension}";
			}
		}

		return $assets;
	}

	/**
	 * Absolute filesystem path to a part's directory. Deliberately derived
	 * from __DIR__ (this file's own location, two levels under the theme
	 * root at src/Support/) rather than get_template_directory(), so path
	 * resolution stays testable without WordPress loaded at all.
	 */
	private static function directory( string $part ): string {
		return dirname( __DIR__, 2 ) . "/parts/{$part}";
	}
}
