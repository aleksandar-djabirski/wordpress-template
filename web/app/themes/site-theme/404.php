<?php
/**
 * Thin root-level delegate — see index.php's docblock for why this file
 * only requires templates/404.php rather than containing markup itself.
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/templates/404.php';
