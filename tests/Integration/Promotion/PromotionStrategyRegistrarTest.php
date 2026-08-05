<?php
/**
 * The Release 3/Release 4 boundary in one file: the registrar adds exactly
 * the templates and template-parts strategies, and global-styles stays out
 * because no strategy is registered for it — even though the state track
 * classifies it promotable. The filter callback is a named method, never a
 * closure, and every registered strategy must be preparable.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\Promotion\PreparablePromotionStrategy;
use AgencyPlatform\State\Promotion\PromotionStrategyRegistrar;
use AgencyPlatform\State\PromotionStrategies;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\PromotionStrategyRegistrar
 */
final class PromotionStrategyRegistrarTest extends IntegrationTestCase {

	/**
	 * The registry memoises the filter result in a static, and the hook
	 * restoration of the test framework cannot see that static. Without a
	 * reset here, the strategies registered by these tests would leak into
	 * every later integration test that reads the registry.
	 */
	public function tear_down(): void {
		PromotionStrategies::reset();

		parent::tear_down();
	}

	public function test_release_three_registers_only_templates_and_template_parts(): void {
		PromotionStrategies::reset();
		( new PromotionStrategyRegistrar() )->register();

		self::assertSame( array( 'template-parts', 'templates' ), array_keys( PromotionStrategies::all() ) );
	}

	public function test_global_styles_has_no_strategy_in_release_three(): void {
		PromotionStrategies::reset();
		( new PromotionStrategyRegistrar() )->register();

		self::assertNull( PromotionStrategies::for_provider( 'global-styles' ) );
	}

	public function test_every_registered_strategy_is_preparable(): void {
		PromotionStrategies::reset();
		( new PromotionStrategyRegistrar() )->register();

		foreach ( PromotionStrategies::all() as $slug => $strategy ) {
			self::assertInstanceOf( PreparablePromotionStrategy::class, $strategy, "Strategy '{$slug}' must be preparable." );
		}
	}

	public function test_the_filter_callback_is_a_named_method_not_a_closure(): void {
		$callback = array( new PromotionStrategyRegistrar(), 'add_strategies' );

		self::assertIsCallable( $callback );
		self::assertNotInstanceOf( \Closure::class, $callback );
	}
}
