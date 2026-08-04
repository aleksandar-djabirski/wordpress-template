import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { openSiteEditorCanvas } from '../../e2e/helpers/wp';
import type { Frame, FrameLocator, Page } from '@playwright/test';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';

export type ParityPage = {
	/** File-safe identifier; also the baseline PNG basename. */
	name: string;
	/** Site-relative path, always with a leading slash. */
	path: string;
	/** Maximum allowed differing-pixel ratio, 0..1. */
	maxDiffRatio: number;
	/** Selectors painted over on BOTH sides before comparison. */
	maskSelectors: string[];
	/** Maximum per-edge bounding-box delta for header/footer geometry. */
	maxEdgeDeltaPx: number;
};

export const MIGRATION_BASELINE_DIR = resolve( __dirname, '..', '__migration_baselines__' );

/**
 * Both sides of every parity comparison are forced onto one libre font that
 * ships with the pinned ubuntu-24.04 runner (fonts-dejavu-core). No font
 * binary is committed, so no licence review is required, and font fallback
 * differences can never produce a false parity failure.
 */
export const PARITY_FONT_CSS = `
	*, *::before, *::after {
		font-family: "DejaVu Sans", sans-serif !important;
		font-synthesis: none !important;
	}
	code, pre, kbd, samp {
		font-family: "DejaVu Sans Mono", monospace !important;
	}
`;

export const MIGRATION_PARITY_PAGES: ParityPage[] = [
	{ name: 'home', path: '/', maxDiffRatio: 0.02, maskSelectors: [], maxEdgeDeltaPx: 8 },
	{ name: 'sample-page', path: '/sample-page/', maxDiffRatio: 0.05, maskSelectors: [], maxEdgeDeltaPx: 8 },
];

export const EDITING_PARITY_PAGES: ParityPage[] = [
	{ name: 'demo', path: '/demo/', maxDiffRatio: 0.05, maskSelectors: [], maxEdgeDeltaPx: 8 },
	{ name: 'sample-page', path: '/sample-page/', maxDiffRatio: 0.05, maskSelectors: [], maxEdgeDeltaPx: 8 },
];

export async function applyParityFonts( target: Page | Frame ): Promise< void > {
	await target.addStyleTag( { content: PARITY_FONT_CSS } );
}

export async function captureFrontend(
	page: Page,
	path: string,
	maskSelectors: string[]
): Promise< Buffer > {
	await page.goto( path, { waitUntil: 'networkidle' } );
	await applyParityFonts( page );
	await page.evaluate( () => document.fonts.ready );
	return page.screenshot( {
		fullPage: true,
		animations: 'disabled',
		caret: 'hide',
		mask: maskSelectors.map( ( selector ) => page.locator( selector ) ),
	} );
}

/**
 * Photographs one Site Editor canvas subtree with the editor chrome excluded.
 * Callers select the same content subtree that they capture on the frontend.
 */
export async function captureEditorCanvas(
	page: Page,
	route: string,
	maskSelectors: string[],
	rootSelector = '.editor-styles-wrapper',
	matchFrontendContentWidth = false
): Promise< Buffer > {
	const canvas = await openSiteEditorCanvas( page, route );
	const root = canvas.locator( rootSelector ).first();

	if ( matchFrontendContentWidth ) {
		await root.evaluate( ( element ) => {
			element.style.setProperty( 'width', 'min(100%, var(--wp--style--global--content-size))', 'important' );
			element.style.setProperty( 'margin-inline', 'auto', 'important' );
			element.style.setProperty( 'padding-inline', '0', 'important' );
		} );
	}

	await applyParityFonts( page );
	await root.evaluate( ( element, css ) => {
		const style = element.ownerDocument.createElement( 'style' );
		style.textContent = css;
		element.ownerDocument.head.appendChild( style );
	}, PARITY_FONT_CSS );
	await root.evaluate( ( element ) => element.ownerDocument.fonts.ready );

	return root.screenshot( {
		animations: 'disabled',
		caret: 'hide',
		mask: maskSelectors.map( ( selector ) => canvas.locator( selector ) ),
	} );
}

export type Box = { x: number; y: number; width: number; height: number };

/**
 * Returns bounding boxes relative to the capture root's own origin. A missing
 * or unlaid-out selector throws so an absent section cannot pass as a zero box.
 */
