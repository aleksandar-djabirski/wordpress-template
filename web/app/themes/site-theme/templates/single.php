<?php
/**
 * Template for a single Post. Real markup lives here — the root-level
 * single.php is a thin delegate to this file (see its docblock).
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
		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}
	endwhile;
	?>
</main>
<?php
get_footer();
