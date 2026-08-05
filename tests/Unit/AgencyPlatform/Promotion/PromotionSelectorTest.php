<?php
/**
 * The selector parser gates operator input on the strategy allow-list: a
 * provider with no registered promotion strategy is invalid input (exit 1)
 * — that single gate is what keeps global-styles out of Release 3 — and a
 * record missing from the bundle is refused the same way. Both registry and
 * bundle are injected as callables so the class stays WordPress-free.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionSelector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\PromotionSelector
 */
final class PromotionSelectorTest extends TestCase {

	/** @var callable(string):bool */
	private $has_strategy;

	/** @var callable(string):bool */
	private $has_record;

	protected function setUp(): void {
		parent::setUp();

		$this->has_strategy = static fn( string $slug ): bool => in_array( $slug, array( 'templates', 'template-parts' ), true );
		$this->has_record   = static fn( string $key ): bool => in_array( $key, array( 'templates:page', 'templates:home', 'template-parts:site-header' ), true );
	}

	public function test_it_parses_and_sorts_selectors(): void {
		$parsed = PromotionSelector::parse( 'templates:page,template-parts:site-header', $this->has_strategy, $this->has_record );

		self::assertSame( array( 'template-parts:site-header', 'templates:page' ), array_column( $parsed, 'key' ) );
	}

	public function test_a_provider_with_no_registered_strategy_is_a_hard_error(): void {
		// This is the Release 3 boundary in one assertion: global-styles is
		// promotable in Task 2 but has no strategy until Release 4.
		$exception = $this->assert_exit_code(
			1,
			fn() => PromotionSelector::parse( 'global-styles:active', $this->has_strategy, $this->has_record )
		);

		self::assertStringContainsString( 'global-styles', $exception->getMessage() );
		self::assertStringContainsString( 'No promotion strategy is registered', $exception->getMessage() );
	}

	public function test_an_unknown_provider_is_a_hard_error(): void {
		$exception = $this->assert_exit_code(
			1,
			fn() => PromotionSelector::parse( 'nope:page', $this->has_strategy, $this->has_record )
		);

		self::assertStringContainsString( 'nope', $exception->getMessage() );
		self::assertStringContainsString( 'No promotion strategy is registered', $exception->getMessage() );
	}

	public function test_a_record_missing_from_the_bundle_is_a_hard_error(): void {
		$exception = $this->assert_exit_code(
			1,
			fn() => PromotionSelector::parse( 'templates:absent', $this->has_strategy, $this->has_record )
		);

		self::assertStringContainsString( 'templates:absent', $exception->getMessage() );
	}

	public function test_a_malformed_selector_is_a_hard_error(): void {
		$exception = $this->assert_exit_code(
			1,
			fn() => PromotionSelector::parse( 'templates', $this->has_strategy, $this->has_record )
		);

		self::assertStringContainsString( '<provider>:<slug>', $exception->getMessage() );
	}

	public function test_duplicate_selectors_collapse(): void {
		$parsed = PromotionSelector::parse( 'templates:page,templates:page', $this->has_strategy, $this->has_record );

		self::assertCount( 1, $parsed );
	}

	/**
	 * Asserts that the operation refuses with exactly the expected exit code,
	 * and returns the exception so the caller can also assert the message.
	 * Fails when no exception is thrown, when a non-PromotionException
	 * escapes, or when the code differs.
	 *
	 * @param callable():mixed $operation
	 */
	private function assert_exit_code( int $expected, callable $operation ): PromotionException {
		try {
			$operation();
		} catch ( PromotionException $exception ) {
			self::assertSame(
				$expected,
				$exception->exit_code(),
				sprintf( 'Expected exit code %d, got %d: %s', $expected, $exception->exit_code(), $exception->getMessage() )
			);

			return $exception;
		}

		self::fail( sprintf( 'Expected a PromotionException with exit code %d, but no exception was thrown.', $expected ) );
	}
}
