<?php

declare(strict_types=1);

namespace SiteTheme\Bootstrap;

use SiteTheme\Support\Parts;

/**
 * Site Theme's single wiring point — mirrors AgencyPlatform\Plugin's /
 * SiteCore\Plugin's "boot() wires named hook callbacks" shape, but for a
 * classic/hybrid theme rather than a plugin: functions.php stays a thin
 * ≤50-line shell (an ABSPATH guard plus one boot() call), every actual
 * setup step lives here as its own named method (never a closure — see
 * the naming contract's "no closures in add_action/add_filter" rule).
 *
 * Pattern registration is deliberately NOT wired here: WordPress
 * auto-registers every *.php file under the theme's patterns/ directory
 * from its own header comment alone (see
 * patterns/reference-landing-section.php and
 * wp-includes/theme.php's _register_theme_block_patterns()) — a manual
 * register_block_pattern() call here would just duplicate that for no
 * benefit.
 */
final class ThemeBootstrap {

	public static function boot(): void {
		add_action( 'after_setup_theme', array( self::class, 'setup' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * Declares theme support and registers nav menus. Both belong on
	 * after_setup_theme — WordPress's documented hook for theme support
	 * flags/menu registration; running either any earlier risks some
	 * support flags not being reliably respected by core.
	 */
	public static function setup(): void {
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support(
			'html5',
			array(
				'search-form',
				'comment-form',
				'comment-list',
				'gallery',
				'caption',
				'style',
				'script',
			)
		);
		add_theme_support( 'editor-styles' );
		add_theme_support( 'responsive-embeds' );

		register_nav_menus(
			array(
				'primary' => __( 'Primary', 'site-theme' ),
				'footer'  => __( 'Footer', 'site-theme' ),
			)
		);
	}

	/**
	 * Enqueues the theme's global base/typography CSS plus each registered
	 * part's co-located CSS/JS (see Parts::MANIFEST / Parts::assets()).
	 * Every handle is versioned by filemtime() rather than a static theme
	 * version string, so a deploy always busts caches without a manual
	 * version bump anywhere.
	 */
	public static function enqueue_assets(): void {
		$theme_dir = get_template_directory();
		$theme_uri = get_template_directory_uri();

		self::enqueue_global_style( $theme_dir, $theme_uri, 'site-theme-base', 'assets/global/base.css', array() );
		self::enqueue_global_style( $theme_dir, $theme_uri, 'site-theme-typography', 'assets/global/typography.css', array( 'site-theme-base' ) );

		foreach ( Parts::MANIFEST as $part ) {
			foreach ( Parts::assets( $part ) as $type => $relative_path ) {
				$file = "{$theme_dir}/{$relative_path}";
				$uri  = "{$theme_uri}/{$relative_path}";
				$ver  = (string) filemtime( $file );

				if ( 'css' === $type ) {
					wp_enqueue_style( "site-theme-{$part}", $uri, array( 'site-theme-base' ), $ver );
				} else {
					wp_enqueue_script( "site-theme-{$part}", $uri, array(), $ver, true );
				}
			}
		}
	}

	/**
	 * @param string[] $deps Style handles this stylesheet depends on.
	 */
	private static function enqueue_global_style( string $theme_dir, string $theme_uri, string $handle, string $relative_path, array $deps ): void {
		wp_enqueue_style(
			$handle,
			"{$theme_uri}/{$relative_path}",
			$deps,
			(string) filemtime( "{$theme_dir}/{$relative_path}" )
		);
	}

	/**
	 * Registers every dynamic block under the theme's blocks/ directory from
	 * its block.json — zero-config: dropping a new blocks/<slug>/block.json in
	 * place registers it, no edit here required. Mirrors the auto-discovery of
	 * the `npm run build` step (scripts/build-blocks.mjs) so adding a block is
	 * a folder-only operation on both the PHP and build sides.
	 *
	 * The editorScript each block declares (blocks/<slug>/build/index.js) is
	 * produced by `npm run build` (@wordpress/scripts) and committed to git;
	 * register_block_type() doesn't require that file to exist at registration
	 * time, only when WordPress actually enqueues it for the block editor. See
	 * blocks/reference-callout/README.md.
	 */
	public static function register_block(): void {
		$manifests = glob( get_template_directory() . '/blocks/*/block.json' );

		foreach ( ( false === $manifests ? array() : $manifests ) as $manifest ) {
			register_block_type( dirname( $manifest ) );
		}
	}
}
