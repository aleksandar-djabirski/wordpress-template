<?php
/**
 * Title: Split Content
 * Slug: agency/split-content
 * Categories: text
 * Inserter: yes
 * Description: A two-column section: a heading and body copy beside supporting copy.
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
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
