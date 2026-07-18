<?php

declare(strict_types=1);

namespace SiteCore\Contracts;

/**
 * Delivers a sanitized, validated lead (see
 * SiteCore\Leads\LeadSubmissionHandler) to wherever it needs to go — email,
 * a webhook, a CRM, ...
 *
 * site-core depends on this interface only, never on a concrete
 * implementation: site-integrations supplies one (e.g.
 * SiteIntegrations\LeadDelivery\FakeLeadDelivery for dev/staging,
 * SiteIntegrations\LeadDelivery\WebhookLeadDelivery for production) and
 * wires it in via the `site_core_lead_delivery` filter. That keeps the
 * dependency direction integrations -> core (deptrac-enforced), never the
 * reverse.
 */
interface LeadDelivery {

	/**
	 * @param array{name: string, email: string, message: string} $lead Pre-sanitized lead fields.
	 * @return bool Whether delivery succeeded.
	 */
	public function deliver( array $lead ): bool;
}
