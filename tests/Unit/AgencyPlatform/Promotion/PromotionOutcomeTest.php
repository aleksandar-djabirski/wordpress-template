<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

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
