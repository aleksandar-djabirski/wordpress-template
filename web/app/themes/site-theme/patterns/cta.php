<?php
/**
 * Title: Call To Action
 * Slug: agency/cta
 * Categories: call-to-action
 * Inserter: yes
 * Description: A closing call-to-action band with a heading, a sentence, and one button.
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!-- wp:group {"className":"is-style-cta","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-cta">

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'Ready when you are', 'site-theme' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Clone the template, rename the project, and start designing.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#"><?php echo esc_html__( 'Get started', 'site-theme' ); ?></a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->

</div>
<!-- /wp:group -->
