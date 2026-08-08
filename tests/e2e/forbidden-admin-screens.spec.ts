import { expect, test } from '@playwright/test';
import { CREDS, loginAs } from './helpers/auth';
import { adminUrl } from './helpers/wp';

/**
 * The admin-screen boundary from the editing model, driven through a real
 * browser. The client role can use the Site Editor, but cannot use legacy
 * design, plugin, or settings screens.
 */
const DENIED = [
	'themes.php',
	'theme-install.php',
	'theme-editor.php',
	'plugin-install.php',
	'plugin-editor.php',
	'customize.php',
	'widgets.php',
	'nav-menus.php',
	'options-general.php',
	'options-permalink.php',
];

const DESKTOP_ONLY = 'wp-admin is not a mobile target';
const POLICY_DENIAL =
	'This screen is not part of the editing model for your role. Design changes belong in the Site Editor.';

for ( const screen of DENIED ) {
	test( `client_editor cannot reach ${ screen }`, async ( {
		page,
	}, testInfo ) => {
		test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );

		await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
		const response = await page.goto( adminUrl( screen ) );

		expect( response?.status(), screen ).toBe( 403 );
		await expect(
			page.getByText( POLICY_DENIAL, { exact: true } )
		).toBeVisible();
	} );
}

test( 'client_editor can reach the Site Editor', async ( {
	page,
}, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );

	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
	const response = await page.goto( adminUrl( 'site-editor.php' ) );

	expect( response?.status() ).toBe( 200 );
} );

test( 'control: an administrator reaches themes.php normally', async ( {
	page,
}, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );

	await loginAs( page, CREDS.admin.u, CREDS.admin.p );
	const response = await page.goto( adminUrl( 'themes.php' ) );

	expect( response?.status() ).toBe( 200 );
} );
