<?php

declare(strict_types=1);

namespace Tests\Unit\SiteIntegrations;

use PHPUnit\Framework\TestCase;
use SiteCore\Contracts\LeadDelivery;
use SiteIntegrations\Plugin;

/**
 * Beyond the brief's three named test files: exercises Plugin::boot()'s
 * actual filter wiring, since "named callables only, no closures in
 * production hooks" is a binding, reviewed requirement — this is the one
 * place that requirement is directly machine-checked rather than just
 * eyeballed in review.
 *
 * @covers \SiteIntegrations\Plugin
 */
final class PluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		unset( $GLOBALS['_test_filters'], $GLOBALS['_test_env_type'] );
	}

	public function test_boot_registers_the_lead_delivery_filter_with_a_named_callable_not_a_closure(): void {
		Plugin::boot();

		$registered = $GLOBALS['_test_filters']['site_core_lead_delivery'][10][0]['callback'];

		self::assertIsArray( $registered, 'Expected a [$object, \'method\'] callable, not a Closure.' );
		self::assertNotInstanceOf( \Closure::class, $registered );
	}

	public function test_boot_wires_a_working_lead_delivery_resolution(): void {
		Plugin::boot();

		$delivery = apply_filters( 'site_core_lead_delivery', null );

		self::assertInstanceOf( LeadDelivery::class, $delivery );
	}
}
