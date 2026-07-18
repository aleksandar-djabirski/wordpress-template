<?php
/**
 * Minimal WordPress function stubs for the `architecture` and `unit`
 * PHPUnit suites, which run without a real WordPress install.
 *
 * Kept intentionally small: add a stub here only once a pure-logic unit
 * test actually needs it, so this file can't silently grow into a shadow
 * WordPress. Never loaded for the `integration` suite (see
 * tests/bootstrap.php), which boots a real WordPress test environment via
 * wp-phpunit instead.
 */

declare(strict_types=1);

// These stub bodies intentionally ignore every parameter — they only need
// to exist so calling code doesn't fatal with "function not defined" while
// running without WordPress; matching WordPress's real signatures (rather
// than a no-arg shorthand) keeps them accurate stand-ins.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Real WordPress stores registered callbacks in a global WP_Hook
	 * registry that apply_filters() later consults. This stub keeps a
	 * minimal stand-in — callbacks grouped by hook name and priority in
	 * $GLOBALS['_test_filters'] — so a test can add_filter() a callback
	 * here and have apply_filters() below actually invoke it, without
	 * needing any of WP_Hook's machinery.
	 */
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['_test_filters'][ $hook_name ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
		$priorities = $GLOBALS['_test_filters'][ $hook_name ] ?? array();

		ksort( $priorities );

		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as $filter ) {
				$callback_args = array_slice( array_merge( array( $value ), $args ), 0, max( 1, $filter['accepted_args'] ) );
				$value         = call_user_func_array( $filter['callback'], $callback_args );
			}
		}

		return $value;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type(): string {
		return $GLOBALS['_test_env_type'] ?? 'development';
	}
}

// --- SiteCore\Testimonials\TestimonialsProvider registration recorders -----
// Record what a provider registered (post type / meta key + args) instead
// of doing anything WordPress-like with it, so a test can assert on the
// exact shape passed in without a real post-type registry.

if ( ! function_exists( 'register_post_type' ) ) {
	/**
	 * @param array<string, mixed> $args
	 */
	function register_post_type( string $post_type, array $args = array() ): bool {
		$GLOBALS['_test_registered']['post_types'][ $post_type ] = $args;

		return true;
	}
}

if ( ! function_exists( 'register_post_meta' ) ) {
	/**
	 * @param array<string, mixed> $args
	 */
	function register_post_meta( string $post_type, string $meta_key, array $args ): bool {
		$GLOBALS['_test_registered']['post_meta'][ $post_type ][ $meta_key ] = $args;

		return true;
	}
}

// --- Sanitization / validation, used by SiteCore\Leads\LeadSubmissionHandler

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
		// Regex-based (rather than strip_tags()) so this stand-in for
		// wp_strip_all_tags() doesn't itself trip the phpcs sniff that
		// tells production code to prefer wp_strip_all_tags() over
		// strip_tags().
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
		$text = (string) preg_replace( '/<[^>]*>/', '', $text );

		if ( $remove_breaks ) {
			$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}

		return trim( $text );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return wp_strip_all_tags( $str, true );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $str ): string {
		return wp_strip_all_tags( $str );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( string $email ): string {
		// Minimal stand-in for WordPress's real (much longer) sanitizer:
		// strips characters that can never appear in a valid email address.
		// Enough for is_email()'s filter_var() check below to still tell
		// realistic addresses and garbage test input apart.
		return (string) preg_replace( '/[^a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~@-]/', '', $email );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( string $email, bool $deprecated = false ): string|false {
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}

// --- Post/meta reads used by SiteCore\Contracts\Testimonials::latest() -----

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return array<int, object>
	 */
	function get_posts( array $args = array() ): array {
		return $GLOBALS['_test_posts'] ?? array();
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		return $GLOBALS['_test_post_meta'][ $post_id ][ $key ] ?? ( $single ? '' : array() );
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	// Untyped $post (rather than WordPress's real `int|WP_Post|null`): a
	// union type naming WP_Post would force PHP to resolve that class the
	// moment this is called, and WP_Post doesn't exist in this stubbed,
	// no-WordPress test environment.
	/**
	 * @param mixed $post
	 * @return int|false
	 */
	function get_post_thumbnail_id( $post = null ) {
		$post_id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;

		return $GLOBALS['_test_thumbnails'][ $post_id ] ?? false;
	}
}

// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
