<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\PromotionSettings;
use PHPUnit\Framework\TestCase;

/**
 * PromotionSettings parses the operator-supplied tuning knobs that decide lock
 * lifetimes, backup retention and backup chunk sizes. A malformed value must
 * fall back to the documented default rather than to zero, and the fallback
 * must be observable — an unparsed value silently becoming 0 would make every
 * lock instantly reclaimable and every backup chunk empty.
 *
 * @covers \AgencyPlatform\State\Promotion\PromotionSettings
 */
// putenv() is how this test drives the AGENCY_PROMOTION_* settings through
// EnvironmentConfig's process-environment fallback without WordPress or real
// .env files loaded; WordPress's discouraged-function sniff would otherwise
// flag every call below. This matches StateDirectoryTest, which drives
// AGENCY_STATE_DIR the same way.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class PromotionSettingsTest extends TestCase {

	/** @var list<string> */
	private const MANAGED = array(
		PromotionSettings::LOCK_TTL,
		PromotionSettings::MUTEX_TTL,
		PromotionSettings::RETENTION_DAYS,
		PromotionSettings::CHUNK_BYTES,
		PromotionSettings::DEPLOYMENT_ID,
		PromotionSettings::VERIFICATION,
	);

	protected function setUp(): void {
		parent::setUp();

		$this->clear_managed_variables();
	}

	protected function tearDown(): void {
		$this->clear_managed_variables();

		parent::tearDown();
	}

	public function test_an_unset_integer_uses_the_default(): void {
		self::assertSame( 900, PromotionSettings::integer( PromotionSettings::LOCK_TTL, 900 ) );
	}

	public function test_an_all_digit_integer_is_read(): void {
		putenv( PromotionSettings::LOCK_TTL . '=120' );

		self::assertSame( 120, PromotionSettings::integer( PromotionSettings::LOCK_TTL, 900 ) );
	}

	/**
	 * Each of these would become 0 under a bare (int) cast, and 0 disables the
	 * protection the setting exists to provide.
	 *
	 * @dataProvider malformed_integers
	 */
	public function test_a_malformed_integer_falls_back_to_the_default( string $value ): void {
		putenv( PromotionSettings::CHUNK_BYTES . '=' . $value );

		self::assertSame( 500000, PromotionSettings::integer( PromotionSettings::CHUNK_BYTES, 500000 ) );
	}

	/** @return array<string, array{string}> */
	public static function malformed_integers(): array {
		return array(
			'words'          => array( 'lots' ),
			'negative'       => array( '-1' ),
			'decimal'        => array( '1.5' ),
			'trailing units' => array( '500kb' ),
			'leading space'  => array( ' 500' ),
			'hexadecimal'    => array( '0x1F' ),
		);
	}

	public function test_text_reads_a_value_and_falls_back_when_unset(): void {
		self::assertSame( 'fallback', PromotionSettings::text( PromotionSettings::DEPLOYMENT_ID, 'fallback' ) );

		putenv( PromotionSettings::DEPLOYMENT_ID . '=release-2026-08-05' );

		self::assertSame( 'release-2026-08-05', PromotionSettings::text( PromotionSettings::DEPLOYMENT_ID, 'fallback' ) );
	}

	public function test_a_blank_text_value_falls_back(): void {
		putenv( PromotionSettings::DEPLOYMENT_ID . '=   ' );

		self::assertSame( 'fallback', PromotionSettings::text( PromotionSettings::DEPLOYMENT_ID, 'fallback' ) );
	}

	public function test_flag_is_false_when_unset(): void {
		self::assertFalse( PromotionSettings::flag( PromotionSettings::DEPLOYMENT_ID ) );
	}

	/** @dataProvider truthy_flags */
	public function test_flag_accepts_the_documented_truthy_values( string $value ): void {
		putenv( PromotionSettings::DEPLOYMENT_ID . '=' . $value );

		self::assertTrue( PromotionSettings::flag( PromotionSettings::DEPLOYMENT_ID ) );
	}

	/** @return array<string, array{string}> */
	public static function truthy_flags(): array {
		return array(
			'one'        => array( '1' ),
			'true'       => array( 'true' ),
			'yes'        => array( 'yes' ),
			'mixed case' => array( 'TrUe' ),
			'padded'     => array( ' yes ' ),
		);
	}

	/** @dataProvider falsy_flags */
	public function test_flag_rejects_everything_else( string $value ): void {
		putenv( PromotionSettings::DEPLOYMENT_ID . '=' . $value );

		self::assertFalse( PromotionSettings::flag( PromotionSettings::DEPLOYMENT_ID ) );
	}

	/** @return array<string, array{string}> */
	public static function falsy_flags(): array {
		return array(
			'zero'     => array( '0' ),
			'false'    => array( 'false' ),
			'no'       => array( 'no' ),
			'on'       => array( 'on' ),
			'anything' => array( 'maybe' ),
		);
	}

	public function test_verification_commands_default_to_the_documented_pair(): void {
		self::assertSame(
			array( 'npm run test:e2e', 'npm run test:visual' ),
			PromotionSettings::verification_commands()
		);
	}

	public function test_verification_commands_split_trim_and_drop_empties(): void {
		putenv( PromotionSettings::VERIFICATION . '= npm run test:e2e , ,npm run test:parity, ' );

		self::assertSame(
			array( 'npm run test:e2e', 'npm run test:parity' ),
			PromotionSettings::verification_commands()
		);
	}

	private function clear_managed_variables(): void {
		foreach ( self::MANAGED as $name ) {
			putenv( $name );
		}
	}
}
