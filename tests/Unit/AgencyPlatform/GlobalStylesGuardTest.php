<?php
/**
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Editor\GlobalStylesGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Editor\GlobalStylesGuard
 */
final class GlobalStylesGuardTest extends TestCase {

	public function test_a_global_styles_write_is_guarded(): void {
		foreach ( array( 'POST', 'PUT', 'PATCH', 'post' ) as $method ) {
			self::assertTrue( GlobalStylesGuard::is_global_styles_write( '/wp/v2/global-styles/12', $method ) );
		}
	}

	public function test_a_global_styles_read_is_not_guarded(): void {
		foreach ( array( 'GET', 'HEAD', 'OPTIONS' ) as $method ) {
			self::assertFalse( GlobalStylesGuard::is_global_styles_write( '/wp/v2/global-styles/12', $method ) );
		}
	}

	public function test_other_routes_are_not_guarded(): void {
		self::assertFalse( GlobalStylesGuard::is_global_styles_write( '/wp/v2/pages/12', 'POST' ) );
		self::assertFalse( GlobalStylesGuard::is_global_styles_write( '/wp/v2/templates', 'POST' ) );
	}
}
