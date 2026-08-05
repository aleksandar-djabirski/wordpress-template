<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionExitCode;
use AgencyPlatform\State\Promotion\PromotionOutcome;
use AgencyPlatform\State\Promotion\RecordRefusal;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\PromotionOutcome
 */
final class PromotionOutcomeTest extends TestCase {

	public function test_all_successes_exit_zero(): void {
		self::assertSame( 0, ( new PromotionOutcome( array( 'templates:page' => 'promoted' ), array() ) )->exit_code() );
	}

	public function test_mixed_outcomes_exit_two(): void {
		$outcome = new PromotionOutcome(
			array(
				'templates:page'             => 'promoted',
				'template-parts:site-header' => 'refused',
			),
			array( $this->refusal( 'template-parts:site-header' ) )
		);

		self::assertSame( 2, $outcome->exit_code() );
	}

	public function test_every_record_refused_exits_one(): void {
		$outcome = new PromotionOutcome( array( 'templates:page' => 'refused' ), array( $this->refusal( 'templates:page' ) ) );

		self::assertSame( 1, $outcome->exit_code() );
	}

	public function test_skipped_records_count_as_successes(): void {
		self::assertSame( 0, ( new PromotionOutcome( array( 'templates:page' => 'skipped' ), array() ) )->exit_code() );
	}

	public function test_to_array_reports_every_refusal_field(): void {
		$array = ( new PromotionOutcome( array( 'templates:page' => 'refused' ), array( $this->refusal( 'templates:page' ) ) ) )->to_array();

		self::assertSame( 'unresolved-reference', $array['refusals'][0]['reasonCode'] );
		self::assertSame( 'core/image', $array['refusals'][0]['blockName'] );
		self::assertSame( 'id', $array['refusals'][0]['attribute'] );
		self::assertSame( '42', $array['refusals'][0]['referencedValue'] );
	}

	public function test_a_refusal_the_outcome_map_does_not_mark_is_a_hard_error(): void {
		// Without this guard the run exits 0 while the manifest reports a
		// refusal, because exit_code() reads only the outcome map.
		$this->expectException( PromotionException::class );
		$this->expectExceptionMessage( 'Refusal report names record(s) that are not marked refused: templates:page.' );

		new PromotionOutcome( array( 'templates:page' => 'promoted' ), array( $this->refusal( 'templates:page' ) ) );
	}

	public function test_a_refusal_the_outcome_map_does_not_mark_carries_exit_one(): void {
		try {
			new PromotionOutcome( array( 'templates:page' => 'promoted' ), array( $this->refusal( 'templates:page' ) ) );
			self::fail( 'Expected a PromotionException.' );
		} catch ( PromotionException $exception ) {
			self::assertSame( PromotionExitCode::HARD_ERROR, $exception->exit_code() );
		}
	}

	public function test_a_record_marked_refused_needs_a_report_entry(): void {
		$this->expectException( PromotionException::class );
		$this->expectExceptionMessage( 'Record(s) marked refused with no refusal report entry: templates:page.' );

		new PromotionOutcome( array( 'templates:page' => 'refused' ), array() );
	}

	public function test_one_record_may_carry_several_refusals(): void {
		$outcome = new PromotionOutcome(
			array(
				'templates:page'             => 'refused',
				'template-parts:site-header' => 'promoted',
			),
			array( $this->refusal( 'templates:page' ), $this->refusal( 'templates:page' ) )
		);

		self::assertSame( PromotionExitCode::PARTIAL_SUCCESS, $outcome->exit_code() );
		self::assertCount( 2, $outcome->refusals() );
	}

	public function test_an_unknown_outcome_is_a_hard_error_not_a_silent_success(): void {
		// 'failed' is not a known status. Counting every non-refused value as a
		// success turned a typo into exit 0.
		$this->expectException( PromotionException::class );
		$this->expectExceptionMessage( 'Unknown promotion outcome "failed" for record templates:page' );

		new PromotionOutcome( array( 'templates:page' => 'failed' ), array() );
	}

	public function test_every_named_success_status_counts_as_a_success(): void {
		$outcome = new PromotionOutcome(
			array(
				'templates:page'             => PromotionOutcome::OUTCOME_PREPARED,
				'templates:404'              => PromotionOutcome::OUTCOME_PROMOTED,
				'template-parts:site-header' => PromotionOutcome::OUTCOME_RESTORED,
				'template-parts:site-footer' => PromotionOutcome::OUTCOME_SKIPPED,
			),
			array()
		);

		self::assertSame( 4, $outcome->successes() );
		self::assertSame( PromotionExitCode::SUCCESS, $outcome->exit_code() );
	}

	public function test_an_empty_run_exits_zero(): void {
		self::assertSame( PromotionExitCode::SUCCESS, ( new PromotionOutcome( array(), array() ) )->exit_code() );
	}

	private function refusal( string $key ): RecordRefusal {
		list( $provider, $slug ) = explode( ':', $key, 2 );

		return new RecordRefusal(
			$key,
			$provider,
			$slug,
			'unresolved-reference',
			'Attachment ID 42 is environment-specific.',
			'core/image',
			'id',
			'42',
			'Replace the image with a theme asset, or exclude this record.'
		);
	}
}
