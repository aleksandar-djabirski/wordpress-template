<?php
/**
 * Template for search results. Real markup lives here — the root-level
 * search.php is a thin delegate to this file (see its docblock).
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
	<header class="search-header">
		<h1 class="search-title">
			<?php
			printf(
				/* translators: %s: search query. */
				esc_html__( 'Search results for: %s', 'site-theme' ),
				get_search_query() // Already esc_attr()-escaped by default; wrapping in esc_html() too would double-encode entities like "&".
			);
			?>
		</h1>
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
		<p><?php esc_html_e( 'Nothing found. Try a different search.', 'site-theme' ); ?></p>
		<?php get_search_form(); ?>
	<?php endif; ?>
</main>
<?php
get_footer();
