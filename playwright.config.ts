import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for the agency-starter e2e/visual/accessibility
 * suites (Task 10).
 *
 * The site under test is EXTERNAL to this config: a DDEV install
 * (https://agency-starter.ddev.site by default, overridable via
 * WP_BASE_URL). There is deliberately NO `webServer` entry here — this repo
 * does not know how to boot WordPress itself (that's DDEV's/CI's job via
 * `scripts/setup`), so Playwright is never responsible for starting the
 * site. Run `ddev start && bash scripts/setup` (or the CI equivalent)
 * before invoking `npm run test:e2e` / `test:visual` / `test:accessibility`.
 *
 * VISUAL SNAPSHOTS ARE LINUX-CI-AUTHORITATIVE (spec §20): browser font
 * hinting/anti-aliasing differs across Windows/macOS/Linux, so
 * `tests/visual/**` screenshots generated on a non-Linux machine will not
 * match what CI produces and must never be committed as baselines locally.
 * Baselines are generated/updated exclusively through the documented CI
 * flow (Task 11's ci.yml) running on Linux runners.
 */
export default defineConfig({
	testDir: './tests',
	fullyParallel: true,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,
	reporter: [
		[ 'list' ],
		[ 'html', { outputFolder: 'playwright-report', open: 'never' } ],
	],
	// Screenshot baselines live next to the spec that owns them, grouped by
	// project, rather than Playwright's default `-snapshots` sibling
	// directory naming.
	snapshotPathTemplate: '{testDir}/{testFileDir}/__screenshots__/{projectName}/{arg}{ext}',
	use: {
		baseURL: process.env.WP_BASE_URL ?? 'https://agency-starter.ddev.site',
		// DDEV serves local HTTPS via a self-signed certificate.
		ignoreHTTPSErrors: true,
		trace: 'on-first-retry',
	},
	expect: {
		toHaveScreenshot: {
			maxDiffPixelRatio: 0.01,
		},
	},
	projects: [
		{
			name: 'chromium-desktop',
			use: {
				...devices[ 'Desktop Chrome' ],
				viewport: { width: 1280, height: 800 },
			},
		},
		{
			name: 'chromium-mobile',
			use: {
				...devices[ 'Pixel 7' ],
			},
		},
	],
	// No `webServer`: the target site is an external DDEV/CI environment,
	// never something this config launches itself.
});
