<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The promotion subsystem entry point, mirroring Task 2's StateSubsystem:
 * the strategy registrar registers ALWAYS — the strategies must exist for
 * web requests too, because finalize runs through whatever entry point the
 * operator uses — and the CLI surface only under WP-CLI.
 */
final class PromotionSubsystem {

	public function register(): void {
		( new PromotionStrategyRegistrar() )->register();

		// Cli\PromotionCommands is created by a later task of this release;
		// the class_exists() guard keeps this reference safe in the
		// intermediate commits. WP-CLI reports an unknown command loudly, so
		// a missing class can never fail silently inside this subsystem.
		$promotion_commands_class = 'AgencyPlatform\\Cli\\PromotionCommands';

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( $promotion_commands_class ) ) {
			( new $promotion_commands_class() )->register();
		}
	}
}
