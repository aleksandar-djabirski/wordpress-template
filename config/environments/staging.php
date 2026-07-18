<?php

/**
 * Configuration overrides for WP_ENV === 'staging'
 */

use Roots\WPConfig\Config;

/**
 * You should try to keep staging as close to production as possible. However,
 * should you need to, you can always override production configuration values
 * with `Config::define`.
 *
 * Example: `Config::define('WP_DEBUG', true);`
 * Example: `Config::define('DISALLOW_FILE_MODS', false);`
 */

Config::define('DISALLOW_INDEXING', true);

// Safety defaults: never let non-production environments call real outbound
// webhooks or send real analytics. AGENCY_DISABLE_OUTBOUND_WEBHOOKS is
// asserted by environment-safety tests (verify-environment + the
// integration suite); AGENCY_DISABLE_ANALYTICS is reserved for a project's
// analytics wiring and is intentionally unasserted until that lands.
Config::define('AGENCY_DISABLE_OUTBOUND_WEBHOOKS', true);
Config::define('AGENCY_DISABLE_ANALYTICS', true);

// Explicit opt-out surface for AgencyPlatform\Security\MailGuard: false keeps
// real email suppressed here; flip to true only to send real mail to a safe
// test mailbox (verify-env then warns, but does not fail).
Config::define('AGENCY_ALLOW_OUTBOUND_EMAIL', false);
