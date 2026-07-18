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
// webhooks or send real analytics. Environment-safety tests assert these.
Config::define('AGENCY_DISABLE_OUTBOUND_WEBHOOKS', true);
Config::define('AGENCY_DISABLE_ANALYTICS', true);
