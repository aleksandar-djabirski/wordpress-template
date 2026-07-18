<?php

declare(strict_types=1);

namespace SiteIntegrations\LeadDelivery;

use SiteCore\Contracts\LeadDelivery;

/**
 * Resolves which SiteCore\Contracts\LeadDelivery implementation handles a
 * lead submission. Registered against site-core's `site_core_lead_delivery`
 * filter by SiteIntegrations\Plugin::boot().
 *
 * This is the environment-safety core of the plugin: WebhookLeadDelivery
 * (which makes a real outbound HTTP call) is returned ONLY when every one
 * of the following holds —
 *   - wp_get_environment_type() reports 'production' (development, staging,
 *     and local never qualify, no matter how LEAD_WEBHOOK_URL is set);
 *   - outbound webhooks haven't been explicitly killed via the
 *     AGENCY_DISABLE_OUTBOUND_WEBHOOKS constant (an operational escape
 *     hatch — e.g. a production maintenance window);
 *   - LeadWebhookConfig resolves a non-empty URL to call.
 *
 * FakeLeadDelivery is returned in every other case, including when an
 * unrecognized environment type is reported — this resolver only ever opts
 * IN to the real webhook, never opts out of the safe default.
 *
 * Deliberately not `final`: webhooks_disabled() is a protected seam a test
 * can override in a subclass to exercise the "disabled" branch without
 * permanently define()-ing AGENCY_DISABLE_OUTBOUND_WEBHOOKS, which (being a
 * real PHP constant) can't be undefined again once set within a process.
 * See tests/Unit/SiteIntegrations/LeadDeliveryResolutionTest.php.
 */
class LeadDeliveryResolver {

	/**
	 * @param mixed $existing The current `site_core_lead_delivery` filter
	 *                        value. If an earlier-registered filter already
	 *                        supplied a LeadDelivery, it's returned
	 *                        unchanged rather than overridden here.
	 */
	public function resolve( mixed $existing ): LeadDelivery {
		if ( $existing instanceof LeadDelivery ) {
			return $existing;
		}

		if ( 'production' !== wp_get_environment_type() ) {
			return new FakeLeadDelivery();
		}

		if ( $this->webhooks_disabled() ) {
			return new FakeLeadDelivery();
		}

		$config = new LeadWebhookConfig();

		if ( '' === $config->url() ) {
			return new FakeLeadDelivery();
		}

		return new WebhookLeadDelivery( $config );
	}

	/**
	 * Reads the AGENCY_DISABLE_OUTBOUND_WEBHOOKS operational kill switch —
	 * a protected seam (rather than inlining the defined()/constant() check
	 * directly in resolve()) so tests can override just this method. See
	 * this class's docblock for why.
	 */
	protected function webhooks_disabled(): bool {
		return defined( 'AGENCY_DISABLE_OUTBOUND_WEBHOOKS' ) && true === constant( 'AGENCY_DISABLE_OUTBOUND_WEBHOOKS' );
	}
}
