<?php

declare(strict_types=1);

namespace AgencyPlatform\Editor;

/**
 * The security boundary the editor allow-list is NOT.
 *
 * `allowed_block_types_all` shapes the inserter; it does nothing about a
 * direct REST request, a pasted block tree, or an automated client. This class
 * re-applies the FULL resolved block policy on save and REJECTS the request
 * with a REST error rather than silently stripping content — stripping
 * destroys a client's work without telling them, and is reserved for an
 * explicit sanitisation command.
 *
 * Enforcement point: `rest_pre_insert_{$post_type}`, which both
 * WP_REST_Posts_Controller and WP_REST_Templates_Controller apply before
 * wp_insert_post() runs, and which can return a WP_Error. Every block-editor
 * and Site Editor save goes through it. Programmatic wp_insert_post() calls
 * (WP-CLI, importers, migrations) are deliberately NOT gated: that is agency
 * tooling, not client input.
 *
 * Four rejection classes, per BLOCK_THEME_PROPOSAL.md §9.3:
 *   1. Any block outside the resolved policy — recursively, not just html and
 *      shortcode. core/freeform is included via BlockPolicy::ALWAYS_DENIED.
 *   2. Non-whitespace raw HTML, i.e. a parse_blocks() block with a null name.
 *   3. Any REGISTERED shortcode tag anywhere in the content, because
 *      do_shortcode() runs on the whole post content and does not care which
 *      block the tag sits in.
 *   4. Per-block custom CSS (the block instance style.css attribute).
 */
final class SaveValidation {

	/**
	 * @var string[]
	 */
	public const VALIDATED_POST_TYPES = array(
		'post',
		'page',
		'wp_template',
		'wp_template_part',
		'wp_block',
		'wp_navigation',
	);

	/**
	 * The subset of VALIDATED_POST_TYPES the Site Editor owns. Everything else
	 * in that list is a post-editor surface.
	 *
	 * @var string[]
	 */
	public const SITE_EDITOR_POST_TYPES = array( 'wp_template', 'wp_template_part', 'wp_navigation' );

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_save_filters' ) );
	}

	public function register_save_filters(): void {
		foreach ( self::VALIDATED_POST_TYPES as $post_type ) {
			add_filter( "rest_pre_insert_{$post_type}", array( $this, 'validate_content' ), 10, 2 );
		}
	}

	/**
	 * @param mixed $prepared The prepared post object (stdClass) or WP_Error.
	 * @return mixed
	 */
	public function validate_content( $prepared, \WP_REST_Request $request ) {
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return $prepared;
		}

		$content = $request->get_param( 'content' );

		if ( is_array( $content ) ) {
			$content = $content['raw'] ?? '';
		}

		$content = (string) $content;

		if ( '' === $content ) {
			return $prepared;
		}

		$parsed  = parse_blocks( $content );
		$context = self::editor_context( $prepared, $request );

		// The FULL resolved policy for this user in THIS editor context —
		// including whatever the three agency_platform_* filters decide for it.
		// Re-deriving it here (rather than re-using a cached post-editor list)
		// is what makes the boundary match the editor a project has actually
		// configured, and lets a filter that widens the Site Editor without
		// widening the post editor stay honest on save.
		$allowed = BlockPolicy::resolve(
			false,
			apply_filters( 'allowed_block_types_all', true, $context ),
			EditorRestrictions::registered_block_names(),
			EditorRestrictions::allowed_namespaces( $context ),
			EditorRestrictions::extra_allowed_blocks( $context ),
			EditorRestrictions::extra_denied_blocks( $context )
		);

		if ( false === $allowed ) {
			return new \WP_Error( // @phpstan-ignore arguments.count
				'agency_platform_no_blocks_allowed',
				__( 'Block editing is disabled for your role in this editor.', 'agency-platform' ),
				array( 'status' => 403 )
			);
		}

		$forbidden = BlockPolicy::forbidden_blocks( $parsed, (array) $allowed );

		if ( array() !== $forbidden ) {
			return new \WP_Error( // @phpstan-ignore arguments.count
				'agency_platform_forbidden_block',
				sprintf(
					/* translators: 1: block name, 2: block path within the content. */
					__( 'The block "%1$s" is not allowed for your role (found at %2$s). Remove it and save again.', 'agency-platform' ),
					$forbidden[0]['block'],
					$forbidden[0]['path']
				),
				array(
					'status'     => 403,
					'violations' => $forbidden,
				)
			);
		}

		$raw_html = BlockPolicy::raw_html_violations( $parsed );

		if ( array() !== $raw_html ) {
			return new \WP_Error( // @phpstan-ignore arguments.count
				'agency_platform_raw_html',
				__( 'Raw HTML is not allowed for your role. Rebuild this content with blocks and save again.', 'agency-platform' ),
				array(
					'status'     => 403,
					'violations' => $raw_html,
				)
			);
		}

		$shortcodes = BlockPolicy::shortcode_violations( $content, get_shortcode_regex() );

		if ( array() !== $shortcodes ) {
			return new \WP_Error( // @phpstan-ignore arguments.count
				'agency_platform_shortcode',
				sprintf(
					/* translators: %s: shortcode tag. */
					__( 'The shortcode [%s] is not allowed for your role. Remove it and save again.', 'agency-platform' ),
					$shortcodes[0]
				),
				array(
					'status'     => 403,
					'violations' => $shortcodes,
				)
			);
		}

		$block_css = BlockPolicy::block_css_violations( $parsed );

		if ( array() !== $block_css ) {
			return new \WP_Error( // @phpstan-ignore arguments.count
				'agency_platform_block_custom_css',
				sprintf(
					/* translators: %s: block name. */
					__( 'Custom CSS on the "%s" block is not allowed for your role. Use the design controls instead.', 'agency-platform' ),
					$block_css[0]['block']
				),
				array(
					'status'     => 403,
					'violations' => $block_css,
				)
			);
		}

		return $prepared;
	}

	/**
	 * Builds the editor context this save actually came from.
	 *
	 * Hard-coding `core/edit-site` would apply the Site Editor's resolved
	 * policy to a page saved from the post editor, so a project that widens one
	 * editor through the agency_platform_* filters would silently widen the
	 * other. `wp_template`, `wp_template_part` and `wp_navigation` are only
	 * ever written by the Site Editor; `post`, `page` and `wp_block` are the
	 * post editor's surfaces.
	 *
	 * @param object $prepared The prepared post object from the REST controller.
	 */
	public static function editor_context( object $prepared, \WP_REST_Request $request ): \WP_Block_Editor_Context {
		$post_type = isset( $prepared->post_type ) ? (string) $prepared->post_type : (string) $request->get_param( 'type' );
		$settings  = array(
			'name' => in_array( $post_type, self::SITE_EDITOR_POST_TYPES, true ) ? 'core/edit-site' : 'core/edit-post',
		);

		$post_id = isset( $prepared->ID ) ? (int) $prepared->ID : (int) $request->get_param( 'id' );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( $post instanceof \WP_Post ) {
			$settings['post'] = $post;
		}

		return new \WP_Block_Editor_Context( $settings );
	}
}
