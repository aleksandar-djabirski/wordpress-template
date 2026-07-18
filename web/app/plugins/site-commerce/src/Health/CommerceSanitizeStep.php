<?php

declare(strict_types=1);

namespace SiteCommerce\Health;

/**
 * Appends real WooCommerce PII anonymization to `wp agency sanitize`.
 *
 * Registered on the `agency_platform_sanitize_steps` filter from
 * SiteCommerce\Plugin::boot(), so this step only ever exists when WooCommerce
 * is active — the base sanitize registry knows nothing about commerce data.
 *
 * Scrubs both WooCommerce order storage backends:
 *   - Classic: `_billing_*` / `_shipping_*` postmeta on `shop_order` /
 *     `shop_order_refund` posts, plus pattern-deleted `_stripe_*` metas.
 *   - HPOS: the `wc_orders` / `wc_order_addresses` tables, each guarded by a
 *     SHOW TABLES existence check so this is a safe no-op on a classic-only
 *     store (and vice-versa).
 * It also clears the WooCommerce payment-token tables and the customer
 * `woocommerce_sessions` table when present.
 *
 * Every statement is idempotent (guarded by a `<>` WHERE clause or a DELETE),
 * so re-running sanitize makes no further changes and reports zero. This step
 * cannot be runtime-tested in this repo (no WooCommerce install), so the SQL
 * is defensive (existence-checked) and the meta-key -> replacement mapping is
 * split into a pure, unit-tested method (address_meta_replacements()).
 */
final class CommerceSanitizeStep {

	/**
	 * Address meta keys whose value is replaced with the literal 'Sanitized'.
	 *
	 * @var list<string>
	 */
	private const SANITIZED_NAME_META_KEYS = array(
		'_billing_first_name',
		'_billing_last_name',
		'_shipping_first_name',
		'_shipping_last_name',
	);

	/**
	 * Address meta keys whose value is blanked outright.
	 *
	 * @var list<string>
	 */
	private const BLANKED_META_KEYS = array(
		'_billing_company',
		'_billing_address_1',
		'_billing_address_2',
		'_billing_city',
		'_billing_state',
		'_billing_postcode',
		'_billing_phone',
		'_shipping_company',
		'_shipping_address_1',
		'_shipping_address_2',
		'_shipping_city',
		'_shipping_state',
		'_shipping_postcode',
	);

	/**
	 * Order post types whose PII is scrubbed under classic (postmeta) storage.
	 */
	private const ORDER_POST_TYPES = array( 'shop_order', 'shop_order_refund' );

	/**
	 * Wires this step onto the sanitize registry. Called only from
	 * SiteCommerce\Plugin::boot(), which runs only once WooCommerce is
	 * confirmed active.
	 */
	public function register(): void {
		add_filter( 'agency_platform_sanitize_steps', array( $this, 'append_step' ) );
	}

	/**
	 * Appends the commerce step to the ordered registry.
	 *
	 * @param array<string, callable> $steps
	 * @return array<string, callable>
	 */
	public function append_step( array $steps ): array {
		$steps['commerce'] = array( self::class, 'sanitize_commerce' );

		return $steps;
	}

	/**
	 * The commerce sanitize step. Scrubs classic postmeta, HPOS tables,
	 * payment tokens, and customer sessions; returns WP-CLI summary lines.
	 *
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $options is part of the uniform sanitize-step signature; the commerce step takes no per-run options.
	public static function sanitize_commerce( array $options ): array {
		global $wpdb;

		$order_types = "'" . implode( "','", self::ORDER_POST_TYPES ) . "'";

		// --- Classic storage: _billing_*/_shipping_* postmeta on orders. ---
		$classic_email = self::run(
			"UPDATE {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id "
			. "SET pm.meta_value = CONCAT('order_', p.ID, '@example.invalid') "
			. "WHERE p.post_type IN ({$order_types}) AND pm.meta_key = '_billing_email' "
			. "AND pm.meta_value <> CONCAT('order_', p.ID, '@example.invalid')"
		);

		$name_keys     = "'" . implode( "','", self::SANITIZED_NAME_META_KEYS ) . "'";
		$classic_names = self::run(
			"UPDATE {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id "
			. "SET pm.meta_value = 'Sanitized' "
			. "WHERE p.post_type IN ({$order_types}) AND pm.meta_key IN ({$name_keys}) "
			. "AND pm.meta_value <> 'Sanitized'"
		);

		$blank_keys     = "'" . implode( "','", self::BLANKED_META_KEYS ) . "'";
		$classic_blanks = self::run(
			"UPDATE {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id "
			. "SET pm.meta_value = '' "
			. "WHERE p.post_type IN ({$order_types}) AND pm.meta_key IN ({$blank_keys}) "
			. "AND pm.meta_value <> ''"
		);

