import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Result } from 'axe-core';

/**
 * WCAG 2 A/AA automated accessibility scan of the home page and one
 * content page. Not exhaustive (axe only catches a subset of real
 * accessibility issues) but catches the common structural regressions —
 * missing landmarks, contrast, form labeling, etc.
 */

function formatViolations( violations: Result[] ): string {
	if ( violations.length === 0 ) {
		return 'no violations';
	}

	return violations
		.map( ( violation ) => {
			const targets = violation.nodes
				.map( ( node ) => `    - ${ node.target.join( ' ' ) }` )
				.join( '\n' );
			return `${ violation.id } [${
				violation.impact ?? 'unknown impact'
			}]: ${ violation.help }\n${ targets }`;
		} )
		.join( '\n\n' );
}

test( 'home page has no WCAG 2 A/AA violations', async ( { page } ) => {
	await page.goto( '/' );

	const results = await new AxeBuilder( { page } )
		.withTags( [ 'wcag2a', 'wcag2aa' ] )
		.analyze();

	expect(
		results.violations,
		formatViolations( results.violations )
	).toEqual( [] );
} );

test( 'a content page (Sample Page) has no WCAG 2 A/AA violations', async ( {
	page,
} ) => {
	const response = await page.goto( '/sample-page/' );

	expect( response?.status() ).toBe( 200 );

	const results = await new AxeBuilder( { page } )
		.withTags( [ 'wcag2a', 'wcag2aa' ] )
		.analyze();

	expect(
		results.violations,
		formatViolations( results.violations )
	).toEqual( [] );
} );

test( 'the demo page has no WCAG 2 A/AA violations', async ( { page } ) => {
	const response = await page.goto( '/demo/' );

	expect( response?.status() ).toBe( 200 );

	const results = await new AxeBuilder( { page } )
		.withTags( [ 'wcag2a', 'wcag2aa' ] )
		.analyze();

	expect(
		results.violations,
		formatViolations( results.violations )
	).toEqual( [] );
} );
