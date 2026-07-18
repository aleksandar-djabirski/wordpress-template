import { expect, test } from '@playwright/test';

/**
 * Baseline "does the site render at all" smoke checks, plus the mobile nav
 * toggle behavior (parts/site-header/site-header.php + .js + .css).
 *
 * Selectors used here come straight from the theme sources:
 *  - header.site-header, .site-header__site-title, .site-header__toggle,
 *    #site-header-nav — web/app/themes/site-theme/parts/site-header/site-header.php
 *  - footer.site-footer — web/app/themes/site-theme/parts/site-footer/site-footer.php
 *  - main#site-main — web/app/themes/site-theme/templates/index.php
 *  - the 782px breakpoint and `.is-open` toggle class — site-header.css / site-header.js
 */

test( 'home page renders the standard chrome', async ( { page } ) => {
	const response = await page.goto( '/' );

	expect( response?.status() ).toBe( 200 );
	await expect( page.locator( 'header.site-header' ) ).toBeVisible();
	await expect( page.locator( '.site-header__site-title' ) ).toBeVisible();
	await expect( page.locator( 'footer.site-footer' ) ).toBeVisible();
	await expect( page.locator( 'main#site-main' ) ).toBeAttached();
} );

test( 'internal links on the home page all resolve', async ( { page, baseURL } ) => {
	await page.goto( '/' );

	const hrefs = await page.$$eval(
		'a[href]',
		( anchors, base ) => {
			const internal = new Set<string>();
			for ( const anchor of anchors ) {
				const href = anchor.getAttribute( 'href' );
				if ( ! href ) {
					continue;
				}
				const isRelative = href.startsWith( '/' );
				const isAbsoluteInternal = base ? href.startsWith( base ) : false;
				if ( isRelative || isAbsoluteInternal ) {
					internal.add( href );
				}
			}
			return Array.from( internal );
		},
		baseURL ?? ''
	);

	const toCheck = hrefs.slice( 0, 20 );

	for ( const href of toCheck ) {
		const response = await page.request.get( href );
		expect( response.status(), `expected ${ href } to respond < 400` ).toBeLessThan( 400 );
	}
} );

test( 'desktop: primary nav is visible without opening the toggle', async ( { page }, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', 'desktop-only layout behavior' );

	await page.goto( '/' );

	await expect( page.locator( '#site-header-nav' ) ).toBeVisible();
	await expect( page.locator( '.site-header__toggle' ) ).toBeHidden();
} );

test( 'mobile: the toggle opens the nav and Escape closes it', async ( { page }, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-mobile', 'mobile-only toggle behavior' );

	await page.goto( '/' );

	const toggle = page.locator( '.site-header__toggle' );
	const nav = page.locator( '#site-header-nav' );

	await expect( toggle ).toBeVisible();
	await expect( nav ).toBeHidden();

	await toggle.click();
	await expect( nav ).toBeVisible();
	await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );

	await page.keyboard.press( 'Escape' );
	await expect( nav ).toBeHidden();
	await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
} );
