<?php

/**
 * Configuration overrides for WP_ENV === 'production'
 *
 * Note: current upstream Bedrock (roots/bedrock) does not ship a
 * config/environments/production.php file — production is the implicit
 * baseline already defined in config/application.php. This project adds an
 * explicit production override file so production-only intent is visible
 * and testable in one place, rather than relying on defaults.
 */

use Roots\WPConfig\Config;

// Reaffirm (config/application.php already sets this) that plugin/theme
// installation, editing, and updates from the wp-admin file editor stay
// disabled in production. Deploys happen via Composer, not wp-admin.
Config::define('DISALLOW_FILE_MODS', true);

// Production is intentionally left indexable and is the only environment
// that does NOT set AGENCY_DISABLE_OUTBOUND_WEBHOOKS / AGENCY_DISABLE_ANALYTICS,
// so real integrations run here.
