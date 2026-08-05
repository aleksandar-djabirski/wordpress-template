<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\PromotionStrategies;
use AgencyPlatform\State\PromotionStrategy;

/**
 * The Release 3/Release 4 boundary: registers the templates and
 * template-parts promotion strategies and nothing else. Global Styles is
 * classified promotable by the state track but has no strategy until
 * Release 4, so the allow-list here is what keeps it out of Release 3.
 */
final class PromotionStrategyRegistrar {

	/** Release 3 registers exactly these two slugs. Release 4 adds global-styles. */
	public const RELEASE_3_SLUGS = array( 'templates', 'template-parts' );

	/** Registers the strategies; the filter runs once per request through the registry's memoisation. */
	public function register(): void {
		add_filter( PromotionStrategies::FILTER, array( $this, 'add_strategies' ) );
	}

	/**
	 * Named filter callback — NEVER a closure (master spec §4).
	 *
	 * @param array<string, PromotionStrategy> $strategies
	 * @return array<string, PromotionStrategy>
	 */
	public function add_strategies( array $strategies ): array {
		$strategies['templates']      = new TemplatePromotionStrategy();
		$strategies['template-parts'] = new TemplatePartPromotionStrategy();

		return $strategies;
	}
}
