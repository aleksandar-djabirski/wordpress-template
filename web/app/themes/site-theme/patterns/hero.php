<?php
/**
 * Title: Hero
 * Slug: agency/hero
 * Categories: featured
 * Inserter: yes
 * Description: A full-width introduction — headline, supporting sentence, and a primary call-to-action button.
 *
 * Unlocked on purpose: the editing posture gives client roles full control
 * within the approved block system, so a pattern is a sanctioned STARTING
 * composition, not a cage. Lock a pattern only when its internal semantics
 * require it (see patterns/reference-landing-section.php).
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!-- wp:group {"className":"is-style-hero","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-hero">

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading"><?php echo esc_html__( 'Ship client sites without losing the design to a database', 'site-theme' ); ?></h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Design tokens, editor guardrails, and dynamic blocks that keep working together — from the first commit to the client\'s hundredth edit.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#"><?php echo esc_html__( 'Start a project', 'site-theme' ); ?></a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->

</div>
<!-- /wp:group -->
