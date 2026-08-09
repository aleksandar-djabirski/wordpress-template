import { expect, test } from '@playwright/test';

/**
 * Frontend smoke for the native block theme. Every selector below is produced
 * by block markup this repository owns:
 *  - header.site-header      -> parts/site-header.html
 *  - footer.site-footer      -> parts/site-footer.html
 *  - main#site-main          -> every templates/*.html main group's anchor
 *  - nav.wp-block-navigation -> core/navigation in site-header.html
 * The mobile overlay toggle class is core's own, not theme-authored.
 */

test( 'home page renders the standard chrome', async ( { page } ) => {
	const response = await page.goto( '/' );

	expect( response?.status() ).toBe( 200 );
	await expect( page.locator( 'header.site-header' ) ).toBeVisible();
	await expect(
		page.locator( '.site-header__branding .wp-block-site-title' )
	).toBeVisible();
	await expect( page.locator( 'footer.site-footer' ) ).toBeVisible();
	await expect( page.locator( 'main#site-main' ) ).toBeAttached();
} );

test( 'internal links on the home page all resolve', async ( {
	page,
	baseURL,
} ) => {
	await page.goto( '/' );

	const hrefs = await page.$$eval(
		'a[href]',
		( anchors, base ) => {
			// Compare URL origins. String-prefix matching would treat
			// https://base.evil.com as internal to https://base.com.
			let baseOrigin: string;
			try {
				baseOrigin = new URL( base ).origin;
			} catch {
				return [];
			}
			const internal = new Set< string >();
			for ( const anchor of anchors ) {
				const href = anchor.getAttribute( 'href' );
				if ( ! href ) {
					continue;
				}
				let origin: string;
				try {
					origin = new URL( href, base ).origin;
				} catch {
					continue;
				}
				if ( origin === baseOrigin ) {
					internal.add( href );
				}
			}
			return Array.from( internal );
		},
		baseURL ?? ''
	);

	for ( const href of hrefs.slice( 0, 20 ) ) {
		const response = await page.request.get( href );
		expect(
			response.status(),
			`expected ${ href } to respond < 400`
		).toBeLessThan( 400 );
	}
} );

test( 'desktop: the site navigation is visible without opening an overlay', async ( {
	page,
}, testInfo ) => {
	test.skip(
		testInfo.project.name !== 'chromium-desktop',
		'desktop-only: core collapses the navigation into an overlay on narrow viewports'
	);

	await page.goto( '/' );

	await expect(
		page.locator( 'header.site-header nav.wp-block-navigation' )
	).toBeVisible();
	await expect(
		page.locator( '.wp-block-navigation__responsive-container-open' )
	).toBeHidden();
} );

test( 'mobile: the navigation overlay opens and Escape closes it', async ( {
	page,
}, testInfo ) => {
	test.skip(
		testInfo.project.name !== 'chromium-mobile',
		"mobile-only: the navigation overlay toggle only renders below core's breakpoint"
	);

	await page.goto( '/' );

	const toggle = page.locator(
		'.wp-block-navigation__responsive-container-open'
	);
	const overlay = page.locator(
		'.wp-block-navigation__responsive-container'
	);

	await expect( toggle ).toBeVisible();
	await toggle.click();
	await expect( overlay ).toHaveClass( /is-menu-open/ );

	await page.keyboard.press( 'Escape' );
	await expect( overlay ).not.toHaveClass( /is-menu-open/ );
} );

test( 'the demo page renders every section', async ( { page } ) => {
	const response = await page.goto( '/demo/' );

	expect( response?.status() ).toBe( 200 );
	await expect(
		page.locator( 'main#site-main h1.wp-block-heading' )
	).toBeVisible();
	await expect(
		page.locator( 'main#site-main .wp-block-image img' )
	).toBeVisible();
	await expect(
		page.locator( 'main#site-main .reference-callout__heading' )
	).toBeVisible();
} );
