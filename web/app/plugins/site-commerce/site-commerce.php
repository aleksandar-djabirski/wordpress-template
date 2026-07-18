<?php
/**
 * Plugin Name:  Site Commerce
 * Plugin URI:   https://github.com/agency/agency-starter
 * Description:  The commerce profile's isolation boundary: every
 *               WooCommerce reference in this starter lives inside this
 *               plugin (or the theme's woocommerce/ template overrides).
 *               Activates only when WooCommerce is present — on any site
 *               without WooCommerce this plugin is a safe no-op (an admin
 *               notice, nothing else), so the base profile never depends
 *               on it.
 * Version:      0.1.0
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * Author:       Agency
 * License:      MIT
 * Text Domain:  site-commerce
 *
 * @package SiteCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Deferred to plugins_loaded (rather than booted immediately, like
// SiteCore\Plugin::boot() / SiteIntegrations\Plugin::boot() are) because
// the class_exists('WooCommerce') check below can only be trusted once
// every plugin has had a chance to load — WooCommerce itself included.
add_action( 'plugins_loaded', array( \SiteCommerce\Plugin::class, 'maybe_boot' ) );
