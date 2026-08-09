import { test, expect } from '@playwright/test';
import {
	addSimpleProductToCart,
	checkoutLocators,
	fillBlockCheckoutWithCod,
	placeOrderAndReadNumber,
	SIMPLE_PRODUCT_SLUG,
	VARIABLE_PRODUCT_SLUG,
} from './helpers/checkout';

/**
 * Commerce-profile e2e journeys. These run only when a project has enabled the
 * commerce profile (`bash scripts/enable-commerce`, which installs WooCommerce,
 * activates site-commerce, and creates the fixtures asserted below) AND opts in
 * via `COMMERCE=1`. The base starter ships the harness, never fake tests that
 * would skip silently or fail against a site with no WooCommerce.
 *
 * Run with: COMMERCE=1 npx playwright test tests/commerce/e2e
 *
 * The storefront runs on the NATIVE BLOCK store: scripts/enable-commerce seeds
 * the cart and checkout pages with WooCommerce's block content (and hard-fails
 * if either ever falls back to a shortcode), and the header Mini-Cart renders
 * from a site-header template-part override. The block Cart and Checkout
 * hydrate client-side, so controls are addressed by accessible role and label
 * text — the policy is stated in helpers/checkout.ts and its contract is
 * enforced by locators.spec.ts, which fails with a named locator the moment a
 * WooCommerce release renames a control.
 *
 * Fixtures (created by scripts/enable-commerce):
 *   - "Test Simple Product"   simple,   $19.99, in stock
 *   - "Test Variable Product" variable, Size S $24.99 / M $29.99
 *   - coupon TESTCOUPON        10% off
 *   - user test-customer / test-customer (role: customer)
 */

// Local-only credential created by scripts/enable-commerce (LOCAL/CI throwaway,
// never valid outside a freshly enabled commerce install).
const TEST_CUSTOMER = { user: 'test-customer', pass: 'test-customer' } as const;

function isMobileProject(): boolean {
	return test.info().project.name === 'chromium-mobile';
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

		// The derived single-product template embeds WooCommerce's legacy
		// product markup, which renders a second <main> (id="main" class="site-main")
		// nested inside the theme's; the theme main is first and contains it.
		await expect( page.locator( 'main' ).first() ).toContainText( '19.99' );
		await checkoutLocators.addToCart( page ).click();
		await expect( page.locator( 'main' ).first() ).toContainText( /added to (your|the) cart/i );

		await page.goto( '/cart/' );
		await expect( page.locator( 'main' ).first() ).toContainText( 'Test Simple Product' );
	} );

	test( 'variable product: selecting a variation updates the price, then adds', async ( { page } ) => {
		test.skip( isMobileProject(), 'desktop journey; the mobile project runs the checkout smoke only' );

		await page.goto( `/product/${ VARIABLE_PRODUCT_SLUG }/` );

		// Add-to-cart is gated until a variation is chosen.
		await expect( checkoutLocators.addToCart( page ) ).toHaveClass( /disabled/ );

		await page.locator( 'select#size' ).selectOption( 'M' );

		await expect( page.locator( '.woocommerce-variation-price' ) ).toContainText( '29.99' );
		await expect( checkoutLocators.addToCart( page ) ).not.toHaveClass( /disabled/ );

		await checkoutLocators.addToCart( page ).click();
		await expect( page.locator( '.woocommerce-message, .wc-block-components-notice-banner' ).first() ).toBeVisible();

		await page.goto( '/cart/' );
		await expect( page.locator( 'main' ).first() ).toContainText( 'Test Variable Product' );
	} );

	test( 'cart: updating quantity and applying TESTCOUPON lowers the total', async ( { page } ) => {
		test.skip( isMobileProject(), 'desktop journey; the mobile project runs the checkout smoke only' );

		await addSimpleProductToCart( page );
		await page.goto( '/cart/' );

		// Quantity 2 -> subtotal 2 x $19.99 = $39.98. The block cart pushes the
		// change through the Store API, so the totals take a moment to catch up.
		await page.getByLabel( /quantity/i ).first().fill( '2' );
		await expect( page.locator( 'main' ) ).toContainText( '39.98', { timeout: 15_000 } );

		// TESTCOUPON (10% off) adds a discount row and lowers the order total.
		await page.getByRole( 'button', { name: /add coupons?/i } ).first().click();
		await page.getByLabel( /enter code/i ).fill( 'TESTCOUPON' );
		await page.getByRole( 'button', { name: /apply/i } ).click();

		await expect( page.locator( 'main' ) ).toContainText( '35.98', { timeout: 15_000 } );
	} );

	test( 'checkout: a guest COD order reaches the order-received page', async ( { page } ) => {
		// This is also the mobile project's checkout smoke — it runs on every
		// configured project, proving the checkout selectors work on a mobile
		// viewport too.
		await addSimpleProductToCart( page );
		await page.goto( '/checkout/' );

		await fillBlockCheckoutWithCod( page, 'test-buyer@example.com' );
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

		// A returning customer's checkout can render the saved shipping address
		// as a summary instead of the editable fields (WooCommerce 11.0.0 block
		// checkout: toggling "Edit shipping address" leaves the form unable to
		// submit, so the summary is used as-is). A new customer gets the
		// editable form. Both states are legitimate; wait for hydration, then
		// branch on which one is showing.
		await expect( checkoutLocators.email( page ) ).toBeVisible( { timeout: 30_000 } );
		if ( await checkoutLocators.firstName( page ).isVisible() ) {
			await fillBlockCheckoutWithCod( page, 'test-customer@example.com' );
		} else {
			await expect( checkoutLocators.cashOnDelivery( page ) ).toBeVisible( { timeout: 30_000 } );
			await checkoutLocators.cashOnDelivery( page ).check();
		}
		const orderNumber = await placeOrderAndReadNumber( page );

		await page.goto( '/my-account/orders/' );
		await expect( page.locator( '.woocommerce-orders-table' ) ).toContainText( orderNumber );
	} );

	test( 'the block theme renders the shop archive inside the theme header and footer', async ( { page } ) => {
		test.skip( isMobileProject(), 'desktop journey; the mobile project runs the checkout smoke only' );

		await page.goto( '/shop/' );

		// The whole justification for templates/archive-product.html is that the
		// upstream template references header/footer parts this theme does not
		// have. Without the override these two assertions fail.
		await expect( page.locator( 'header' ).first() ).toBeVisible();
		await expect( page.locator( 'footer' ).first() ).toBeVisible();
	} );

	test( 'the header Mini-Cart reflects the cart contents', async ( { page } ) => {
		test.skip( isMobileProject(), 'desktop journey; the mobile project runs the checkout smoke only' );

		await page.goto( '/' );
		// Seeded by scripts/enable-commerce as a site-header template-part
		// override, so it is present on every storefront page.
		await expect( checkoutLocators.miniCart( page ) ).toBeVisible();
		// The Mini-Cart button's ACCESSIBLE NAME carries the count. Assert the
		// empty state too, so the "1" assertion below cannot pass vacuously.
		await expect( checkoutLocators.miniCart( page ) ).toHaveAccessibleName( /Number of items in the cart:\s*0\b/ );

		await addSimpleProductToCart( page );
		await page.goto( '/' );

		// One fixture product is in the cart. The Mini-Cart button's ACCESSIBLE
		// NAME carries the count, so assert the transition 0 -> 1 rather than the
		// button's visible text.
		await expect( checkoutLocators.miniCart( page ) ).toHaveAccessibleName( /Number of items in the cart:\s*1\b/ );
	} );
} );
