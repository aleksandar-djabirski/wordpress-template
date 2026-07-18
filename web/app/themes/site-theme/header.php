<?php
/**
 * Real theme chrome: the page shell before <main> begins — the <!doctype>,
 * <html>, <head> (via wp_head()), and the opening <body> tag. This lives at
 * the theme root as a genuine template (not a templates/ delegate, unlike
 * index.php/page.php/single.php/archive.php/search.php/404.php) because it
 * IS chrome, not per-request page content: WordPress's get_header() always
 * looks for header.php at the theme root, and site-header markup itself
 * lives in parts/site-header/site-header.php, rendered through
 * \SiteTheme\Support\Parts so it stays a reusable, independently
 * unit-testable "part" rather than inline markup here.
 *
 * templates/*.php open <main id="site-main" class="site-main"> themselves
 * (and close it before calling get_footer()) — this file deliberately
 * stops right after the site header.
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link" href="#site-main"><?php esc_html_e( 'Skip to content', 'site-theme' ); ?></a>
<?php \SiteTheme\Support\Parts::render( 'site-header' ); ?>
