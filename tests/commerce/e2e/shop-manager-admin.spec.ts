import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../../e2e/helpers/auth';
import { adminUrl, expectNoAdminMenu } from '../../e2e/helpers/wp';

/**
 * Lean shop-manager wp-admin smoke for the commerce profile. Like its journey
 * sibling it runs only when the commerce profile is enabled
 * (`bash scripts/enable-commerce`, which creates the shop-manager user and the
 * fixtures asserted here) AND `COMMERCE=1` is set. It is deliberately DESKTOP
 * ONLY — wp-admin is not a mobile target — scoped with the same
 * `isMobileProject()` skip the journey suite uses.
 *
 * Scope is intentionally lean: full fulfillment/refund flows are deferred to
 * the first real store project. This proves the reduced `client_shop_manager`
 * role (created by scripts/enable-commerce; capabilities pinned by
 * tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest) can do
 * its day-to-day admin work AND that the agency lockdown still holds around it.
 *
 * Every selector below was verified against a live `scripts/enable-commerce`
 * store (WooCommerce 10.9, HPOS active, products in the CLASSIC editor), not
 * from memory. Auth reuses tests/e2e/helpers/auth.ts and the admin-menu
 * assertion reuses tests/e2e/helpers/wp.ts — both resolve across the shared
 * `tests/` tree (tsconfig includes the whole tests tree, Playwright testDir is
 * ./tests), so the cross-directory imports type-check and bundle cleanly.
 *
 * Credentials are the LOCAL-ONLY throwaway pair scripts/enable-commerce creates
 * (never valid outside a freshly enabled commerce install).
 */

const SHOP_MANAGER = { user: 'shop-manager', pass: 'shop-manager' } as const;
const SIMPLE_PRODUCT_SLUG = 'test-simple-product';

function isMobileProject(): boolean {
	return test.info().project.name === 'chromium-mobile';
}

/**
 * Places a guest Cash-on-Delivery order through the CLASSIC storefront checkout
 * (the same server-rendered selectors the journey suite drives) and returns the
 * order number from the order-received page. Used to seed a deterministic order
 * for the orders-screen test WITHOUT depending on the journey suite having run
 * first — the whole point is that this spec is self-contained.
 */
async function placeGuestCodOrder( page: Page ): Promise<string> {
	await page.goto( `/product/${ SIMPLE_PRODUCT_SLUG }/` );
	await page.locator( '.single_add_to_cart_button' ).click();
	await expect( page.locator( '.woocommerce-message, .wc-block-components-notice-banner' ).first() ).toBeVisible();

	await page.goto( '/checkout/' );
	await page.locator( '#billing_first_name' ).fill( 'Smoke' );
	await page.locator( '#billing_last_name' ).fill( 'Tester' );
	await page.locator( '#billing_country' ).selectOption( 'US' );
	await page.locator( '#billing_address_1' ).fill( '123 Test Street' );
	await page.locator( '#billing_city' ).fill( 'Los Angeles' );
	await page.locator( '#billing_state' ).selectOption( 'CA' );
	await page.locator( '#billing_postcode' ).fill( '90001' );
	await page.locator( '#billing_phone' ).fill( '5550100' );
	await page.locator( '#billing_email' ).fill( 'smoke-tester@example.invalid' );
	// COD lives in the payment panel update_order_review re-renders; check it
	// last (Playwright waits out the .blockOverlay the AJAX refresh paints).
	await page.locator( '#payment_method_cod' ).check();

	await page.locator( '#place_order' ).click();
	await page.waitForURL( /order-received/ );

	const raw = ( await page.locator( '.woocommerce-order-overview__order strong' ).innerText() ).trim();
	// HPOS numbers orders by id; strip any decoration so the value can address
	// the order-edit screen directly.
	return raw.replace( /\D/g, '' );
}

/**
 * The numeric product id parsed from a products-list row's `<tr id="post-{id}">`.
 * Needed because — see the products test — the reduced role renders the product
 * row title WITHOUT an edit-link href, so the id can't be read off the anchor.
 */
async function productIdFromRow( page: Page, title: string ): Promise<string> {
	const trId = await page
		.locator( 'tr', { has: page.locator( 'a.row-title', { hasText: title } ) } )
		.first()
		.getAttribute( 'id' );

	return ( trId ?? '' ).replace( /\D/g, '' );
}

