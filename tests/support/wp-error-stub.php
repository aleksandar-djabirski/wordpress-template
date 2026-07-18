<?php
/**
 * Minimal WP_Error stand-in for the `architecture` and `unit` PHPUnit
 * suites (see wp-stubs.php's docblock for why this is a separate file).
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_Error — only what
	 * SiteIntegrations\LeadDelivery\WebhookLeadDelivery needs to read the
	 * code/message off whatever a test's wp_remote_post() stub response
	 * returns.
	 */
	class WP_Error {

		public function __construct( private string $code = '', private string $message = '' ) {
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}
