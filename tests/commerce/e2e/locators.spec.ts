import { test } from '@playwright/test';
import { verifyBlockCheckoutLocators } from './helpers/checkout';

/**
 * Contract check for helpers/checkout.ts. It runs FIRST in intent: when a
 * WooCommerce release renames a checkout label, this spec fails with the exact
 * locator name instead of every journey timing out somewhere in the middle.
 */
test.describe( 'block checkout locator contract', () => {
	test.skip(
		process.env.COMMERCE !== '1',
		'commerce profile only — set COMMERCE=1 to run'
	);

	test( 'every block checkout locator resolves on the live store', async ( {
		page,
	} ) => {
		test.setTimeout( 120_000 );
		await verifyBlockCheckoutLocators( page );
	} );
} );
