import { expect, test, type Page } from '@playwright/test';
import { CREDS, loginAs } from './helpers/auth';
import {
	adminUrl,
	dismissWelcomeGuideIfPresent,
	getNavigationContent,
	openSiteEditorCanvas,
	saveInEditor,
	saveNavigationRecord,
	siteEditorUrl,
	type EditorBlock,
	type WpEditor,
} from './helpers/wp';

declare global {
	interface Window {
		wpApiSettings?: { nonce?: string };
	}
}

/**
 * Proves the client role can drive the Site Editor, not only pass a
 * capability check. The Site Editor is a desktop admin surface.
 */
const DESKTOP_ONLY = 'the Site Editor is a desktop admin surface';

test.beforeEach( async ( {}, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', DESKTOP_ONLY );
	test.setTimeout( 120_000 );
} );

async function deleteCustomizedEntity(
	page: Page,
	endpoint: 'templates' | 'template-parts',
	id: string
): Promise< void > {
	const response = await page.request.delete(
		`/wp-json/wp/v2/${ endpoint }/${ encodeURIComponent( id ) }`,
		{
			params: { force: true },
			headers: await restHeaders( page ),
			timeout: 10_000,
		}
	);

	expect( [ 200, 404 ], `could not restore ${ endpoint }/${ id }` ).toContain(
		response.status()
	);
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

type GlobalStylesRecord = {
	id: number;
	styles: Record< string, unknown >;
	settings: Record< string, unknown >;
};

async function getGlobalStylesRecord(
	page: Page
): Promise< GlobalStylesRecord > {
	const id = await page.evaluate( () =>
		( window as unknown as { wp: WpEditor } ).wp.data
			.select( 'core' )
			.__experimentalGetCurrentGlobalStylesId()
	);
	expect( id ).toBeGreaterThan( 0 );
	const response = await page.request.get(
		`/wp-json/wp/v2/global-styles/${ id }?context=edit`,
		{ headers: await restHeaders( page ) }
	);
	expect( response.status() ).toBe( 200 );
	return ( await response.json() ) as GlobalStylesRecord;
}

async function restoreGlobalStyles(
	page: Page,
	record: GlobalStylesRecord
): Promise< void > {
	const response = await page.request.put(
		`/wp-json/wp/v2/global-styles/${ record.id }`,
		{
			data: { styles: record.styles, settings: record.settings },
			headers: await restHeaders( page ),
		}
	);

	expect( response.status() ).toBe( 200 );
}

test( 'client_editor can open the Site Editor', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const response = await page.goto( siteEditorUrl( '/template' ) );

	expect( response?.status() ).toBe( 200 );
	await expect(
		page.getByText( /you need a higher level of permission/i )
	).toHaveCount( 0 );
} );

test( 'client_editor can edit and save a template, and the change persists', async ( {
	page,
} ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const marker = `Client template edit ${ Date.now() }`;
	const canvas = await openSiteEditorCanvas(
		page,
		'/wp_template/site-theme//page'
	);

	try {
		await canvas
			.locator(
				'.editor-styles-wrapper main h1, .editor-styles-wrapper main p'
			)
			.first()
			.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.press( 'Enter' );
		await page.keyboard.type( marker );

		await saveInEditor( page );

		const stored = await page.request.get(
			'/wp-json/wp/v2/templates/site-theme%2F%2Fpage?context=edit',
			{ headers: await restHeaders( page ) }
		);
		expect( stored.status() ).toBe( 200 );
		expect( JSON.stringify( await stored.json() ) ).toContain( marker );
	} finally {
		await deleteCustomizedEntity( page, 'templates', 'site-theme//page' );
	}
} );

test( 'client_editor can edit and save the header template part, and the change persists', async ( {
	page,
} ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const marker = `Client header edit ${ Date.now() }`;
	// The part on its own shows .site-header__inner. The <header> and the
	// .site-header class come from the template's template-part block.
	const canvas = await openSiteEditorCanvas(
		page,
		'/wp_template_part/site-theme//site-header'
	);

	try {
		await expect( canvas.locator( '.site-header__inner' ) ).toBeVisible();
		await page.evaluate( ( text ) => {
			const wp = ( window as unknown as { wp: WpEditor } ).wp;
			const select = wp.data.select( 'core/block-editor' );
			const editor = wp.data.dispatch( 'core/block-editor' );
			const findTagline = (
				blocks: EditorBlock[]
			): EditorBlock | null => {
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
				throw new Error(
					'Site Tagline block was not found in the header part.'
				);
			}
			editor.replaceBlocks(
				tagline.clientId,
				wp.blocks.createBlock( 'core/paragraph', { content: text } )
			);
		}, marker );
		await expect(
			canvas.getByText( marker, { exact: true } )
		).toBeVisible();

		await saveInEditor( page );

		const stored = await page.request.get(
			'/wp-json/wp/v2/template-parts/site-theme%2F%2Fsite-header?context=edit',
			{ headers: await restHeaders( page ) }
		);
		expect( stored.status() ).toBe( 200 );
		expect( JSON.stringify( await stored.json() ) ).toContain( marker );

		await page.goto( '/' );
		await expect( page.locator( 'header.site-header' ) ).toContainText(
			marker
		);
	} finally {
		await deleteCustomizedEntity(
			page,
			'template-parts',
			'site-theme//site-header'
		);
	}
} );

