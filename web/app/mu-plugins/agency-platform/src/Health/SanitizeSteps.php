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
	 * Profile usermeta keys blanked outright for every sanitized user. These
	 * are free-text profile fields that routinely carry a person's real name
	 * or bio; they are keyed by both the WordPress meta key and (for the first
	 * three) the wp_insert_user() argument name, but are blanked via
	 * update_user_meta() rather than wp_update_user() — the latter resets an
	 * empty `nickname` back to the user_login, which is exactly the value this
	 * step deliberately preserves elsewhere.
	 *
	 * @var list<string>
	 */
	private const SCRUBBED_USER_META_KEYS = array(
		'first_name',
		'last_name',
		'nickname',
		'description',
	);

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
	 * Anonymizes user identity and profile PII: `user_email` becomes
	 * `user_{ID}@example.invalid`, `user_url` is cleared, `display_name`
	 * becomes `Sanitized User {ID}`, `user_nicename` (the public author slug)
	 * becomes the per-ID-unique `sanitized-user-{id}`, and the `first_name`,
	 * `last_name`, `nickname`, and `description` usermeta are blanked.
	 *
	 * `user_login` is DELIBERATELY left untouched: it is the identity needed to
	 * actually log into the sanitized copy locally, a login is rarely
	 * third-party PII, and rewriting it would break active sessions, fixtures,
	 * and any test that references a known login. (See docs/restore.md and
	 * ops/launch-checklist.md — sanitize is a baseline scrub, not a guarantee.)
	 *
	 * Administrators are skipped by default — an agency needs its own staff
	 * logins and password-reset addresses to stay usable on a local import —
	 * unless `--include-admins` (options['include_admins']) is set.
	 *
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	public static function users( array $options ): array {
		$include_admins = ! empty( $options['include_admins'] );

		$users = get_users( self::user_query_args( $include_admins ) );

		$emails_sanitized = 0;
		$names_sanitized  = 0;
		$urls_cleared     = 0;
		$meta_cleared     = 0;

		foreach ( $users as $user ) {
			$user_id = (int) $user->ID;

			$sanitized_email    = self::sanitized_user_email( $user_id );
			$sanitized_name     = self::sanitized_display_name( $user_id );
			$sanitized_nicename = self::sanitized_user_nicename( $user_id );

			$update = array( 'ID' => $user_id );

			if ( $sanitized_email !== $user->user_email ) {
				$update['user_email'] = $sanitized_email;
				++$emails_sanitized;
			}

			$name_changed = false;

			if ( $sanitized_name !== $user->display_name ) {
				$update['display_name'] = $sanitized_name;
				$name_changed           = true;
			}

			if ( $sanitized_nicename !== $user->user_nicename ) {
				$update['user_nicename'] = $sanitized_nicename;
				$name_changed            = true;
			}

			if ( $name_changed ) {
				++$names_sanitized;
			}

			if ( '' !== $user->user_url ) {
				$update['user_url'] = '';
				++$urls_cleared;
			}

			// More than just the 'ID' anchor means at least one users-table
			// field changed; user_login is never among the keys.
			if ( count( $update ) > 1 ) {
				wp_update_user( $update );
			}

			// Blank profile meta directly: update_user_meta() lets us set an
			// empty 'nickname' (wp_update_user() would reset that back to the
			// user_login, which this step preserves on purpose).
			$meta_changed = false;

			foreach ( self::SCRUBBED_USER_META_KEYS as $meta_key ) {
				if ( '' !== (string) get_user_meta( $user_id, $meta_key, true ) ) {
					update_user_meta( $user_id, $meta_key, '' );
					$meta_changed = true;
				}
			}

			if ( $meta_changed ) {
				++$meta_cleared;
			}
		}

		$scope = $include_admins ? 'user(s)' : 'non-administrator user(s)';

		return array(
			sprintf( 'Sanitized email address on %d %s.', $emails_sanitized, $scope ),
			sprintf( 'Sanitized display name + author slug (user_nicename) on %d %s.', $names_sanitized, $scope ),
			sprintf( 'Cleared user_url on %d %s.', $urls_cleared, $scope ),
			sprintf( 'Blanked profile meta (first/last name, nickname, description) on %d %s.', $meta_cleared, $scope ),
		);
	}

	/**
	 * Anonymizes commenter PII: `comment_author_email` becomes
	 * `comment_{ID}@example.invalid`, `comment_author` becomes
	 * `Sanitized Commenter {ID}`, and `comment_author_url`, `comment_author_IP`
	 * and `comment_agent` are blanked. Each field is its own guarded UPDATE so
	 * the step stays idempotent: a second run matches nothing and reports zero
	 * changes. Empty commenter emails (logged-in-user comments can carry none)
	 * are left untouched rather than stamped with a synthetic address.
	 *
	 * `comment_content` is deliberately NOT scrubbed: free-text comment bodies
	 * are a per-project editorial decision (they can carry PII a customer wants
	 * kept, or none at all), flagged in the sanitize audit checklist
	 * (ops/launch-checklist.md) rather than blanked wholesale here.
	 *
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $options is part of the uniform step signature; the comments step takes no per-run options.
	public static function comments( array $options ): array {
		global $wpdb;

		// The synthetic values are built inline (CONCAT over comment_ID) rather
		// than via a bound placeholder because they are fixed SQL expressions,
		// not external input; only the $wpdb->comments table name is interpolated.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off CLI sanitize; table name is $wpdb->comments and the statement carries no external input.
		$emails_sanitized = (int) $wpdb->query( "UPDATE {$wpdb->comments} SET comment_author_email = CONCAT('comment_', comment_ID, '@example.invalid') WHERE comment_author_email <> '' AND comment_author_email <> CONCAT('comment_', comment_ID, '@example.invalid')" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off CLI sanitize; table name is $wpdb->comments and the statement carries no external input.
		$authors_sanitized = (int) $wpdb->query( "UPDATE {$wpdb->comments} SET comment_author = CONCAT('Sanitized Commenter ', comment_ID) WHERE comment_author <> '' AND comment_author <> CONCAT('Sanitized Commenter ', comment_ID)" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off CLI sanitize; table name is $wpdb->comments and the statement carries no external input.
		$urls_cleared = (int) $wpdb->query( "UPDATE {$wpdb->comments} SET comment_author_url = '' WHERE comment_author_url <> ''" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off CLI sanitize; table name is $wpdb->comments and the statement carries no external input.
		$ips_cleared = (int) $wpdb->query( "UPDATE {$wpdb->comments} SET comment_author_IP = '' WHERE comment_author_IP <> ''" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off CLI sanitize; table name is $wpdb->comments and the statement carries no external input.
		$agents_cleared = (int) $wpdb->query( "UPDATE {$wpdb->comments} SET comment_agent = '' WHERE comment_agent <> ''" );

		return array(
			sprintf( 'Sanitized email address on %d comment(s).', $emails_sanitized ),
			sprintf( 'Sanitized author name on %d comment(s).', $authors_sanitized ),
			sprintf( 'Cleared comment_author_url on %d comment(s).', $urls_cleared ),
			sprintf( 'Cleared commenter IP address on %d comment(s).', $ips_cleared ),
			sprintf( 'Cleared commenter user agent on %d comment(s).', $agents_cleared ),
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
	 * Pure: the synthetic replacement display name for a given user ID.
	 */
	public static function sanitized_display_name( int $user_id ): string {
		return sprintf( 'Sanitized User %d', $user_id );
	}

	/**
	 * Pure: the synthetic replacement `user_nicename` (public author slug) for
	 * a given user ID. The ID keeps it unique — nicename is a slug WordPress
	 * expects to be distinct per user, so a fixed literal would collide.
	 */
	public static function sanitized_user_nicename( int $user_id ): string {
		return sprintf( 'sanitized-user-%d', $user_id );
	}

	/**
	 * Pure: the synthetic replacement email for a given comment ID.
	 */
	public static function sanitized_comment_email( int $comment_id ): string {
		return sprintf( 'comment_%d@example.invalid', $comment_id );
	}

	/**
	 * Pure: the synthetic replacement author name for a given comment ID.
	 */
	public static function sanitized_comment_author( int $comment_id ): string {
		return sprintf( 'Sanitized Commenter %d', $comment_id );
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
