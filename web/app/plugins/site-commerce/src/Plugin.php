<?php

declare(strict_types=1);

namespace SiteCommerce;

use SiteCommerce\Products\ExampleProductRules;

/**
 * Site Commerce's activation guard and bootstrapper.
 *
 * This is the whole isolation model in one class: `maybe_boot()` is only
 * ever reached once, from site-commerce.php's `plugins_loaded` hook, and
 * every WooCommerce-dependent provider is wired from `boot()` — which never
 * runs unless WooCommerce is actually active. Deactivating WooCommerce (or
 * running this starter without it) leaves site-commerce in the no-op branch
 * below: an admin notice, nothing else. Nothing in the base profile
 * (site-core, site-integrations, the theme's non-commerce templates) may
 * depend on this plugin having booted.
 */
final class Plugin {

	/**
	 * Registered against `plugins_loaded` by site-commerce.php. Named
	 * `maybe_boot` (rather than `boot`, like SiteCore\Plugin /
	 * SiteIntegrations\Plugin) because whether it does anything at all
	 * depends on WooCommerce being present.
	 */
	public static function maybe_boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			// Safe no-op: wire only the admin notice and stop. No provider
			// below may assume WooCommerce classes/functions exist.
			add_action( 'admin_notices', array( self::class, 'render_missing_woocommerce_notice' ) );

			return;
		}

		self::boot();
	}

	/**
	 * Only reachable once WooCommerce is confirmed active. Mirrors
	 * SiteCore\Plugin::boot() / SiteIntegrations\Plugin::boot()'s shape: a
	 * fixed list of providers, each responsible for wiring its own hooks.
	 */
	private static function boot(): void {
		$providers = array(
			new ExampleProductRules(),
		);

		foreach ( $providers as $provider ) {
			$provider->register();
		}
	}

	/**
	 * Tells a site administrator why Site Commerce isn't doing anything —
	 * WooCommerce isn't active, so this plugin has deliberately stayed a
	 * no-op rather than fataling or half-registering commerce behavior
	 * against APIs that don't exist.
	 *
	 * Gated on `activate_plugins` (the conventional WordPress capability
	 * for "can act on plugin state") so only users who could actually
	 * install/activate WooCommerce see it — not every logged-in admin
	 * screen visitor.
	 */
	public static function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html__( 'Site Commerce is inactive: WooCommerce is not active.', 'site-commerce' )
		);
	}
}
