import { spawnSync } from 'child_process';
import { readFileSync, writeFileSync } from 'fs';
import { join } from 'path';

/**
 * Promotion-lifecycle helpers for tests/e2e/promotion-lifecycle.spec.ts
 * (plan Task 20, master spec §11.14): run the `wp agency` promotion
 * commands against the site under test, read and restore the theme's
 * Git-owned template part, and resolve the AGENCY_* environment the
 * promotion commands require — the site's own .env carries none of them.
 * The git helpers run in the Playwright process (git works natively on
 * the host and in CI); every wpCli() call goes through WP-CLI, which
 * runs where the site runs.
 */

type WpResolution = {
	command: string;
	args: string[];
};

/**
 * Runs a WP-CLI command against the site under test. Resolves `wp` on PATH
 * first (CI/DDEV container), then falls back to `ddev wp` (host runs).
 * `env` is merged into the child process environment.
 */
export function wpCli( args: string[], env: NodeJS.ProcessEnv = {} ): string {
	const wp = resolveWp();

	if ( wp === null ) {
		throw new Error( 'WP-CLI is required for the promotion lifecycle gate.' );
	}

	const result = spawnSync( wp.command, [ ...wp.args, ...args ], {
		env: { ...process.env, ...env },
		encoding: 'utf8',
	} );

	if ( result.status !== 0 ) {
		const stderr = ( result.stderr ?? '' ).trim();
		throw new Error(
			`wp ${ args.join( ' ' ) } exited with code ${ result.status ?? 'null' }${ stderr ? `: ${ stderr }` : '' }`
		);
	}

	return result.stdout ?? '';
}

/**
 * True when a WP-CLI command can actually be executed here.
 */
export function wpCliAvailable(): boolean {
	return resolveWp() !== null;
}

/**
 * True when `git status --porcelain` is empty — the precondition for mutating the checkout.
 */
export function repoIsClean(): boolean {
	const result = spawnSync( 'git', [ 'status', '--porcelain' ], { encoding: 'utf8' } );

	if ( result.status !== 0 ) {
		throw new Error( `git status failed: ${ ( result.stderr ?? '' ).trim() }` );
	}

	return ( result.stdout ?? '' ).trim() === '';
}

/**
 * Absolute path of the active theme directory, read from WP-CLI.
 *
 * `wp theme path` without an argument returns the themes DIRECTORY, so the
 * active theme's own directory comes from get_stylesheet_directory().
 */
export function themeDir(): string {
	const dir = wpCli( [ 'eval', 'echo get_stylesheet_directory();' ] ).trim();

	if ( dir === '' ) {
		throw new Error( 'Could not resolve the active theme directory.' );
	}

	return dir;
}

/**
 * Current bytes of a theme-relative file, for later restoration.
 */
export function capturePartFile( themeRelativePath: string ): string {
	return readFileSync( join( themeDir(), themeRelativePath ), 'utf8' );
}

/**
 * Writes previously captured bytes back into the theme.
 */
export function restoreOriginalPartFile( themeRelativePath: string, contents: string ): void {
	writeFileSync( join( themeDir(), themeRelativePath ), contents );
}

/**
 * Stages ONLY the given theme-relative file and commits it. Returns the new SHA.
 *
 * The commit identity and signing are pinned inline so the commit works on
 * any host, configured or not; only the one file is ever staged — never
 * the whole theme directory.
 */
export function commitPreparedFile( themeRelativePath: string ): string {
	const directory = themeDir();

	const stage = spawnSync( 'git', [ '-C', directory, 'add', '--', themeRelativePath ], { encoding: 'utf8' } );

	if ( stage.status !== 0 ) {
		throw new Error( `git add failed: ${ ( stage.stderr ?? '' ).trim() }` );
	}

	const commit = spawnSync(
		'git',
		[
			'-C',
			directory,
			'-c',
			'user.email=promotion-e2e@example.invalid',
			'-c',
			'user.name=Promotion E2E',
			'-c',
			'commit.gpgsign=false',
			'commit',
			'-m',
			`promote ${ themeRelativePath }`,
		],
		{ encoding: 'utf8' }
	);

	if ( commit.status !== 0 ) {
		throw new Error( `git commit failed: ${ ( commit.stderr ?? '' ).trim() }` );
	}

	const head = spawnSync( 'git', [ '-C', directory, 'rev-parse', 'HEAD' ], { encoding: 'utf8' } );

	if ( head.status !== 0 ) {
		throw new Error( `git rev-parse failed: ${ ( head.stderr ?? '' ).trim() }` );
	}

	const sha = ( head.stdout ?? '' ).trim();

	if ( ! /^[0-9a-f]{40}$/.test( sha ) ) {
		throw new Error( `Expected a 40-hex commit sha, got "${ sha }".` );
	}

	return sha;
}

/**
 * Every AGENCY_* value the promotion commands need, resolved from the live site.
 */
export function promotionEnv(): NodeJS.ProcessEnv {
	return {
		AGENCY_PROMOTION_HMAC_KEYS: JSON.stringify( { 'e2e': 'e2e-promotion-key-that-is-long-enough-32+' } ),
		AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID: 'e2e',
		AGENCY_TARGET_SITE_UUID: wpCli( [ 'option', 'get', 'agency_platform_site_uuid' ] ).trim(),
		AGENCY_STATE_DIR: 'var/agency-state',
		AGENCY_REPO_ROOT: process.cwd(),
	};
}

/**
 * Resolves the WP-CLI entry point: `wp` on PATH (inside a CI/DDEV
 * container) or `ddev wp` (host runs against a DDEV project).
 */
function resolveWp(): WpResolution | null {
	const direct = spawnSync( 'wp', [ '--version' ], { encoding: 'utf8' } );

	if ( direct.status === 0 ) {
		return { command: 'wp', args: [] };
	}

	const ddev = spawnSync( 'ddev', [ 'wp', '--version' ], { encoding: 'utf8' } );

	if ( ddev.status === 0 ) {
		return { command: 'ddev', args: [ 'wp' ] };
	}

	return null;
}
