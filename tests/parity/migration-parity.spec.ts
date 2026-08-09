import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import {
	MIGRATION_BASELINE_DIR,
	MIGRATION_PARITY_PAGES,
	captureFrontend,
	compareToBaseline,
} from './helpers/parity';

/**
 * Compares today's block-theme frontend with the immutable classic-theme
 * screenshots captured before the migration.
 */
for ( const parityPage of MIGRATION_PARITY_PAGES ) {
	test( `migration parity: ${ parityPage.name }`, async ( {
		page,
	}, testInfo ) => {
		const actual = await captureFrontend(
			page,
			parityPage.path,
			parityPage.maskSelectors
		);
		const baseline = join(
			MIGRATION_BASELINE_DIR,
			testInfo.project.name,
			`${ parityPage.name }.png`
		);
		const diffOut = testInfo.outputPath(
			`${ parityPage.name }-migration-diff.png`
		);

		const { diffRatio } = compareToBaseline(
			actual,
			baseline,
			parityPage.maxDiffRatio,
			diffOut
		);

		await testInfo.attach( `${ parityPage.name }-actual`, {
			body: actual,
			contentType: 'image/png',
		} );

		expect(
			diffRatio,
			`${
				parityPage.name
			} differs from its pre-migration baseline by ${ (
				diffRatio * 100
			).toFixed( 2 ) }% (limit ${ (
				parityPage.maxDiffRatio * 100
			).toFixed( 2 ) }%). Diff written to ${ diffOut }.`
		).toBeLessThanOrEqual( parityPage.maxDiffRatio );
	} );
}
