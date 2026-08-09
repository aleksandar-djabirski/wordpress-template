<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The site's persistent identity: a UUID minted once per environment and a
 * marker recording which environment it was minted in
 * (BLOCK_THEME_PROPOSAL.md §7.7). Promotion pairs the target's UUID with the
 * AGENCY_TARGET_SITE_UUID setting, so a production database cloned to
 * staging or local development must regenerate its UUID before promotion can
 * be trusted — SiteUuidSanitizeStep turns that into an idempotent sanitize
 * step.
 *
 * Both options are stored non-autoloaded: they are read only by CLI
 * state/promotion commands, never on a front-end request.
 */
final class SiteIdentity {

	public const OPTION_UUID        = 'agency_platform_site_uuid';
	public const OPTION_ENVIRONMENT = 'agency_platform_site_uuid_environment';

	private function __construct() {
		// Static-only utility class; never instantiated.
	}

	/**
	 * The stored UUID when it is present and well-formed, otherwise a freshly
	 * minted one. Reading never mutates a valid stored UUID.
	 */
	public static function uuid(): string {
		$stored = get_option( self::OPTION_UUID, '' );

		if ( is_string( $stored ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $stored ) ) {
			return $stored;
		}

		return self::regenerate();
	}

	/**
	 * Mints a fresh UUID and stamps it with the current environment. Returns
	 * the new UUID.
	 */
	public static function regenerate(): string {
		$uuid = wp_generate_uuid4();

		// Non-autoloaded: read only by CLI state/promotion commands, never on
		// a front-end request.
		update_option( self::OPTION_UUID, $uuid, false );
		update_option( self::OPTION_ENVIRONMENT, wp_get_environment_type(), false );

		return $uuid;
	}

	/**
	 * The environment marker of the stored UUID, or null when no UUID is
	 * stored at all — so should_regenerate() can tell "no UUID" from
	 * "UUID with no marker" (a pre-upgrade row).
	 */
	public static function uuid_environment(): ?string {
		$stored = get_option( self::OPTION_UUID, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		return (string) get_option( self::OPTION_ENVIRONMENT, '' );
	}
}
