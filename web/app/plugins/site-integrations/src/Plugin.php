<?php

declare(strict_types=1);

namespace SiteIntegrations;

use SiteIntegrations\LeadDelivery\LeadDeliveryResolver;

/**
 * Bootstraps every site-integrations feature. Mirrors SiteCore\Plugin's /
 * AgencyPlatform\Plugin's shape (a fixed list, each wiring its own hooks),
 * but today has exactly one feature: supplying site-core's
 * `site_core_lead_delivery` filter with an environment-appropriate
 * SiteCore\Contracts\LeadDelivery implementation (see
 * LeadDelivery\LeadDeliveryResolver for the environment-safety rules).
 */
final class Plugin {

	public static function boot(): void {
		$resolver = new LeadDeliveryResolver();

		// A named callable (array pointing at an instance method), never a
		// closure — per the naming contract's "no closures in
		// add_action/add_filter" rule.
		add_filter( 'site_core_lead_delivery', array( $resolver, 'resolve' ), 10, 1 );
	}
}
