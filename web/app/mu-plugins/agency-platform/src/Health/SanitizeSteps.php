<?php

declare(strict_types=1);

namespace AgencyPlatform\Health;

/**
 * The ordered, pluggable registry behind `wp agency sanitize`.
 *
 * Each step is a named callable keyed by a stable slug; `steps()` returns
 * them in run order and passes the array through the
 * `agency_platform_sanitize_steps` filter so other plugins (e.g. site-commerce
 * when WooCommerce is active) can append their own PII-scrubbing steps without
 * touching this file. Registrants MUST supply named callables — never
 * closures — for the same traceability reason hook callbacks do.
 *
 * Every step callable receives the resolved `$options` array (currently just
 * `include_admins`) and returns a list of human-readable summary lines for
 * WP-CLI to print. All steps are idempotent: running sanitize twice produces
 * the same end state and the second run reports zero further changes.
 *
 * Pure helpers (the synthetic address builders and the user-query shape) are
 * split out from the WordPress-coupled step bodies so they can be unit-tested
 * without a database, mirroring DatabaseOverrideCheck's classify()/run() split.
 */
final class SanitizeSteps {

	/**
	 * Ordered step registry, after the extension filter. Keys are stable
	 * slugs; values are named callables of shape `fn(array $options): list<string>`.
	 *
	 * @return array<string, callable>
	 */
	public static function steps(): array {
		$steps = array(
			'users'                 => array( self::class, 'users' ),
			'comments'              => array( self::class, 'comments' ),
			'sessions'              => array( self::class, 'sessions' ),
			'application_passwords' => array( self::class, 'application_passwords' ),
			'blog_public'           => array( self::class, 'blog_public' ),
		);

		/**
		 * Filters the ordered sanitize-step registry. Append a step to scrub
		 * data this base registry does not know about (commerce PII, a
		 * third-party plugin's tables). Callbacks MUST register a named
		 * callable, not a closure.
		 *
		 * @param array<string, callable> $steps slug => `fn(array $options): list<string>`.
		 */
		$steps = apply_filters( 'agency_platform_sanitize_steps', $steps );

		return is_array( $steps ) ? $steps : array();
	}

	/**
	 * Replaces user email addresses with `user_{ID}@example.invalid` and
	 * clears `user_url`. Administrators are skipped by default — an agency
	 * needs its own staff logins and password-reset addresses to stay usable
	 * on a local import — unless `--include-admins` (options['include_admins'])
	 * is set.
	 *
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	public static function users( array $options ): array {
		$include_admins = ! empty( $options['include_admins'] );

		$users = get_users( self::user_query_args( $include_admins ) );

		$emails_sanitized = 0;
		$urls_cleared     = 0;

		foreach ( $users as $user ) {
			$sanitized_email = self::sanitized_user_email( (int) $user->ID );
			$email_changed   = $sanitized_email !== $user->user_email;
			$url_changed     = '' !== $user->user_url;

			if ( ! $email_changed && ! $url_changed ) {
				continue;
			}

			wp_update_user(
				array(
					'ID'         => $user->ID,
					'user_email' => $sanitized_email,
					'user_url'   => '',
				)
			);

			if ( $email_changed ) {
				++$emails_sanitized;
			}

			if ( $url_changed ) {
				++$urls_cleared;
			}
		}

		$scope = $include_admins ? 'user(s)' : 'non-administrator user(s)';

		return array(
			sprintf( 'Sanitized email address on %d %s.', $emails_sanitized, $scope ),
			sprintf( 'Cleared user_url on %d %s.', $urls_cleared, $scope ),
		);
	}

	/**
	 * Replaces commenter email addresses with `comment_{ID}@example.invalid`
	 * and blanks `comment_author_url`. Two guarded UPDATEs keep it idempotent:
	 * a second run matches nothing and reports zero changes. Empty commenter
	 * emails (logged-in-user comments can carry none) are left untouched rather
	 * than stamped with a synthetic address.
	 *
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $options is part of the uniform step signature; the comments step takes no per-run options.
	public static function comments( array $options ): array {
		global $wpdb;

		// The synthetic address is built inline (CONCAT over comment_ID) rather
		// than via a bound placeholder because it is a fixed SQL expression, not
		// external input; only the $wpdb->comments table name is interpolated.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off CLI sanitize; table name is $wpdb->comments and the statement carries no external input.
		$emails_sanitized = (int) $wpdb->query( "UPDATE {$wpdb->comments} SET comment_author_email = CONCAT('comment_', comment_ID, '@example.invalid') WHERE comment_author_email <> '' AND comment_author_email <> CONCAT('comment_', comment_ID, '@example.invalid')" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off CLI sanitize; table name is $wpdb->comments and the statement carries no external input.
		$urls_cleared = (int) $wpdb->query( "UPDATE {$wpdb->comments} SET comment_author_url = '' WHERE comment_author_url <> ''" );

		return array(
			sprintf( 'Sanitized email address on %d comment(s).', $emails_sanitized ),
			sprintf( 'Cleared comment_author_url on %d comment(s).', $urls_cleared ),
		);
	}

	/**
	 * Revokes active login sessions for every user (administrators included).
	 *
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $options is part of the uniform step signature; this step takes no per-run options.
	public static function sessions( array $options ): array {
		$sessions_cleared = 0;

		foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
			if ( delete_user_meta( (int) $user_id, 'session_tokens' ) ) {
				++$sessions_cleared;
			}
		}

		return array( sprintf( 'Revoked active sessions for %d user(s).', $sessions_cleared ) );
	}

	/**
	 * Deletes application passwords for every user (administrators included).
	 *
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $options is part of the uniform step signature; this step takes no per-run options.
	public static function application_passwords( array $options ): array {
		$app_passwords_gone = 0;

		foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
			if ( delete_user_meta( (int) $user_id, '_application_passwords' ) ) {
				++$app_passwords_gone;
			}
		}

		return array( sprintf( 'Deleted application passwords for %d user(s).', $app_passwords_gone ) );
	}

	/**
	 * Discourages search engines from indexing the sanitized copy.
	 *
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $options is part of the uniform step signature; this step takes no per-run options.
	public static function blog_public( array $options ): array {
		update_option( 'blog_public', 0 );

		return array( 'Set "blog_public" to 0 (discourage search engines from indexing).' );
	}

	/**
	 * Pure: the synthetic replacement email for a given user ID.
	 */
	public static function sanitized_user_email( int $user_id ): string {
		return sprintf( 'user_%d@example.invalid', $user_id );
	}

	/**
	 * Pure: the synthetic replacement email for a given comment ID.
	 */
	public static function sanitized_comment_email( int $comment_id ): string {
		return sprintf( 'comment_%d@example.invalid', $comment_id );
	}

	/**
	 * Pure: the get_users() query args for the users step. Administrators are
	 * excluded unless `$include_admins` is true.
	 *
	 * @return array<string, mixed>
	 */
	public static function user_query_args( bool $include_admins ): array {
		$args = array( 'fields' => 'all' );

		if ( ! $include_admins ) {
			$args['role__not_in'] = array( 'administrator' );
		}

		return $args;
	}
}
