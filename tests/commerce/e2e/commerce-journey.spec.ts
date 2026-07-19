import { test, expect, type Page } from '@playwright/test';

/**
 * Commerce-profile e2e journeys. These run only when a project has enabled the
 * commerce profile (`bash scripts/enable-commerce`, which installs WooCommerce,
 * activates site-commerce, and creates the fixtures asserted below) AND opts in
 * via `COMMERCE=1`. The base starter ships the harness, never fake tests that
 * would skip silently or fail against a site with no WooCommerce.
 *
 * Run with: COMMERCE=1 npx playwright test tests/commerce/e2e
 *
 * Selectors target WooCommerce's CLASSIC (shortcode) cart/checkout templates,
 * which scripts/enable-commerce switches the cart/checkout pages to. The block
 * Cart/Checkout render their fields client-side after hydration; the classic
 * templates are server-rendered, so their selectors are stable and the
 * automated checkout is deterministic. All selectors here were verified against
 * a live `scripts/enable-commerce` install, not from memory.
 *
 * Fixtures (created by scripts/enable-commerce):
 *   - "Test Simple Product"   simple,   $19.99, in stock
 *   - "Test Variable Product" variable, Size S $24.99 / M $29.99
 *   - coupon TESTCOUPON        10% off
 *   - user test-customer / test-customer (role: customer)
 */

const SIMPLE_PRODUCT_SLUG = 'test-simple-product';
const VARIABLE_PRODUCT_SLUG = 'test-variable-product';

// Local-only credential created by scripts/enable-commerce (LOCAL/CI throwaway,
// never valid outside a freshly enabled commerce install).
const TEST_CUSTOMER = { user: 'test-customer', pass: 'test-customer' } as const;

function isMobileProject(): boolean {
	return test.info().project.name === 'chromium-mobile';
}

/**
 * Puts one Test Simple Product in the cart via WooCommerce's add-to-cart query
 * parameter — a fast, reliable way to seed cart state without driving the PDP,
 * used by the cart/checkout journeys that are about what happens AFTER the cart
 * has an item.
 */
async function addSimpleProductToCart( page: Page ): Promise<void> {
	await page.goto( `/product/${ SIMPLE_PRODUCT_SLUG }/` );
	await page.locator( '.single_add_to_cart_button' ).click();
	// Classic single-product add-to-cart reloads to a success notice.
	await expect( page.locator( '.woocommerce-message, .wc-block-components-notice-banner' ).first() ).toBeVisible();
}

/**
 * Fills the classic checkout billing form with deterministic data and selects
 * Cash on Delivery. Country/state are set on the underlying <select> elements
 * (WooCommerce enhances them with select2, but selectOption drives the real
 * control), which also triggers the update_order_review AJAX that refreshes the
 * payment methods — so COD is checked after the fields are populated.
 */
async function fillCheckoutBillingWithCod( page: Page ): Promise<void> {
	await page.locator( '#billing_first_name' ).fill( 'Test' );
	await page.locator( '#billing_last_name' ).fill( 'Buyer' );
	await page.locator( '#billing_country' ).selectOption( 'US' );
	await page.locator( '#billing_address_1' ).fill( '123 Test Street' );
	await page.locator( '#billing_city' ).fill( 'Los Angeles' );
	await page.locator( '#billing_state' ).selectOption( 'CA' );
	await page.locator( '#billing_postcode' ).fill( '90001' );
	await page.locator( '#billing_phone' ).fill( '5550100' );
	await page.locator( '#billing_email' ).fill( 'test-buyer@example.invalid' );

	// COD lives in the payment panel that update_order_review re-renders; check
	// it last. Playwright waits out the .blockOverlay the AJAX refresh paints.
	await page.locator( '#payment_method_cod' ).check();
}

/**
 * Places the order and returns the WooCommerce order number from the
 * order-received (thank-you) page.
 */
async function placeOrderAndReadNumber( page: Page ): Promise<string> {
	await page.locator( '#place_order' ).click();

	await page.waitForURL( /order-received/ );
	await expect( page.locator( '.woocommerce-order' ) ).toBeVisible();

	const orderNumber = ( await page.locator( '.woocommerce-order-overview__order strong' ).innerText() ).trim();
	expect( orderNumber ).not.toEqual( '' );

	return orderNumber;
}

