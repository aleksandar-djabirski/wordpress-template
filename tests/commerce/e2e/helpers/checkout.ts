import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Block Cart/Checkout helpers for the commerce journeys.
 *
 * scripts/enable-commerce seeds the cart and checkout pages with WooCommerce's
 * NATIVE BLOCK content and hard-fails if either ever falls back to a shortcode,
 * so these helpers drive the block UI the client actually gets.
 *
 * LOCATOR POLICY: address controls by accessible role and visible label, never
 * by a `wc-block-components-*` class. Those classes are private block internals
 * that change between releases; the accessible names are the public surface and
 * are what a customer (and a screen reader) actually uses. `verifyBlockCheckoutLocators()`
 * below is run by a dedicated spec against the live store, so a WooCommerce
 * release that renames a label fails loudly with a named locator instead of
 * producing a mystery timeout inside a journey.
 *
 * The block Cart and Checkout hydrate client-side, so every accessor waits for
 * the hydrated control rather than the server-rendered placeholder.
 */

export const SIMPLE_PRODUCT_SLUG = 'test-simple-product';
export const VARIABLE_PRODUCT_SLUG = 'test-variable-product';

/** One named locator per control the journeys drive. */
export const checkoutLocators = {
	addToCart: ( page: Page ): Locator =>
		page.getByRole( 'button', { name: /add to cart/i } ).first(),
	email: ( page: Page ): Locator =>
		page.getByLabel( /email address/i ).first(),
	firstName: ( page: Page ): Locator =>
		page.getByLabel( /first name/i ).first(),
	lastName: ( page: Page ): Locator =>
		page.getByLabel( /last name/i ).first(),
	country: ( page: Page ): Locator =>
		page.getByLabel( /country\s*\/\s*region/i ).first(),
	address: ( page: Page ): Locator => page.getByLabel( /^address/i ).first(),
	city: ( page: Page ): Locator => page.getByLabel( /city/i ).first(),
	state: ( page: Page ): Locator =>
		page.getByLabel( /state|province|county/i ).first(),
	postcode: ( page: Page ): Locator =>
		page.getByLabel( /postcode|zip/i ).first(),
	cashOnDelivery: ( page: Page ): Locator =>
		page.getByRole( 'radio', { name: /cash on delivery/i } ),
	placeOrder: ( page: Page ): Locator =>
		page.getByRole( 'button', { name: /place order/i } ),
	miniCart: ( page: Page ): Locator =>
		page
			.locator( 'header' )
			.getByRole( 'button', { name: /cart/i } )
			.first(),
} as const;

/**
 * Asserts every locator above resolves on the live store. Called by
 * tests/commerce/e2e/locators.spec.ts so a renamed label fails as a named
 * assertion rather than a timeout buried in a journey.
 * @param page The Playwright page that hosts the commerce storefront.
 */
