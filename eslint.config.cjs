/**
 * ESLint flat config for the theme's frontend sources.
 *
 * `wp-scripts lint-js` uses its own default config
 * (@wordpress/scripts/config/eslint.config.cjs — @wordpress/eslint-plugin's
 * "recommended" set) unless the project provides one, in which case that
 * default is used instead of being merged in. We reuse it as-is because
 * parts/ now holds HTML-only block markup; the old vanilla JS override is no
 * longer needed. Every recommended rule (formatting, JSDoc, i18n,
 * accessibility, etc.) still applies to the block sources.
 */

const wpDefaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

// The former parts/**/*.js override was removed because parts/ is HTML-only.
module.exports = [ ...wpDefaultConfig ];
