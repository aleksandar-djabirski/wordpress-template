<?php
/**
 * The Release 4 registration contract in one file: the registrar adds the
 * templates, template-parts and global-styles strategies — Release 3
 * shipped the first two, Release 4 adds global-styles behind the Theme
 * JSON adapter. The filter callback is a named method, never a closure,
 * and every registered strategy must be preparable.
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

	public function test_release_four_registers_all_three_strategies(): void {
		PromotionStrategies::reset();
		( new PromotionStrategyRegistrar() )->register();

		self::assertSame( array( 'global-styles', 'template-parts', 'templates' ), array_keys( PromotionStrategies::all() ) );
	}

	public function test_global_styles_is_registered_as_a_preparable_strategy_in_release_four(): void {
		PromotionStrategies::reset();
		( new PromotionStrategyRegistrar() )->register();

		$strategy = PromotionStrategies::for_provider( 'global-styles' );

		self::assertNotNull( $strategy );
		self::assertInstanceOf( PreparablePromotionStrategy::class, $strategy );
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
