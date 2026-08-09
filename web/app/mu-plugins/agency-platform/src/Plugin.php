<?php

declare(strict_types=1);

namespace AgencyPlatform;

use AgencyPlatform\Cli\AgencyCommands;
use AgencyPlatform\Editor\EditorRestrictions;
use AgencyPlatform\Editor\GlobalStylesGuard;
use AgencyPlatform\Editor\SaveValidation;
use AgencyPlatform\Environment\EnvironmentIndicator;
use AgencyPlatform\Roles\RolesProvider;
use AgencyPlatform\Roles\ShopRole;
use AgencyPlatform\Security\ApplicationPasswords;
use AgencyPlatform\Security\AdminScreenPolicy;
use AgencyPlatform\Security\CapabilityPolicy;
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
			new CapabilityPolicy(),
			new AdminScreenPolicy(),
			new EditorRestrictions(),
			new SaveValidation(),
			new GlobalStylesGuard(),
			new ApplicationPasswords(),
			new FileModGuard(),
			new MailGuard(),
			new AgencyCommands(),
			new \AgencyPlatform\State\StateSubsystem(),
			new \AgencyPlatform\State\Promotion\PromotionSubsystem(),
		);

		foreach ( $providers as $provider ) {
			$provider->register();
		}
	}
}