export async function boundingBoxes(
	scope: Page | FrameLocator,
	rootSelector: string,
	selectors: string[]
): Promise< Record< string, Box > > {
	const root = await scope.locator( rootSelector ).first().boundingBox();

	if ( ! root ) {
		throw new Error( `boundingBoxes: capture root "${ rootSelector }" was not found or is not visible.` );
	}

	const result: Record< string, Box > = {};

	for ( const selector of selectors ) {
		const target = scope.locator( selector ).first();

		if ( ( await target.count() ) === 0 ) {
			throw new Error( `boundingBoxes: "${ selector }" is missing. Editing parity cannot pass when a section is absent from one side.` );
		}

		const box = await target.boundingBox();

		if ( ! box ) {
			throw new Error( `boundingBoxes: "${ selector }" exists but has no layout box (display:none?).` );
		}

		result[ selector ] = {
			x: box.x - root.x,
			y: box.y - root.y,
			width: box.width,
			height: box.height,
		};
	}

	return result;
}

/**
 * Measures the editor canvas content width. The iframe width can differ from
 * the browser viewport when the Site Editor sidebars are open.
 */
export async function effectiveCanvasWidth(
	page: Page,
	rootSelector = '.editor-styles-wrapper'
): Promise< number > {
	return page
		.frameLocator( 'iframe[name="editor-canvas"]' )
		.locator( rootSelector )
		.first()
		.evaluate( ( element ) => element.getBoundingClientRect().width );
}

export async function computedStyles(
	scope: Page | FrameLocator,
	selector: string,
	properties: string[]
): Promise< Record< string, string > > {
	return scope.locator( selector ).first().evaluate( ( element, props ) => {
		const styles = element.ownerDocument.defaultView!.getComputedStyle( element );
		const out: Record< string, string > = {};

		for ( const prop of props ) {
			out[ prop ] = styles.getPropertyValue( prop );
		}

		return out;
	}, properties );
}

export function compareToBaseline(
	actual: Buffer,
	baselinePath: string,
	maxDiffRatio: number,
	diffOutPath: string
): { diffRatio: number } {
	return compareBuffers( readFileSync( baselinePath ), actual, maxDiffRatio, diffOutPath );
}

/**
 * Captures a frontend region as an element screenshot so it uses the same
 * capture geometry as the Site Editor canvas screenshot.
 */
export async function captureFrontendRegion(
	page: Page,
	path: string,
	maskSelectors: string[],
	rootSelector = 'body'
): Promise< Buffer > {
	await page.goto( path, { waitUntil: 'networkidle' } );
	await applyParityFonts( page );
	await page.evaluate( () => document.fonts.ready );

	return page.locator( rootSelector ).first().screenshot( {
		animations: 'disabled',
		caret: 'hide',
		mask: maskSelectors.map( ( selector ) => page.locator( selector ) ),
	} );
}

/**
 * Compares two PNG buffers and counts a capture-size mismatch as difference.
 */
export function compareBuffers(
	expectedBuffer: Buffer,
	actualBuffer: Buffer,
	maxDiffRatio: number,
	diffOutPath: string
): { diffRatio: number } {
	const expectedPng = PNG.sync.read( expectedBuffer );
	const actualPng = PNG.sync.read( actualBuffer );

	const width = Math.min( expectedPng.width, actualPng.width );
	const height = Math.min( expectedPng.height, actualPng.height );
	const diff = new PNG( { width, height } );

	const differing = pixelmatch(
		cropTo( expectedPng, width, height ).data,
		cropTo( actualPng, width, height ).data,
		diff.data,
		width,
		height,
		{ threshold: 0.2 }
	);

	const maxArea = Math.max(
		expectedPng.width * expectedPng.height,
		actualPng.width * actualPng.height
	);
	const diffRatio = ( differing + ( maxArea - width * height ) ) / maxArea;

	if ( diffRatio > maxDiffRatio ) {
		mkdirSync( dirname( diffOutPath ), { recursive: true } );
		writeFileSync( diffOutPath, PNG.sync.write( diff ) );
	}

	return { diffRatio };
}

/**
 * Resolves a page id through the REST API so a missing setup fixture fails with
 * a named error instead of an editor timeout.
 */
export async function resolvePageId( page: Page, path: string ): Promise< number > {
	const slug = path.replace( /^\/|\/$/g, '' ) || 'home';
	const response = await page.request.get( `/wp-json/wp/v2/pages?slug=${ encodeURIComponent( slug ) }` );
	const results = ( await response.json() ) as Array< { id: number } >;

	if ( ! Array.isArray( results ) || results.length === 0 ) {
		throw new Error(
			`resolvePageId: no page found for path "${ path }" (slug "${ slug }"). Run scripts/setup so the Demo page and Sample Page exist.`
		);
	}

	return results[ 0 ].id;
}

function cropTo( source: PNG, width: number, height: number ): PNG {
	if ( source.width === width && source.height === height ) {
		return source;
	}
	const cropped = new PNG( { width, height } );
	PNG.bitblt( source, cropped, 0, 0, width, height, 0, 0 );
	return cropped;
}
