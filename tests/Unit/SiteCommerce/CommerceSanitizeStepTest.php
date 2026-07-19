<?php

declare(strict_types=1);

namespace Tests\Unit\SiteCommerce;

use PHPUnit\Framework\TestCase;
use SiteCommerce\Health\CommerceSanitizeStep;

/**
 * Exercises the pure, database-free parts of the commerce sanitize step (the
 * meta-key -> replacement mapping and the registry hook), which is all that
 * can be verified without a live WooCommerce install. The SQL bodies are
 * proven only at runtime inside a WooCommerce environment; here we lock down
 * the mapping the classic-storage SQL mirrors, and that the step registers as
 * a named callable under a stable slug.
 *
 * @covers \SiteCommerce\Health\CommerceSanitizeStep
 */
final class CommerceSanitizeStepTest extends TestCase {

	public function test_order_email_is_deterministic(): void {
		self::assertSame( 'order_7@example.invalid', CommerceSanitizeStep::order_email( 7 ) );
	}

	public function test_customer_email_matches_the_base_user_email_shape(): void {
		// The registered-customer billing_email scrub reuses the base users
		// step's `user_{id}@example.invalid` shape so a customer's order and
		// account addresses land on the same synthetic domain.
		self::assertSame( 'user_7@example.invalid', CommerceSanitizeStep::customer_email( 7 ) );
	}

	public function test_order_blanked_meta_keys_are_order_level_non_address_metas(): void {
		$keys = CommerceSanitizeStep::order_blanked_meta_keys();

		self::assertContains( '_customer_ip_address', $keys );
		self::assertContains( '_customer_user_agent', $keys );
		self::assertContains( '_transaction_id', $keys );

		// These are order-level metas, never billing/shipping address keys
		// (which the address map above owns) — no overlap.
		foreach ( $keys as $key ) {
			self::assertDoesNotMatchRegularExpression( '/^_(billing|shipping)_/', $key );
		}
	}

	public function test_customer_blanked_meta_keys_are_only_billing_or_shipping_account_metas(): void {
		$keys = CommerceSanitizeStep::customer_blanked_meta_keys();

		self::assertContains( 'billing_first_name', $keys );
		self::assertContains( 'billing_phone', $keys );
		self::assertContains( 'shipping_address_1', $keys );

		// Account usermeta keys carry no leading underscore (unlike the per-order
		// postmeta) and are strictly billing_*/shipping_* — billing_email is
		// handled separately as a synthetic address, so it must NOT be blanked here.
		self::assertNotContains( 'billing_email', $keys );

		foreach ( $keys as $key ) {
			self::assertMatchesRegularExpression( '/^(billing|shipping)_/', $key );
		}
	}

	public function test_address_meta_replacements_maps_email_names_and_blanks(): void {
		$replacements = CommerceSanitizeStep::address_meta_replacements( 7 );

		self::assertSame( 'order_7@example.invalid', $replacements['_billing_email'] );

		self::assertSame( 'Sanitized', $replacements['_billing_first_name'] );
		self::assertSame( 'Sanitized', $replacements['_billing_last_name'] );
		self::assertSame( 'Sanitized', $replacements['_shipping_first_name'] );
		self::assertSame( 'Sanitized', $replacements['_shipping_last_name'] );

		self::assertSame( '', $replacements['_billing_phone'] );
		self::assertSame( '', $replacements['_billing_address_1'] );
		self::assertSame( '', $replacements['_shipping_city'] );
	}

	public function test_address_meta_replacements_only_touches_pii_meta_keys(): void {
		$keys = array_keys( CommerceSanitizeStep::address_meta_replacements( 1 ) );

		// A guardrail: only billing/shipping PII keys are in the map — nothing
		// like order totals, status, or line items that a sanitize must leave
		// intact.
		foreach ( $keys as $key ) {
			self::assertMatchesRegularExpression( '/^_(billing|shipping)_/', $key );
		}
	}

	public function test_append_step_registers_a_named_callable_under_a_stable_slug(): void {
		$steps = ( new CommerceSanitizeStep() )->append_step( array() );

		self::assertArrayHasKey( 'commerce', $steps );
		self::assertIsCallable( $steps['commerce'] );
		self::assertNotInstanceOf( \Closure::class, $steps['commerce'] );
		self::assertSame(
			array( CommerceSanitizeStep::class, 'sanitize_commerce' ),
			$steps['commerce']
		);
	}

	public function test_append_step_preserves_existing_steps(): void {
		$existing = array( 'users' => array( self::class, 'existing_step_fixture' ) );

		$steps = ( new CommerceSanitizeStep() )->append_step( $existing );

		self::assertArrayHasKey( 'users', $steps );
		self::assertArrayHasKey( 'commerce', $steps );
	}

	/**
	 * Named callable fixture with the sanitize-step signature — used only to
	 * prove append_step() preserves pre-existing registry entries.
	 *
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- matches the uniform step signature; this fixture does nothing.
	public static function existing_step_fixture( array $options ): array {
		return array();
	}
}
