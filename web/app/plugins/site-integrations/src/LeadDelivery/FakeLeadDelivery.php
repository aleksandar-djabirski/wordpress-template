<?php

declare(strict_types=1);

namespace SiteIntegrations\LeadDelivery;

use SiteCore\Contracts\LeadDelivery;

/**
 * The development/staging SiteCore\Contracts\LeadDelivery implementation —
 * proves the lead pipeline works end-to-end without requiring any real
 * external service. Never sends the lead anywhere; logs one line and always
 * reports success.
 *
 * The logged line intentionally omits PII: it carries the name, the email's
 * domain only (never the full address), and the message length (never the
 * message text) — enough to eyeball that a submission happened without
 * putting personal data in a log file.
 *
 * This is what LeadDeliveryResolver returns everywhere except production
 * (and even in production, until LEAD_WEBHOOK_URL is configured) — see that
 * class for the exact rules.
 *
 * Deliberately not `final`: write_log() is a protected seam a test
 * overrides to capture the logged line instead of writing to the real PHP
 * error log.
 */
class FakeLeadDelivery implements LeadDelivery {

	public function deliver( array $lead ): bool {
		$payload = array(
			'name'           => (string) ( $lead['name'] ?? '' ),
			'email_domain'   => $this->email_domain( (string) ( $lead['email'] ?? '' ) ),
			'message_length' => strlen( (string) ( $lead['message'] ?? '' ) ),
		);

		$encoded = wp_json_encode( $payload );

		if ( false === $encoded ) {
			// The payload contained something wp_json_encode() couldn't
			// handle; log a fixed, always-valid fallback rather than
			// silently dropping the line or risking a second encode failure.
			$encoded = '"payload omitted: not JSON-encodable"';
		}

		$this->write_log( '[site-integrations] FAKE lead delivery (not sent): ' . $encoded );

		return true;
	}

	private function email_domain( string $email ): string {
		$at_position = strrpos( $email, '@' );

		return false === $at_position ? '' : substr( $email, $at_position + 1 );
	}

	/**
	 * A protected seam over error_log(): tests override this in a subclass
	 * (see tests/Unit/SiteIntegrations/Doubles/LoggingFakeLeadDelivery.php)
	 * to capture the log line instead of writing to the real PHP error log.
	 */
	protected function write_log( string $line ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional single-line logging sink, not leftover debug code; see class docblock.
		error_log( $line );
	}
}
