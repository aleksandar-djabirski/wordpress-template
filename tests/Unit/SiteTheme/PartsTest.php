<?php

declare(strict_types=1);

namespace Tests\Unit\SiteTheme;

use PHPUnit\Framework\TestCase;
use SiteTheme\Support\Parts;

/**
 * @covers \SiteTheme\Support\Parts
 */
final class PartsTest extends TestCase {

	private const THEME_ROOT = __DIR__ . '/../../../web/app/themes/site-theme';

	public function test_manifest_contains_exactly_the_two_known_parts(): void {
		self::assertSame( array( 'site-header', 'site-footer' ), Parts::MANIFEST );
	}

	public function test_every_manifest_part_has_a_php_template_on_disk(): void {
		foreach ( Parts::MANIFEST as $part ) {
			self::assertFileExists( self::THEME_ROOT . "/parts/{$part}/{$part}.php" );
		}
	}

	public function test_site_header_assets_returns_existing_css_and_js_paths(): void {
		$assets = Parts::assets( 'site-header' );

		self::assertSame( array( 'css', 'js' ), array_keys( $assets ) );
		self::assertFileExists( self::THEME_ROOT . '/' . $assets['css'] );
		self::assertFileExists( self::THEME_ROOT . '/' . $assets['js'] );
	}

	public function test_site_footer_assets_returns_css_only_since_it_has_no_js_file(): void {
		$assets = Parts::assets( 'site-footer' );

		self::assertSame( array( 'css' ), array_keys( $assets ) );
		self::assertFileExists( self::THEME_ROOT . '/' . $assets['css'] );
	}

	public function test_assets_for_an_unknown_part_returns_an_empty_array(): void {
		self::assertSame( array(), Parts::assets( 'nonexistent' ) );
	}

	public function test_render_of_an_unknown_part_fails_soft_without_throwing_or_output(): void {
		ob_start();
		Parts::render( 'nonexistent' );
		$output = ob_get_clean();

		// No exception/fatal is the primary assertion here (implicit: this
		// test method completing at all): an unknown part must never take
		// down header.php/footer.php on a live site. It also must not
		// require/echo anything.
		self::assertSame( '', $output );
	}
}
