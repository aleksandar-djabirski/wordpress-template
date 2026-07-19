<?php
/**
 * The first runtime exercise of SiteCommerce\Health\CommerceSanitizeStep's SQL.
 *
 * R2 shipped this step's HPOS/payment-token SQL WITHOUT any live WooCommerce to
 * run it against — only the pure meta-map was unit-tested. This suite runs the
 * real thing: it creates a WooCommerce order carrying billing/shipping PII plus
 * a stored payment token in the HPOS tables (the commerce bootstrap enables
 * HPOS), runs the sanitize step, and asserts every PII column was anonymized
 * and the payment-token tables were cleared — reading the raw tables directly
 * (not the cached order API) so the assertions prove exactly what the SQL wrote.
 *
 * @package Tests\Commerce\Integration
 */

declare(strict_types=1);

namespace Tests\Commerce\Integration\Health;

use SiteCommerce\Health\CommerceSanitizeStep;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \SiteCommerce\Health\CommerceSanitizeStep
 */
final class CommerceSanitizeStepTest extends IntegrationTestCase {

	/**
	 * Real customer PII the order is seeded with — the values the sanitize step
	 * must scrub away. Deliberately realistic (a routable-looking email, a real
	 * phone shape) so a passing test means real PII was actually removed.
	 */
	private const PII = array(
		'first_name' => 'Jane',
		'last_name'  => 'Customer',
		'email'      => 'jane.customer@real-example.com',
		'phone'      => '+1-555-0100',
		'address_1'  => '742 Evergreen Terrace',
		'city'       => 'Springfield',
		'postcode'   => '12345',
		'company'    => 'Acme Real Company',
	);

	/**
	 * Creates a saved WooCommerce order carrying billing + shipping PII and
	 * returns its ID. With HPOS active (see the commerce bootstrap) the data
	 * lands in wc_orders / wc_order_addresses.
	 */
	private function create_order_with_pii(): int {
		$order = wc_create_order();

		$order->set_billing_first_name( self::PII['first_name'] );
		$order->set_billing_last_name( self::PII['last_name'] );
		$order->set_billing_email( self::PII['email'] );
		$order->set_billing_phone( self::PII['phone'] );
		$order->set_billing_company( self::PII['company'] );
		$order->set_billing_address_1( self::PII['address_1'] );
		$order->set_billing_city( self::PII['city'] );
		$order->set_billing_postcode( self::PII['postcode'] );

		$order->set_shipping_first_name( self::PII['first_name'] );
		$order->set_shipping_last_name( self::PII['last_name'] );
		$order->set_shipping_company( self::PII['company'] );
		$order->set_shipping_address_1( self::PII['address_1'] );
		$order->set_shipping_city( self::PII['city'] );
		$order->set_shipping_postcode( self::PII['postcode'] );

		$order->save();

		return $order->get_id();
	}

	/**
	 * Stores a real payment token (populating woocommerce_payment_tokens and
	 * woocommerce_payment_tokenmeta) and returns its ID.
	 */
	private function create_payment_token(): int {
		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'tok_real_secret_4242' );
		$token->set_gateway_id( 'stripe' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2035' );
		$token->set_card_type( 'visa' );
		$token->set_user_id( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$token->save();

		return $token->get_id();
	}

	public function test_hpos_is_the_active_order_store(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_orders';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test-only schema probe; the table name is derived from $wpdb->prefix.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		self::assertSame(
			$table,
			$found,
			'The commerce bootstrap must enable HPOS so the wc_orders table exists — the sanitize step HPOS branch is the whole point of this suite.'
		);
	}