test.describe( 'commerce journeys', () => {
	test.skip( process.env.COMMERCE !== '1', 'commerce profile only — set COMMERCE=1 to run' );

	// These journeys are multi-step (login + AJAX checkout + order history), so
	// give them headroom over Playwright's 30s default — the account journey in
	// particular runs long on a loaded CI runner.
	test.beforeEach( () => {
		test.setTimeout( 60_000 );
	} );

	test( 'product archive lists the fixture products', async ( { page } ) => {
		test.skip( isMobileProject(), 'desktop journey; the mobile project runs the checkout smoke only' );

		await page.goto( '/shop/' );

		await expect( page.locator( '.woocommerce-loop-product__title', { hasText: 'Test Simple Product' } ) ).toBeVisible();
		await expect( page.locator( '.woocommerce-loop-product__title', { hasText: 'Test Variable Product' } ) ).toBeVisible();
	} );

	test( 'simple product: PDP renders price and adds to the cart', async ( { page } ) => {
		test.skip( isMobileProject(), 'desktop journey; the mobile project runs the checkout smoke only' );

		await page.goto( `/product/${ SIMPLE_PRODUCT_SLUG }/` );

		await expect( page.locator( 'p.price' ) ).toContainText( '19.99' );
		await page.locator( '.single_add_to_cart_button' ).click();
		await expect( page.locator( '.woocommerce-message, .wc-block-components-notice-banner' ).first() ).toContainText( /added to (your|the) cart/i );

		await page.goto( '/cart/' );
		await expect( page.locator( '.woocommerce-cart-form' ) ).toContainText( 'Test Simple Product' );
	} );

	test( 'variable product: selecting a variation updates the price, then adds', async ( { page } ) => {
		test.skip( isMobileProject(), 'desktop journey; the mobile project runs the checkout smoke only' );

		await page.goto( `/product/${ VARIABLE_PRODUCT_SLUG }/` );

		// Add-to-cart is gated until a variation is chosen.
		await expect( page.locator( '.single_add_to_cart_button' ) ).toHaveClass( /disabled/ );

		await page.locator( 'select#size' ).selectOption( 'M' );

		await expect( page.locator( '.woocommerce-variation-price' ) ).toContainText( '29.99' );
		await expect( page.locator( '.single_add_to_cart_button' ) ).not.toHaveClass( /disabled/ );

		await page.locator( '.single_add_to_cart_button' ).click();
		await expect( page.locator( '.woocommerce-message, .wc-block-components-notice-banner' ).first() ).toBeVisible();

		await page.goto( '/cart/' );
		await expect( page.locator( '.woocommerce-cart-form' ) ).toContainText( 'Test Variable Product' );
	} );

	test( 'cart: updating quantity and applying TESTCOUPON lowers the total', async ( { page } ) => {
		test.skip( isMobileProject(), 'desktop journey; the mobile project runs the checkout smoke only' );

		await addSimpleProductToCart( page );
		await page.goto( '/cart/' );

		// Quantity 2 -> subtotal 2 x $19.99 = $39.98.
		await page.locator( '.woocommerce-cart-form input.qty' ).first().fill( '2' );
		await page.locator( 'button[name="update_cart"]' ).click();
		await expect( page.locator( '.cart-subtotal' ) ).toContainText( '39.98' );

		// TESTCOUPON (10% off) adds a discount row and lowers the order total.
		await page.locator( '#coupon_code' ).fill( 'TESTCOUPON' );
		await page.locator( 'button[name="apply_coupon"]' ).click();

		await expect( page.locator( '.woocommerce-message' ) ).toContainText( /coupon code applied successfully/i );
		await expect( page.locator( '.cart-discount' ) ).toBeVisible();
		await expect( page.locator( '.order-total' ) ).toContainText( '35.98' );
	} );

	test( 'checkout: a guest COD order reaches the order-received page', async ( { page } ) => {
		// This is also the mobile project's checkout smoke — it runs on every
		// configured project, proving the checkout selectors work on a mobile
		// viewport too.
		await addSimpleProductToCart( page );
		await page.goto( '/checkout/' );

		await fillCheckoutBillingWithCod( page );
		const orderNumber = await placeOrderAndReadNumber( page );

		expect( orderNumber ).toMatch( /\d+/ );
		// MailGuard suppresses the WooCommerce order email outside production;
		// reaching this page proves the order completed anyway — the whole point
		// of MailGuard is that a suppressed email never blocks the transaction.
		await expect( page.getByText( /order has been received|thank you/i ).first() ).toBeVisible();
	} );

	test( 'account: a logged-in customer sees the order in their history', async ( { page } ) => {
		test.skip( isMobileProject(), 'account-history journey runs on desktop only (login race + mobile is a checkout smoke)' );

		// Log in first so the order is tied to the account, then check it out.
		await page.goto( '/wp/wp-login.php' );
		await page.locator( '#user_login' ).fill( TEST_CUSTOMER.user );
		await page.locator( '#user_pass' ).fill( TEST_CUSTOMER.pass );
		await page.locator( '#wp-submit' ).click();
		await expect( page ).not.toHaveURL( /wp-login\.php/ );

		await addSimpleProductToCart( page );
		await page.goto( '/checkout/' );
		await fillCheckoutBillingWithCod( page );
		const orderNumber = await placeOrderAndReadNumber( page );

		await page.goto( '/my-account/orders/' );
		await expect( page.locator( '.woocommerce-orders-table' ) ).toContainText( orderNumber );
	} );
} );
