<?php
/**
 * Site Theme functions and definitions.
 *
 * Deliberately minimal, and kept that way on purpose (architecture tests
 * enforce a line-count ceiling on this exact file): every real setup step
 * lives in \SiteTheme\Bootstrap\ThemeBootstrap, wired via named hook
 * callbacks rather than closures or inline code here. Bedrock's
 * web/wp-config.php requires vendor/autoload.php before WordPress loads
 * any theme, so SiteTheme\* classes are already autoloadable by the time
 * this file runs — same as every site-* plugin's main file (see e.g.
 * web/app/plugins/site-core/site-core.php).
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\SiteTheme\Bootstrap\ThemeBootstrap::boot();