	public function test_sanitize_anonymizes_hpos_order_pii_and_clears_payment_tokens(): void {
		global $wpdb;

		$order_id = $this->create_order_with_pii();
		$this->create_payment_token();

		$orders_table    = $wpdb->prefix . 'wc_orders';
		$addresses_table = $wpdb->prefix . 'wc_order_addresses';
		$tokens_table    = $wpdb->prefix . 'woocommerce_payment_tokens';
		$tokenmeta_table = $wpdb->prefix . 'woocommerce_payment_tokenmeta';

		// --- Pre-state: the PII really is in the HPOS tables. ---
		self::assertSame(
			self::PII['email'],
			$this->order_billing_email( $order_id ),
			'Guard: the order must start with the real billing email in wc_orders, or the scrub below proves nothing.'
		);
		self::assertGreaterThan( 0, $this->row_count( $tokens_table ), 'Guard: a payment token must exist before sanitize.' );
		self::assertGreaterThan( 0, $this->row_count( $tokenmeta_table ), 'Guard: payment token meta must exist before sanitize.' );

		$billing_before = $this->billing_address_row( $order_id );
		self::assertSame( self::PII['first_name'], $billing_before['first_name'] );
		self::assertSame( self::PII['email'], $billing_before['email'] );
		self::assertSame( self::PII['phone'], $billing_before['phone'] );

		// --- Run the step under test. ---
		$summary = CommerceSanitizeStep::sanitize_commerce( array() );

		self::assertIsArray( $summary );
		self::assertCount( 4, $summary, 'The step returns one summary line per data area (classic, HPOS, tokens, sessions).' );

		// --- Post-state: wc_orders billing email anonymized. ---
		self::assertSame(
			sprintf( 'order_%d@example.invalid', $order_id ),
			$this->order_billing_email( $order_id ),
			'wc_orders.billing_email must be replaced with the synthetic per-order address.'
		);

		// --- Post-state: wc_order_addresses billing row scrubbed. ---
		$billing_after = $this->billing_address_row( $order_id );
		self::assertSame( 'Sanitized', $billing_after['first_name'], 'billing first_name must be anonymized.' );
		self::assertSame( 'Sanitized', $billing_after['last_name'], 'billing last_name must be anonymized.' );
		self::assertSame(
			sprintf( 'order_%d@example.invalid', $order_id ),
			$billing_after['email'],
			'billing email in wc_order_addresses must be the synthetic per-order address.'
		);
		self::assertSame( '', $billing_after['phone'], 'billing phone must be blanked.' );
		self::assertSame( '', $billing_after['address_1'], 'billing address_1 must be blanked.' );
		self::assertSame( '', $billing_after['city'], 'billing city must be blanked.' );
		self::assertSame( '', $billing_after['postcode'], 'billing postcode must be blanked.' );
		self::assertSame( '', $billing_after['company'], 'billing company must be blanked.' );

		// --- Post-state: no PII survives anywhere in the addresses table. ---
		self::assertSame(
			0,
			$this->count_rows_matching_pii( $addresses_table ),
			'No original PII string may survive in wc_order_addresses after sanitize (shipping row included).'
		);

		// --- Post-state: payment tokens fully cleared. ---
		self::assertSame( 0, $this->row_count( $tokens_table ), 'woocommerce_payment_tokens must be cleared.' );
		self::assertSame( 0, $this->row_count( $tokenmeta_table ), 'woocommerce_payment_tokenmeta must be cleared.' );
	}

	public function test_sanitize_is_idempotent(): void {
		global $wpdb;

		$order_id = $this->create_order_with_pii();

		CommerceSanitizeStep::sanitize_commerce( array() );

		$first_pass_email = $this->order_billing_email( $order_id );

		// A second run must change nothing and report zero HPOS rows scrubbed.
		$summary = CommerceSanitizeStep::sanitize_commerce( array() );

		self::assertSame(
			$first_pass_email,
			$this->order_billing_email( $order_id ),
			'A second sanitize run must leave the already-anonymized email unchanged.'
		);
		self::assertContains(
			'WooCommerce HPOS order/address rows scrubbed: 0.',
			$summary,
			'The second run must report zero HPOS rows scrubbed — proof the guarded WHERE clauses are idempotent.'
		);
	}

	private function order_billing_email( int $order_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test-only read; table name derived from $wpdb->prefix, order id is bound.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT billing_email FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id ) );
	}

	/**
	 * @return array<string, string>
	 */
	private function billing_address_row( int $order_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test-only read; table name derived from $wpdb->prefix, order id is bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT first_name, last_name, email, phone, address_1, city, postcode, company FROM {$wpdb->prefix}wc_order_addresses WHERE order_id = %d AND address_type = 'billing'", $order_id ), ARRAY_A );

		return is_array( $row ) ? array_map( 'strval', $row ) : array();
	}

	private function row_count( string $table ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test-only count; table name derived from $wpdb->prefix.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	/**
	 * Counts rows in the addresses table where any column still contains one of
	 * the original PII values — a belt-and-braces check that nothing leaked.
	 */
	private function count_rows_matching_pii( string $table ): int {
		global $wpdb;

		$needles = array( self::PII['first_name'], self::PII['last_name'], self::PII['email'], self::PII['phone'], self::PII['address_1'], self::PII['company'] );
		$total   = 0;

		foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'address_1', 'city', 'postcode', 'company' ) as $column ) {
			foreach ( $needles as $needle ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- test-only leak check; column list is a fixed allow-list, value is bound.
				$total += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = %s", $needle ) );
			}
		}

		return $total;
	}
}
