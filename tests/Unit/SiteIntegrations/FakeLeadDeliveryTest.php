<?php

declare(strict_types=1);

namespace Tests\Unit\SiteIntegrations;

use PHPUnit\Framework\TestCase;
use Tests\Unit\SiteIntegrations\Doubles\LoggingFakeLeadDelivery;

/**
 * @covers \SiteIntegrations\LeadDelivery\FakeLeadDelivery
 */
final class FakeLeadDeliveryTest extends TestCase {

	public function test_always_reports_success(): void {
		$delivery = new LoggingFakeLeadDelivery();

		$result = $delivery->deliver(
			array(
				'name'    => 'Ada Lovelace',
				'email'   => 'ada@example.com',
				'message' => 'Hello there.',
			)
		);

		self::assertTrue( $result );
	}

	public function test_logs_a_single_line_containing_the_name_and_email_domain(): void {
		$delivery = new LoggingFakeLeadDelivery();

		$delivery->deliver(
			array(
				'name'    => 'Ada Lovelace',
				'email'   => 'ada@example.com',
				'message' => 'Hello there.',
			)
		);

		self::assertCount( 1, $delivery->logged );
		self::assertStringContainsString( 'Ada Lovelace', $delivery->logged[0] );
		self::assertStringContainsString( 'example.com', $delivery->logged[0] );
	}

	public function test_never_logs_the_full_email_address_or_the_message_text(): void {
		$delivery = new LoggingFakeLeadDelivery();

		$delivery->deliver(
			array(
				'name'    => 'Ada Lovelace',
				'email'   => 'ada@example.com',
				'message' => 'This is a private message body that must never be logged.',
			)
		);

		self::assertStringNotContainsString( 'ada@example.com', $delivery->logged[0] );
		self::assertStringNotContainsString( 'This is a private message body that must never be logged.', $delivery->logged[0] );
	}

	public function test_logs_the_message_length_instead_of_the_message_text(): void {
		$delivery = new LoggingFakeLeadDelivery();

		$delivery->deliver(
			array(
				'name'    => 'Ada',
				'email'   => 'ada@example.com',
				'message' => '12345',
			)
		);

		self::assertStringContainsString( '"message_length":5', $delivery->logged[0] );
	}

	public function test_the_logged_line_carries_the_documented_prefix(): void {
		$delivery = new LoggingFakeLeadDelivery();

		$delivery->deliver(
			array(
				'name'    => 'Ada',
				'email'   => 'ada@example.com',
				'message' => 'Hi',
			)
		);

		self::assertStringStartsWith( '[site-integrations] FAKE lead delivery (not sent): ', $delivery->logged[0] );
	}
}
