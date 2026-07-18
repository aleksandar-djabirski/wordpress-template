<?php
/**
 * Site header chrome: branding + primary navigation with a mobile toggle.
 * Non-editable by design (this is a "part", not a block) — rendered once
 * per request by root header.php via \SiteTheme\Support\Parts::render().
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$description = get_bloginfo( 'description', 'display' );
?>
<header class="site-header">
	<div class="site-header__branding">
		<a class="site-header__site-title" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
		</a>
		<?php if ( '' !== $description ) : ?>
			<p class="site-header__tagline"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
	</div>

	<button type="button" class="site-header__toggle" aria-expanded="false" aria-controls="site-header-nav">
		<span class="site-header__toggle-bar"></span>
		<span class="site-header__toggle-bar"></span>
		<span class="site-header__toggle-bar"></span>
		<span class="screen-reader-text"><?php esc_html_e( 'Menu', 'site-theme' ); ?></span>
	</button>

	<?php
	wp_nav_menu(
		array(
			'theme_location'       => 'primary',
			'container'            => 'nav',
			'container_id'         => 'site-header-nav',
			'container_class'      => 'site-header__nav',
			'container_aria_label' => __( 'Primary', 'site-theme' ),
			'fallback_cb'          => false,
			'menu_class'           => 'site-header__menu',
		)
	);
	?>
</header>
