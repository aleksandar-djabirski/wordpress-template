<?php

declare(strict_types=1);

namespace SiteCore\Leads;

use SiteCore\Contracts\LeadDelivery;

/**
 * Sanitizes, validates, and dispatches a lead submission (name/email/message)
 * to whichever SiteCore\Contracts\LeadDelivery implementation is resolved
 * via the `site_core_lead_delivery` filter.
 *
 * Deliberately out of scope for this starter, left to per-project code:
 *   - Form markup/rendering.
 *   - A REST route or admin-post action that calls process() with $_POST.
 *   - Nonce verification/CSRF protection.
 *
 * process() only ever receives an already-extracted array of raw field
 * values ($raw); the transport that gets it there — and verifies the
 * request is legitimate — is a per-project concern, not a starter one.
 */
final class LeadSubmissionHandler {

	/**
	 * @param array<string, mixed> $raw Raw, untrusted field values. Expected
	 *                                  keys: 'name', 'email', 'message'.
	 * @return array{ok: bool, errors?: array<string, string>}
	 */
	public function process( array $raw ): array {
		$lead = array(
			'name'    => sanitize_text_field( trim( $this->string_from( $raw, 'name' ) ) ),
			'email'   => sanitize_email( $this->string_from( $raw, 'email' ) ),
			'message' => sanitize_textarea_field( $this->string_from( $raw, 'message' ) ),
		);

		$errors = $this->validate( $lead );

		if ( array() !== $errors ) {
			return array(
				'ok'     => false,
				'errors' => $errors,
			);
		}

		$delivery = apply_filters( 'site_core_lead_delivery', null );

		if ( ! $delivery instanceof LeadDelivery ) {
			// Fail closed: no project has wired a delivery implementation
			// (via the site_core_lead_delivery filter) yet. One log line,
			// no secrets in it, so a misconfigured project is diagnosable
			// without anything sensitive leaking into the log.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional one-line diagnostic, not leftover debug code; see class docblock.
			error_log( 'site_core_lead_delivery filter did not return a SiteCore\Contracts\LeadDelivery instance; lead submission dropped.' );

			return array(
				'ok'     => false,
				'errors' => array(
					'delivery' => __( 'Lead delivery is not configured.', 'site-core' ),
				),
			);
		}

		return array(
			'ok' => $delivery->deliver( $lead ),
		);
	}

	/**
	 * Reads $raw[$key] as a string, treating anything non-scalar (array,
	 * object, ...) as empty rather than coercing it — e.g. (string) on an
	 * array yields the literal string "Array" plus a PHP warning, which
	 * would otherwise sail straight through the non-empty-string checks
	 * below as if it were legitimate input.
	 *
	 * @param array<string, mixed> $raw
	 */
	private function string_from( array $raw, string $key ): string {
		$value = $raw[ $key ] ?? null;

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param array{name: string, email: string, message: string} $lead
	 * @return array<string, string>
	 */
	private function validate( array $lead ): array {
		$errors = array();

		if ( '' === $lead['name'] ) {
			$errors['name'] = __( 'Name is required.', 'site-core' );
		}

		if ( ! is_email( $lead['email'] ) ) {
			$errors['email'] = __( 'A valid email address is required.', 'site-core' );
		}

		if ( '' === $lead['message'] ) {
			$errors['message'] = __( 'Message is required.', 'site-core' );
		}

		return $errors;
	}
}
