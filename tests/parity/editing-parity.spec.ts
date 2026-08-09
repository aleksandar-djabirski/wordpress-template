import { expect, test } from '@playwright/test';
import {
	EDITING_PARITY_PAGES,
	EDITING_EDITOR_ONLY_SELECTORS,
	captureEditorCanvas,
	captureFrontendRegion,
	compareBuffers,
	computedStyles,
	effectiveCanvasWidth,
	layoutWidth,
	resolvePageId,
} from './helpers/parity';
import { CREDS, loginAs } from '../e2e/helpers/auth';

/**
 * Compares the client Site Editor canvas with the frontend for the same page.
 * The `/page/{id}` route is a post-editing context, so it renders content only
 * and has no template chrome. Both screenshots therefore use the matching
 * post-content subtree. This excludes the frontend title and the editor
 * wrapper padding from the comparison. The demo's testimonial preview is an
 * intentional editor-only projection with no frontend counterpart, so the
 * comparison removes that content from the editor projection; the e2e suite
 * separately verifies that the preview remains available to editors.
 */
for ( const parityPage of EDITING_PARITY_PAGES ) {
	test( `editing parity: ${ parityPage.name }`, async ( {
		page,
	}, testInfo ) => {
		test.setTimeout( 120_000 );

		const frontendContentSelector = 'main#site-main .wp-block-post-content';
		const editorContentSelector = '.wp-block-post-content';
		const styleProperties = [
			'font-family',
			'font-size',
			'line-height',
			'color',
			'background-color',
			'padding-top',
			'padding-right',
			'padding-bottom',
			'padding-left',
			'gap',
		];

		const frontend = await captureFrontendRegion(
			page,
			parityPage.path,
			parityPage.maskSelectors,
			frontendContentSelector
		);
		const frontendWidth = await layoutWidth(
			page,
			frontendContentSelector
		);
		const frontendStyles = await computedStyles(
			page,
			frontendContentSelector,
			styleProperties
		);

		await loginAs( page, CREDS.clientEditor.u, CREDS.clientEditor.p );

		const postId = await resolvePageId( page, parityPage.path );
		const canvasShot = await captureEditorCanvas(
			page,
			`/page/${ postId }`,
			parityPage.maskSelectors,
			editorContentSelector,
			frontendWidth,
			EDITING_EDITOR_ONLY_SELECTORS[ parityPage.name ] ?? []
		);
		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		const canvasWidth = await effectiveCanvasWidth(
			page,
			editorContentSelector
		);

		expect(
			Math.abs( canvasWidth - frontendWidth ),
			`${ parityPage.name }: the editor canvas is ${ canvasWidth }px wide but the frontend captured at ${ frontendWidth }px. Geometry cannot be compared across different layout widths.`
		).toBeLessThanOrEqual( 1 );

		const canvasStyles = await computedStyles(
			canvas,
			editorContentSelector,
			styleProperties
		);

		await testInfo.attach( `${ parityPage.name }-frontend`, {
			body: frontend,
			contentType: 'image/png',
		} );
		await testInfo.attach( `${ parityPage.name }-canvas`, {
			body: canvasShot,
			contentType: 'image/png',
		} );

		const diffOut = testInfo.outputPath(
			`${ parityPage.name }-editing-diff.png`
		);
		const { diffRatio } = compareBuffers(
			frontend,
			canvasShot,
			parityPage.maxDiffRatio,
			diffOut
		);

		expect(
			diffRatio,
			`${
				parityPage.name
			}: the Site Editor canvas differs from the frontend by ${ (
				diffRatio * 100
			).toFixed( 2 ) }% (limit ${ (
				parityPage.maxDiffRatio * 100
			).toFixed( 2 ) }%). Diff written to ${ diffOut }.`
		).toBeLessThanOrEqual( parityPage.maxDiffRatio );

		expect( canvasStyles ).toEqual( frontendStyles );
	} );
}
