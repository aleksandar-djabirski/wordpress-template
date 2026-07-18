<?php

declare(strict_types=1);

namespace AgencyPlatform\Editor;

/**
 * Blocks the Site Editor, Widgets, and Menus admin screens for anyone who
 * can't `manage_options`, by mapping the `edit_theme_options` meta
 * capability to `do_not_allow` for them.
 *
 * Note: this intentionally also removes the block-based Navigation editor
 * for customer roles, since WordPress core gates it behind this same
 * `edit_theme_options` capability. If a specific project needs
 * customer-editable navigation, that needs a dedicated, project-specific
 * capability/UI solution — don't loosen this shared guardrail globally to
 * get there.
 */
final class SiteEditorLockdown {

	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'restrict_site_editor' ), 10, 4 );
	}

	/**
	 * @param string[]           $required_capabilities
	 * @param array<int, mixed>  $args
	 * @return string[]
	 */
	public function restrict_site_editor( array $required_capabilities, string $capability, int $user_id, array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $args is required to match WordPress's `map_meta_cap` filter signature.
		if ( 'edit_theme_options' !== $capability ) {
			return $required_capabilities;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return $required_capabilities;
		}

		return array( 'do_not_allow' );
	}
}
