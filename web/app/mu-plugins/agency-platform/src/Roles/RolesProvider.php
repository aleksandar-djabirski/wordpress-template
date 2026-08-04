<?php

declare(strict_types=1);

namespace AgencyPlatform\Roles;

/**
 * Registers the `client_editor` role: everything a WordPress core `editor`
 * can do, minus `unfiltered_html` and every "keys to the kingdom" capability
 * in NEVER_GRANT, plus every capability in ALWAYS_GRANT (the Site Editor's
 * `edit_theme_options`).
 *
 * mu-plugins have no activation hook, so registration happens idempotently
 * on `init`: `add_role()` only runs when the role doesn't exist yet, and an
 * existing role has its capabilities re-synced if they've drifted from the
 * desired set (e.g. after a WordPress core upgrade changes `editor`'s
 * capabilities, or a stale role was left over from an older version of
 * this plugin).
 */
final class RolesProvider {

	public const ROLE = 'client_editor';

	/**
	 * Capabilities this role must never be granted, even if a future
	 * WordPress core release starts including one of them on `editor`.
	 *
	 * @var string[]
	 */
	private const NEVER_GRANT = array(
		'manage_options',
		'switch_themes',
		'install_plugins',
		'activate_plugins',
		'edit_themes',
		'edit_plugins',
		'edit_files',
		'update_core',
		'unfiltered_html',
	);

	/**
	 * Capabilities this role must be granted EXPLICITLY, because core's
	 * `editor` role does not carry them. Removing a capability from
	 * NEVER_GRANT is not enough on its own.
	 *
	 * `edit_theme_options` is what opens the Site Editor (and the wp_template,
	 * wp_template_part, wp_global_styles and wp_navigation post types, which
	 * all map their capabilities to it). The screens it would otherwise expose
	 * are closed by AgencyPlatform\Security\AdminScreenPolicy, and the
	 * `customize` / `edit_css` meta capabilities it would otherwise imply are
	 * denied by AgencyPlatform\Security\CapabilityPolicy.
	 *
	 * @var string[]
	 */
	private const ALWAYS_GRANT = array(
		'edit_theme_options',
	);

	public function register(): void {
		add_action( 'init', array( self::class, 'register_role' ) );
	}

	public static function register_role(): void {
		$capabilities = self::client_editor_capabilities();
		$role         = get_role( self::ROLE );

		if ( null === $role ) {
			add_role( self::ROLE, __( 'Client Editor', 'agency-platform' ), $capabilities );
			return;
		}

		self::sync_role_capabilities( $role, $capabilities );
	}

	/**
	 * The desired `client_editor` capability set: a live read of core's
	 * `editor` role (so it tracks whatever WordPress ships) minus every
	 * capability in NEVER_GRANT.
	 *
	 * Exposed as a public method so ShopRole can build `client_shop_manager`
	 * on the same baseline without depending on `client_editor` already
	 * being registered (both roles are registered on `init`, and hook order
	 * between two providers shouldn't matter for correctness).
	 *
	 * @return array<string, bool>
	 */
	public static function client_editor_capabilities(): array {
		$editor       = get_role( 'editor' );
		$capabilities = null !== $editor ? $editor->capabilities : array();

		foreach ( self::NEVER_GRANT as $capability ) {
			unset( $capabilities[ $capability ] );
		}

		foreach ( self::ALWAYS_GRANT as $capability ) {
			$capabilities[ $capability ] = true;
		}

		return $capabilities;
	}

	/**
	 * Adds/removes individual capabilities so a persisted role converges on
	 * $desired_capabilities, without writing to the database when nothing
	 * has actually drifted (`WP_Role::add_cap()`/`remove_cap()` each
	 * persist a `wp_roles` option update, so this only calls them when the
	 * current and desired state actually differ).
	 *
	 * @param array<string, bool> $desired_capabilities
	 */
	public static function sync_role_capabilities( \WP_Role $role, array $desired_capabilities ): void {
		if ( $role->capabilities === $desired_capabilities ) {
			return;
		}

		foreach ( $role->capabilities as $capability => $granted ) {
			if ( ! array_key_exists( $capability, $desired_capabilities ) ) {
				$role->remove_cap( $capability );
			}
		}

		foreach ( $desired_capabilities as $capability => $granted ) {
			if ( ! $granted ) {
				continue;
			}

			if ( true !== ( $role->capabilities[ $capability ] ?? null ) ) {
				$role->add_cap( $capability );
			}
		}
	}
}
