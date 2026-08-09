<?php
/**
 * Proves AgencyPlatform\Editor\BlockPolicy resolves the client block set from
 * the REGISTERED blocks (not a hand-maintained list), keeps html/shortcode/
 * freeform permanently denied, and detects every save-time violation class
 * BLOCK_THEME_PROPOSAL.md §9.3 names.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

$wordpress_includes = dirname( __DIR__, 3 ) . '/web/wp/wp-includes';

if ( ! class_exists( 'WP_Block_Parser_Block', false ) ) {
	require_once $wordpress_includes . '/class-wp-block-parser-block.php';
}

if ( ! class_exists( 'WP_Block_Parser_Frame', false ) ) {
	require_once $wordpress_includes . '/class-wp-block-parser-frame.php';
}

if ( ! class_exists( 'WP_Block_Parser', false ) ) {
	require_once $wordpress_includes . '/class-wp-block-parser.php';
}

if ( ! function_exists( 'parse_blocks' ) ) {
	require_once $wordpress_includes . '/blocks.php';
}

use AgencyPlatform\Editor\BlockPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Editor\BlockPolicy
 */
final class BlockPolicyTest extends TestCase {

	/**
	 * @return list<string>
	 */
	private function registered(): array {
		return array(
			'core/paragraph',
			'core/group',
			'core/navigation',
			'core/template-part',
			'core/html',
			'core/shortcode',
			'core/freeform',
			sprintf( 'agency/%s-%s', 'reference', 'callout' ),
			'woocommerce/mini-cart',
			'acme/tracking-pixel',
		);
	}

	/**
	 * @param bool|string[] $incoming
	 * @return bool|string[]
	 */
	private function resolve( bool $privileged, bool|array $incoming ): bool|array {
		return BlockPolicy::resolve(
			$privileged,
			$incoming,
			$this->registered(),
			BlockPolicy::DEFAULT_NAMESPACES,
			array(),
			array()
		);
	}

	public function test_privileged_users_get_the_incoming_value_back_unchanged(): void {
		self::assertTrue( $this->resolve( true, true ) );
		self::assertSame( array( 'core/paragraph' ), $this->resolve( true, array( 'core/paragraph' ) ) );
	}

	public function test_client_roles_get_every_registered_block_in_an_approved_namespace(): void {
		$allowed = $this->resolve( false, true );

		self::assertIsArray( $allowed );
		self::assertContains( 'core/paragraph', $allowed );
		self::assertContains( 'core/group', $allowed );
		self::assertContains( 'core/navigation', $allowed );
		self::assertContains( 'core/template-part', $allowed );
		self::assertContains( sprintf( 'agency/%s-%s', 'reference', 'callout' ), $allowed );
		self::assertContains( 'woocommerce/mini-cart', $allowed );
	}

	public function test_unapproved_namespaces_are_excluded(): void {
		self::assertNotContains( 'acme/tracking-pixel', (array) $this->resolve( false, true ) );
	}

	public function test_html_shortcode_and_freeform_are_always_denied(): void {
		$allowed = (array) $this->resolve( false, true );

		self::assertNotContains( 'core/html', $allowed );
		self::assertNotContains( 'core/shortcode', $allowed );
		self::assertNotContains( 'core/freeform', $allowed );
	}

	public function test_an_incoming_array_restriction_is_intersected_not_replaced(): void {
		$allowed = $this->resolve( false, array( 'core/paragraph', 'core/html', 'acme/tracking-pixel' ) );

		self::assertSame( array( 'core/paragraph' ), array_values( (array) $allowed ) );
	}

	public function test_an_incoming_false_restriction_is_preserved(): void {
		// `false` means another filter already decided this context gets no
		// blocks at all. Turning that into an allow-list would widen access.
		self::assertFalse( $this->resolve( false, false ) );
		self::assertFalse( $this->resolve( true, false ) );
	}

	public function test_extra_allowed_blocks_are_added_even_outside_the_namespace_set(): void {
		$allowed = BlockPolicy::resolve(
			false,
			true,
			$this->registered(),
			BlockPolicy::DEFAULT_NAMESPACES,
			array( 'acme/tracking-pixel' ),
			array()
		);

		self::assertContains( 'acme/tracking-pixel', (array) $allowed );
	}

	public function test_extra_denied_blocks_are_removed(): void {
		$allowed = BlockPolicy::resolve(
			false,
			true,
			$this->registered(),
			BlockPolicy::DEFAULT_NAMESPACES,
			array(),
			array( 'core/navigation' )
		);

		self::assertNotContains( 'core/navigation', (array) $allowed );
	}

	public function test_extra_allowed_cannot_re_enable_a_permanently_denied_block(): void {
		$allowed = BlockPolicy::resolve(
			false,
			true,
			$this->registered(),
			BlockPolicy::DEFAULT_NAMESPACES,
			array( 'core/html', 'core/shortcode', 'core/freeform' ),
			array()
		);

		self::assertNotContains( 'core/html', (array) $allowed );
		self::assertNotContains( 'core/shortcode', (array) $allowed );
		self::assertNotContains( 'core/freeform', (array) $allowed );
	}

