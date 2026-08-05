<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The site-UUID branch of `wp agency sanitize` (BLOCK_THEME_PROPOSAL.md §7.7):
 * a production UUID that has landed outside production — the cloned-database
 * case — is regenerated, and a UUID whose provenance is unknown (stored
 * before the environment marker existed) is regenerated once. Everything else
 * is preserved, so a second sanitize run changes nothing and `wp agency
 * sanitize` keeps the idempotency contract every other step honours.
 *
 * Registers through the agency_platform_sanitize_steps filter, never by
 * editing AgencyCommands.
 */
final class SiteUuidSanitizeStep {

	/**
	 * Hooks the step into the sanitize registry. Named method, never a
	 * closure.
	 */
	public function register(): void {
		add_filter( 'agency_platform_sanitize_steps', array( $this, 'append_step' ) );
	}

	/**
	 * @param array<string, callable> $steps slug => step callable.
	 * @return array<string, callable>
	 */
	public function append_step( array $steps ): array {
		$steps['site_uuid'] = array( self::class, 'sanitize_site_uuid' );

		return $steps;
	}

	/**
	 * The step body: regenerates the UUID when the stored marker demands it,
	 * and returns exactly one WP-CLI summary line.
	 *
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $options is part of the uniform sanitize-step signature; the site-UUID step takes no per-run options.
	public static function sanitize_site_uuid( array $options ): array {
		$environment = wp_get_environment_type();
		$marker      = SiteIdentity::uuid_environment();

		if ( null === $marker ) {
			return array( 'Site UUID: none stored; nothing to regenerate.' );
		}

		if ( self::should_regenerate( $marker, $environment ) ) {
			SiteIdentity::regenerate();

			return array( sprintf( 'Site UUID: regenerated (a "%s" UUID was found in the "%s" environment).', $marker, $environment ) );
		}

		return array( sprintf( 'Site UUID: preserved (already minted in the "%s" environment).', $environment ) );
	}

	/**
	 * Pure: the idempotency decision behind the step. A null marker means no
	 * UUID is stored, so there is nothing to regenerate. A production marker
	 * outside production is the cloned-database case and regenerates; the
	 * empty marker is a pre-upgrade row of unknown provenance and regenerates
	 * once. Everything else — including a production UUID still in production
	 * and any non-production UUID anywhere — is preserved, so a second run
	 * changes nothing.
	 */
	public static function should_regenerate( ?string $marker, string $environment ): bool {
		if ( null === $marker ) {
			return false;
		}

		if ( 'production' === $marker && 'production' === $environment ) {
			return false;
		}

		if ( 'production' === $marker || '' === $marker ) {
			return true;
		}

		return false;
	}
}
