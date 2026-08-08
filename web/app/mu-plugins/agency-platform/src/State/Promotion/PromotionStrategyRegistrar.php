<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\PromotionStrategies;
use AgencyPlatform\State\PromotionStrategy;

/**
 * The Release 4 boundary: registers the templates, template-parts and
 * global-styles promotion strategies. Release 3 shipped the first two;
 * Release 4 adds global-styles behind the Theme JSON adapter, so the
 * allow-list here is what admits it to the selector and the finalizer.
 */
final class PromotionStrategyRegistrar {

	/** Release 3 shipped exactly these two slugs; Release 4 added global-styles. */
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
		$strategies['global-styles']  = new GlobalStylesPromotionStrategy( new ThemeJsonAdapter(), new StateGateway() );

		return $strategies;
	}
}
