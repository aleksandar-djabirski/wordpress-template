<?php

/**
 * Configuration overrides for WP_ENV === 'development'
 */

use Roots\WPConfig\Config;

use function Env\env;

Config::define('SAVEQUERIES', true);
Config::define('WP_DEBUG', true);
Config::define('WP_DEBUG_DISPLAY', true);
Config::define('WP_DEBUG_LOG', env('WP_DEBUG_LOG') ?? true);
Config::define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
Config::define('SCRIPT_DEBUG', true);
Config::define('DISALLOW_INDEXING', true);

ini_set('display_errors', '1');

// Enable plugin and theme updates and installation from the admin
Config::define('DISALLOW_FILE_MODS', false);

// Safety defaults: never let non-production environments call real outbound
// webhooks or send real analytics. AGENCY_DISABLE_OUTBOUND_WEBHOOKS is
// asserted by environment-safety tests (verify-environment + the
// integration suite); AGENCY_DISABLE_ANALYTICS is reserved for a project's
// analytics wiring and is intentionally unasserted until that lands.
Config::define('AGENCY_DISABLE_OUTBOUND_WEBHOOKS', true);
Config::define('AGENCY_DISABLE_ANALYTICS', true);

// Explicit opt-out surface for AgencyPlatform\Security\MailGuard: false keeps
// real email suppressed here; flip to true only to send real mail to a safe
// test mailbox.
Config::define('AGENCY_ALLOW_OUTBOUND_EMAIL', false);
