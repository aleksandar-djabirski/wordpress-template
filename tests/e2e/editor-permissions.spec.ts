import { expect, test } from '@playwright/test';
import { CREDS, loginAs } from './helpers/auth';
import {
	adminUrl,
	expectNoAdminMenu,
	openBlockInserter,
	searchInserter,
} from './helpers/wp';

/**
 * Proves the `client_editor` role's admin-UI restrictions
 * (AgencyPlatform\Roles\RolesProvider, AgencyPlatform\Editor\EditorRestrictions,
 * AgencyPlatform\Security\AdminScreenPolicy and
 * AgencyPlatform\Security\CapabilityPolicy — see
 * tests/Integration/Permissions/ClientEditorCapabilitiesTest.php for the
 * PHPUnit-level coverage of the same policy) hold up end-to-end, against a
 * real browser and a real wp-admin render, not just capability checks.
 *
 * `#adminmenu li#menu-*` ids are WordPress core admin markup
 * (wp-admin/menu.php), not theme-authored; the block inserter selectors
 * are documented in tests/e2e/helpers/wp.ts.
 */

// The wp-admin sidebar (#adminmenu and its items) is a desktop-only surface:
// WordPress core collapses it behind an off-canvas toggle on narrow viewports,
// so `toBeVisible()` assertions on it are only meaningful on the desktop
// project. The role-restriction logic these prove is viewport-independent —
// desktop coverage is sufficient. Same `test.skip` pattern as smoke.spec.ts.
const DESKTOP_ONLY =
	'wp-admin sidebar is desktop-only (core collapses it on narrow viewports)';

test( 'client_editor can reach the wp-admin dashboard', async ( {
	page,
}, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );

	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
	await page.goto( adminUrl() );

	await expect( page ).toHaveURL( /\/wp\/wp-admin\// );
	await expect( page.locator( '#adminmenu' ) ).toBeVisible();
} );

test( 'client_editor admin menu hides Plugins and Appearance, keeps content menus', async ( {
	page,
}, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );

	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
	await page.goto( adminUrl() );

	await expectNoAdminMenu( page, 'menu-plugins' );
	await expectNoAdminMenu( page, 'menu-appearance' );

	// AdminScreenPolicy replaces the denied Appearance menu with one Design
	// entry that links straight to the Site Editor.
	await expect(
		page.locator( '#adminmenu a[href$="site-editor.php"]' ).first()
	).toBeVisible();

	await expect( page.locator( '#adminmenu li#menu-posts' ) ).toBeVisible();
	await expect( page.locator( '#adminmenu li#menu-pages' ) ).toBeVisible();
	await expect( page.locator( '#adminmenu li#menu-media' ) ).toBeVisible();
} );

test( "client_editor's block inserter excludes Custom HTML and Shortcode but offers Reference Callout", async ( {
	page,
} ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
	await page.goto( adminUrl( 'post-new.php?post_type=page' ) );

	await openBlockInserter( page );

	await searchInserter( page, 'Custom HTML' );
	// core/html and core/shortcode are in BlockPolicy::ALWAYS_DENIED, which no
	// filter can override. Waiting for the panel's own empty state avoids a
	// false pass racing the search debounce.
	await expect( page.getByText( /no results found/i ) ).toBeVisible();

	await searchInserter( page, 'Shortcode' );
	await expect( page.getByText( /no results found/i ) ).toBeVisible();

	await searchInserter( page, 'Reference Callout' );
	await expect(
		page.getByText( 'Reference Callout', { exact: true } ).first()
	).toBeVisible();

	// The registered-block policy offers every core block in an approved
	// namespace, including a block outside the old fixed allow-list.
	await searchInserter( page, 'Cover' );
	await expect(
		page.getByText( 'Cover', { exact: true } ).first()
	).toBeVisible();
} );

test( 'control: administrators keep the Plugins menu', async ( {
	page,
}, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );

	await loginAs( page, CREDS.admin.u, CREDS.admin.p );
	await page.goto( adminUrl() );

	// Proves the assertion mechanism itself works: an unrestricted user
	// really does see the menu the client_editor tests assert is absent.
	await expect( page.locator( '#adminmenu li#menu-plugins' ) ).toBeVisible();
} );