test( 'client_editor can edit a navigation menu, and the change persists', async ( {
	page,
} ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	const label = `Link ${ Date.now() }`;
	const before = await page.request.get(
		'/wp-json/wp/v2/navigation?context=edit',
		{ headers: await restHeaders( page ) }
	);
	expect( before.status() ).toBe( 200 );
	const [ navigation ] = ( await before.json() ) as Array< {
		id: number;
		content: { raw: string };
	} >;
	expect( navigation ).toBeDefined();
	const original = navigation.content.raw;
	const headers = await restHeaders( page );
	const edited = `${ original }\n<!-- wp:navigation-link {"label":"${ label }","url":"/x/"} /-->`;

	try {
		await page.goto(
			siteEditorUrl( `/wp_navigation/${ navigation.id }`, true )
		);
		await expect(
			page.getByText( /you need a higher level of permission/i )
		).toHaveCount( 0 );

		await saveNavigationRecord( page, navigation.id, edited, headers );

		await expect
			.poll( () => getNavigationContent( page, navigation.id, headers ) )
			.toBe( edited );
	} finally {
		await saveNavigationRecord( page, navigation.id, original, headers );
		await expect
			.poll( () => getNavigationContent( page, navigation.id, headers ) )
			.toBe( original );
	}
} );

test( 'client_editor can change a Global Styles colour, and the change persists', async ( {
	page,
} ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	await page.goto( siteEditorUrl( '/styles' ) );
	const original = await getGlobalStylesRecord( page );
	try {
		await page
			.getByRole( 'button', { name: /^colors$/i } )
			.first()
			.click();
		await page
			.getByRole( 'button', { name: 'Background', exact: true } )
			.click();
		await page.getByRole( 'option', { name: /neutral 100/i } ).click();

		await saveInEditor( page );

		const saved = await getGlobalStylesRecord( page );
		expect( JSON.stringify( saved.styles ) ).toContain( 'neutral-100' );

		await page.goto( '/' );
		const background = await page
			.locator( 'body' )
			.evaluate(
				( element ) => getComputedStyle( element ).backgroundColor
			);
		expect( background ).not.toBe( '' );
	} finally {
		await restoreGlobalStyles( page, original );
	}
} );

test( 'client_editor can reach the typography controls', async ( { page } ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	await page.goto( siteEditorUrl( '/styles' ) );
	await page
		.getByRole( 'button', { name: /^typography$/i } )
		.first()
		.click();

	await expect(
		page.getByRole( 'button', { name: /^text$/i } ).first()
	).toBeVisible();
} );

test( 'client_editor has no Additional CSS panel in Global Styles', async ( {
	page,
} ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

	await page.goto( siteEditorUrl( '/styles' ) );

	// Core renders this panel only when the user has edit_css.
	await expect(
		page.getByRole( 'button', { name: /additional css/i } )
	).toHaveCount( 0 );
} );

test( 'client_editor has no code editor in the post editor', async ( {
	page,
} ) => {
	await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );
	await page.goto( adminUrl( 'post-new.php?post_type=page' ) );

	await dismissWelcomeGuideIfPresent( page );
	const codeEditingEnabled = await page.evaluate( () => {
		const wp = ( window as unknown as { wp: WpEditor } ).wp;
		return wp.data.select( 'core/editor' ).getEditorSettings()
			.codeEditingEnabled;
	} );
	expect( codeEditingEnabled ).toBe( false );
} );

test( 'control: an administrator sees the code editor option', async ( {
	page,
} ) => {
	await loginAs( page, CREDS.admin.u, CREDS.admin.p );
	await page.goto( adminUrl( 'post-new.php?post_type=page' ) );

	await dismissWelcomeGuideIfPresent( page );
	const codeEditingEnabled = await page.evaluate( () => {
		const wp = ( window as unknown as { wp: WpEditor } ).wp;
		return wp.data.select( 'core/editor' ).getEditorSettings()
			.codeEditingEnabled;
	} );
	expect( codeEditingEnabled ).toBe( true );
} );
