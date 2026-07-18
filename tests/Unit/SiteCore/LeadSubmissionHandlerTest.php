<?php

declare(strict_types=1);

namespace Tests\Unit\SiteCore;

use PHPUnit\Framework\TestCase;
use SiteCore\Contracts\LeadDelivery;
use SiteCore\Leads\LeadSubmissionHandler;

/**
 * @covers \SiteCore\Leads\LeadSubmissionHandler
 */
final class LeadSubmissionHandlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Each test gets a clean filter registry: a site_core_lead_delivery
		// callback registered by one test must not leak into the next.
		unset( $GLOBALS['_test_filters'] );
	}

	public function test_rejects_an_invalid_email_with_a_field_error(): void {
		$result = ( new LeadSubmissionHandler() )->process(
			array(
				'name'    => 'Ada Lovelace',
				'email'   => 'not-an-email',
				'message' => 'Hello there.',
			)
		);

		self::assertFalse( $result['ok'] );
		self::assertArrayHasKey( 'email', $result['errors'] );
	}

	public function test_rejects_an_empty_name(): void {
		$result = ( new LeadSubmissionHandler() )->process(
			array(
				'name'    => '   ',
				'email'   => 'ada@example.com',
				'message' => 'Hello there.',
			)
		);

		self::assertFalse( $result['ok'] );
		self::assertArrayHasKey( 'name', $result['errors'] );
	}

	public function test_rejects_an_empty_message(): void {
		$result = ( new LeadSubmissionHandler() )->process(
			array(
				'name'    => 'Ada Lovelace',
				'email'   => 'ada@example.com',
				'message' => '',
			)
		);

		self::assertFalse( $result['ok'] );
		self::assertArrayHasKey( 'message', $result['errors'] );
	}

	public function test_fails_closed_when_no_delivery_filter_is_registered(): void {
		$result = ( new LeadSubmissionHandler() )->process(
			array(
				'name'    => 'Ada Lovelace',
				'email'   => 'ada@example.com',
				'message' => 'Hello there.',
			)
		);

		self::assertFalse( $result['ok'] );
		self::assertArrayHasKey( 'delivery', $result['errors'] );
	}

	public function test_delivers_a_valid_lead_through_the_registered_delivery_implementation(): void {
		$fake = new class() implements LeadDelivery {
			/** @var array{name: string, email: string, message: string}|null */
			public ?array $received = null;

			public function deliver( array $lead ): bool {
				$this->received = $lead;

				return true;
			}
		};

		add_filter(
			'site_core_lead_delivery',
			function () use ( $fake ) {
				return $fake;
			}
		);

		$result = ( new LeadSubmissionHandler() )->process(
			array(
				'name'    => '  Ada Lovelace  ',
				'email'   => 'ADA@Example.com',
				'message' => "Hello there.\nLooking forward to it.",
			)
		);

		self::assertSame( array( 'ok' => true ), $result );
		self::assertSame(
			array(
				'name'    => 'Ada Lovelace',
				'email'   => 'ADA@Example.com',
				'message' => "Hello there.\nLooking forward to it.",
			),
			$fake->received
		);
	}

	public function test_delivery_failure_is_reported_as_not_ok(): void {
		$fake = new class() implements LeadDelivery {
			public function deliver( array $lead ): bool {
				return false;
			}
		};

		add_filter(
			'site_core_lead_delivery',
			function () use ( $fake ) {
				return $fake;
			}
		);

		$result = ( new LeadSubmissionHandler() )->process(
			array(
				'name'    => 'Ada Lovelace',
				'email'   => 'ada@example.com',
				'message' => 'Hello there.',
			)
		);

		self::assertSame( array( 'ok' => false ), $result );
	}
}
