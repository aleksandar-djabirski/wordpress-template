import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from '@playwright/test';
import { MIGRATION_BASELINE_DIR, MIGRATION_PARITY_PAGES, captureFrontend } from './helpers/parity';

/**
 * Writes the IMMUTABLE pre-migration baselines. Deliberately writes PNGs with
 * fs.writeFileSync instead of toHaveScreenshot(), so `--update-snapshots` can
 * never regenerate them: regenerating a migration baseline requires running
 * this spec explicitly with CAPTURE_MIGRATION_BASELINE=1 against the classic
 * theme, which no longer exists after the migration lands.
 */
test.describe( 'migration baseline capture', () => {
	test.skip(
		process.env.CAPTURE_MIGRATION_BASELINE !== '1',
		'capture-only spec — set CAPTURE_MIGRATION_BASELINE=1 to run'
	);

	for ( const parityPage of MIGRATION_PARITY_PAGES ) {
		test( `capture ${ parityPage.name }`, async ( { page }, testInfo ) => {
			const buffer = await captureFrontend( page, parityPage.path, parityPage.maskSelectors );
			const dir = join( MIGRATION_BASELINE_DIR, testInfo.project.name );
			mkdirSync( dir, { recursive: true } );
			writeFileSync( join( dir, `${ parityPage.name }.png` ), buffer );
		} );
	}
} );
