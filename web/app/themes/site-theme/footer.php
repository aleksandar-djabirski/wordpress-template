<?php
/**
 * Real theme chrome: the page shell after </main> ends — mirrors
 * header.php's role and reasoning (see that file's docblock for why these
 * two stay real root-level files instead of templates/ delegates).
 * WordPress's get_footer() always looks for footer.php at the theme root.
 *
 * @package SiteTheme
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\SiteTheme\Support\Parts::render( 'site-footer' );
wp_footer();
?>
</body>
</html>
