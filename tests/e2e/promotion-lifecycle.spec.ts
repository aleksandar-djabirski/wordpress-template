import { expect, test } from '@playwright/test';
import { rmSync } from 'fs';
import {
	capturePartFile,
	commitPreparedFile,
	promotionEnv,
	repoIsClean,
	restoreOriginalPartFile,
	wpCli,
	wpCliAvailable,
} from './helpers/promotion';

const PART = 'parts/site-header.html';

test.describe( 'promotion lifecycle keeps the frontend stable', () => {
	let original = '';

	test.beforeAll( () => {
		if ( ! wpCliAvailable() ) {
			throw new Error(
				'WP-CLI is required for the promotion lifecycle gate.'
			);
		}
		if ( ! repoIsClean() ) {
			throw new Error(
				'The promotion lifecycle gate requires a clean working tree.'
			);
		}
		original = capturePartFile( PART );
		wpCli( [] );
	} );

	test.afterAll( () => {
		wpCli(
			[ 'eval-file', 'tests/fixtures/promotion/cleanup.php' ],
			promotionEnv()
		);
		rmSync( 'var/agency-state/e2e-bundle.json', { force: true } );
		rmSync( 'var/agency-state/e2e-manifest.json', { force: true } );
		rmSync( 'var/agency-state/promotions', {
			recursive: true,
			force: true,
		} );
	} );

	test( 'the frontend is unchanged by prepare and finalize, and rollback restores it', async ( {
		page,
	} ) => {
		const env = promotionEnv();

		// 1. Baseline: what a visitor sees from the Git-owned template part.
		await page.goto( '/' );
		const gitText = await page
			.locator( 'header.wp-block-template-part' )
			.innerText();

		// 2. A client edit lands in the database.
		wpCli(
			[
				'eval-file',
				'tests/fixtures/promotion/create-part-override.php',
			],
			env
		);
		await page.reload();
		const overriddenText = await page
			.locator( 'header.wp-block-template-part' )
			.innerText();
		expect( overriddenText ).not.toBe( gitText );

		// 3. Export, prepare, commit, seal, finalize.
		wpCli(
			[
				'agency',
				'state-export',
				'--output=var/agency-state/e2e-bundle.json',
			],
			env
		);
		wpCli(
			[
				'agency',
				'promote-overrides',
				'--prepare',
				'--source=var/agency-state/e2e-bundle.json',
				'--select=template-parts:site-header',
				'--manifest=var/agency-state/e2e-manifest.json',
			],
			env
		);

		const sha = commitPreparedFile( PART );
		wpCli(
			[
				'agency',
				'promote-overrides',
				'--seal',
				'--manifest=var/agency-state/e2e-manifest.json',
				`--deploy-commit=${ sha }`,
			],
			env
		);
		wpCli(
			[
				'agency',
				'promote-overrides',
				'--finalize',
				'--manifest=var/agency-state/e2e-manifest.json',
			],
			{ ...env, AGENCY_DEPLOY_COMMIT: sha }
		);

		// 4. The visitor sees exactly the same thing — promotion is invisible.
		await page.reload();
		expect(
			await page.locator( 'header.wp-block-template-part' ).innerText()
		).toBe( overriddenText );

		// 5. Simulate a deployment rollback: put the original file back.
		restoreOriginalPartFile( PART, original );
		await page.reload();
		expect(
			await page.locator( 'header.wp-block-template-part' ).innerText()
		).toBe( gitText );

		// 6. `--rollback` re-applies the database override, so the client's work returns.
		wpCli(
			[
				'agency',
				'promote-overrides',
				'--rollback',
				'--manifest=var/agency-state/e2e-manifest.json',
			],
			{ ...env, AGENCY_DEPLOY_COMMIT: sha }
		);
		await page.reload();
		expect(
			await page.locator( 'header.wp-block-template-part' ).innerText()
		).toBe( overriddenText );
	} );
} );
