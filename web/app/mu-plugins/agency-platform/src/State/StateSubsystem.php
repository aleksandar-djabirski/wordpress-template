<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

use AgencyPlatform\Cli\StateCommands;

/**
 * The one provider class Plugin::boot() registers (a single array entry,
 * Task 13): the state command surface and the sanitize step. The sanitize
 * step registers unconditionally — not behind the WP-CLI guard — so the
 * agency_platform_sanitize_steps filter carries it whenever `wp agency
 * sanitize` runs, exactly like SiteCommerce\Health\CommerceSanitizeStep.
 */
final class StateSubsystem {

	public function register(): void {
		( new SiteUuidSanitizeStep() )->register();
		( new StateCommands() )->register();
	}
}
