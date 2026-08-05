<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\StateException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\PromotionException
 */
final class PromotionExceptionTest extends TestCase {

	public function test_a_task_two_failure_keeps_its_exit_code(): void {
		self::assertSame( 4, PromotionException::from_state_exception( StateException::tamper( 'bad signature' ) )->exit_code() );
	}

	public function test_a_task_two_keyring_failure_stays_a_hard_error(): void {
		// A missing keyring is exit 1 in Task 2. Message-based mapping used to
		// turn it into exit 4 because the text contains hmac.
		self::assertSame(
			1,
			PromotionException::from_state_exception(
				StateException::hard_error( 'AGENCY_PROMOTION_HMAC_KEYS is not set' )
			)->exit_code()
		);
	}

	public function test_a_task_two_lock_conflict_keeps_exit_code_three(): void {
		self::assertSame(
			3,
			PromotionException::from_state_exception(
				new StateException( 'another promotion holds the lock', StateException::EXIT_LOCKED )
			)->exit_code()
		);
	}

	public function test_from_state_exception_chains_the_state_exception(): void {
		$state = StateException::tamper( 'bad signature' );

		self::assertSame( $state, PromotionException::from_state_exception( $state )->getPrevious() );
	}

	public function test_hard_factory_exits_one(): void {
		self::assertSame( 1, PromotionException::hard( 'boom' )->exit_code() );
	}

	public function test_tamper_factory_exits_four(): void {
		self::assertSame( 4, PromotionException::tamper( 'boom' )->exit_code() );
	}

	public function test_lock_conflict_factory_exits_three(): void {
		self::assertSame( 3, PromotionException::lock_conflict( 'boom' )->exit_code() );
	}
}
