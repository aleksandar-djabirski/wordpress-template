<?php

declare(strict_types=1);

namespace Tests\Unit\SiteIntegrations;

use PHPUnit\Framework\TestCase;
use SiteCore\Contracts\LeadDelivery;
use SiteIntegrations\LeadDelivery\FakeLeadDelivery;
use SiteIntegrations\LeadDelivery\LeadDeliveryResolver;
use SiteIntegrations\LeadDelivery\WebhookLeadDelivery;

/**
 * The environment-safety core of the whole plugin: WebhookLeadDelivery (a
 * real outbound HTTP call) must be unreachable from any resolve() call
 * except the narrow production+configured+not-disabled case. Every other
 * branch — including "no delivery filter value yet" from a fresh
 * apply_filters(..., null) call — must fall back to FakeLeadDelivery.
 *
 * @covers \SiteIntegrations\LeadDelivery\LeadDeliveryResolver
 */
// putenv() is how this test drives LeadWebhookConfig's env-var fallback
// chain without WordPress or real .env files loaded; WordPress's discouraged
// -function sniff otherwise flags every call below.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class LeadDeliveryResolutionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		unset( $GLOBALS['_test_env_type'] );
		putenv( 'LEAD_WEBHOOK_URL' );
		unset( $_ENV['LEAD_WEBHOOK_URL'], $_SERVER['LEAD_WEBHOOK_URL'] );
	}

	protected function tearDown(): void {
		putenv( 'LEAD_WEBHOOK_URL' );
		unset( $_ENV['LEAD_WEBHOOK_URL'], $_SERVER['LEAD_WEBHOOK_URL'], $GLOBALS['_test_env_type'] );

		parent::tearDown();
	}

	public function test_development_resolves_fake_delivery(): void {
		$GLOBALS['_test_env_type'] = 'development';

		self::assertInstanceOf( FakeLeadDelivery::class, ( new LeadDeliveryResolver() )->resolve( null ) );
	}

	public function test_staging_resolves_fake_delivery(): void {
		$GLOBALS['_test_env_type'] = 'staging';
		putenv( 'LEAD_WEBHOOK_URL=https://hooks.example.com/leads' );

		self::assertInstanceOf( FakeLeadDelivery::class, ( new LeadDeliveryResolver() )->resolve( null ) );
	}

	public function test_local_resolves_fake_delivery(): void {
		$GLOBALS['_test_env_type'] = 'local';
		putenv( 'LEAD_WEBHOOK_URL=https://hooks.example.com/leads' );

		self::assertInstanceOf( FakeLeadDelivery::class, ( new LeadDeliveryResolver() )->resolve( null ) );
	}

	public function test_production_without_a_webhook_url_resolves_fake_delivery(): void {
		$GLOBALS['_test_env_type'] = 'production';

		self::assertInstanceOf( FakeLeadDelivery::class, ( new LeadDeliveryResolver() )->resolve( null ) );
	}

	public function test_production_with_a_whitespace_only_webhook_url_resolves_fake_delivery(): void {
		// A LEAD_WEBHOOK_URL of only whitespace is not a usable endpoint.
		// LeadWebhookConfig::url() trims every candidate before its empty-check,
		// so this must resolve to the safe FakeLeadDelivery rather than
		// constructing a WebhookLeadDelivery around a blank URL.
		$GLOBALS['_test_env_type'] = 'production';
		putenv( 'LEAD_WEBHOOK_URL=   ' );

		self::assertInstanceOf( FakeLeadDelivery::class, ( new LeadDeliveryResolver() )->resolve( null ) );
	}

	public function test_production_with_a_url_but_webhooks_disabled_resolves_fake_delivery(): void {
		// Exercises the "disabled" branch via the protected webhooks_disabled()
		// seam rather than actually define()-ing AGENCY_DISABLE_OUTBOUND_WEBHOOKS:
		// PHP constants can't be undefined once set, so defining it here would
		// leak `true` into every later test in this process (including the
		// "production+url+not-disabled -> Webhook" case below). See
		// LeadDeliveryResolver::webhooks_disabled()'s docblock, and the isolated
		// process test at the bottom of this file for coverage of the real
		// constant-reading default implementation.
		$GLOBALS['_test_env_type'] = 'production';
		putenv( 'LEAD_WEBHOOK_URL=https://hooks.example.com/leads' );

		$resolver = new class() extends LeadDeliveryResolver {
			protected function webhooks_disabled(): bool {
				return true;
			}
		};

		self::assertInstanceOf( FakeLeadDelivery::class, $resolver->resolve( null ) );
	}

	public function test_production_with_a_url_and_webhooks_not_disabled_resolves_webhook_delivery(): void {
		$GLOBALS['_test_env_type'] = 'production';
		putenv( 'LEAD_WEBHOOK_URL=https://hooks.example.com/leads' );

		self::assertInstanceOf( WebhookLeadDelivery::class, ( new LeadDeliveryResolver() )->resolve( null ) );
	}

	public function test_an_existing_lead_delivery_instance_is_returned_unchanged(): void {
		// Respect earlier-registered filters: if something upstream already
		// supplied a LeadDelivery, resolve() must not override it.
		$existing = new class() implements LeadDelivery {
			public function deliver( array $lead ): bool {
				return true;
			}
		};

		self::assertSame( $existing, ( new LeadDeliveryResolver() )->resolve( $existing ) );
	}

	/**
	 * Exercises the real (non-seam-overridden) default webhooks_disabled()
	 * implementation, which reads the AGENCY_DISABLE_OUTBOUND_WEBHOOKS
	 * constant. Isolated into its own process — see the docblock on
	 * test_production_with_a_url_but_webhooks_disabled_resolves_fake_delivery()
	 * above for why defining the constant can't happen in-process alongside
	 * the other tests here.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_real_disable_constant_blocks_webhook_delivery_when_defined_true(): void {
		define( 'AGENCY_DISABLE_OUTBOUND_WEBHOOKS', true );

		$GLOBALS['_test_env_type'] = 'production';
		putenv( 'LEAD_WEBHOOK_URL=https://hooks.example.com/leads' );

		self::assertInstanceOf( FakeLeadDelivery::class, ( new LeadDeliveryResolver() )->resolve( null ) );
	}
}
// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
