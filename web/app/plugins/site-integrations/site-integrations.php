<?php
/**
 * Plugin Name:  Site Integrations
 * Plugin URI:   https://github.com/agency/agency-starter
 * Description:  The only approved home for outbound HTTP in the base
 *               profile. Supplies site-core's `site_core_lead_delivery`
 *               filter with an environment-appropriate
 *               SiteCore\Contracts\LeadDelivery implementation: a fake,
 *               log-only delivery everywhere except production, and a real
 *               webhook POST in production once one is configured. Self-
 *               contained and safely removable: deactivating this plugin
 *               only takes lead delivery back to "not configured" (site-core
 *               fails closed), nothing else in the starter depends on it.
 * Version:      0.1.0
 * Requires PHP: 8.3
 * Author:       Agency
 * License:      MIT
 * Text Domain:  site-integrations
 *
 * @package SiteIntegrations
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\SiteIntegrations\Plugin::boot();