	public function test_forbidden_blocks_are_found_recursively(): void {
		$parsed = parse_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Fine</p><!-- /wp:paragraph --><!-- wp:html --><div>raw</div><!-- /wp:html --></div><!-- /wp:group -->' );

		$violations = BlockPolicy::forbidden_blocks( $parsed, array( 'core/group', 'core/paragraph' ) );

		self::assertCount( 1, $violations );
		self::assertSame( 'core/html', $violations[0]['block'] );
		self::assertSame( 'core/group > core/html', $violations[0]['path'] );
	}

	public function test_null_name_blocks_with_real_markup_are_raw_html_violations(): void {
		$parsed = parse_blocks( '<!-- wp:paragraph --><p>Safe</p><!-- /wp:paragraph -->\n\n<script>alert(1)</script>' );

		$violations = BlockPolicy::raw_html_violations( $parsed );

		self::assertCount( 1, $violations );
		self::assertStringContainsString( '<script>', $violations[0] );
	}

	public function test_named_blocks_with_disallowed_markup_are_raw_html_violations(): void {
		$parsed = parse_blocks( '<!-- wp:paragraph --><p style="position:fixed;z-index:99999">Injected</p><!-- /wp:paragraph -->' );

		self::assertSame(
			array( '<p style="position:fixed;z-index:99999">Injected</p>' ),
			BlockPolicy::raw_html_violations( $parsed )
		);
	}

	public function test_named_blocks_with_ordinary_markup_are_not_raw_html_violations(): void {
		$parsed = parse_blocks( '<!-- wp:paragraph --><p>Ordinary paragraph markup.</p><!-- /wp:paragraph -->' );

		self::assertSame( array(), BlockPolicy::raw_html_violations( $parsed ) );
	}

	public function test_block_instance_custom_css_is_detected_recursively(): void {
		$parsed = parse_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph {"style":{"css":"color:red"}} --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );

		$violations = BlockPolicy::block_css_violations( $parsed );

		self::assertCount( 1, $violations );
		self::assertSame( 'core/paragraph', $violations[0]['block'] );
	}

	public function test_registered_shortcode_tags_are_detected_anywhere_in_content(): void {
		// The regex shape WordPress's get_shortcode_regex() produces for the
		// registered tags "gallery" and "contact-form".
		$regex = '\[(\[?)(gallery|contact\-form)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)';

		$violations = BlockPolicy::shortcode_violations(
			'<!-- wp:paragraph --><p>Call us [contact-form] today</p><!-- /wp:paragraph -->',
			$regex
		);

		self::assertSame( array( 'contact-form' ), $violations );
	}

	public function test_escaped_shortcodes_and_ordinary_bracket_text_are_not_violations(): void {
		$regex = '\[(\[?)(gallery|contact\-form)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)';

		self::assertSame( array(), BlockPolicy::shortcode_violations( '<p>See [[gallery]] for the escape syntax.</p>', $regex ) );
		self::assertSame( array(), BlockPolicy::shortcode_violations( '<p>Rates are [subject to change] this year.</p>', $regex ) );
	}

	public function test_global_styles_root_custom_css_is_detected(): void {
		self::assertSame(
			array( 'styles.css' ),
			BlockPolicy::global_styles_css_violations( array( 'css' => 'body{color:red}' ) )
		);
	}

	public function test_global_styles_block_and_element_custom_css_is_detected(): void {
		$styles = array(
			'blocks'   => array( 'core/group' => array( 'css' => '.x{}' ) ),
			'elements' => array( 'link' => array( 'css' => 'a{}' ) ),
		);

		self::assertSame(
			array( 'styles.blocks.core/group.css', 'styles.elements.link.css' ),
			BlockPolicy::global_styles_css_violations( $styles )
		);
	}

	public function test_global_styles_variation_custom_css_is_detected(): void {
		$styles = array( 'variations' => array( 'section-a' => array( 'blocks' => array( 'core/group' => array( 'css' => '.y{}' ) ) ) ) );

		self::assertSame(
			array( 'styles.variations.section-a.blocks.core/group.css' ),
			BlockPolicy::global_styles_css_violations( $styles )
		);
	}

	public function test_global_styles_without_custom_css_is_clean(): void {
		$styles = array(
			'color'      => array( 'background' => 'var(--wp--preset--color--base)' ),
			'blocks'     => array( 'core/group' => array( 'spacing' => array( 'padding' => array( 'top' => '1rem' ) ) ) ),
			'typography' => array( 'fontSize' => '1rem' ),
		);

		self::assertSame( array(), BlockPolicy::global_styles_css_violations( $styles ) );
	}

	public function test_an_empty_css_string_is_not_a_violation(): void {
		self::assertSame( array(), BlockPolicy::global_styles_css_violations( array( 'css' => '   ' ) ) );
	}
}
