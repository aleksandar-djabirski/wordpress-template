<?php
/**
 * Plugin Name:  Agency Platform
 * Plugin URI:   https://github.com/agency/agency-starter
 * Description:  Reusable agency guardrails shared by every project built on
 *               this starter: environment safety, client roles, editor
 *               restrictions, security hardening, health checks, and
 *               WP-CLI tooling. No customer/business/brand logic lives
 *               here — see the site-* plugins and the theme for that.
 * Version:      0.1.0
 * Requires PHP: 8.3
 * Author:       Agency
 * License:      MIT
 * Text Domain:  agency-platform
 *
 * @package AgencyPlatform
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\AgencyPlatform\Plugin::boot();
