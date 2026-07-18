<?php
/**
 * Template for archives (category/tag/date/author/CPT archives). Real
 * markup lives here — the root-level archive.php is a thin delegate to
 * this file (see its docblock).
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
	<header class="archive-header">
		<h1 class="archive-title"><?php the_archive_title(); ?></h1>
		<?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
	</header>

	<?php if ( have_posts() ) : ?>
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class(); ?>>
				<h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
				<div class="entry-summary"><?php the_excerpt(); ?></div>
			</article>
			<?php
		endwhile;

		the_posts_pagination();
		?>
	<?php else : ?>
		<p><?php esc_html_e( 'Nothing found.', 'site-theme' ); ?></p>
	<?php endif; ?>
</main>
<?php
get_footer();
