<?php

declare(strict_types=1);

namespace AgencyPlatform\Editor;

/**
 * Locks the block editor down to a curated allow-list for anyone who can't
 * `manage_options`, and disables block-locking/code-editing UI for them too.
 *
 * Users who can `manage_options` (site admins) are unaffected — they keep
 * whatever WordPress/other plugins would otherwise allow.
 */
final class EditorRestrictions {

	/**
	 * Approved blocks for non-privileged (customer) users. Deliberately
	 * excludes `core/shortcode` (arbitrary PHP/shortcode execution) and any
	 * `woocommerce/*` block (that's ShopRole/site-commerce's territory, not
	 * a base editorial concern).
	 *
	 * @var string[]
	 */
	public const ALLOWED_BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/image',
		'core/gallery',
		'core/buttons',
		'core/button',
		'core/columns',
		'core/column',
		'core/group',
		'core/quote',
		'core/separator',
		'core/spacer',
		'core/table',
		'agency/reference-callout',
	);

	public function register(): void {
		add_filter( 'allowed_block_types_all', array( $this, 'filter_allowed_block_types' ), 10, 2 );
		add_filter( 'block_editor_settings_all', array( $this, 'filter_block_editor_settings' ), 10, 2 );
	}

	/**
	 * @param bool|string[] $allowed_block_types
	 * @return bool|string[]
	 */
	public function filter_allowed_block_types( $allowed_block_types, \WP_Block_Editor_Context $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $context is required to match WordPress's `allowed_block_types_all` filter signature.
		return self::allowed_blocks_for( current_user_can( 'manage_options' ), $allowed_block_types );
	}

	/**
	 * Pure policy decision, independent of WordPress, so it's directly
	 * unit-testable without bootstrapping the block editor: privileged
	 * users get back whatever was passed in (no restriction added on top
	 * of what other code already decided); everyone else gets the
	 * ALLOWED_BLOCKS allow-list, full stop.
	 *
	 * @param bool          $is_privileged Whether the current user can `manage_options`.
	 * @param bool|string[] $incoming      The value WordPress/other filters passed in.
	 * @return bool|string[]
	 */
	public static function allowed_blocks_for( bool $is_privileged, $incoming ) {
		if ( $is_privileged ) {
			return $incoming;
		}

		return self::ALLOWED_BLOCKS;
	}

	/**
	 * @param array<string, mixed> $editor_settings
	 * @return array<string, mixed>
	 */
	public function filter_block_editor_settings( array $editor_settings, \WP_Block_Editor_Context $context ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $context is required to match WordPress's `block_editor_settings_all` filter signature.
		if ( current_user_can( 'manage_options' ) ) {
			return $editor_settings;
		}

		$editor_settings['canLockBlocks']      = false;
		$editor_settings['codeEditingEnabled'] = false;

		return $editor_settings;
	}
}
