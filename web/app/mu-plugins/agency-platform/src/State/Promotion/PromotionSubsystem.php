<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\Cli\PromotionCommands;

/**
 * The promotion subsystem entry point, mirroring Task 2's StateSubsystem:
 * the strategy registrar registers ALWAYS — the strategies must exist for
 * web requests too, because finalize runs through whatever entry point the
 * operator uses — and the CLI surface only under WP-CLI.
 */
final class PromotionSubsystem {

	public function register(): void {
		( new PromotionStrategyRegistrar() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new PromotionCommands() )->register();
		}
	}
}
