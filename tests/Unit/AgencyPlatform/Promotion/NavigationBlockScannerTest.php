<?php
/**
 * The §7.4 count-and-order cross-check: scan_parsed() walks already-parsed
 * block trees and emits one entry per core/navigation block in document
 * order — the exact array shape NavigationPolicy::evaluate() consumes, so a
 * malformed export that silently drops a Navigation block can never pass
 * the source-side check. WordPress-free by design: it builds block trees by
 * hand, so nothing here may call a WordPress function.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\NavigationBlockScanner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\NavigationBlockScanner
 */
final class NavigationBlockScannerTest extends TestCase {

	public function test_it_finds_a_ref_less_navigation_block(): void {
		self::assertSame(
			array( array( 'ref' => null ) ),
			NavigationBlockScanner::scan_parsed(
				array(
					array(
						'blockName'   => 'core/navigation',
						'attrs'       => array(),
						'innerBlocks' => array(),
					),
				)
			)
		);
	}

	public function test_it_finds_nested_navigation_blocks_with_their_refs(): void {
		$blocks = array(
			array(
				'blockName'   => 'core/group',
				'attrs'       => array(),
				'innerBlocks' => array(
					array(
						'blockName'   => 'core/navigation',
						'attrs'       => array( 'ref' => 12 ),
						'innerBlocks' => array(),
					),
					array(
						'blockName'   => 'core/navigation',
						'attrs'       => array( 'ref' => 13 ),
						'innerBlocks' => array(),
					),
				),
			),
		);

		self::assertSame( array( array( 'ref' => 12 ), array( 'ref' => 13 ) ), NavigationBlockScanner::scan_parsed( $blocks ) );
	}

	public function test_a_record_with_no_navigation_blocks_scans_empty(): void {
		self::assertSame(
			array(),
			NavigationBlockScanner::scan_parsed(
				array(
					array(
						'blockName'   => 'core/paragraph',
						'attrs'       => array(),
						'innerBlocks' => array(),
					),
				)
			)
		);
	}

	public function test_a_non_integer_ref_is_reported_as_null(): void {
		self::assertSame(
			array( array( 'ref' => null ) ),
			NavigationBlockScanner::scan_parsed(
				array(
					array(
						'blockName'   => 'core/navigation',
						'attrs'       => array( 'ref' => '12' ),
						'innerBlocks' => array(),
					),
				)
			)
		);
	}
}
