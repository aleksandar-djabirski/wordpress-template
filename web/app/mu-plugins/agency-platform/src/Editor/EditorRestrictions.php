<?php

declare(strict_types=1);

namespace AgencyPlatform\Editor;

/**
 * Applies AgencyPlatform\Editor\BlockPolicy to the block editor and the Site
 * Editor for anyone who can't `manage_options`.
 *
 * The policy is derived from the registered block types rather than a fixed
 * list, so the Site Editor keeps every core template/query/navigation block it
 * needs, and a newly registered core block does not silently disappear from
 * the inserter. Projects extend it through three filters instead of editing
 * this file:
 *
 *   agency_platform_allowed_block_namespaces — array<string> of namespace
 *       prefixes (no slash). Default: BlockPolicy::DEFAULT_NAMESPACES.
 *   agency_platform_allowed_blocks — array<string> of extra block names to
 *       allow regardless of namespace.
 *   agency_platform_disallowed_blocks — array<string> of block names to deny.
 *
 * Each filter receives the current value plus the WP_Block_Editor_Context.
 * BlockPolicy::ALWAYS_DENIED wins over all three.
 */
final class EditorRestrictions {

	public function register(): void {
		add_filter( 'allowed_block_types_all', array( $this, 'filter_allowed_block_types' ), 10, 2 );
		add_filter( 'block_editor_settings_all', array( $this, 'filter_block_editor_settings' ), 10, 2 );
	}

	/**
	 * @param bool|string[] $allowed_block_types
	 * @return bool|string[]
	 */
	public function filter_allowed_block_types( $allowed_block_types, \WP_Block_Editor_Context $context ) {
		return BlockPolicy::resolve(
			current_user_can( 'manage_options' ),
			$allowed_block_types,
			self::registered_block_names(),
			self::allowed_namespaces( $context ),
			self::extra_allowed_blocks( $context ),
			self::extra_denied_blocks( $context )
		);
	}

	/**
	 * @return string[]
	 */
	public static function registered_block_names(): array {
		return array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() );
	}

	/**
	 * @return string[]
	 */
	public static function allowed_namespaces( \WP_Block_Editor_Context $context ): array {
		/**
		 * Filters the block namespaces a client role may use.
		 *
		 * @param string[]                 $namespaces Namespace prefixes without the slash.
		 * @param \WP_Block_Editor_Context $context    The current editor context.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'agency_platform_allowed_block_namespaces', BlockPolicy::DEFAULT_NAMESPACES, $context ) ) ) );
	}

	/**
	 * @return string[]
	 */
	public static function extra_allowed_blocks( \WP_Block_Editor_Context $context ): array {
		/**
		 * Filters block names allowed for client roles regardless of namespace.
		 *
		 * @param string[]                 $blocks  Block names.
		 * @param \WP_Block_Editor_Context $context The current editor context.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'agency_platform_allowed_blocks', array(), $context ) ) ) );
	}

	/**
	 * @return string[]
	 */
	public static function extra_denied_blocks( \WP_Block_Editor_Context $context ): array {
		/**
		 * Filters block names denied to client roles.
		 *
		 * @param string[]                 $blocks  Block names.
		 * @param \WP_Block_Editor_Context $context The current editor context.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'agency_platform_disallowed_blocks', array(), $context ) ) ) );
	}

	/**
	 * @param array<string, mixed> $editor_settings
	 * @return array<string, mixed>
	 */
	public function filter_block_editor_settings( array $editor_settings, \WP_Block_Editor_Context $context ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $context is required to match WordPress's `block_editor_settings_all` filter signature.
		if ( current_user_can( 'manage_options' ) ) {
			return $editor_settings;
		}

		// Clients may lock their OWN blocks (§5.2); they may never open the
		// code editor, which would bypass the block policy entirely.
		$editor_settings['canLockBlocks']      = true;
		$editor_settings['codeEditingEnabled'] = false;

		return $editor_settings;
	}
}
