<?php
/**
 * Proves `wp agency check-overrides` reports drift instead of failing on it.
 * Database overrides are EXPECTED under the block-theme editing model — a
 * client saving a template in the Site Editor writes exactly the rows this
 * command reports — so only the explicit --fail-on-drift flag may produce a
 * non-zero exit.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Cli\AgencyCommands;
use PHPUnit\Framework\TestCase;

/**
 * @covers \\AgencyPlatform\Cli\AgencyCommands
 */
final class CheckOverridesReportTest extends TestCase {

	/**
	 * @param int $overrides
	 * @return array{overrides: array<int, array<string, mixed>>, expected: array<int, array<string, mixed>>, synced_patterns: array<int, array<string, mixed>>}
	 */
	private function report( int $overrides ): array {
		$rows = array();

		for ( $i = 0; $i < $overrides; $i++ ) {
			$rows[] = array(
				'post_type'   => 'wp_template',
				'post_name'   => 'page',
				'post_status' => 'publish',
			);
		}

		return array(
			'overrides'       => $rows,
			'expected'        => array(),
			'synced_patterns' => array(),
		);
	}

	public function test_drift_without_the_flag_is_not_a_failure(): void {
		self::assertFalse( AgencyCommands::drift_is_failure( $this->report( 3 ), false ) );
	}

	public function test_drift_with_the_flag_is_a_failure(): void {
		self::assertTrue( AgencyCommands::drift_is_failure( $this->report( 1 ), true ) );
	}

	public function test_no_drift_is_never_a_failure(): void {
		self::assertFalse( AgencyCommands::drift_is_failure( $this->report( 0 ), false ) );
		self::assertFalse( AgencyCommands::drift_is_failure( $this->report( 0 ), true ) );
	}

	public function test_the_summary_lists_every_bucket_and_every_override(): void {
		$lines = AgencyCommands::drift_summary_lines( $this->report( 2 ) );

		self::assertContains( 'Template/template-part overrides: 2', $lines );
		self::assertContains( '  - page (wp_template) [publish]', $lines );
		self::assertContains( 'Expected core-generated global-styles records: 0', $lines );
		self::assertContains( 'Synced patterns (informational only): 0', $lines );
	}
}
