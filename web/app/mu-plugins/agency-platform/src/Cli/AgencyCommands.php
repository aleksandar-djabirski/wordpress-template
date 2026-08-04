<?php

declare(strict_types=1);

namespace AgencyPlatform\Cli;

use AgencyPlatform\Health\DatabaseOverrideCheck;
use AgencyPlatform\Health\SanitizeSteps;

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
	 * Reports database records that differ from the Git-owned theme baseline.
	 *
	 * INFORMATIONAL BY DEFAULT. Under the block-theme editing model, database
	 * template/template-part/Global Styles rows are EXPECTED: they are what a
	 * client using the Site Editor produces, and the promotion workflow is how
	 * they get reconciled back into Git. A deployment must not fail simply
	 * because a client edited their own site.
	 *
	 * Pass --fail-on-drift when you deliberately want a CI or deploy gate to
	 * stop on drift.
	 *
	 * This command is kept as a compatibility alias for the state subsystem's
	 * richer drift reporting.
	 *
	 * ## OPTIONS
	 *
	 * [--fail-on-drift]
	 * : Exit non-zero when any drift is found. Off by default.
	 *
	 * @param array<int, string>   $args       Positional arguments (unused; required by the WP-CLI command signature).
	 * @param array<string, mixed> $assoc_args Associative arguments/flags, e.g. `--fail-on-drift`.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $args is required by the WP-CLI command signature; this command takes no positional arguments.
	public static function check_overrides( array $args, array $assoc_args = array() ): void {
		$report = ( new DatabaseOverrideCheck() )->run();

		foreach ( self::drift_summary_lines( $report ) as $line ) {
			\WP_CLI::log( $line );
		}

		if ( self::drift_is_failure( $report, ! empty( $assoc_args['fail-on-drift'] ) ) ) {
			// WP_CLI::error() halts execution (exit code 1); no explicit
			// `return` after it — PHPStan knows this call never returns.
			\WP_CLI::error(
				sprintf(
					'%d database override(s) found and --fail-on-drift was requested. Reconcile them through the promotion workflow, or re-run without the flag to report only.',
					count( $report['overrides'] )
				)
			);
		}

		if ( array() === $report['overrides'] ) {
			\WP_CLI::success( 'No database overrides found.' );
			return;
		}

		\WP_CLI::success(
			sprintf(
				'%d database override(s) reported. Overrides are expected under the Site Editor editing model; pass --fail-on-drift to make them a hard failure.',
				count( $report['overrides'] )
			)
		);
	}

	/**
	 * @param array{overrides: array<int, array<string, mixed>>, expected: array<int, array<string, mixed>>, synced_patterns: array<int, array<string, mixed>>} $report
	 */
	public static function drift_is_failure( array $report, bool $fail_on_drift ): bool {
		return $fail_on_drift && array() !== $report['overrides'];
	}

	/**
	 * @param array{overrides: array<int, array<string, mixed>>, expected: array<int, array<string, mixed>>, synced_patterns: array<int, array<string, mixed>>} $report
	 * @return list<string>
	 */
	public static function drift_summary_lines( array $report ): array {
		$lines = array( sprintf( 'Template/template-part overrides: %d', count( $report['overrides'] ) ) );

		foreach ( $report['overrides'] as $record ) {
			$lines[] = sprintf(
				'  - %s (%s) [%s]',
				(string) ( $record['post_name'] ?? '' ),
				(string) ( $record['post_type'] ?? '' ),
				(string) ( $record['post_status'] ?? '' )
			);
		}

		$lines[] = sprintf( 'Expected core-generated global-styles records: %d', count( $report['expected'] ) );
		$lines[] = sprintf( 'Synced patterns (informational only): %d', count( $report['synced_patterns'] ) );

		return $lines;
	}

	/**
	 * Idempotently scrubs personally-identifying and session/credential
	 * data — intended for sanitizing a production database dump before it
	 * lands in a non-production environment.
	 *
	 * The work is a registry of ordered, idempotent steps (see
	 * AgencyPlatform\Health\SanitizeSteps): users, comments, sessions,
	 * application passwords, and blog_public by default, extensible by any
	 * plugin via the `agency_platform_sanitize_steps` filter (site-commerce
	 * appends real WooCommerce PII scrubbing when WooCommerce is active).
	 * Every step is idempotent, so re-running is always safe.
	 *
	 * By default, administrator email/URL is left intact so a human can still
	 * log in with a known address (sessions and application passwords are
	 * still revoked for everyone, administrators included). Pass
	 * `--include-admins` to extend the users step to administrators too.
	 *
	 * ## OPTIONS
	 *
	 * [--include-admins]
	 * : Also sanitize administrator email addresses and URLs. Off by default
	 *   so agency staff logins and password resets stay usable on a local
	 *   import.
	 *
	 * @param array<int, string>    $args       Positional arguments (unused; required by the WP-CLI command signature).
	 * @param array<string, mixed>  $assoc_args Associative arguments/flags, e.g. `--include-admins`.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $args is required by the WP-CLI command signature (positional args precede $assoc_args); this command takes no positional arguments.
	public static function sanitize( array $args, array $assoc_args = array() ): void {
		$options = array(
			'include_admins' => ! empty( $assoc_args['include-admins'] ),
		);

		foreach ( SanitizeSteps::steps() as $slug => $step ) {
			if ( ! is_callable( $step ) ) {
				\WP_CLI::warning( sprintf( 'Skipping sanitize step "%s": its registered value is not callable.', (string) $slug ) );
				continue;
			}

			foreach ( (array) call_user_func( $step, $options ) as $line ) {
				\WP_CLI::log( (string) $line );
			}
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

		// Asymmetry by design: AGENCY_ALLOW_OUTBOUND_EMAIL is only ever a
		// WARNING (never a hard failure) because it is a deliberate opt-out
		// surface — a project may point staging at a safe test mailbox and
		// enable real mail on purpose — whereas AGENCY_DISABLE_OUTBOUND_WEBHOOKS
		// below is a hard invariant that must always hold outside production.
		if ( defined( 'AGENCY_ALLOW_OUTBOUND_EMAIL' ) && true === AGENCY_ALLOW_OUTBOUND_EMAIL ) {
			\WP_CLI::warning(
				sprintf(
					'AGENCY_ALLOW_OUTBOUND_EMAIL is defined true in non-production ("%s"): MailGuard is disabled and real email can be sent. Confirm this points at a safe test mailbox.',
					$environment
				)
			);
		}

		$failures = array();

		// $environment is guaranteed non-production here (the early return
		// above handles 'production'), so this invariant applies to every
		// non-production value WP_ENVIRONMENT_TYPE can hold — 'development'
		// and 'staging' explicitly, but also 'local' (a valid core
		// environment type; see config/application.php) and any other
		// custom, non-production value a project might introduce.
		if ( ! defined( 'AGENCY_DISABLE_OUTBOUND_WEBHOOKS' ) || true !== AGENCY_DISABLE_OUTBOUND_WEBHOOKS ) {
			$failures[] = sprintf(
				'AGENCY_DISABLE_OUTBOUND_WEBHOOKS must be defined and true when WP_ENVIRONMENT_TYPE is not "production" (current: "%s").',
				$environment
			);
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
