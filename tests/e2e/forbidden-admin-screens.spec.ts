import { expect, test } from '@playwright/test';
import { CREDS, loginAs } from './helpers/auth';
import { adminUrl } from './helpers/wp';

/**
 * The admin-screen boundary from the editing model, driven through a real
 * browser. The client role can use the Site Editor, but cannot use legacy
 * design, plugin, or settings screens.
 */
/**
 * Every screen here must refuse a client_editor. They do NOT all refuse for
 * the same reason, and the difference is the whole point of this file.
 *
 * `policyIsSoleControl: true` means client_editor HOLDS the core capability
 * for that screen — `edit_theme_options`, granted deliberately so the role can
 * use the Site Editor — so WordPress core would happily SERVE it. Only
 * AgencyPlatform\Security\AdminScreenPolicy stands between the client and that
 * screen. If the policy regressed, core would return 200 and this test is the
 * only thing that would notice.
 *
 * `policyIsSoleControl: false` means the role lacks the core capability, so
 * core refuses on its own and the policy is belt-and-braces. Measured, not
 * assumed: client_editor lacks switch_themes, install_themes, edit_themes,
 * install_plugins, edit_plugins, customize and manage_options.
 *
 * Asserting the policy's exact wording on EVERY screen was wrong in both
 * directions. The original regex `/higher level of permission|not allowed/i`
 * accepted core's own denial, so it passed whether or not the policy ran at
 * all. Demanding the policy's text everywhere fails the six screens core
 * refuses first — which is correct behaviour, not a defect.
 */
const DENIED: Array< { screen: string; policyIsSoleControl: boolean } > = [
	{ screen: 'themes.php', policyIsSoleControl: false },
	{ screen: 'theme-install.php', policyIsSoleControl: false },
	{ screen: 'theme-editor.php', policyIsSoleControl: false },
	{ screen: 'plugin-install.php', policyIsSoleControl: false },
	{ screen: 'plugin-editor.php', policyIsSoleControl: false },
	{ screen: 'customize.php', policyIsSoleControl: false },
	{ screen: 'widgets.php', policyIsSoleControl: true },
	{ screen: 'nav-menus.php', policyIsSoleControl: true },
	{ screen: 'options-general.php', policyIsSoleControl: false },
	{ screen: 'options-permalink.php', policyIsSoleControl: false },
];

const DESKTOP_ONLY = 'wp-admin is not a mobile target';
const POLICY_DENIAL =
	'This screen is not part of the editing model for your role. Design changes belong in the Site Editor.';

for ( const { screen, policyIsSoleControl } of DENIED ) {
	test( `client_editor cannot reach ${ screen }`, async ( {
		page,
	}, testInfo ) => {
		test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );

		await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
		const response = await page.goto( adminUrl( screen ) );

		// Refused is non-negotiable for all ten.
		expect( response?.status(), screen ).toBe( 403 );

		if ( policyIsSoleControl ) {
			// Core would serve this screen. The policy's own wording is the
			// proof that the policy — not a capability check — did the refusing.
			await expect(
				page.getByText( POLICY_DENIAL, { exact: true } ),
				`${ screen } must be refused by AdminScreenPolicy, not by core`
			).toBeVisible();
		}
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
