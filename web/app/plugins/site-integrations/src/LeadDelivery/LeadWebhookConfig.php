<?php

declare(strict_types=1);

namespace SiteIntegrations\LeadDelivery;

/**
 * Resolves the outbound lead-webhook URL that WebhookLeadDelivery posts to.
 * Kept separate from WebhookLeadDelivery so config resolution (constant /
 * env var fallback chain) can be unit-tested independently of the HTTP call
 * itself.
 *
 * Checked in order: the LEAD_WEBHOOK_URL constant (if defined and a
 * non-empty string — see config/environments/*.php's Config::define()
 * calls), then the LEAD_WEBHOOK_URL environment variable (getenv(), then
 * $_ENV, then $_SERVER — Bedrock's .env loading populates these via
 * vlucas/phpdotenv). Returns '' if none resolve to a non-empty string;
 * callers (LeadDeliveryResolver) treat that as "not configured".
 */
final class LeadWebhookConfig {

	public function url(): string {
		if ( defined( 'LEAD_WEBHOOK_URL' ) ) {
			$constant_value = constant( 'LEAD_WEBHOOK_URL' );

			if ( is_string( $constant_value ) && '' !== $constant_value ) {
				return $constant_value;
			}
		}

		$from_getenv = getenv( 'LEAD_WEBHOOK_URL' );

		if ( is_string( $from_getenv ) && '' !== $from_getenv ) {
			return $from_getenv;
		}

		foreach ( array( $_ENV, $_SERVER ) as $source ) {
			$value = $source['LEAD_WEBHOOK_URL'] ?? null;

			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}
