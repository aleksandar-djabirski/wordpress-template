<?php
/**
 * The pure half of the site-UUID sanitize step: the decision of whether a
 * stored UUID marker forces regeneration (BLOCK_THEME_PROPOSAL.md §7.7).
 * sanitize_site_uuid() itself needs WordPress and the database, so it lives
 * in the integration suite; this suite pins the truth table so a production
 * UUID can never survive into a cloned non-production database, while a
 * second sanitize run changes nothing.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\SiteUuidSanitizeStep;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\SiteUuidSanitizeStep
 */
final class SiteUuidDecisionTest extends TestCase {

	public function test_a_production_uuid_seen_in_development_is_regenerated(): void {
		self::assertTrue( SiteUuidSanitizeStep::should_regenerate( 'production', 'development' ) );
	}

	public function test_a_second_run_after_regeneration_changes_nothing(): void {
		self::assertTrue( SiteUuidSanitizeStep::should_regenerate( 'production', 'staging' ) );
		self::assertFalse( SiteUuidSanitizeStep::should_regenerate( 'staging', 'staging' ) );
	}

	public function test_production_keeps_its_own_uuid(): void {
		self::assertFalse( SiteUuidSanitizeStep::should_regenerate( 'production', 'production' ) );
	}

	public function test_a_missing_marker_on_an_existing_uuid_is_regenerated_once(): void {
		self::assertTrue( SiteUuidSanitizeStep::should_regenerate( '', 'development' ) );
	}

	public function test_no_stored_uuid_means_nothing_to_regenerate(): void {
		self::assertFalse( SiteUuidSanitizeStep::should_regenerate( null, 'development' ) );
	}
}
