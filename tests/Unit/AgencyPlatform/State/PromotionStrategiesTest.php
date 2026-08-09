<?php
/**
 * The promotion delegation point ships empty (Release 2 is export-and-diff
 * only, §13) and fills only through the agency_platform_promotion_strategies
 * filter — and anything the filter returns that is not a PromotionStrategy
 * is a hard error, never a silent skip. The provider side of the delegation
 * is covered through BaseStateProvider::promotion_strategy().
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\PromotionStrategies;
use AgencyPlatform\State\PromotionStrategy;
use AgencyPlatform\State\StateException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AgencyPlatform\State\Doubles\FakePromotionStrategy;
use Tests\Unit\AgencyPlatform\State\Doubles\FakeStateProvider;

/**
 * @covers \AgencyPlatform\State\PromotionStrategies
 * @covers \AgencyPlatform\State\BaseStateProvider
 */
final class PromotionStrategiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		PromotionStrategies::reset();
		$GLOBALS['_test_filters'] = array();
	}

	public function test_no_strategy_is_registered_by_default(): void {
		self::assertSame( array(), PromotionStrategies::all(), 'Release 2 is export-and-diff only; promotion behaviour arrives on the filter.' );
		self::assertNull( PromotionStrategies::for_provider( 'templates' ) );
	}

	public function test_a_filtered_strategy_is_resolved_by_provider_slug(): void {
		add_filter( PromotionStrategies::FILTER, array( self::class, 'append_fake_strategy' ) );
		PromotionStrategies::reset();

		self::assertInstanceOf( PromotionStrategy::class, PromotionStrategies::for_provider( 'templates' ) );
	}

	public function test_a_non_strategy_value_from_the_filter_is_rejected(): void {
		add_filter( PromotionStrategies::FILTER, array( self::class, 'append_garbage' ) );
		PromotionStrategies::reset();

		$this->expectException( StateException::class );

		PromotionStrategies::all();
	}

	public function test_a_provider_reports_no_strategy_until_one_is_registered(): void {
		$provider = new FakeStateProvider();

		self::assertNull( $provider->promotion_strategy() );
	}

	/**
	 * @param array<string, mixed> $strategies
	 * @return array<string, mixed>
	 */
	public static function append_fake_strategy( array $strategies ): array {
		$strategies['templates'] = new FakePromotionStrategy();

		return $strategies;
	}

	/**
	 * @param array<string, mixed> $strategies
	 * @return array<string, mixed>
	 */
	public static function append_garbage( array $strategies ): array {
		$strategies['broken'] = 'not-a-strategy';

		return $strategies;
	}
}
