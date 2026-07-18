<?php
/**
 * Title: Reference Landing Section
 * Slug: agency/reference-landing-section
 * Categories: featured
 * Inserter: yes
 * Description: A locked hero composition — heading, intro paragraph, reference callout, and a call-to-action button — for building landing pages quickly without breaking the approved layout.
 *
 * WordPress auto-registers every *.php file under patterns/ from the
 * header comment above (see wp-includes/theme.php's
 * _register_theme_block_patterns()) and captures this file's *output* via
 * output buffering as the pattern content — nothing here is ever called
 * directly, so the ABSPATH guard below only matters if the file is somehow
 * requested directly.
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!-- wp:group {"templateLock":"contentOnly","layout":{"type":"constrained"}} -->
<div class="wp-block-group">

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading"><?php echo esc_html__( 'Built for agencies who ship fast', 'site-theme' ); ?></h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo esc_html__( 'A starter that keeps design tokens, editor guardrails, and dynamic blocks working together out of the box.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:agency/reference-callout {"heading":"What clients say","showTestimonial":true} /-->

<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#"><?php echo esc_html__( 'Get started', 'site-theme' ); ?></a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->

</div>
<!-- /wp:group -->
