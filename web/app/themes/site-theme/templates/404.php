<?php
/**
 * Template for the 404 Not Found response. Real markup lives here — the
 * root-level 404.php is a thin delegate to this file (see its docblock).
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="site-main" class="site-main">
	<header class="not-found-header">
		<h1><?php esc_html_e( 'Nothing here', 'site-theme' ); ?></h1>
		<p><?php esc_html_e( 'The page you were looking for could not be found. Try a search instead.', 'site-theme' ); ?></p>
	</header>

	<?php get_search_form(); ?>
</main>
<?php
get_footer();
