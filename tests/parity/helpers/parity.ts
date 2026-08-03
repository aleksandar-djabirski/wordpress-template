import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import type { Frame, Page } from '@playwright/test';
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
	{ name: 'home', path: '/', maxDiffRatio: 0.05, maskSelectors: [], maxEdgeDeltaPx: 8 },
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

export function compareToBaseline(
	actual: Buffer,
	baselinePath: string,
	maxDiffRatio: number,
	diffOutPath: string
): { diffRatio: number } {
	const expectedPng = PNG.sync.read( readFileSync( baselinePath ) );
	const actualPng = PNG.sync.read( actual );

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

	// A size mismatch counts as difference, so a taller or shorter page cannot
	// pass by comparing only the overlapping region.
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

function cropTo( source: PNG, width: number, height: number ): PNG {
	if ( source.width === width && source.height === height ) {
		return source;
	}
	const cropped = new PNG( { width, height } );
	PNG.bitblt( source, cropped, 0, 0, width, height, 0, 0 );
	return cropped;
}
