<?php

declare(strict_types=1);

namespace AgencyPlatform;

use AgencyPlatform\Cli\AgencyCommands;
use AgencyPlatform\Editor\EditorRestrictions;
use AgencyPlatform\Editor\SiteEditorLockdown;
use AgencyPlatform\Environment\EnvironmentIndicator;
use AgencyPlatform\Roles\RolesProvider;
use AgencyPlatform\Roles\ShopRole;
use AgencyPlatform\Security\ApplicationPasswords;
use AgencyPlatform\Security\FileModGuard;
use AgencyPlatform\Security\MailGuard;

/**
 * Bootstraps every agency-platform guardrail.
 *
 * Each feature is its own small provider class with a `register()` method
 * that wires its own hooks. This class only knows the list of providers —
 * it holds no feature logic itself, so adding/removing a guardrail never
 * touches anything but this list and the provider's own file.
 */
final class Plugin {

	public static function boot(): void {
		$providers = array(
			new EnvironmentIndicator(),
			new RolesProvider(),
			new ShopRole(),
			new EditorRestrictions(),
			new SiteEditorLockdown(),
			new ApplicationPasswords(),
			new FileModGuard(),
			new MailGuard(),
			new AgencyCommands(),
		);

		foreach ( $providers as $provider ) {
			$provider->register();
		}
	}
}
