/**
 * ESLint flat config for the theme's frontend sources.
 *
 * `wp-scripts lint-js` uses its own default config
 * (@wordpress/scripts/config/eslint.config.cjs — @wordpress/eslint-plugin's
 * "recommended" set) unless the project provides one, in which case that
 * default is used instead of being merged in. We reuse it as-is and only
 * add one targeted override: parts/**\/*.js (e.g. site-header.js) are
 * deliberately plain, build-free vanilla IIFEs enqueued directly by
 * ThemeBootstrap::enqueue_assets() — see that file's own header comment —
 * so the `esnext`-oriented `no-var` rule (aimed at code that goes through
 * @wordpress/scripts' Babel/webpack pipeline) doesn't apply to them. Every
 * other recommended rule (formatting, JSDoc, i18n, accessibility, etc.)
 * still applies everywhere, including those files.
 */

const wpDefaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...wpDefaultConfig,
	{
		files: [ 'web/app/themes/site-theme/parts/**/*.js' ],
		rules: {
			'no-var': 'off',
		},
	},
];