export async function verifyBlockCheckoutLocators(
	page: Page
): Promise< void > {
	await page.goto( `/product/${ SIMPLE_PRODUCT_SLUG }/` );
	await expect( checkoutLocators.addToCart( page ) ).toBeVisible();
	await checkoutLocators.addToCart( page ).click();

	// The miniCart locator must stay scoped to the header. This page also
	// carries an "Add to cart" button, so the PAGE-WIDE /cart/i set has two
	// buttons while the HEADER-SCOPED set has exactly one — the header scope is
	// what disambiguates the Mini-Cart from the add-to-cart button. (The
	// shipped locator ends in .first(), so a count on the locator itself would
	// be vacuous; the raw sets are the contract.)
	await expect(
		page.locator( 'header' ).getByRole( 'button', { name: /cart/i } ),
		'block checkout locator "miniCart" did not resolve to exactly one header button — the header Mini-Cart is missing or the locator is no longer scoped to the header'
	).toHaveCount( 1 );
	await expect(
		page.getByRole( 'button', { name: /cart/i } ),
		'expected the product page to also carry an "Add to cart" button, so the miniCart scoping contract is meaningful — read the live DOM and update helpers/checkout.ts'
	).toHaveCount( 2 );

	await page.goto( '/checkout/' );
	for ( const name of [
		'email',
		'firstName',
		'lastName',
		'country',
		'address',
		'city',
		'state',
		'postcode',
	] as const ) {
		await expect(
			checkoutLocators[ name ]( page ),
			`block checkout locator "${ name }" did not resolve — read the live DOM and update helpers/checkout.ts`
		).toBeVisible( { timeout: 30_000 } );
	}

	// Visibility alone is not a contract: a control that is visible but not
	// usable would stay green here and then fail inside a journey. Country and
	// state are native <select> elements in this WooCommerce build, so
	// selectOption is the interaction to prove. If a release turns them into
	// comboboxes, the selectOption calls below throw with the locator named.
	await checkoutLocators.email( page ).fill( 'locator-contract@example.com' );
	await checkoutLocators.firstName( page ).fill( 'Test' );
	await checkoutLocators.lastName( page ).fill( 'Buyer' );
	await checkoutLocators.country( page ).selectOption( 'US' );
	await checkoutLocators.address( page ).fill( '123 Test Street' );
	await checkoutLocators.city( page ).fill( 'Los Angeles' );
	await checkoutLocators.state( page ).selectOption( 'CA' );
	await checkoutLocators.postcode( page ).fill( '90001' );

	await expect(
		checkoutLocators.cashOnDelivery( page ),
		'block checkout locator "cashOnDelivery" did not resolve — is the COD gateway enabled?'
	).toBeVisible( { timeout: 30_000 } );
	// COD must be checkable, not merely visible: the payment panel only offers
	// it once the shipping address above resolved.
	await checkoutLocators.cashOnDelivery( page ).check();
	await expect(
		checkoutLocators.placeOrder( page ),
		'block checkout locator "placeOrder" did not resolve'
	).toBeVisible();
}

export async function addSimpleProductToCart( page: Page ): Promise< void > {
	await page.goto( `/product/${ SIMPLE_PRODUCT_SLUG }/` );
	await checkoutLocators.addToCart( page ).click();
	// The header Mini-Cart reflects cart state on every page, so it is the
	// storefront-wide confirmation that the item landed.
	await expect( checkoutLocators.miniCart( page ) ).toBeVisible();
}

export async function fillBlockCheckoutWithCod(
	page: Page,
	email: string
): Promise< void > {
	await expect( checkoutLocators.email( page ) ).toBeVisible( {
		timeout: 30_000,
	} );

	await checkoutLocators.email( page ).fill( email );
	await checkoutLocators.firstName( page ).fill( 'Test' );
	await checkoutLocators.lastName( page ).fill( 'Buyer' );
	await checkoutLocators.country( page ).selectOption( 'US' );
	await checkoutLocators.address( page ).fill( '123 Test Street' );
	await checkoutLocators.city( page ).fill( 'Los Angeles' );
	await checkoutLocators.state( page ).selectOption( 'CA' );
	await checkoutLocators.postcode( page ).fill( '90001' );

	// The payment panel re-renders once the address resolves shipping, so the
	// Cash-on-Delivery option is chosen last.
	await checkoutLocators.cashOnDelivery( page ).check();
}

export async function placeOrderAndReadNumber( page: Page ): Promise< string > {
	await checkoutLocators.placeOrder( page ).click();

	await page.waitForURL( /order-received/ );

	// The order confirmation states the order number in its summary; read it
	// from the page text rather than a private class name.
	const summary = await page.locator( 'main' ).innerText();
	const match = summary.match( /(?:order number|order)\D{0,20}?(\d+)/i );

	expect(
		match,
		`could not read an order number from the confirmation page:\n${ summary }`
	).not.toBeNull();

	return ( match as RegExpMatchArray )[ 1 ];
}
