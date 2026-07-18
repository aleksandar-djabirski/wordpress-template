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
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
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

// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
