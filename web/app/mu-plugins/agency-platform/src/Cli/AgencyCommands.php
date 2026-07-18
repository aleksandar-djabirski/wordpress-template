<?php

declare(strict_types=1);

namespace AgencyPlatform\Cli;

use AgencyPlatform\Health\DatabaseOverrideCheck;

/**
 * Registers the `wp agency ...` WP-CLI command family. Guarded so it never
 * touches the `WP_CLI` class/constant outside of an actual WP-CLI request —
 * this file loads on every request via Plugin::boot(), including normal web
 * requests where WP-CLI isn't loaded at all.
 */
final class AgencyCommands {

	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'agency check-overrides', array( self::class, 'check_overrides' ) );
		\WP_CLI::add_command( 'agency sanitize', array( self::class, 'sanitize' ) );
		\WP_CLI::add_command( 'agency verify-env', array( self::class, 'verify_env' ) );
	}

	/**
	 * Reports database records that shadow Git-owned templates/styles.
	 * Exits with code 1 when overrides are found, so this is safe to wire
	 * into CI/deploy checks.
	 */
	public static function check_overrides(): void {
		$report = ( new DatabaseOverrideCheck() )->run();

		\WP_CLI::log( sprintf( 'Template/template-part overrides: %d', count( $report['overrides'] ) ) );

		foreach ( $report['overrides'] as $record ) {
			\WP_CLI::log(
				sprintf(
					'  - %s (%s) [%s]',
					(string) ( $record['post_name'] ?? '' ),
					(string) ( $record['post_type'] ?? '' ),
					(string) ( $record['post_status'] ?? '' )
				)
			);
		}

		\WP_CLI::log( sprintf( 'Expected core-generated global-styles records: %d', count( $report['expected'] ) ) );
		\WP_CLI::log( sprintf( 'Synced patterns (informational only): %d', count( $report['synced_patterns'] ) ) );

		if ( array() !== $report['overrides'] ) {
			// WP_CLI::error() halts execution (exit code 1); no explicit
			// `return` after it — PHPStan knows this call never returns.
			\WP_CLI::error( 'Database overrides found — Git owns templates/template-parts. Reconcile or intentionally re-export them to disk.' );
		}

		\WP_CLI::success( 'No database overrides found.' );
	}

	/**
	 * Idempotently scrubs personally-identifying and session/credential
	 * data — intended for sanitizing a production database dump before it
	 * lands in a non-production environment. Never touches administrator
	 * accounts' email/URL (so a human can still log in with a known
	 * address), but revokes sessions and application passwords for every
	 * user, administrators included.
	 */
	public static function sanitize(): void {
		$summary = array();

		$non_admin_users = get_users(
			array(
				'role__not_in' => array( 'administrator' ),
				'fields'       => 'all',
			)
		);

		$emails_sanitized = 0;
		$urls_cleared     = 0;

		foreach ( $non_admin_users as $user ) {
			$sanitized_email = sprintf( 'user_%d@example.invalid', $user->ID );
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

		$summary[] = sprintf( 'Sanitized email address on %d non-administrator user(s).', $emails_sanitized );
		$summary[] = sprintf( 'Cleared user_url on %d non-administrator user(s).', $urls_cleared );

		$all_user_ids       = get_users( array( 'fields' => 'ID' ) );
		$sessions_cleared   = 0;
		$app_passwords_gone = 0;

		foreach ( $all_user_ids as $user_id ) {
			if ( delete_user_meta( (int) $user_id, 'session_tokens' ) ) {
				++$sessions_cleared;
			}

			if ( delete_user_meta( (int) $user_id, '_application_passwords' ) ) {
				++$app_passwords_gone;
			}
		}

		$summary[] = sprintf( 'Revoked active sessions for %d user(s).', $sessions_cleared );
		$summary[] = sprintf( 'Deleted application passwords for %d user(s).', $app_passwords_gone );

		// LEAD_WEBHOOK_URL itself is consumed directly as an environment
		// variable by SiteIntegrations\LeadDelivery\WebhookLeadDelivery —
		// nothing caches it into a wp_options row today. delete_option() is
		// a no-op (returns false) when the option doesn't exist, so this
		// stays safe/idempotent if a later task ever introduces such a
		// cache under this option name.
		delete_option( 'agency_lead_webhook_url' );
		$summary[] = 'Cleared any cached LEAD_WEBHOOK_URL-derived option (none present today; call is a no-op).';

		update_option( 'blog_public', 0 );
		$summary[] = 'Set "blog_public" to 0 (discourage search engines from indexing).';

		foreach ( $summary as $line ) {
			\WP_CLI::log( $line );
		}

		\WP_CLI::success( 'Sanitize complete.' );
	}

	/**
	 * Asserts environment-safety invariants that must hold outside
	 * production. Exits with code 1 and lists every failed invariant when
	 * any check fails.
	 */
	public static function verify_env(): void {
		$environment = wp_get_environment_type();

		if ( 'production' === $environment ) {
			// This command exists to verify non-production safety nets;
			// running it against production isn't itself a failure, but is
			// probably not what was intended, so flag it and stop.
			\WP_CLI::warning( 'Running verify-env against a production environment; there are no non-production invariants to check here.' );
			\WP_CLI::success( 'Nothing to verify in production.' );
			return;
		}

		$failures = array();

		if ( in_array( $environment, array( 'development', 'staging' ), true ) ) {
			if ( ! defined( 'AGENCY_DISABLE_OUTBOUND_WEBHOOKS' ) || true !== AGENCY_DISABLE_OUTBOUND_WEBHOOKS ) {
				$failures[] = 'AGENCY_DISABLE_OUTBOUND_WEBHOOKS must be defined and true when WP_ENVIRONMENT_TYPE is "development" or "staging".';
			}
		}

		if ( array() === $failures ) {
			\WP_CLI::success( sprintf( 'Environment invariants hold for "%s".', $environment ) );
			return;
		}

		foreach ( $failures as $failure ) {
			\WP_CLI::log( '  - ' . $failure );
		}

		\WP_CLI::error( sprintf( '%d environment invariant(s) failed for "%s".', count( $failures ), $environment ) );
	}
}
