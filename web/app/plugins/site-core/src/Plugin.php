<?php

declare(strict_types=1);

namespace SiteCore;

use SiteCore\Testimonials\TestimonialsProvider;

/**
 * Bootstraps every site-core feature.
 *
 * Each feature is its own small provider class with a `register()` method
 * that wires its own hooks (see AgencyPlatform\Plugin for the same
 * pattern) — this class only knows the list of providers.
 */
final class Plugin {

	public static function boot(): void {
		$providers = array(
			new TestimonialsProvider(),
		);

		foreach ( $providers as $provider ) {
			$provider->register();
		}
	}
}
