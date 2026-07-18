<?php

declare(strict_types=1);

namespace Tests\Unit\SiteIntegrations;

use PHPUnit\Framework\TestCase;
use SiteIntegrations\LeadDelivery\LeadWebhookConfig;
use SiteIntegrations\LeadDelivery\WebhookLeadDelivery;
use Tests\Unit\SiteIntegrations\Doubles\LoggingWebhookLeadDelivery;

/**
 * @covers \SiteIntegrations\LeadDelivery\WebhookLeadDelivery
 */
// putenv() is how this test drives LeadWebhookConfig's env-var fallback
// chain without WordPress or real .env files loaded; see the same disable in
// LeadDeliveryResolutionTest.php for why.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class WebhookLeadDeliveryTest extends TestCase {

	private const LEAD = array(
		'name'    => 'Ada Lovelace',
		'email'   => 'ada@example.com',
		'message' => 'Hello there.',
	);

	protected function setUp(): void {
		parent::setUp();

		unset( $GLOBALS['_test_remote_post_calls'], $GLOBALS['_test_remote_post_response'] );
		putenv( 'LEAD_WEBHOOK_URL=https://hooks.example.com/leads' );
	}

	protected function tearDown(): void {
		putenv( 'LEAD_WEBHOOK_URL' );
		unset( $GLOBALS['_test_remote_post_calls'], $GLOBALS['_test_remote_post_response'] );

		parent::tearDown();
	}

	public function test_a_2xx_response_is_reported_as_a_successful_delivery(): void {
		$GLOBALS['_test_remote_post_response'] = array( 'response' => array( 'code' => 200 ) );

		$result = ( new WebhookLeadDelivery( new LeadWebhookConfig() ) )->deliver( self::LEAD );

		self::assertTrue( $result );
	}

	public function test_a_404_response_is_reported_as_a_failed_delivery_and_logs_the_status_code(): void {
		$GLOBALS['_test_remote_post_response'] = array( 'response' => array( 'code' => 404 ) );

		$delivery = new LoggingWebhookLeadDelivery( new LeadWebhookConfig() );
		$result   = $delivery->deliver( self::LEAD );

		self::assertFalse( $result );
		self::assertCount( 1, $delivery->logged );
		self::assertStringContainsString( '404', $delivery->logged[0] );
		self::assertStringNotContainsString( self::LEAD['name'], $delivery->logged[0] );
		self::assertStringNotContainsString( self::LEAD['email'], $delivery->logged[0] );
	}

	public function test_a_wp_error_response_is_reported_as_a_failed_delivery_and_logs_the_error(): void {
		$GLOBALS['_test_remote_post_response'] = new \WP_Error( 'http_request_failed', 'Connection timed out' );

		$delivery = new LoggingWebhookLeadDelivery( new LeadWebhookConfig() );
		$result   = $delivery->deliver( self::LEAD );

		self::assertFalse( $result );
		self::assertCount( 1, $delivery->logged );
		self::assertStringContainsString( 'http_request_failed', $delivery->logged[0] );
		self::assertStringContainsString( 'Connection timed out', $delivery->logged[0] );
		self::assertStringNotContainsString( self::LEAD['name'], $delivery->logged[0] );
		self::assertStringNotContainsString( self::LEAD['email'], $delivery->logged[0] );
	}

	public function test_wp_remote_post_receives_the_configured_url_a_5_second_timeout_and_the_json_encoded_lead(): void {
		$GLOBALS['_test_remote_post_response'] = array( 'response' => array( 'code' => 200 ) );

		( new WebhookLeadDelivery( new LeadWebhookConfig() ) )->deliver( self::LEAD );

		self::assertCount( 1, $GLOBALS['_test_remote_post_calls'] );

		$call = $GLOBALS['_test_remote_post_calls'][0];

		self::assertSame( 'https://hooks.example.com/leads', $call['url'] );
		self::assertSame( 5, $call['args']['timeout'] );
		self::assertSame( 'application/json', $call['args']['headers']['Content-Type'] );
		self::assertSame( wp_json_encode( self::LEAD ), $call['args']['body'] );
	}

	public function test_defaults_to_a_new_lead_webhook_config_when_none_is_given(): void {
		$GLOBALS['_test_remote_post_response'] = array( 'response' => array( 'code' => 200 ) );

		( new WebhookLeadDelivery() )->deliver( self::LEAD );

		self::assertSame( 'https://hooks.example.com/leads', $GLOBALS['_test_remote_post_calls'][0]['url'] );
	}
}
// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
