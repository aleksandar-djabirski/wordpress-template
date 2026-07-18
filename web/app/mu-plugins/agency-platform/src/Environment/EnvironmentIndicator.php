<?php

declare(strict_types=1);

namespace AgencyPlatform\Environment;

/**
 * Adds an admin-bar badge naming the current WordPress environment
 * (`wp_get_environment_type()`) on every environment except production, so
 * nobody mistakes a staging/development site for the live one.
 */
final class EnvironmentIndicator {

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_environment_node' ), 999 );
	}

	public function add_environment_node( \WP_Admin_Bar $admin_bar ): void {
		$environment = wp_get_environment_type();

		if ( 'production' === $environment ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'id'    => 'agency-platform-environment',
				'title' => sprintf(
					'<span style="%1$s">%2$s</span>',
					esc_attr( self::style_for( $environment ) ),
					esc_html( self::label_for( $environment ) )
				),
				// No 'href': this is a status badge, not a link. Omitting
				// the key (rather than passing false) keeps this compatible
				// with WP_Admin_Bar::add_node()'s `href?: string` shape —
				// WordPress core itself only checks the value for
				// truthiness, so an absent key behaves the same as false.
				'meta'  => array(
					'title' => esc_attr__( 'Current WordPress environment', 'agency-platform' ),
				),
			)
		);
	}

	/**
	 * Inline style for the badge: red-ish for staging (closest to
	 * production, so it should feel the most alarming), grey for
	 * development and any other non-production value.
	 */
	public static function style_for( string $environment ): string {
		if ( 'staging' === $environment ) {
			return 'background:#c0392b;color:#fff;padding:0 8px;border-radius:2px;font-weight:600;';
		}

		return 'background:#7f8c8d;color:#fff;padding:0 8px;border-radius:2px;font-weight:600;';
	}

	public static function label_for( string $environment ): string {
		return sprintf(
			/* translators: %s: environment name, e.g. "staging" or "development". */
			__( 'Env: %s', 'agency-platform' ),
			$environment
		);
	}
}
