import { expect, test, type Page } from '@playwright/test';
import { CREDS, loginAs } from './helpers/auth';
import { openSiteEditorCanvas, saveInEditor, siteEditorUrl, type EditorBlock, type WpEditor } from './helpers/wp';

declare global {
	interface Window {
		wpApiSettings?: { nonce?: string };
	}
}

/**
 * The client task list is driven end to end as client_editor. Each test does
 * the real action and saves, because a visible panel is not proof of the
 * client's ability to complete the task.
 */
const DESKTOP_ONLY = 'the Site Editor is a desktop admin surface';

test.beforeEach( async ( {}, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );
	test.setTimeout( 120_000 );
} );

async function deleteCustomizedTemplatePart( page: Page ): Promise< void > {
	const response = await page.request.delete( '/wp-json/wp/v2/template-parts/site-theme%2F%2Fsite-header', {
		params: { force: true },
		headers: await restHeaders( page ),
	} );

	expect( [ 200, 404 ], 'could not restore the site header template part' ).toContain( response.status() );
}

async function deleteCustomizedTemplate( page: Page, slug: string ): Promise< void > {
	const response = await page.request.delete( `/wp-json/wp/v2/templates/site-theme%2F%2F${ slug }`, {
		params: { force: true },
		headers: await restHeaders( page ),
	} );

	// A 400 means the editor has already returned the file-backed template to
	// its theme source, which is the desired clean state.
	expect( [ 200, 400, 404 ], `could not restore the ${ slug } template` ).toContain( response.status() );
}

async function restHeaders( page: Page ): Promise< { 'X-WP-Nonce': string } > {
	let nonce = await page.evaluate( () => window.wpApiSettings?.nonce ?? '' );
	if ( '' === nonce ) {
		await page.goto( siteEditorUrl( '/template' ) );
		nonce = await page.evaluate( () => window.wpApiSettings?.nonce ?? '' );
	}
	expect( nonce ).not.toBe( '' );

	return { 'X-WP-Nonce': nonce };
}

async function saveNavigationRecord( page: Page, id: number, content: string ): Promise< void > {
	await page.evaluate( async ( record ) => {
		const wp = ( window as unknown as { wp: WpEditor } ).wp;
		await wp.data.dispatch( 'core' ).saveEntityRecord( 'postType', 'wp_navigation', record );
	}, { id, content } );
}

type GlobalStylesRecord = {
	id: number;
	styles: Record< string, unknown >;
	settings: Record< string, unknown >;
};

async function getGlobalStylesRecord( page: Page ): Promise< GlobalStylesRecord > {
	const id = await page.evaluate( () => ( window as unknown as { wp: WpEditor } ).wp.data.select( 'core' ).__experimentalGetCurrentGlobalStylesId() );
	expect( id ).toBeGreaterThan( 0 );
	const response = await page.request.get( `/wp-json/wp/v2/global-styles/${ id }?context=edit`, { headers: await restHeaders( page ) } );
	expect( response.status() ).toBe( 200 );
	return ( await response.json() ) as GlobalStylesRecord;
}

async function restoreGlobalStyles( page: Page, record: GlobalStylesRecord ): Promise< void > {
	const response = await page.request.put( `/wp-json/wp/v2/global-styles/${ record.id }`, {
		data: { styles: record.styles, settings: record.settings },
		headers: await restHeaders( page ),
	} );

	expect( response.status() ).toBe( 200 );
}

test( 'client_editor edits header text and saves', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const canvas = await openSiteEditorCanvas( page, '/wp_template_part/site-theme//site-header' );
	try {
		await expect( canvas.locator( '.site-header__inner' ) ).toBeVisible();
		await page.evaluate( ( text ) => {
			const wp = ( window as unknown as { wp: WpEditor } ).wp;
			const select = wp.data.select( 'core/block-editor' );
			const editor = wp.data.dispatch( 'core/block-editor' );
			const findTagline = ( blocks: EditorBlock[] ): EditorBlock | null => {
				for ( const block of blocks ) {
					if ( 'core/site-tagline' === block.name ) {
						return block;
					}
					const nested = findTagline( block.innerBlocks ?? [] );
					if ( nested ) {
						return nested;
					}
				}
				return null;
			};
			const tagline = findTagline( select.getBlocks() );
			if ( ! tagline ) {
				throw new Error( 'Site Tagline block was not found in the header part.' );
			}
			editor.replaceBlocks( tagline.clientId, wp.blocks.createBlock( 'core/paragraph', { content: text } ) );
		}, 'Edited by the client' );
		await expect( canvas.getByText( 'Edited by the client', { exact: true } ) ).toBeVisible();

		await saveInEditor( page );

		await page.goto( '/' );
		await expect( page.locator( 'header.site-header' ) ).toContainText( 'Edited by the client' );
	} finally {
		await deleteCustomizedTemplatePart( page );
	}
} );

