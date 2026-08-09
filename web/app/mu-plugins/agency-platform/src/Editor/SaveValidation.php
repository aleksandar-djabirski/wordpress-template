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
 * Enforcement points: `rest_pre_insert_{$post_type}`, which both
 * WP_REST_Posts_Controller and WP_REST_Templates_Controller apply before
 * wp_insert_post() runs, and `wp_insert_post_data` for the classic admin save
 * path. REST requests use the REST callback only, so their WP_Error shape is
 * unchanged. Programmatic wp_insert_post() calls, autosaves and revisions are
 * not gated: they are not the client's final classic save.
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
		add_filter( 'wp_insert_post_data', array( $this, 'validate_classic_save' ), 10, 2 );
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
		// This repository targets exactly one WordPress version — the one
		// composer.lock resolves — and WP_Block_Type_Registry::get_all_registered()
		// exists in it. A method_exists() guard stood here claiming to keep the
		// policy closed on a "partial WordPress runtime"; PHPStan proves the
		// condition is always true, so that fallback could never be reached and
		// the branch was dead code pretending to be a safety net.
		$registered_block_names = EditorRestrictions::registered_block_names();

		$allowed = BlockPolicy::resolve(
			false,
			apply_filters( 'allowed_block_types_all', true, $context ),
			$registered_block_names,
			EditorRestrictions::allowed_namespaces( $context ),
			EditorRestrictions::extra_allowed_blocks( $context ),
			EditorRestrictions::extra_denied_blocks( $context )
		);

		if ( false === $allowed ) {
			return new \WP_Error(
				'agency_platform_no_blocks_allowed',
				__( 'Block editing is disabled for your role in this editor.', 'agency-platform' ),
				array( 'status' => 403 )
			);
		}

		$forbidden = BlockPolicy::forbidden_blocks( $parsed, (array) $allowed );

		if ( array() !== $forbidden ) {
			return new \WP_Error(
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
			return new \WP_Error(
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
			return new \WP_Error(
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
			return new \WP_Error(
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
	 * Rejects forbidden content submitted through wp-admin/post.php.
	 *
	 * `wp_insert_post_data` cannot return a WP_Error to WordPress's insert
	 * pipeline. A violation therefore stops the request with wp_die() after
	 * reusing the REST validator to keep the policy and message aligned.
	 *
	 * @param array<string, mixed> $data    The filtered post data.
	 * @param array<string, mixed> $postarr The post data passed to wp_insert_post().
	 * @return array<string, mixed>
	 */
	public function validate_classic_save( array $data, array $postarr ): array {
		if (
			current_user_can( 'manage_options' )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ! $this->is_classic_admin_save( $data, $postarr )
		) {
			return $data;
		}

		$post_type = isset( $postarr['post_type'] ) ? (string) $postarr['post_type'] : (string) ( $data['post_type'] ?? '' );

		if ( ! in_array( $post_type, self::VALIDATED_POST_TYPES, true ) ) {
			return $data;
		}

		// Revisions need no check of their own: 'revision' is not in
		// VALIDATED_POST_TYPES, so the in_array() guard above has already
		// returned. Autosaves DO reach here — they carry the parent's post type
		// — so that half is load-bearing and stays.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}

		$content = isset( $postarr['post_content'] ) ? (string) wp_unslash( $postarr['post_content'] ) : '';
		$request = new \WP_REST_Request( 'POST', '/wp-admin/post.php' );
		$request->set_param( 'content', $content );
		$request->set_param( 'type', $post_type );

		if ( isset( $postarr['ID'] ) ) {
			$request->set_param( 'id', (int) $postarr['ID'] );
		}

		$result = $this->validate_content( (object) $postarr, $request );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) );
		}

		return $data;
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
		$post_id   = isset( $prepared->ID ) ? (int) $prepared->ID : (int) $request->get_param( 'id' );
		$post      = $post_id > 0 ? get_post( $post_id ) : null;
		$post_type = isset( $prepared->post_type )
			? (string) $prepared->post_type
			: ( $post instanceof \WP_Post ? (string) $post->post_type : (string) $request->get_param( 'type' ) );
		$settings  = array(
			'name' => in_array( $post_type, self::SITE_EDITOR_POST_TYPES, true ) ? 'core/edit-site' : 'core/edit-post',
		);

		if ( $post instanceof \WP_Post ) {
			$settings['post'] = $post;
		}

		return new \WP_Block_Editor_Context( $settings );
	}

	/**
	 * Limits validation to the client's final classic admin post save.
	 *
	 * @param array<string, mixed> $data    The filtered post data.
	 * @param array<string, mixed> $postarr The post data passed to wp_insert_post().
	 */
	private function is_classic_admin_save( array $data, array $postarr ): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( ! is_admin() ) {
			return false;
		}

		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		// Core has already checked the update-post nonce before this filter runs.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wp-admin/post.php verifies the nonce before wp_insert_post_data.
		$action = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';

		if ( 'post.php' !== $pagenow || ! in_array( $action, array( 'editpost', 'post', 'postajaxpost' ), true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wp-admin/post.php verifies the nonce before wp_insert_post_data.
		if ( ! isset( $_REQUEST['post_ID'] ) || 0 === (int) $_REQUEST['post_ID'] ) {
			return false;
		}

		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : (int) ( $data['ID'] ?? 0 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wp-admin/post.php verifies the nonce before wp_insert_post_data.
		return 0 === $post_id || (int) $_REQUEST['post_ID'] === $post_id;
	}
}
