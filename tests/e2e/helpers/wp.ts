import { expect, type FrameLocator, type Page } from '@playwright/test';

export type EditorBlock = {
	clientId: string;
	innerBlocks?: EditorBlock[];
	name: string;
};

export type WpEditor = {
	blocks: {
		createBlock: ( name: string, attributes: { content: string } ) => EditorBlock;
	};
	data: {
		dispatch( store: 'core' ): {
			saveEntityRecord: ( kind: string, name: string, record: { id: number; content: string } ) => Promise< unknown >;
		};
		dispatch( store: 'core/block-editor' ): {
			moveBlocksUp: ( clientIds: string[] ) => void;
			removeBlocks: ( clientIds: string[] ) => void;
			replaceBlocks: ( clientId: string, block: EditorBlock ) => void;
		};
		select( store: 'core' ): {
			__experimentalGetCurrentGlobalStylesId: () => number;
		};
		select( store: 'core/block-editor' ): {
			getBlocks: () => EditorBlock[];
		};
		select( store: 'core/editor' ): {
			getEditorSettings: () => { codeEditingEnabled: boolean };
		};
	};
};

/**
 * Small WordPress-shaped URL/assertion helpers shared across the e2e
 * suites. Paths are returned relative (leading `/`) rather than absolute so
 * every call site resolves through Playwright's configured `baseURL`
 * (see playwright.config.ts) instead of hard-coding the DDEV host.
 */

/**
 * Bedrock serves WordPress core (and wp-admin) under `/wp/`, not the site
 * root — see the repo's Bedrock layout (web/wp).
 */
export function adminUrl( path = '' ): string {
	const normalized = path.replace( /^\//, '' );
	return `/wp/wp-admin/${ normalized }`;
}

export function siteUrl( path = '/' ): string {
	return path.startsWith( '/' ) ? path : `/${ path }`;
}

/**
 * Asserts the WP admin menu does NOT contain a top-level item with the
 * given `#adminmenu li` id (e.g. `menu-plugins`, `menu-appearance`) — the
 * markup WordPress core renders for `wp-admin/menu.php` list items.
 */
export async function expectNoAdminMenu( page: Page, menuId: string ): Promise<void> {
	await expect( page.locator( `#adminmenu li#${ menuId }` ) ).toHaveCount( 0 );
}

/**
 * The block editor shows a "Welcome to the block editor" guide dialog the
 * first time a given WordPress user opens it (tracked in user meta, so it
 * only appears once per user/browser profile). Closes it if present so it
 * doesn't cover the inserter toggle; a no-op otherwise.
 */
export async function dismissWelcomeGuideIfPresent( page: Page ): Promise<void> {
	const closeButton = page.getByRole( 'dialog' ).getByRole( 'button', { name: /close/i } ).first();
	try {
		await closeButton.waitFor( { state: 'visible', timeout: 3000 } );
		await closeButton.click();
	} catch {
		// No welcome guide shown — nothing to close.
	}

	const getStartedButton = page.getByRole( 'dialog' ).getByRole( 'button', { name: /get started/i } ).first();
	try {
		await getStartedButton.waitFor( { state: 'visible', timeout: 3000 } );
		await getStartedButton.click();
	} catch {
		// The Site Editor onboarding dialog is not shown.
	}
}

/**
 * Opens the block editor's block inserter panel.
 *
 * The toggle button lives in `@wordpress/editor` (not vendored in this
 * repo's own theme/plugin sources — it's WordPress-core admin UI), and its
 * accessible name has changed across WP releases ("Toggle block inserter"
 * historically, "Block Inserter" in newer 6.x releases). Matching by role
 * + a loose /inserter/i name is the resilient option the block editor's
 * own accessible name is guaranteed to contain in either case.
 */
export async function openBlockInserter( page: Page ): Promise<void> {
	await dismissWelcomeGuideIfPresent( page );
	await page.getByRole( 'button', { name: /inserter/i } ).first().click();
}

/**
 * Types into the open inserter panel's search field. `@wordpress/components`
 * SearchControl renders `<input type="search" placeholder={__('Search')}>`
 * (see node_modules/@wordpress/components/src/search-control/index.tsx) —
 * stable across WP versions, unlike the panel's internal class names.
 */
export async function searchInserter( page: Page, term: string ): Promise<void> {
	await page.getByPlaceholder( 'Search' ).fill( term );
}

/**
 * The Site Editor's WordPress 7.0 route shape: site-editor.php?p=<route>, with
 * &canvas=edit to land directly in the editing canvas rather than the browse
 * view.
 */
export function siteEditorUrl( route = '/template', canvas = false ): string {
	const suffix = canvas ? '&canvas=edit' : '';
	return adminUrl( `site-editor.php?p=${ encodeURIComponent( route ) }${ suffix }` );
}

/**
 * Opens a Site Editor route and returns its block canvas frame.
 */
export async function openSiteEditorCanvas( page: Page, route: string ): Promise< FrameLocator > {
	await page.goto( siteEditorUrl( route, true ) );
	await dismissWelcomeGuideIfPresent( page );

	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas.locator( '.editor-styles-wrapper' ).waitFor( { state: 'visible' } );

	return canvas;
}

/**
 * Clicks the editor's Save button and waits for the confirmation.
 */
export async function saveInEditor( page: Page ): Promise< void > {
	await page.keyboard.press( 'Escape' );

	const reviewButton = page.getByRole( 'button', { name: /^review \d+ changes?/i } ).first();
	const saveButton = page.getByRole( 'button', { name: /^save$/i } );
	if ( await saveButton.count() === 0 && await reviewButton.count() > 0 ) {
		await reviewButton.click();
	}

	await saveButton.first().click();

	const panelSave = page.getByRole( 'button', { name: /^save$/i } );
	if ( await panelSave.count() > 0 ) {
		await panelSave.last().click().catch( () => undefined );
	}

	await expect( page.getByText( /saved|updated/i ).first() ).toBeVisible( { timeout: 30_000 } );
}
