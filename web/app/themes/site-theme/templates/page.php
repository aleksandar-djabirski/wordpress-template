<?php
/**
 * Template for a single Page. Real markup lives here — the root-level
 * page.php is a thin delegate to this file (see its docblock).
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
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class(); ?>>
			<h1 class="entry-title"><?php the_title(); ?></h1>
			<div class="entry-content"><?php the_content(); ?></div>
		</article>
		<?php
	endwhile;
	?>
</main>
<?php
get_footer();
