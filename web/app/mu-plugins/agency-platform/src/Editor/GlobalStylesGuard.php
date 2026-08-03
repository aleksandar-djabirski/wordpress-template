<?php

declare(strict_types=1);

namespace AgencyPlatform\Editor;

/**
 * Refuses custom CSS in a Global Styles write from a client role.
 *
 * This is a SEPARATE class from SaveValidation because Global Styles has a
 * separate save path: WP_REST_Global_Styles_Controller overrides
 * prepare_item_for_database() and applies no rest_pre_insert_* filter, so the
 * post-type save boundary never sees the request. rest_pre_dispatch is the
 * earliest hook that does, and returning a WP_Error from it short-circuits the
 * request with that error.
 *
 * It covers three of the four custom-CSS surfaces BLOCK_THEME_PROPOSAL.md
 * §9.3 names — Global Styles root CSS, block-type CSS and element/variation
 * CSS. The fourth (an individual block instance's style.css attribute) lives
 * in post content and belongs to SaveValidation. The Customizer's custom_css
 * post type needs no guard: WordPress maps every one of its post-type
 * capabilities to edit_css (wp-includes/post.php:209-212), which
 * AgencyPlatform\Security\CapabilityPolicy denies outright.
 *
 * Rejecting rather than stripping is the point: core already strips, and a
 * client whose CSS silently vanishes files a bug against the agency.
 */
final class GlobalStylesGuard {

	public const ROUTE_PREFIX = '/wp/v2/global-styles/';

	/**
	 * @var string[]
	 */
	public const WRITE_METHODS = array( 'POST', 'PUT', 'PATCH' );

	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'guard_global_styles_write' ), 10, 3 );
	}

	/**
	 * @param mixed $result Response to short-circuit with, or null to continue.
	 * @return mixed
	 */
	public function guard_global_styles_write( $result, \WP_REST_Server $server, \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $server is required to match WordPress's `rest_pre_dispatch` filter signature.
		if ( null !== $result ) {
			return $result;
		}

		if ( ! self::is_global_styles_write( (string) $request->get_route(), (string) $request->get_method() ) ) {
			return $result;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return $result;
		}

		$styles     = $request->get_param( 'styles' );
		$violations = is_array( $styles ) ? BlockPolicy::global_styles_css_violations( $styles ) : array();

		if ( array() === $violations ) {
			return $result;
		}

		return new \WP_Error(
			'agency_platform_global_styles_custom_css',
			sprintf(
				/* translators: %s: dotted path to the offending custom CSS, e.g. styles.blocks.core/group.css */
				__( 'Custom CSS is not allowed for your role (found at %s). Use the design controls instead.', 'agency-platform' ),
				$violations[0]
			),
			array(
				'status'     => 403,
				'violations' => $violations,
			)
		);
	}

	/**
	 * Pure route/method test, so it is unit-testable without a REST server.
	 */
	public static function is_global_styles_write( string $route, string $method ): bool {
		if ( ! str_starts_with( $route, self::ROUTE_PREFIX ) ) {
			return false;
		}

		return in_array( strtoupper( $method ), self::WRITE_METHODS, true );
	}
}