test.describe( 'shop-manager wp-admin smoke', () => {
	test.skip( process.env.COMMERCE !== '1', 'commerce profile only — set COMMERCE=1 to run' );

	test.beforeEach( () => {
		test.skip( isMobileProject(), 'desktop-only admin smoke — wp-admin is not a mobile target' );
		// The orders test does a full storefront checkout AND an admin note
		// round-trip; under parallel load that runs well past Playwright's 30s
		// default, so give the whole describe generous headroom.
		test.setTimeout( 90_000 );
	} );

	test( 'products list shows the fixtures; editing a PUBLISHED product is refused for the reduced role', async ( { page } ) => {
		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		await page.goto( adminUrl( 'edit.php?post_type=product' ) );
		await expect( page.locator( 'a.row-title', { hasText: 'Test Simple Product' } ) ).toBeVisible();
		await expect( page.locator( 'a.row-title', { hasText: 'Test Variable Product' } ) ).toBeVisible();

		// DOCUMENTED CONTRACT: client_shop_manager is a REDUCED shop role
		// (client_editor + exactly nine WooCommerce caps — see
		// ShopManagerCapabilitiesTest). It deliberately OMITS
		// `edit_published_products`, so it can browse the catalogue but cannot
		// open a published product's editor: WordPress renders the row title
		// with an empty href and refuses a direct edit with a 403. Asserting
		// that boundary here makes the contract break loudly if the role's
		// product-editing scope ever changes (see docs/editing-strictness.md →
		// "Commerce role dial"). Products themselves use the CLASSIC editor in
		// this WooCommerce build (verified as admin: input#title carries the
		// fixture name) — the block product editor is not in play.
		const simpleId = await productIdFromRow( page, 'Test Simple Product' );
		expect( simpleId ).toMatch( /\d+/ );

		const response = await page.goto( adminUrl( `post.php?post=${ simpleId }&action=edit` ) );
		expect( response?.status() ).toBe( 403 );
		await expect( page.getByText( /not allowed to edit this item/i ) ).toBeVisible();
	} );

	test( 'orders screen loads and a private order note round-trips', async ( { page } ) => {
		// Deterministic precondition: place a guest COD order in THIS spec so the
		// assertion never depends on the journey suite having run first. The page
		// is logged out at this point, so it is a genuine guest checkout.
		const orderNumber = await placeGuestCodOrder( page );
		expect( orderNumber ).toMatch( /\d+/ );

		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		// HPOS orders admin lives at admin.php?page=wc-orders (verified live).
		await page.goto( adminUrl( 'admin.php?page=wc-orders' ) );
		await expect( page.locator( 'h1', { hasText: 'Orders' } ).first() ).toBeVisible();
		const ordersTable = page.locator( 'table.wp-list-table' );
		await expect( ordersTable ).toBeVisible();
		await expect( ordersTable ).toContainText( orderNumber );

		// Open the order we just placed and add a PRIVATE note. The order-note
		// metabox's #order_note_type defaults to the empty value = "Private note",
		// so the default selection is already the private path.
		await page.goto( adminUrl( `admin.php?page=wc-orders&action=edit&id=${ orderNumber }` ) );
		const note = `W2 smoke private note ${ Date.now() }`;
		await page.locator( '#add_order_note' ).fill( note );
		await page.locator( 'button.add_note' ).click();

		// The new note is prepended to the notes list via AJAX.
		await expect(
			page.locator( 'ul.order_notes li .note_content', { hasText: note } ).first()
		).toBeVisible();
	} );

	test( 'coupons screen lists the TESTCOUPON fixture', async ( { page } ) => {
		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		await page.goto( adminUrl( 'edit.php?post_type=shop_coupon' ) );
		// The coupon's post title renders lower-cased ("testcoupon"); the coupon
		// CODE applied at checkout is TESTCOUPON.
		await expect( page.locator( 'a.row-title', { hasText: /testcoupon/i } ) ).toBeVisible();
	} );

	test( 'wp-admin lockdown holds: no Plugins or Appearance menus', async ( { page } ) => {
		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		await page.goto( adminUrl() );
		// Same assertion the base editor-permissions suite makes for client_editor,
		// reused via tests/e2e/helpers/wp.ts: the agency lockdown that hides
		// Plugins and Appearance must hold for the shop manager too.
		await expectNoAdminMenu( page, 'menu-plugins' );
		await expectNoAdminMenu( page, 'menu-appearance' );
	} );

	test( 'shop-manager CAN reach WooCommerce Settings (documented manage_woocommerce dial)', async ( { page } ) => {
		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		const response = await page.goto( adminUrl( 'admin.php?page=wc-settings' ) );

		// DOCUMENTED DIAL: client_shop_manager carries `manage_woocommerce` (core
		// shop_manager parity), which grants WooCommerce Settings access. This is
		// a deliberate, per-project dial (docs/editing-strictness.md → "Commerce
		// role dial"): a project that wants Settings locked removes that cap in
		// ShopRole. Pinning the CURRENT behavior makes this test break loudly if
		// the contract changes in EITHER direction.
		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( 'a.nav-tab' ).first() ).toBeVisible();
		await expect(
			page.getByText( /you do not have sufficient permissions|not allowed to access this page/i )
		).toHaveCount( 0 );
	} );
} );
