<?php

declare(strict_types=1);

namespace AgencyPlatform\Security;

use AgencyPlatform\Logging\Logger;

/**
 * Repository-level guard that stops non-production environments from
 * delivering real email — the mail counterpart to the outbound-webhook
 * kill-switch. Staging and development routinely run against imported
 * production data (real customer/admin addresses), so a stray
 * wp_mail() — a password reset, a form notification, a WooCommerce order
 * email — would otherwise reach real inboxes.
 *
 * Hooks `pre_wp_mail` (WP 5.7+) at `PHP_INT_MAX` so it runs LAST: whatever a
 * plugin or theme filter decided at an earlier priority, this guard gets the
 * final say and no later filter can flip its verdict back to "deliver". That
 * fail-closed ordering is the whole point — a stray or hostile `pre_wp_mail`
 * filter returning `null` (to re-enable delivery) cannot outrank the guard.
 * The filter is consulted before WordPress hands anything to PHPMailer, and
 * returning a NON-NULL value short-circuits sending. This guard returns
 * `false` (not `null`) when suppressing —
 * matching WordPress's own contract: a `false` return makes wp_mail() return
 * false to its caller without sending, i.e. the mail is reported as a failed
 * send rather than a silent success. Returning `null` would instead let the
 * mail flow, so `null` is reserved for the production/opt-in path where this
 * guard deliberately stays out of the way and returns the incoming value
 * unchanged.
 *
 * Suppression is logged once per attempt via the shared Logger, recording
 * only the recipient DOMAIN(s) and the subject length — never the local-part,
 * body, or headers — so the log stays PII-free while still proving the guard
 * fired.
 *
 * Escape hatch: defining `AGENCY_ALLOW_OUTBOUND_EMAIL` truthy re-enables real
 * delivery even outside production, for the deliberate case of a project
 * pointing staging at a safe test mailbox. See
 * config/environments/{development,staging}.php.
 */
final class MailGuard {

	public function register(): void {
		// PHP_INT_MAX: run last so this guard's suppression verdict is final and
		// no later-registered filter can re-enable delivery. See the class docblock.
		add_filter( 'pre_wp_mail', array( $this, 'maybe_suppress' ), PHP_INT_MAX, 2 );
	}

	/**
	 * `pre_wp_mail` callback. Returns `false` to suppress the send (see the
	 * class docblock for why `false` and not `null`), or the incoming
	 * short-circuit value unchanged when mail is allowed to flow.
	 *
	 * @param bool|null            $short_circuit Value a higher-priority filter may already have set; `null` by default.
	 * @param array<string, mixed> $atts          wp_mail() arguments: `to`, `subject`, `message`, `headers`, `attachments`.
	 * @return bool|null
	 */
	public function maybe_suppress( $short_circuit, array $atts ) {
		$allow_override = defined( 'AGENCY_ALLOW_OUTBOUND_EMAIL' ) && true === AGENCY_ALLOW_OUTBOUND_EMAIL;

		if ( ! self::should_suppress( wp_get_environment_type(), $allow_override ) ) {
			// Production, or a deliberate non-production opt-in: stay out of
			// the way and preserve any decision an earlier filter made.
			return $short_circuit;
		}

		$subject = $atts['subject'] ?? '';

		Logger::log(
			'mail-guard',
			'Suppressed outbound email in a non-production environment.',
			array(
				'recipient_domains' => self::recipient_domains( $atts['to'] ?? array() ),
				'subject_length'    => is_string( $subject ) ? strlen( $subject ) : 0,
			)
		);

		return false;
	}

	/**
	 * Pure suppression decision, isolated from WordPress so it can be
	 * exhaustively unit-tested. Production is never suppressed; every
	 * non-production environment (development, staging, local, or any custom
	 * value) is suppressed unless the explicit override is in force.
	 */
	public static function should_suppress( string $environment, bool $allow_override ): bool {
		if ( 'production' === $environment ) {
			return false;
		}

		return ! $allow_override;
	}

	/**
	 * Pure extraction of the unique, lowercased recipient domains from a
	 * wp_mail() `to` value (a string, a comma-separated string, or an array,
	 * each entry either `addr@domain` or `Name <addr@domain>`). Deliberately
	 * drops the local-part so nothing that could identify a person reaches the
	 * log — only the domain, which is what a reviewer needs to see the guard
	 * fired against the right place.
	 *
	 * @param mixed $to
	 * @return list<string>
	 */
	public static function recipient_domains( $to ): array {
		$recipients = is_array( $to ) ? $to : explode( ',', is_string( $to ) ? $to : '' );

		$domains = array();

		foreach ( $recipients as $recipient ) {
			$address = is_string( $recipient ) ? $recipient : '';

			if ( 1 === preg_match( '/<([^>]+)>/', $address, $matches ) ) {
				$address = $matches[1];
			}

			$at = strrpos( $address, '@' );

			if ( false === $at ) {
				continue;
			}

			$domain = strtolower( trim( substr( $address, $at + 1 ) ) );

			if ( '' !== $domain ) {
				$domains[ $domain ] = true;
			}
		}

		$unique = array_keys( $domains );
		sort( $unique );

		return $unique;
	}
}
