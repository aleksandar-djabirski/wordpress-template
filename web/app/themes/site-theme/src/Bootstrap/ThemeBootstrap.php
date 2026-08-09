<?php

declare(strict_types=1);

namespace SiteTheme\Bootstrap;

/**
 * Site Theme's single wiring point. functions.php stays a thin ≤50-line shell
 * (an ABSPATH guard plus one boot() call); every setup step lives here as its
 * own named method, never a closure — see the "no closures in
 * add_action/add_filter" rule enforced by HookOwnershipTest.
 *
 * This is a NATIVE BLOCK THEME: templates/*.html and parts/*.html are the only
 * rendering path. WordPress recognises that from the file layout alone
 * (templates/index.html), so there is deliberately no
 * add_theme_support( 'block-templates' ) call here.
 *
 * Most classic theme supports are redundant: core's _add_default_theme_supports()
 * (wp-includes/theme.php, hooked to after_setup_theme at priority 1) already
 * adds post-thumbnails, responsive-embeds, editor-styles, html5 and
 * automatic-feed-links for every block theme. `editor-styles` is re-declared
 * below only because add_editor_style() depends on it; nav-menu locations are
 * gone because the native Navigation block replaces wp_nav_menu().
 *
 * Pattern registration is deliberately NOT wired here: WordPress auto-registers
 * every *.php file under patterns/ from its own header comment (see
 * wp-includes/theme.php's _register_theme_block_patterns()).
 */
final class ThemeBootstrap {

	/**
	 * Frontend stylesheets, in load order: enqueue handle => theme-relative
	 * path. Each sheet depends on the one before it.
	 *
	 * @var array<string, string>
	 */
	public const FRONTEND_STYLESHEETS = array(
		'site-theme-frontend-reset' => 'assets/global/frontend-reset.css',
		'site-theme-shared'         => 'assets/global/shared.css',
	);

	/**
	 * Stylesheets loaded into the block editor canvas. frontend-reset.css is
	 * deliberately absent: its document-level reset would fight the canvas's
	 * own layout. GlobalAssetRulesTest asserts that exclusion.
	 *
	 * @var string[]
	 */
	public const EDITOR_STYLESHEETS = array(
		'assets/global/shared.css',
		'assets/global/editor.css',
	);

	public static function boot(): void {
		add_action( 'after_setup_theme', array( self::class, 'setup' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'init', array( self::class, 'register_pattern_categories' ), 9 );
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * add_editor_style() requires the `editor-styles` support flag, and both
	 * belong on after_setup_theme — WordPress's documented hook for theme
	 * support flags.
	 */
	public static function setup(): void {
		add_theme_support( 'editor-styles' );
		add_editor_style( self::EDITOR_STYLESHEETS );
	}

	public static function register_pattern_categories(): void {
		register_block_pattern_category(
			'pages',
			array(
				'label' => __( 'Pages', 'site-theme' ),
			)
		);
	}

	/**
	 * Enqueues the theme's global frontend CSS. Every handle is versioned by
	 * filemtime() rather than a static theme version string, so a deploy always
	 * busts caches without a manual version bump.
	 */
	public static function enqueue_assets(): void {
		$theme_dir    = get_template_directory();
		$theme_uri    = get_template_directory_uri();
		$dependencies = array();

		foreach ( self::FRONTEND_STYLESHEETS as $handle => $relative_path ) {
			wp_enqueue_style(
				$handle,
				"{$theme_uri}/{$relative_path}",
				$dependencies,
				(string) filemtime( "{$theme_dir}/{$relative_path}" )
			);

			$dependencies = array( $handle );
		}
	}

	/**
	 * Registers every dynamic block under blocks/ from its block.json —
	 * zero-config: dropping a new blocks/<slug>/block.json in place registers
	 * it, no edit here required. Mirrors scripts/build-blocks.mjs's
	 * auto-discovery so adding a new blocks/<slug>/block.json is a folder-only
	 * operation on both the PHP and build sides.
	 */
	public static function register_block(): void {
		$manifests = glob( get_template_directory() . '/blocks/*/block.json' );

		foreach ( ( false === $manifests ? array() : $manifests ) as $manifest ) {
			register_block_type( dirname( $manifest ) );
		}
	}
}
