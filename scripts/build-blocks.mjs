#!/usr/bin/env node
/**
 * scripts/build-blocks.mjs — zero-config multi-block build for the theme.
 *
 * WHY a per-block loop instead of wp-scripts' native src-dir discovery:
 * @wordpress/scripts CAN auto-discover block.json entries with
 * `--webpack-src-dir`, but it emits every block's output into ONE shared
 * build/ directory. Our block contract (docs/adding-a-block.md,
 * tests/Architecture/BlockManifestTest) requires PER-BLOCK output at
 * blocks/<slug>/build/index.js, referenced by each block.json's
 * `editorScript: "file:./build/index.js"`. So we run wp-scripts once per
 * block, each with its own --output-path, landing output exactly where
 * block.json expects it and keeping CI's per-block build-drift check
 * meaningful. Adding a block needs ZERO edits here: drop a
 * blocks/<slug>/index.js in place and it is picked up automatically.
 *
 * The wp-scripts invocation mirrors the historical single-block command
 * verbatim — repo-root-relative entry + --output-path, run with cwd at the
 * repo root — so committed build output stays byte-identical.
 *
 * Usage:
 *   node scripts/build-blocks.mjs                 # build every block (npm run build)
 *   node scripts/build-blocks.mjs --watch <slug>  # watch ONE block (npm run start -- <slug>)
 *
 * Watch mode is single-block on purpose: parallel wp-scripts watchers
 * interleave output and cannot be stopped cleanly. `npm run start --
 * reference-callout` watches just that block; run a second terminal for a
 * second block.
 */

import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { existsSync, readdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire( import.meta.url );
const wpScriptsBin = require.resolve( '@wordpress/scripts/bin/wp-scripts.js' );

const repoRoot = dirname( dirname( fileURLToPath( import.meta.url ) ) );

// Repo-root-relative, forward-slash paths (portable across Windows/POSIX and
// identical to the historical single-block build command).
const blocksRel = 'web/app/themes/site-theme/blocks';
const blocksDir = `${ repoRoot }/${ blocksRel }`;

/**
 * Every block directory that carries an index.js source entry, sorted for
 * deterministic build order.
 *
 * @return {string[]} Block slugs.
 */
function discoverBlocks() {
	if ( ! existsSync( blocksDir ) ) {
		return [];
	}

	return readdirSync( blocksDir, { withFileTypes: true } )
		.filter( ( entry ) => entry.isDirectory() )
		.map( ( entry ) => entry.name )
		.filter( ( slug ) => existsSync( `${ blocksDir }/${ slug }/index.js` ) )
		.sort();
}

/**
 * Runs `wp-scripts <command>` for a single block, failing fast on a nonzero
 * exit so a broken block aborts the whole build.
 *
 * @param {string} command wp-scripts subcommand ('build' or 'start').
 * @param {string} slug    Block directory name under blocks/.
 */
function runWpScripts( command, slug ) {
	const result = spawnSync(
		process.execPath,
		[
			wpScriptsBin,
			command,
			`${ blocksRel }/${ slug }/index.js`,
			`--output-path=${ blocksRel }/${ slug }/build`,
		],
		{ cwd: repoRoot, stdio: 'inherit' }
	);

	if ( result.status !== 0 ) {
		process.exit( result.status ?? 1 );
	}
}

const args = process.argv.slice( 2 );
const watch = args.includes( '--watch' );
const named = args.filter( ( arg ) => ! arg.startsWith( '--' ) );

if ( watch ) {
	if ( named.length !== 1 ) {
		process.stderr.write(
			'Watch mode builds ONE block: `npm run start -- <block-slug>` ' +
				'(e.g. `npm run start -- reference-callout`).\n'
		);
		process.exit( 1 );
	}

	const slug = named[ 0 ];

	if ( ! existsSync( `${ blocksDir }/${ slug }/index.js` ) ) {
		process.stderr.write( `No block source at ${ blocksRel }/${ slug }/index.js.\n` );
		process.exit( 1 );
	}

	runWpScripts( 'start', slug );
} else {
	const blocks = named.length > 0 ? named : discoverBlocks();

	if ( blocks.length === 0 ) {
		process.stderr.write( `No blocks with an index.js found under ${ blocksRel }/.\n` );
		process.exit( 1 );
	}

	for ( const slug of blocks ) {
		process.stdout.write( `\n> Building block: ${ slug }\n` );
		runWpScripts( 'build', slug );
	}
}
