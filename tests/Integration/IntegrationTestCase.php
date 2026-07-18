<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Shared base class for the `integration` PHPUnit suite. Every test here
 * runs against a real WordPress test install (see
 * tests/Integration/bootstrap.php) — WP_UnitTestCase gives each test its
 * own rolled-back database transaction plus the fixture factories the
 * make_*() helpers below build on.
 *
 * WP_UnitTestCase (via wp-phpunit -> PHPUnit Polyfills) uses snake_case
 * fixture method names — set_up()/tear_down(), not PHPUnit's own
 * setUp()/tearDown() — see
 * vendor/wp-phpunit/wp-phpunit/includes/abstract-testcase.php and
 * vendor/yoast/phpunit-polyfills/src/TestCases/TestCasePHPUnitGte8.php.
 * Subclasses that need their own fixture setup must override set_up()/
 * tear_down() (calling the parent method) for the same reason.
 */
abstract class IntegrationTestCase extends \WP_UnitTestCase {

	/**
	 * A user in this starter's `client_editor` role — the restricted
	 * customer-editor profile (see AgencyPlatform\Roles\RolesProvider).
	 */
	protected function make_client_editor(): \WP_User {
		$user_id = self::factory()->user->create( array( 'role' => 'client_editor' ) );

		return new \WP_User( $user_id );
	}

	/**
	 * A user in WordPress core's `administrator` role — the only role this
	 * starter's guardrails treat as fully privileged (`manage_options`).
	 */
	protected function make_admin(): \WP_User {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		return new \WP_User( $user_id );
	}
}
