<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Health\SanitizeSteps;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Health\SanitizeSteps
 */
final class SanitizeStepsNotificationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		self::register_wordpress_test_doubles();

		$GLOBALS['_test_filters']                = array();
		$GLOBALS['_sanitize_test_notifications'] = array();
		$GLOBALS['_sanitize_test_updates']       = array();
		$GLOBALS['_sanitize_test_users']         = array(
			(object) array(
				'ID'            => 42,
				'user_email'    => 'real.person@example.com',
				'display_name'  => 'Real Person',
				'user_nicename' => 'real-person',
				'user_url'      => 'https://real.example.com',
			),
		);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_test_filters'],
			$GLOBALS['_sanitize_test_notifications'],
			$GLOBALS['_sanitize_test_updates'],
			$GLOBALS['_sanitize_test_users']
		);

		parent::tearDown();
	}

	public function test_user_email_change_notification_is_suppressed_only_during_user_mutation(): void {
		$report = SanitizeSteps::users( array() );

		self::assertSame(
			array(
				array(
					'filter_registered' => true,
					'notification_sent' => false,
				),
			),
			$GLOBALS['_sanitize_test_updates']
		);
		self::assertSame( array(), $GLOBALS['_sanitize_test_notifications'] );
		self::assertArrayNotHasKey( 'send_email_change_email', $GLOBALS['_test_filters'] );
		self::assertSame(
			array(
				'Sanitized email address on 1 non-administrator user(s).',
				'Sanitized display name + author slug (user_nicename) on 1 non-administrator user(s).',
				'Cleared user_url on 1 non-administrator user(s).',
				'Blanked profile meta (first/last name, nickname, description) on 0 non-administrator user(s).',
			),
			$report
		);
	}

	/**
	 * The unit bootstrap has no user mutation stubs. Define only the functions
	 * needed by this test in the production namespace.
	 */
	private static function register_wordpress_test_doubles(): void {
		if ( function_exists( 'AgencyPlatform\\Health\\get_users' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only namespace function doubles are required because the unit bootstrap has no user mutation stubs.
		eval(
			<<<'PHP'
namespace AgencyPlatform\Health;

/**
 * @return list<object>
 */
function get_users( array $args = array() ): array {
	return \Tests\Unit\AgencyPlatform\SanitizeStepsNotificationTest::get_users_fixture();
}

/**
 * @param array<string, mixed> $userdata
 */
function wp_update_user( array $userdata ): int {
	return \Tests\Unit\AgencyPlatform\SanitizeStepsNotificationTest::update_user_fixture( $userdata );
}

function get_user_meta( int $user_id, string $key = '', bool $single = false ): string {
	return '';
}

function update_user_meta( int $user_id, string $key, mixed $value ): bool {
	return true;
}

function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool {
	return \Tests\Unit\AgencyPlatform\SanitizeStepsNotificationTest::remove_filter_fixture( $hook_name, $callback, $priority );
}
PHP
		);
	}

	/**
	 * @return list<object>
	 */
	public static function get_users_fixture(): array {
		return $GLOBALS['_sanitize_test_users'];
	}

	/**
	 * @param array<string, mixed> $userdata
	 */
	public static function update_user_fixture( array $userdata ): int {
		$callbacks  = $GLOBALS['_test_filters']['send_email_change_email'][10] ?? array();
		$registered = array();

		foreach ( $callbacks as $filter ) {
			$registered[] = $filter['callback'];
		}

		$notification_allowed = apply_filters( 'send_email_change_email', true, array(), $userdata );

		$GLOBALS['_sanitize_test_updates'][] = array(
			'filter_registered' => ! empty( $registered ),
			'notification_sent' => $notification_allowed,
		);

		if ( $notification_allowed ) {
			$GLOBALS['_sanitize_test_notifications'][] = 'Email change notification sent.';
		}

		return (int) $userdata['ID'];
	}

	public static function remove_filter_fixture( string $hook_name, callable $callback, int $priority ): bool {
		if ( ! isset( $GLOBALS['_test_filters'][ $hook_name ][ $priority ] ) ) {
			return false;
		}

		$removed = false;

		foreach ( $GLOBALS['_test_filters'][ $hook_name ][ $priority ] as $index => $filter ) {
			if ( $filter['callback'] === $callback ) {
				unset( $GLOBALS['_test_filters'][ $hook_name ][ $priority ][ $index ] );
				$removed = true;
			}
		}

		if ( empty( $GLOBALS['_test_filters'][ $hook_name ][ $priority ] ) ) {
			unset( $GLOBALS['_test_filters'][ $hook_name ][ $priority ] );
		}

		if ( empty( $GLOBALS['_test_filters'][ $hook_name ] ) ) {
			unset( $GLOBALS['_test_filters'][ $hook_name ] );
		}

		return $removed;
	}
}
