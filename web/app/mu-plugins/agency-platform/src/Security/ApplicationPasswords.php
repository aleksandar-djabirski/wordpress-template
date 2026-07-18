<?php

declare(strict_types=1);

namespace AgencyPlatform\Security;

/**
 * Restricts Application Passwords to users who can `manage_options`.
 * Customer roles (`client_editor`, `client_shop_manager`) never get an
 * Application Passwords section on their profile screen and can't
 * authenticate the REST API/XML-RPC with one.
 */
final class ApplicationPasswords {

	public function register(): void {
		add_filter( 'wp_is_application_passwords_available_for_user', array( $this, 'restrict_to_administrators' ), 10, 2 );
	}

	public function restrict_to_administrators( bool $available, \WP_User $user ): bool {
		if ( ! $available ) {
			return $available;
		}

		return user_can( $user, 'manage_options' );
	}
}
