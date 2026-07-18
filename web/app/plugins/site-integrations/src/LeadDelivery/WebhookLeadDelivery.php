<?php

declare(strict_types=1);

namespace SiteIntegrations\LeadDelivery;

use SiteCore\Contracts\LeadDelivery;

/**
 * The production SiteCore\Contracts\LeadDelivery implementation: POSTs the
 * lead as JSON to the URL resolved by LeadWebhookConfig, via
 * wp_remote_post(). This is the only outbound HTTP call in the base
 * profile — see the naming contract's "Outbound HTTP allow-locations" rule.
 *
 * LeadDeliveryResolver is the only thing that should ever construct this
 * class outside of tests: it's the sole gate that keeps it from firing
 * anywhere but production. See that class's docblock for the full rules.
 *
 * Never logs the lead payload itself (name/email/message are PII); failure
 * logs are limited to the WP_Error code/message or the HTTP status code.
 *
 * Deliberately not `final`: write_log() is a protected seam a test
 * overrides to capture the logged line instead of writing to the real PHP
 * error log.
 */
class WebhookLeadDelivery implements LeadDelivery {

	private const TIMEOUT_SECONDS = 5;

	private LeadWebhookConfig $config;

	public function __construct( ?LeadWebhookConfig $config = null ) {
		$this->config = $config ?? new LeadWebhookConfig();
	}

	public function deliver( array $lead ): bool {
		$response = wp_remote_post(
			esc_url_raw( $this->config->url() ),
			array(
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $lead ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->write_log(
				sprintf(
					'[site-integrations] webhook lead delivery failed: %s (%s)',
					$response->get_error_code(),
					$response->get_error_message()
				)
			);

			return false;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 200 && $status_code < 300 ) {
			return true;
		}

		$this->write_log( sprintf( '[site-integrations] webhook lead delivery failed: HTTP %d', $status_code ) );

		return false;
	}

	/**
	 * A protected seam over error_log(): tests override this in a subclass
	 * (see tests/Unit/SiteIntegrations/Doubles/LoggingWebhookLeadDelivery.php)
	 * to capture the log line instead of writing to the real PHP error log.
	 */
	protected function write_log( string $line ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional single-line logging sink, not leftover debug code; see class docblock.
		error_log( $line );
	}
}
