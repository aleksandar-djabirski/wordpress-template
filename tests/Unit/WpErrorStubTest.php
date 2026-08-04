<?php
/**
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WpErrorStubTest extends TestCase {

	public function test_error_data_is_available_from_the_third_constructor_argument(): void {
		$error = new \WP_Error( 'agency_error', 'Error message.', array( 'status' => 403 ) );

		self::assertSame( array( 'status' => 403 ), $error->get_error_data() );
	}
}
