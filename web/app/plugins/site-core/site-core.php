<?php
/**
 * Plugin Name:  Site Core
 * Plugin URI:   https://github.com/agency/agency-starter
 * Description:  Owns this project's content domain: custom post types,
 *               metadata, content rules, and form processing. Exposes a
 *               stable public API via SiteCore\Contracts\* — the only
 *               site-core namespace the theme, site-integrations, and
 *               site-commerce may reference.
 * Version:      0.1.0
 * Requires PHP: 8.2
 * Author:       Agency
 * License:      MIT
 * Text Domain:  site-core
 *
 * @package SiteCore
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\SiteCore\Plugin::boot();
