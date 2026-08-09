import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../../e2e/helpers/auth';
import { adminUrl, expectNoAdminMenu } from '../../e2e/helpers/wp';
import {
	addSimpleProductToCart,
	fillBlockCheckoutWithCod,
	placeOrderAndReadNumber,
} from './helpers/checkout';

/**
 * Lean shop-manager wp-admin smoke for the commerce profile. Like its journey
 * sibling it runs only when the commerce profile is enabled
 * (`bash scripts/enable-commerce`, which creates the shop-manager user and the
 * fixtures asserted here) AND `COMMERCE=1` is set. It is deliberately DESKTOP
 * ONLY — wp-admin is not a mobile target — scoped with the same
 * `isMobileProject()` skip the journey suite uses.
 *
 * Scope is intentionally lean: full fulfillment/refund flows are deferred to
 * the first real store project. This proves the restricted `client_shop_manager`
 * role (created by scripts/enable-commerce; capabilities pinned by
 * tests/commerce/Integration/Permissions/ShopManagerCapabilitiesTest) can do
 * its day-to-day catalogue/order/coupon work AND that the agency lockdown still
 * holds around it.
 *
 * Auth reuses tests/e2e/helpers/auth.ts and the admin-menu assertion reuses
 * tests/e2e/helpers/wp.ts — both resolve across the shared `tests/` tree
 * (tsconfig includes the whole tests tree, Playwright testDir is ./tests), so
 * the cross-directory imports type-check and bundle cleanly.
 *
 * Credentials are the LOCAL-ONLY throwaway pair scripts/enable-commerce creates
 * (never valid outside a freshly enabled commerce install).
 */

const SHOP_MANAGER = { user: 'shop-manager', pass: 'shop-manager' } as const;

function isMobileProject(): boolean {
	return test.info().project.name === 'chromium-mobile';
}

/**
 * Places a guest Cash-on-Delivery order through the BLOCK storefront checkout
 * (the same accessible-role/label helpers the journey suite drives, from
 * helpers/checkout.ts) and returns the order number from the order-received
 * page. Used to seed a deterministic order for the orders-screen test WITHOUT
 * depending on the journey suite having run first — the whole point is that
 * this spec is self-contained.
 * @param page The Playwright page used for the storefront checkout.
 */
async function placeGuestCodOrder( page: Page ): Promise< string > {
	await addSimpleProductToCart( page );
	await page.goto( '/checkout/' );
	await fillBlockCheckoutWithCod( page, 'smoke-tester@example.com' );
	return placeOrderAndReadNumber( page );
}

/**
 * The numeric product id parsed from a products-list row's `<tr id="post-{id}">`.
 * Read off the row id rather than the title anchor's href so it does not depend
 * on how WordPress renders the edit link.
 * @param page  The Playwright page containing the products table.
 * @param title The product title to locate in the table row.
 */
async function productIdFromRow(
	page: Page,
	title: string
): Promise< string > {
	const trId = await page
		.locator( 'tr', {
			has: page.locator( 'a.row-title', { hasText: title } ),
		} )
		.first()
		.getAttribute( 'id' );

	return ( trId ?? '' ).replace( /\D/g, '' );
}

