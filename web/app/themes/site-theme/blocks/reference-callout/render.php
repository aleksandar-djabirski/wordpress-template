<?php
/**
 * Server-side render for the `agency/reference-callout` block.
 *
 * This is the theme's only cross-layer call: everywhere else in site-theme
 * stays inside its own boundary, but this block deliberately demonstrates
 * consuming SiteCore\Contracts\Testimonials — the ONLY site-core namespace
 * the theme may depend on (see deptrac.yaml's SiteTheme -> SiteCoreContracts
 * rule). The class_exists() guard keeps this block safe to render even if
 * site-core is ever deactivated: the testimonial section simply disappears
 * rather than fataling.
 *
 * WordPress provides $attributes, $content, and $block in scope when it
 * includes this file as the block's `render` callback.
 *
 * @var array{heading?: string, content?: string, showTestimonial?: bool} $attributes
 * @var string                                                            $content
 * @var WP_Block                                                          $block
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$heading          = (string) ( $attributes['heading'] ?? '' );
$body             = (string) ( $attributes['content'] ?? '' );
$show_testimonial = (bool) ( $attributes['showTestimonial'] ?? false );

$testimonial = null;

if ( $show_testimonial && class_exists( \SiteCore\Contracts\Testimonials::class ) ) {
	$latest = \SiteCore\Contracts\Testimonials::latest( 1 );

	if ( array() !== $latest ) {
		$testimonial = $latest[0];
	}
}
?>
<section <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() already returns pre-escaped HTML attributes. ?>>
	<?php if ( '' !== $heading ) : ?>
		<h2 class="reference-callout__heading"><?php echo esc_html( $heading ); ?></h2>
	<?php endif; ?>

	<?php if ( '' !== $body ) : ?>
		<p class="reference-callout__content"><?php echo esc_html( $body ); ?></p>
	<?php endif; ?>

	<?php if ( null !== $testimonial ) : ?>
		<blockquote class="reference-callout__testimonial">
			<p><?php echo wp_kses_post( $testimonial['content'] ); ?></p>
			<cite><?php echo esc_html( $testimonial['author'] ); ?></cite>
		</blockquote>
	<?php endif; ?>
</section>
