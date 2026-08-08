import { test, expect } from '@playwright/test';

/**
 * Promotion verification drill. It exists to make `scripts/promote-overrides`
 * take its ROLLBACK branch on demand, so the auto-rollback path is proven
 * rather than assumed.
 *
 * How it stays inert:
 *
 * - No npm script can run it by accident. Every script passes an explicit
 *   path that never includes `tests/promotion/`: `test:e2e` targets
 *   `tests/e2e`, `test:visual` targets `tests/visual`,
 *   `test:accessibility` targets `tests/accessibility`, and
 *   `test:e2e:commerce` targets `tests/commerce/e2e`.
 * - A bare `npx playwright test` WOULD collect it: `playwright.config.ts`
 *   sets `testDir` to `./tests`, and the two default projects ignore only
 *   `tests/parity/` and `tests/e2e/promotion-lifecycle.spec.ts`. It is
 *   reported as skipped in that case, not run — the `test.skip()` guard
 *   below fires because AGENCY_PROMOTION_FAILURE_DRILL is unset.
 * - The promotion wrapper runs it on purpose, with
 *   AGENCY_PROMOTION_FAILURE_DRILL=1 and AGENCY_PLAYWRIGHT_TESTS pointing
 *   at this file, so the single test collects and fails exactly once.
 */
test.describe( 'promotion verification drill', () => {
	test.skip(
		process.env.AGENCY_PROMOTION_FAILURE_DRILL !== '1',
		'drill only — set AGENCY_PROMOTION_FAILURE_DRILL=1 to make verification fail on purpose'
	);

	test( 'fails on purpose so promotion verification rolls back', async ( { page } ) => {
		await page.goto( '/' );
		// A second navigation keeps the run longer than the wrapper's
		// heartbeat interval, so at least one --heartbeat provably fires
		// while verification is still running. The assertion below still
		// fails on purpose; the collected-test count stays exactly one.
		await page.goto( '/sample-page/' );
		expect( 'promotion-verification-drill' ).toBe( 'this assertion always fails' );
	} );
} );