		// Pattern-delete Stripe (and Stripe-token) metas. The LIKE underscores
		// are escaped (\_) so they match literal '_stripe_' prefixes, not the
		// single-character LIKE wildcard.
		$stripe_deleted = self::run(
			"DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id "
			. "WHERE p.post_type IN ({$order_types}) AND pm.meta_key LIKE '\\_stripe\\_%'"
		);

		$classic_changed = $classic_email + $classic_names + $classic_blanks + $stripe_deleted;

		// --- HPOS storage: wc_orders / wc_order_addresses (if present). ---
		$hpos_changed = self::scrub_hpos_orders() + self::scrub_hpos_addresses();

		// --- Payment tokens + customer sessions (if present). ---
		$payment_cleared = self::clear_table( $wpdb->prefix . 'woocommerce_payment_tokenmeta' )
			+ self::clear_table( $wpdb->prefix . 'woocommerce_payment_tokens' );

		$sessions_cleared = self::clear_table( $wpdb->prefix . 'woocommerce_sessions' );

		return array(
			sprintf( 'WooCommerce classic order PII rows scrubbed/deleted: %d.', $classic_changed ),
			sprintf( 'WooCommerce HPOS order/address rows scrubbed: %d.', $hpos_changed ),
			sprintf( 'WooCommerce payment token rows cleared: %d.', $payment_cleared ),
			sprintf( 'WooCommerce session rows cleared: %d.', $sessions_cleared ),
		);
	}

	/**
	 * Pure: the full meta-key -> replacement-value map for one order, mirroring
	 * exactly what the classic-storage SQL applies. Unit-testable without a
	 * database.
	 *
	 * @return array<string, string>
	 */
	public static function address_meta_replacements( int $order_id ): array {
		$replacements = array( '_billing_email' => self::order_email( $order_id ) );

		foreach ( self::SANITIZED_NAME_META_KEYS as $key ) {
			$replacements[ $key ] = 'Sanitized';
		}

		foreach ( self::BLANKED_META_KEYS as $key ) {
			$replacements[ $key ] = '';
		}

		return $replacements;
	}

	/**
	 * Pure: the synthetic replacement email for a given order ID.
	 */
	public static function order_email( int $order_id ): string {
		return sprintf( 'order_%d@example.invalid', $order_id );
	}

	/**
	 * Scrubs the HPOS `wc_orders` table's billing email, if the table exists.
	 */
	private static function scrub_hpos_orders(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_orders';

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		return self::run(
			"UPDATE `{$table}` SET billing_email = CONCAT('order_', id, '@example.invalid') "
			. "WHERE billing_email IS NOT NULL AND billing_email <> '' "
			. "AND billing_email <> CONCAT('order_', id, '@example.invalid')"
		);
	}

	/**
	 * Scrubs the HPOS `wc_order_addresses` table (names, email, and blanked
	 * address/phone fields), if the table exists.
	 */
	private static function scrub_hpos_addresses(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_order_addresses';

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		$email = self::run(
			"UPDATE `{$table}` SET email = CONCAT('order_', order_id, '@example.invalid') "
			. "WHERE email IS NOT NULL AND email <> '' "
			. "AND email <> CONCAT('order_', order_id, '@example.invalid')"
		);

		$names = self::run(
			"UPDATE `{$table}` SET first_name = 'Sanitized', last_name = 'Sanitized' "
			. "WHERE first_name <> 'Sanitized' OR last_name <> 'Sanitized'"
		);

		$blanks = self::run(
			"UPDATE `{$table}` SET company = '', address_1 = '', address_2 = '', city = '', "
			. "state = '', postcode = '', phone = '' "
			. "WHERE company <> '' OR address_1 <> '' OR address_2 <> '' OR city <> '' "
			. "OR state <> '' OR postcode <> '' OR phone <> ''"
		);

		return $email + $names + $blanks;
	}

	/**
	 * Deletes every row of $table when it exists; a no-op (returning 0)
	 * otherwise. Used for the payment-token and session tables, which carry
	 * no data worth preserving in a sanitized copy.
	 */
	private static function clear_table( string $table ): int {
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		return self::run( "DELETE FROM `{$table}`" );
	}

	/**
	 * True when $table exists in the current database. Guards every optional
	 * (HPOS / payment / session) table before it is touched.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off CLI schema probe for an optional table; $wpdb->prepare() escapes the LIKE argument.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return is_string( $found ) && $found === $table;
	}

	/**
	 * Runs one idempotent sanitize statement and returns the affected-row
	 * count. Centralizes the phpcs suppression for these deliberate raw
	 * queries: they are a one-off CLI operation, every table name comes from
	 * $wpdb, and the statements carry no external input.
	 */
	private static function run( string $sql ): int {
		global $wpdb;

		/** @var \wpdb $wpdb */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- see method docblock: one-off CLI sanitize, table names are $wpdb-owned, no external input.
		return (int) $wpdb->query( $sql );
	}
}
