import { expect, test } from '@playwright/test';

/**
 * Full-page visual regression baselines for the home page.
 *
 * NOTE (spec §20): these screenshots are only authoritative when generated
 * on Linux — see the top-of-file comment in playwright.config.ts. Do not
 * run `--update-snapshots` locally on Windows/macOS and commit the result;
 * baselines are produced by the documented CI flow (Task 11's ci.yml).
 *
 * Nothing is masked yet: the home page (a fresh WordPress install's default
 * front page) has no dynamic/non-deterministic content — no dates, no
 * live-updating widgets — so full-page screenshots are stable as-is.
 */

test( 'home page (desktop)', async ( { page }, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-desktop', 'desktop-only baseline' );

	await page.goto( '/' );
	await expect( page ).toHaveScreenshot( 'home-desktop.png', { fullPage: true } );
} );

test( 'home page (mobile)', async ( { page }, testInfo ) => {
	test.skip( testInfo.project.name !== 'chromium-mobile', 'mobile-only baseline' );

	await page.goto( '/' );
	await expect( page ).toHaveScreenshot( 'home-mobile.png', { fullPage: true } );
} );
