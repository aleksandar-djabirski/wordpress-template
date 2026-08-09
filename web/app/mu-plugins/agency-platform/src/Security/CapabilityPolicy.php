<?php

declare(strict_types=1);

namespace AgencyPlatform\Security;

/**
 * Denies the two meta capabilities that granting `edit_theme_options` to a
 * client role would otherwise hand over as a side effect.
 *
 * `customize`: WordPress core maps it straight to `edit_theme_options`
 * (wp-includes/capabilities.php), so the Customizer would open the moment the
 * Site Editor did. Clients get the Site Editor, never the Customizer â€” there
 * is exactly one design surface.
 *
 * `edit_css`: core maps it to `unfiltered_html`, which client roles already
 * lack, so this denial is belt-and-braces rather than the sole barrier. It
 * matters because it is enforced at the MAPPING level: a plugin that grants
 * `unfiltered_html` to an editor role cannot accidentally re-open Additional
 * CSS, per-block custom CSS, or the `custom_css` post type (which maps all of
 * its own capabilities to `edit_css`).
 *
 * Privileged users (anyone who can `manage_options`) are never affected.
 */
final class CapabilityPolicy {

	/**
	 * @var string[]
	 */
	public const DENIED_META_CAPS = array( 'edit_css', 'customize' );

	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'deny_client_meta_caps' ), 10, 4 );
	}

	/**
	 * @param string[]          $required_capabilities
	 * @param array<int, mixed> $args
	 * @return string[]
	 */
	public function deny_client_meta_caps( array $required_capabilities, string $capability, int $user_id, array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $args is required to match WordPress's `map_meta_cap` filter signature.
		// EARLY RETURN BEFORE user_can(). This callback runs on EVERY
		// map_meta_cap call, and user_can() itself calls map_meta_cap again â€”
		// so calling it unconditionally would re-enter this method for every
		// capability check on the site, once per nesting level. Checking the
		// cheap, WordPress-free condition first means user_can() runs only for
		// the two capabilities in DENIED_META_CAPS, and the nested call for
		// 'manage_options' returns here immediately because 'manage_options'
		// is not in that list. Recursion therefore terminates at depth 1.
		if ( ! in_array( $capability, self::DENIED_META_CAPS, true ) ) {
			return $required_capabilities;
		}

		return self::map( $required_capabilities, $capability, user_can( $user_id, 'manage_options' ) );
	}

	/**
	 * Pure policy decision, so it is directly unit-testable without a
	 * WordPress runtime.
	 *
	 * @param string[] $required_capabilities
	 * @return string[]
	 */
	public static function map( array $required_capabilities, string $capability, bool $user_is_privileged ): array {
		if ( $user_is_privileged ) {
			return $required_capabilities;
		}

		if ( ! in_array( $capability, self::DENIED_META_CAPS, true ) ) {
			return $required_capabilities;
		}

		return array( 'do_not_allow' );
	}
}
