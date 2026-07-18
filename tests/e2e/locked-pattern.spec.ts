import { expect, test } from '@playwright/test';
import { CREDS, loginAs } from './helpers/auth';
import { adminUrl, openBlockInserter, searchInserter } from './helpers/wp';

/**
 * Proves the "Reference Landing Section" pattern
 * (web/app/themes/site-theme/patterns/reference-landing-section.php) is
 * insertable by client_editor and stays template-locked once inserted —
 * the pattern's wrapping `core/group` block carries
 * `{"templateLock":"contentOnly"}` in its source.
 *
 * ASSERTION STRATEGY: rather than driving the block toolbar's "..." options
 * menu and asserting a "Remove" option is absent (brittle — that menu's
 * DOM structure/labels have shifted across WP releases), this reads the
 * editor's own `core/block-editor` data store via `page.evaluate`. WordPress
 * exposes `wp.data` as a documented, stable public API
 * (https://developer.wordpress.org/block-editor/reference-guides/data/) —
 * `getTemplateLock( clientId )` is the same function the editor UI itself
 * uses to decide whether to show block-removal controls, so asserting on it
 * directly proves the lock is in effect without depending on how (or
 * whether) the UI chooses to surface that fact.
 */

declare global {
	// `var` is required for ambient global declarations (TS disallows
	// `let`/`const` here); this augments the real `window.wp` global that
	// wp-admin's block editor exposes, it does not create a new binding.
	var wp: {
		data: {
			select: ( store: string ) => {
				getBlocks: () => Array<{ name: string; clientId: string }>;
				getTemplateLock: ( clientId?: string ) => string | false;
			};
		};
	};
}

test( 'client_editor can insert the locked landing-section pattern, which stays template-locked', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
	await page.goto( adminUrl( 'post-new.php?post_type=page' ) );

	await openBlockInserter( page );
	await searchInserter( page, 'Reference Landing Section' );

	const patternResult = page.getByText( 'Reference Landing Section', { exact: true } ).first();
	await expect( patternResult ).toBeVisible();
	await patternResult.click();

	// Inserting a pattern resolves/inserts its blocks asynchronously; poll
	// the store until the group block lands rather than asserting once.
	await expect( async () => {
		const groupCount = await page.evaluate(
			() => wp.data.select( 'core/block-editor' ).getBlocks().filter( ( block ) => block.name === 'core/group' ).length
		);
		expect( groupCount ).toBeGreaterThan( 0 );
	} ).toPass();

	const templateLock = await page.evaluate( () => {
		const editorStore = wp.data.select( 'core/block-editor' );
		const group = editorStore.getBlocks().find( ( block ) => block.name === 'core/group' );
		return group ? editorStore.getTemplateLock( group.clientId ) : null;
	} );

	expect( templateLock ).toBe( 'contentOnly' );
} );
