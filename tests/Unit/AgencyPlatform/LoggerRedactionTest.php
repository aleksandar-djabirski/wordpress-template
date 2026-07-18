<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Logging\Logger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Logging\Logger
 */
final class LoggerRedactionTest extends TestCase {

	public function test_redacts_a_password_key(): void {
		$redacted = Logger::redact( array( 'password' => 'hunter2' ) );

		self::assertSame( '[redacted]', $redacted['password'] );
	}

	public function test_redacts_key_variants_matching_the_sensitive_pattern(): void {
		$context = array(
			'pass'       => 'a',
			'password'   => 'b',
			'secret'     => 'c',
			'token'      => 'd',
			'api_key'    => 'e',
			'api-key'    => 'f',
			'apikey'     => 'g',
			'auth'       => 'h',
			'credential' => 'i',
		);

		$redacted = Logger::redact( $context );

		foreach ( array_keys( $context ) as $key ) {
			self::assertSame( '[redacted]', $redacted[ $key ], "Expected \"{$key}\" to be redacted." );
		}
	}

	public function test_redaction_is_case_insensitive(): void {
		$redacted = Logger::redact(
			array(
				'API_Key'  => 'abc123',
				'PASSWORD' => 'xyz',
			)
		);

		self::assertSame( '[redacted]', $redacted['API_Key'] );
		self::assertSame( '[redacted]', $redacted['PASSWORD'] );
	}

	public function test_redacts_sensitive_keys_inside_nested_arrays(): void {
		$redacted = Logger::redact(
			array(
				'user' => array(
					'name'     => 'Ada Lovelace',
					'password' => 'hunter2',
					'address'  => array(
						'city'  => 'London',
						'token' => 'r-123',
					),
				),
			)
		);

		self::assertSame( 'Ada Lovelace', $redacted['user']['name'] );
		self::assertSame( '[redacted]', $redacted['user']['password'] );
		self::assertSame( 'London', $redacted['user']['address']['city'] );
		self::assertSame( '[redacted]', $redacted['user']['address']['token'] );
	}

	public function test_a_key_that_itself_matches_the_pattern_is_fully_redacted_even_if_its_value_is_an_array(): void {
		// "tokens" contains the substring "token", so the whole value is
		// replaced outright rather than recursed into — recursion only
		// happens for keys that do NOT themselves match the pattern.
		$redacted = Logger::redact(
			array(
				'tokens' => array( 'refresh_token' => 'r-123' ),
			)
		);

		self::assertSame( '[redacted]', $redacted['tokens'] );
	}

	public function test_leaves_non_sensitive_keys_untouched(): void {
		$redacted = Logger::redact(
			array(
				'user_id' => 42,
				'channel' => 'leads',
				'count'   => 3,
			)
		);

		self::assertSame( 42, $redacted['user_id'] );
		self::assertSame( 'leads', $redacted['channel'] );
		self::assertSame( 3, $redacted['count'] );
	}

	public function test_returns_an_empty_array_unchanged(): void {
		self::assertSame( array(), Logger::redact( array() ) );
	}
}
