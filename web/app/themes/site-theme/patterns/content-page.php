<?php
/**
 * Title: Content Page
 * Slug: agency/content-page
 * Categories: pages
 * Inserter: yes
 * Post Types: page
 * Description: A complete starting page: hero, split content, feature grid, and a closing call to action.
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">

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

<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading -->
<h2 class="wp-block-heading"><?php echo esc_html__( 'Built to be handed over', 'site-theme' ); ?></h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Every layout decision lives in Git, so a new developer — or a coding agent — can read the site instead of guessing at it.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Clients still get real visual control: templates, header, footer, navigation, colours and typography are all editable in the Site Editor.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p><?php echo esc_html__( 'What they cannot reach is the code editor, raw HTML, shortcodes, or Additional CSS — the four ways a site normally becomes unmaintainable.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php echo esc_html__( 'Tokens, not hex codes', 'site-theme' ); ?></h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Colours, spacing and the type scale live in theme.json and stay consistent on every page.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php echo esc_html__( 'Guardrails, not lockouts', 'site-theme' ); ?></h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Clients edit the whole site visually; the code editor, raw HTML and shortcodes stay closed.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading"><?php echo esc_html__( 'Tested, not hoped for', 'site-theme' ); ?></h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Architecture, unit, integration, end-to-end and visual suites gate every commit.', 'site-theme' ); ?></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

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

</div>
<!-- /wp:group -->
