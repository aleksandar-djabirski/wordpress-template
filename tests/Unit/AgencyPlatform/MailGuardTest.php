<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Security\MailGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Security\MailGuard
 */
final class MailGuardTest extends TestCase {

	/**
	 * Production is never suppressed, regardless of the override flag; every
	 * non-production environment is suppressed unless the override is set.
	 *
	 * @dataProvider suppression_cases
	 */
	public function test_should_suppress_is_exhaustive( string $environment, bool $allow_override, bool $expected ): void {
		self::assertSame( $expected, MailGuard::should_suppress( $environment, $allow_override ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool, 2: bool}>
	 */
	public static function suppression_cases(): array {
		return array(
			'production, no override'      => array( 'production', false, false ),
			'production, override'         => array( 'production', true, false ),
			'development, no override'     => array( 'development', false, true ),
			'development, override'        => array( 'development', true, false ),
			'staging, no override'         => array( 'staging', false, true ),
			'staging, override'            => array( 'staging', true, false ),
			'local, no override'           => array( 'local', false, true ),
			'local, override'              => array( 'local', true, false ),
			'custom non-prod, no override' => array( 'qa', false, true ),
			'custom non-prod, override'    => array( 'qa', true, false ),
		);
	}

	public function test_recipient_domains_from_a_single_string_address(): void {
		self::assertSame( array( 'example.com' ), MailGuard::recipient_domains( 'ada@example.com' ) );
	}

	public function test_recipient_domains_lowercases_and_strips_display_names(): void {
		self::assertSame( array( 'foo.com' ), MailGuard::recipient_domains( array( 'Ada Lovelace <ada@Foo.COM>' ) ) );
	}

	public function test_recipient_domains_splits_comma_separated_and_sorts_uniquely(): void {
		self::assertSame(
			array( 'x.com', 'y.com' ),
			MailGuard::recipient_domains( 'a@y.com, b@x.com' )
		);
	}

	public function test_recipient_domains_deduplicates(): void {
		self::assertSame(
			array( 'x.com' ),
			MailGuard::recipient_domains( array( 'a@x.com', 'b@x.com' ) )
		);
	}

	public function test_recipient_domains_ignores_entries_without_an_at_sign(): void {
		self::assertSame( array(), MailGuard::recipient_domains( 'not-an-email' ) );
	}

	public function test_recipient_domains_never_leaks_the_local_part(): void {
		$domains = MailGuard::recipient_domains( 'secret.person@example.com' );

		self::assertSame( array( 'example.com' ), $domains );
		self::assertNotContains( 'secret.person', $domains );
	}

	public function test_recipient_domains_handles_non_string_array_entries(): void {
		self::assertSame(
			array( 'example.com' ),
			MailGuard::recipient_domains( array( 'ada@example.com', 42, null ) )
		);
	}
}
