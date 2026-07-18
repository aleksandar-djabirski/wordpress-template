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
			const targets = violation.nodes.map( ( node ) => `    - ${ node.target.join( ' ' ) }` ).join( '\n' );
			return `${ violation.id } [${ violation.impact ?? 'unknown impact' }]: ${ violation.help }\n${ targets }`;
		} )
		.join( '\n\n' );
}

test( 'home page has no WCAG 2 A/AA violations', async ( { page } ) => {
	await page.goto( '/' );

	const results = await new AxeBuilder( { page } ).withTags( [ 'wcag2a', 'wcag2aa' ] ).analyze();

	expect( results.violations, formatViolations( results.violations ) ).toEqual( [] );
} );

test( 'a content page (Sample Page) has no WCAG 2 A/AA violations', async ( { page } ) => {
	// Fresh WordPress installs ship a default "Sample Page" at /sample-page/
	// (wp core install seeds it). If a project has since deleted/renamed it,
	// soft-skip rather than fail — this suite verifies the theme's page
	// template is accessible, not that any specific content exists.
	const response = await page.goto( '/sample-page/' );

	if ( ! response || response.status() === 404 ) {
		test.skip( true, '/sample-page/ returned 404 — default Sample Page not present on this install, skipping.' );
		return;
	}

	const results = await new AxeBuilder( { page } ).withTags( [ 'wcag2a', 'wcag2aa' ] ).analyze();

	expect( results.violations, formatViolations( results.violations ) ).toEqual( [] );
} );
