<?php
/**
 * EnvironmentConfig must read a PHP constant first and fall back to the
 * process environment, because this repository defines AGENCY_* settings
 * both ways: config/environments/*.php uses Config::define() (constants),
 * while Bedrock's dotenv loader registers a PutenvAdapter (getenv()).
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\EnvironmentConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\EnvironmentConfig
 */
final class EnvironmentConfigTest extends TestCase {

	public function test_resolve_prefers_the_constant_over_the_environment(): void {
		$resolved = EnvironmentConfig::resolve(
			array(
				'constant'    => 'from-constant',
				'environment' => 'from-environment',
			),
			'AGENCY_STATE_DIR'
		);

		self::assertSame( 'from-constant', $resolved );
	}

	public function test_resolve_falls_back_to_the_environment(): void {
		$resolved = EnvironmentConfig::resolve(
			array( 'environment' => 'from-environment' ),
			'AGENCY_STATE_DIR'
		);

		self::assertSame( 'from-environment', $resolved );
	}

	public function test_resolve_returns_null_when_neither_source_has_a_value(): void {
		self::assertNull( EnvironmentConfig::resolve( array(), 'AGENCY_STATE_DIR' ) );
	}

	public function test_resolve_treats_an_empty_string_as_absent(): void {
		self::assertNull(
			EnvironmentConfig::resolve(
				array(
					'constant'    => '',
					'environment' => '',
				),
				'AGENCY_STATE_DIR'
			)
		);
	}

	public function test_get_reads_a_real_defined_constant(): void {
		define( 'AGENCY_TEST_ONLY_STATE_SETTING', 'defined-value' );

		self::assertSame( 'defined-value', EnvironmentConfig::get( 'AGENCY_TEST_ONLY_STATE_SETTING' ) );
		self::assertTrue( EnvironmentConfig::has( 'AGENCY_TEST_ONLY_STATE_SETTING' ) );
	}

	public function test_get_returns_null_for_an_unknown_setting(): void {
		self::assertNull( EnvironmentConfig::get( 'AGENCY_TEST_ONLY_MISSING_SETTING' ) );
		self::assertFalse( EnvironmentConfig::has( 'AGENCY_TEST_ONLY_MISSING_SETTING' ) );
	}
}