test( 'client_editor adds a core block, reorders it, then removes it', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const canvas = await openSiteEditorCanvas( page, '/wp_template/site-theme//404' );
	const paragraphs = canvas.locator( '.editor-styles-wrapper p' );
	const before = await paragraphs.count();
	const marker = 'Client added this paragraph';

	try {
		await canvas.locator( '.editor-styles-wrapper main p' ).first().click();
		await page.keyboard.press( 'End' );
		await page.keyboard.press( 'Enter' );
		await page.keyboard.type( marker );
		await expect( paragraphs ).toHaveCount( before + 1 );

		// Reorder the selected block through the editor's public data action. The
		// shipped Site Editor renders this toolbar inside its own canvas state,
		// so the data action is the stable equivalent of the Move up control.
		const clientId = await canvas.getByText( marker, { exact: true } ).getAttribute( 'data-block' );
		if ( null === clientId ) {
			throw new Error( 'The added paragraph block has no client ID.' );
		}
		await page.evaluate( ( id ) => {
			( window as unknown as { wp: WpEditor } ).wp.data.dispatch( 'core/block-editor' ).moveBlocksUp( [ id ] );
		}, clientId );

		await saveInEditor( page );
		const stored = await page.request.get( '/wp-json/wp/v2/templates/site-theme%2F%2F404?context=edit', { headers: await restHeaders( page ) } );
		expect( stored.status() ).toBe( 200 );
		expect( JSON.stringify( await stored.json() ) ).toContain( marker );

		// Remove the block again, leaving the template as it was.
		await page.evaluate( ( id ) => {
			( window as unknown as { wp: WpEditor } ).wp.data.dispatch( 'core/block-editor' ).removeBlocks( [ id ] );
		}, clientId );
		await expect( canvas.getByText( marker, { exact: true } ) ).toHaveCount( 0 );
		await expect( canvas.getByText( 'The page you were looking for could not be found. Try a search instead.', { exact: true } ) ).toBeVisible();

		await saveInEditor( page );
	} finally {
		await deleteCustomizedTemplate( page, '404' );
	}
} );

test( 'client_editor changes a Global Styles colour and reaches a typography setting', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	await page.goto( siteEditorUrl( '/styles' ) );
	const original = await getGlobalStylesRecord( page );
	try {
		await page.getByRole( 'button', { name: /^colors$/i } ).first().click();
		await page.getByRole( 'button', { name: 'Background', exact: true } ).click();
		await page.getByRole( 'option', { name: /neutral 100/i } ).click();

		await saveInEditor( page );

		const saved = await getGlobalStylesRecord( page );
		expect( JSON.stringify( saved.styles ) ).toContain( 'neutral-100' );

		await page.goto( siteEditorUrl( '/styles' ) );
		await page.getByRole( 'button', { name: /^typography$/i } ).first().click();
		await expect( page.getByRole( 'button', { name: /text/i } ).first() ).toBeVisible();
	} finally {
		await restoreGlobalStyles( page, original );
	}
} );

test( 'client_editor can undo a Site Editor change', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const canvas = await openSiteEditorCanvas( page, '/wp_template/site-theme//404' );
	await canvas.locator( '.editor-styles-wrapper h1' ).click();
	await page.keyboard.type( 'X' );

	await page.getByRole( 'button', { name: /^undo$/i } ).first().click();

	await expect( canvas.locator( '.editor-styles-wrapper h1' ) ).toHaveText( 'Nothing here' );
} );

test( 'client_editor can edit the navigation menu', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const before = await page.request.get( '/wp-json/wp/v2/navigation?context=edit', { headers: await restHeaders( page ) } );
	expect( before.status() ).toBe( 200 );
	const [ navigation ] = ( await before.json() ) as Array< { id: number; content: { raw: string } } >;
	expect( navigation ).toBeDefined();
	const original = navigation.content.raw;
	const label = `Client link ${ Date.now() }`;

	try {
		await page.goto( siteEditorUrl( '/navigation' ) );
		await page.getByText( 'Primary' ).first().click();
		await expect( page.getByText( 'Home' ).first() ).toBeVisible();

		// The navigation editor's DOM is not a stable public surface. Use the
		// editor data layer after opening the real navigation editor.
		await saveNavigationRecord( page, navigation.id, `${ original }\n<!-- wp:navigation-link {"label":"${ label }","url":"/client-link/"} /-->` );
		await expect
			.poll( async () => JSON.stringify( await ( await page.request.get( `/wp-json/wp/v2/navigation/${ navigation.id }?context=edit`, { headers: await restHeaders( page ) } ) ).json() ) )
			.toContain( label );
	} finally {
		await saveNavigationRecord( page, navigation.id, original );
	}
} );

test( 'every static section of the demo page is visible in the editor canvas', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const response = await page.request.get( '/wp-json/wp/v2/pages?slug=demo' );
	expect( response.status() ).toBe( 200 );
	const [ demo ] = ( await response.json() ) as Array< { id: number } >;
	expect( demo ).toBeDefined();

	const canvas = await openSiteEditorCanvas( page, `/page/${ demo.id }` );

	// Static sections are visible in the editor and the dynamic block shows a
	// representative preview when it has testimonial content enabled.
	await expect(
		canvas.locator( '.editor-styles-wrapper h1.wp-block-heading' ).filter( {
			hasText: 'Ship client sites without losing the design to a database',
		} ),
	).toBeVisible();
	await expect( canvas.locator( '.editor-styles-wrapper .wp-block-columns' ).first() ).toBeVisible();
	await expect( canvas.locator( '.editor-styles-wrapper .wp-block-image img' ) ).toBeVisible();
	await expect( canvas.locator( '.editor-styles-wrapper .wp-block-button' ).first() ).toBeVisible();
	await expect( canvas.locator( '.editor-styles-wrapper .reference-callout__testimonial--preview' ) ).toBeVisible();
} );
