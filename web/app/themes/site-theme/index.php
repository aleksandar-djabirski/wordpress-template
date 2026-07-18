<?php
/**
 * Thin root-level delegate. WordPress's classic-theme template hierarchy
 * requires index.php to exist at the theme root — this project's spec
 * additionally requires templates/ to own all real layout markup, so this
 * file does nothing but hand off. See templates/index.php for the actual
 * template; header.php/footer.php are the only root-level files with real
 * markup, since they ARE theme chrome (see their own docblocks).
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/templates/index.php';
