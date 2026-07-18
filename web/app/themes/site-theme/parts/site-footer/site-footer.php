<?php
/**
 * Site footer chrome: footer navigation + copyright line. Non-editable by
 * design (this is a "part", not a block) — rendered once per request by
 * root footer.php via \SiteTheme\Support\Parts::render().
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<footer class="site-footer">
	<?php
	wp_nav_menu(
		array(
			'theme_location'       => 'footer',
			'container'            => 'nav',
			'container_class'      => 'site-footer__nav',
			'container_aria_label' => __( 'Footer', 'site-theme' ),
			'fallback_cb'          => false,
			'menu_class'           => 'site-footer__menu',
		)
	);
	?>
	<p class="site-footer__copyright">
		<?php
		printf(
			/* translators: 1: current year (gmdate(), not date() — see WordPress.DateTime.RestrictedFunctions), 2: site name. */
			esc_html__( '© %1$s %2$s. All rights reserved.', 'site-theme' ),
			esc_html( gmdate( 'Y' ) ),
			esc_html( get_bloginfo( 'name' ) )
		);
		?>
	</p>
</footer>
