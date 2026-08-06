<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\PromotionBackup;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionExitCode;
use PHPUnit\Framework\TestCase;

/**
 * The pure chunking and retention-parsing logic of the promotion backup
 * (BLOCK_THEME_PROPOSAL.md §7.9): split_payload() must cut a JSON payload
 * into fixed-size byte chunks whose reassembly is byte-identical to the
 * original, and parse_older_than() must translate the operator's "30d" /
 * "12h" / "3600" durations into seconds — refusing anything else loudly
 * with a hard error, never guessing.
 *
 * @covers \AgencyPlatform\State\Promotion\PromotionBackup
 */
final class BackupChunkingTest extends TestCase {

	public function test_a_small_payload_is_a_single_chunk(): void {
		self::assertSame( array( 'abc' ), PromotionBackup::split_payload( 'abc', 10 ) );
	}

	public function test_a_large_payload_splits_on_exact_byte_boundaries(): void {
		$chunks = PromotionBackup::split_payload( str_repeat( 'x', 25 ), 10 );

		self::assertCount( 3, $chunks );
		self::assertSame( 25, strlen( implode( '', $chunks ) ) );
		self::assertSame(
			str_repeat( 'x', 25 ),
			implode( '', $chunks ),
			'The reassembled chunks must equal the original payload byte for byte.'
		);
	}

	public function test_older_than_parsing(): void {
		self::assertSame( 2592000, PromotionBackup::parse_older_than( '30d' ) );
		self::assertSame( 43200, PromotionBackup::parse_older_than( '12h' ) );
		self::assertSame( 3600, PromotionBackup::parse_older_than( '3600' ) );
	}

	public function test_a_malformed_older_than_value_throws(): void {
		try {
			PromotionBackup::parse_older_than( 'soon' );

			self::fail( 'parse_older_than() must refuse a malformed duration.' );
		} catch ( PromotionException $exception ) {
			self::assertSame( PromotionExitCode::HARD_ERROR, $exception->exit_code() );
			self::assertStringContainsString( 'soon', $exception->getMessage() );
		}
	}
}
