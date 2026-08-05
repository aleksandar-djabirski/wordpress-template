<?php
/**
 * BLOCK_THEME_PROPOSAL.md §7.7: a production database cloned to staging or
 * local development carries the SAME agency_platform_site_uuid, which
 * weakens the AGENCY_TARGET_SITE_UUID protection promotion relies on. The
 * sanitize step regenerates it — but idempotently, so `wp agency sanitize`
 * keeps the idempotency contract every other step honours.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

use AgencyPlatform\Health\SanitizeSteps;
use AgencyPlatform\State\SiteIdentity;
use AgencyPlatform\State\SiteUuidSanitizeStep;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\SiteUuidSanitizeStep
 * @covers \AgencyPlatform\State\SiteIdentity
 */
final class SiteUuidSanitizeTest extends IntegrationTestCase {

	public function test_a_production_uuid_seen_outside_production_is_regenerated_once(): void {
		update_option( SiteIdentity::OPTION_UUID, '11111111-2222-4333-8444-555566667777', false );
		update_option( SiteIdentity::OPTION_ENVIRONMENT, 'production', false );

		SiteUuidSanitizeStep::sanitize_site_uuid( array() );

		$after_first = get_option( SiteIdentity::OPTION_UUID );

		self::assertNotSame( '11111111-2222-4333-8444-555566667777', $after_first, 'A production UUID must not survive into a non-production environment.' );
		self::assertSame( wp_get_environment_type(), get_option( SiteIdentity::OPTION_ENVIRONMENT ), 'The marker must record the environment the UUID was minted in.' );

		SiteUuidSanitizeStep::sanitize_site_uuid( array() );

		self::assertSame( $after_first, get_option( SiteIdentity::OPTION_UUID ), 'A second sanitize run must preserve the local UUID — sanitize is idempotent.' );
	}

	public function test_a_locally_minted_uuid_is_preserved(): void {
		$uuid = SiteIdentity::uuid();

		SiteUuidSanitizeStep::sanitize_site_uuid( array() );

		self::assertSame( $uuid, get_option( SiteIdentity::OPTION_UUID ) );
	}

	public function test_the_step_is_registered_on_the_sanitize_registry(): void {
		( new SiteUuidSanitizeStep() )->register();

		self::assertArrayHasKey( 'site_uuid', SanitizeSteps::steps(), 'The site-UUID step must reach `wp agency sanitize` through the agency_platform_sanitize_steps filter, not by editing AgencyCommands.' );
	}

	public function test_the_subsystem_registers_the_sanitize_step(): void {
		( new \AgencyPlatform\State\StateSubsystem() )->register();

		self::assertArrayHasKey( 'site_uuid', SanitizeSteps::steps() );
	}

	public function test_the_site_uuid_option_is_not_autoloaded(): void {
		SiteIdentity::uuid();

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only read of the autoload column, which no WordPress API exposes.
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", SiteIdentity::OPTION_UUID ) );

		self::assertNotSame( 'yes', $autoload, 'The site UUID is read only by CLI state commands; it must never be autoloaded on front-end requests.' );
	}
}
