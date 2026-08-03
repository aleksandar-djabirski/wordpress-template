<?php

declare(strict_types=1);

namespace AgencyPlatform\Security;

/**
 * The explicit admin-screen boundary that makes granting `edit_theme_options`
 * safe.
 *
 * `edit_theme_options` is the capability the Site Editor needs, but core also
 * gates themes.php, widgets.php and nav-menus.php behind it, and maps
 * `customize` through it. Hiding menu items is cosmetic, so the real barrier
 * here is a wp_die() on `admin_init` for a fixed deny list; the menu surgery
 * below only stops clients being shown doors that would slam in their face.
 *
 * ALLOW (Â§9.2): site-editor.php and its REST endpoints (templates, template
 * parts, navigation, global styles), plus font-library.php, which is the Site
 * Editor's own font surface.
 *
 * DENY: themes.php, theme-install.php, theme-editor.php, plugin-install.php,
 * plugin-editor.php, customize.php, widgets.php, nav-menus.php, and the
 * unrelated settings screens. The settings screens are already gated on
 * `manage_options` by core; listing them keeps the boundary explicit rather
 * than implied.
 *
 * REST is deliberately untouched: the Site Editor is a REST client, and core's
 * own `edit_theme_options` gate on those routes is the correct check.
 */
final class AdminScreenPolicy {

	public const SITE_EDITOR_SCREEN = 'site-editor.php';

	/**
	 * @var string[]
	 */
	public const DENIED_SCREENS = array(
		'themes.php',
		'theme-install.php',
		'theme-editor.php',
		'plugin-install.php',
		'plugin-editor.php',
		'customize.php',
		'widgets.php',
		'nav-menus.php',
		'options-general.php',
		'options-writing.php',
		'options-reading.php',
		'options-discussion.php',
		'options-media.php',
		'options-permalink.php',
		'options.php',
	);

	public function register(): void {
		add_action( 'admin_init', array( $this, 'block_denied_screens' ) );
		add_action( 'admin_menu', array( $this, 'replace_appearance_menu' ), 999 );
	}

	/**
	 * Hard boundary. Runs on `admin_init`, which every wp-admin screen fires
	 * through wp-admin/admin.php before the screen's own capability check.
	 */
	public function block_denied_screens(): void {
		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';

		if ( ! self::is_denied( $pagenow, current_user_can( 'manage_options' ) ) ) {
			return;
		}

		wp_die(
			'<h1>' . esc_html__( 'You need a higher level of permission.', 'agency-platform' ) . '</h1>' .
			'<p>' . esc_html__( 'This screen is not part of the editing model for your role. Design changes belong in the Site Editor.', 'agency-platform' ) . '</p>',
			403
		);
	}

	/**
	 * Removes the Appearance menu (whose top-level target is themes.php, a
	 * denied screen) and replaces it with one Design entry that links straight
	 * to the Site Editor. Passing an existing admin file as the menu slug makes
	 * WordPress link to that file, so the empty callback is never invoked.
	 */
	public function replace_appearance_menu(): void {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		remove_menu_page( 'themes.php' );

		add_menu_page(
			__( 'Design', 'agency-platform' ),
			__( 'Design', 'agency-platform' ),
			'edit_theme_options',
			self::SITE_EDITOR_SCREEN,
			'',
			'dashicons-admin-appearance',
			60
		);
	}

	/**
	 * Pure policy decision, so it is directly unit-testable without a
	 * WordPress runtime.
	 */
	public static function is_denied( string $pagenow, bool $user_is_privileged ): bool {
		if ( $user_is_privileged ) {
			return false;
		}

		return in_array( $pagenow, self::DENIED_SCREENS, true );
	}
}