test.describe( 'shop-manager wp-admin smoke', () => {
	test.skip(
		process.env.COMMERCE !== '1',
		'commerce profile only — set COMMERCE=1 to run'
	);

	test.beforeEach( () => {
		test.skip(
			isMobileProject(),
			'desktop-only admin smoke — wp-admin is not a mobile target'
		);
		// The orders test does a full storefront checkout AND an admin note
		// round-trip; under parallel load that runs well past Playwright's 30s
		// default, so give the whole describe generous headroom.
		test.setTimeout( 90_000 );
	} );

	test( 'products list shows the fixtures and a published product opens for editing', async ( {
		page,
	} ) => {
		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		await page.goto( adminUrl( 'edit.php?post_type=product' ) );
		await expect(
			page.locator( 'a.row-title', { hasText: 'Test Simple Product' } )
		).toBeVisible();
		await expect(
			page.locator( 'a.row-title', { hasText: 'Test Variable Product' } )
		).toBeVisible();

		// Workflow proof: client_shop_manager is workflow-complete for the
		// catalogue — ShopRole grants `edit_published_products` +
		// `edit_others_products` (see ShopManagerCapabilitiesTest), so an
		// admin-authored, PUBLISHED fixture product opens in its editor rather
		// than 403-ing. Products use the CLASSIC editor in this WooCommerce
		// build, so the title field is input#title and must carry the fixture
		// name (that field being editable is the day-to-day price/stock edit the
		// project editing model promises a shop manager).
		const simpleId = await productIdFromRow( page, 'Test Simple Product' );
		expect( simpleId ).toMatch( /\d+/ );

		const response = await page.goto(
			adminUrl( `post.php?post=${ simpleId }&action=edit` )
		);
		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( 'input#title' ) ).toHaveValue(
			'Test Simple Product'
		);
	} );

	test( 'orders screen loads and a private order note round-trips', async ( {
		page,
	} ) => {
		// Deterministic precondition: place a guest COD order in THIS spec so the
		// assertion never depends on the journey suite having run first. The page
		// is logged out at this point, so it is a genuine guest checkout.
		const orderNumber = await placeGuestCodOrder( page );
		expect( orderNumber ).toMatch( /\d+/ );

		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		// HPOS orders admin lives at admin.php?page=wc-orders (verified live).
		await page.goto( adminUrl( 'admin.php?page=wc-orders' ) );
		await expect(
			page.locator( 'h1', { hasText: 'Orders' } ).first()
		).toBeVisible();
		const ordersTable = page.locator( 'table.wp-list-table' );
		await expect( ordersTable ).toBeVisible();
		await expect( ordersTable ).toContainText( orderNumber );

		// Open the order we just placed and add a PRIVATE note. The order-note
		// metabox's #order_note_type defaults to the empty value = "Private note",
		// so the default selection is already the private path.
		await page.goto(
			adminUrl(
				`admin.php?page=wc-orders&action=edit&id=${ orderNumber }`
			)
		);
		const note = `W2 smoke private note ${ Date.now() }`;
		await page.locator( '#add_order_note' ).fill( note );
		await page.locator( 'button.add_note' ).click();

		// The new note is prepended to the notes list via AJAX.
		await expect(
			page
				.locator( 'ul.order_notes li .note_content', { hasText: note } )
				.first()
		).toBeVisible();
	} );

	test( 'coupons screen lists the TESTCOUPON fixture', async ( { page } ) => {
		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		await page.goto( adminUrl( 'edit.php?post_type=shop_coupon' ) );
		// The coupon's post title renders lower-cased ("testcoupon"); the coupon
		// CODE applied at checkout is TESTCOUPON.
		await expect(
			page.locator( 'a.row-title', { hasText: /testcoupon/i } )
		).toBeVisible();
	} );

	test( 'wp-admin lockdown holds: no Plugins menu, and Appearance is replaced by Design', async ( {
		page,
	} ) => {
		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		await page.goto( adminUrl() );
		// Plugins stays fully hidden: client roles never get activate_plugins.
		await expectNoAdminMenu( page, 'menu-plugins' );

		// AdminScreenPolicy REMOVES the Appearance menu (its top-level target is
		// themes.php, a denied screen) and replaces it with a single Design entry
		// that links straight to the Site Editor. Same contract the base suite
		// pins in tests/e2e/editor-permissions.spec.ts.
		await expectNoAdminMenu( page, 'menu-appearance' );
		await expect(
			page.locator( '#adminmenu a[href$="site-editor.php"]' ).first()
		).toBeVisible();

		// No denied design screen is reachable from the menu at all.
		for ( const denied of [
			'themes.php',
			'theme-editor.php',
			'customize.php',
			'widgets.php',
			'nav-menus.php',
		] ) {
			await expect(
				page.locator( `#adminmenu a[href*="${ denied }"]` )
			).toHaveCount( 0 );
		}
	} );

	test( 'shop-manager CAN reach WooCommerce Settings (documented manage_woocommerce dial)', async ( {
		page,
	} ) => {
		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		const response = await page.goto(
			adminUrl( 'admin.php?page=wc-settings' )
		);

		// DOCUMENTED DIAL: client_shop_manager carries `manage_woocommerce` (core
		// shop_manager parity), which grants WooCommerce Settings access. This is
		// a deliberate, per-project dial (docs/editing-strictness.md → "Commerce
		// role dial"): a project that wants Settings locked removes that cap in
		// ShopRole. Pinning the CURRENT behavior makes this test break loudly if
		// the contract changes in EITHER direction.
		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( 'a.nav-tab' ).first() ).toBeVisible();
		await expect(
			page.getByText(
				/you do not have sufficient permissions|not allowed to access this page/i
			)
		).toHaveCount( 0 );
	} );

	test( 'shop-manager can open the Site Editor', async ( { page } ) => {
		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		const response = await page.goto( adminUrl( 'site-editor.php' ) );
		expect( response?.status() ).toBe( 200 );
		await expect(
			page.getByText(
				/you do not have sufficient permissions|not allowed to access this page/i
			)
		).toHaveCount( 0 );

		// The Site Editor canvas is an iframe; its presence is the proof the
		// editor booted rather than rendering a permissions error.
		await expect(
			page
				.locator(
					'iframe[name="editor-canvas"], .edit-site-visual-editor'
				)
				.first()
		).toBeVisible( { timeout: 30_000 } );
	} );

	test( 'shop-manager is still refused the theme installer and the theme file editor', async ( {
		page,
	} ) => {
		await loginAs( page, SHOP_MANAGER.user, SHOP_MANAGER.pass );

		for ( const denied of [
			'themes.php',
			'theme-editor.php',
			'customize.php',
		] ) {
			const response = await page.goto( adminUrl( denied ) );
			expect( response?.status(), denied ).toBe( 403 );
			await expect(
				page
					.getByText( /higher level of permission|not allowed/i )
					.first()
			).toBeVisible();
		}
	} );
} );
