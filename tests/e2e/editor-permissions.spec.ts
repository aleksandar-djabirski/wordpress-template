import { expect, test } from '@playwright/test';
import { CREDS, loginAs } from './helpers/auth';
import { adminUrl, expectNoAdminMenu, openBlockInserter, searchInserter } from './helpers/wp';

/**
 * Proves the `client_editor` role's admin-UI restrictions
 * (AgencyPlatform\Roles\RolesProvider, AgencyPlatform\Editor\EditorRestrictions,
 * AgencyPlatform\Editor\SiteEditorLockdown — see
 * tests/Integration/Permissions/ClientEditorCapabilitiesTest.php for the
 * PHPUnit-level coverage of the same policy) hold up end-to-end, against a
 * real browser and a real wp-admin render, not just capability checks.
 *
 * `#adminmenu li#menu-*` ids are WordPress core admin markup
 * (wp-admin/menu.php), not theme-authored; the block inserter selectors
 * are documented in tests/e2e/helpers/wp.ts.
 */

test( 'client_editor can reach the wp-admin dashboard', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
	await page.goto( adminUrl() );

	await expect( page ).toHaveURL( /\/wp\/wp-admin\// );
	await expect( page.locator( '#adminmenu' ) ).toBeVisible();
} );

test( 'client_editor admin menu hides Plugins and Appearance, keeps content menus', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
	await page.goto( adminUrl() );

	await expectNoAdminMenu( page, 'menu-plugins' );
	await expectNoAdminMenu( page, 'menu-appearance' );

	await expect( page.locator( '#adminmenu li#menu-posts' ) ).toBeVisible();
	await expect( page.locator( '#adminmenu li#menu-pages' ) ).toBeVisible();
	await expect( page.locator( '#adminmenu li#menu-media' ) ).toBeVisible();
} );

test( "client_editor's block inserter excludes Custom HTML but offers Reference Callout", async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
	await page.goto( adminUrl( 'post-new.php?post_type=page' ) );

	await openBlockInserter( page );

	await searchInserter( page, 'Custom HTML' );
	// core/html is deliberately excluded from EditorRestrictions::ALLOWED_BLOCKS
	// (see web/app/mu-plugins/agency-platform/src/Editor/EditorRestrictions.php).
	// Waiting for the panel's own "No results found." empty state (rather than
	// asserting absence immediately) avoids a false pass racing the search
	// debounce — the assertion only succeeds once filtering has genuinely
	// settled on zero matches.
	await expect( page.getByText( /no results found/i ) ).toBeVisible();

	await searchInserter( page, 'Reference Callout' );
	// agency/reference-callout IS in EditorRestrictions::ALLOWED_BLOCKS.
	await expect( page.getByText( 'Reference Callout', { exact: true } ).first() ).toBeVisible();
} );

test( 'control: administrators keep the Plugins menu', async ( { page } ) => {
	await loginAs( page, CREDS.admin.u, CREDS.admin.p );
	await page.goto( adminUrl() );

	// Proves the assertion mechanism itself works: an unrestricted user
	// really does see the menu the client_editor tests assert is absent.
	await expect( page.locator( '#adminmenu li#menu-plugins' ) ).toBeVisible();
} );
