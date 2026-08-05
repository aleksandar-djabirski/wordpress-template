<?php
/**
 * The resolved-template seam (plan §7.4): WordPress injects a "theme"
 * attribute into every core/template-part block when it builds a template
 * from a file, and a prepared file that keeps it could never hash equal to
 * the resolved form. Navigation refs are the other v1 policy: every
 * core/navigation block's `ref` is stripped so a template can be promoted
 * while the menu itself stays database-owned. Both methods must run
 * without WordPress, so everything here is pure string rewriting over the
 * block-comment syntax WordPress's own serialiser emits.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\ResolvedTemplateNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\ResolvedTemplateNormalizer
 */
final class ResolvedTemplateNormalizerTest extends TestCase {

	public function test_it_strips_the_injected_theme_attribute(): void {
		$markup = '<!-- wp:template-part {"slug":"site-header","theme":"site-theme","area":"header"} /-->';

		self::assertSame(
			'<!-- wp:template-part {"area":"header","slug":"site-header"} /-->',
			ResolvedTemplateNormalizer::strip_theme_attribute( $markup )
		);
	}

	public function test_it_leaves_other_blocks_untouched(): void {
		$markup = '<!-- wp:paragraph {"theme":"kept"} --><p>x</p><!-- /wp:paragraph -->';

		self::assertSame( $markup, ResolvedTemplateNormalizer::strip_theme_attribute( $markup ) );
	}

	public function test_it_strips_every_navigation_ref(): void {
		$markup = '<!-- wp:navigation {"ref":12,"layout":{"type":"flex"}} /--><!-- wp:navigation {"ref":13} /-->';

		self::assertSame(
			'<!-- wp:navigation {"layout":{"type":"flex"}} /--><!-- wp:navigation /-->',
			ResolvedTemplateNormalizer::strip_navigation_refs( $markup )
		);
	}

	public function test_it_strips_the_ref_from_a_paired_navigation_block_and_keeps_its_children(): void {
		$markup = '<!-- wp:navigation {"ref":12,"layout":{"type":"flex"}} -->'
			. '<!-- wp:navigation-link {"label":"Home"} /-->'
			. '<!-- /wp:navigation -->';

		self::assertSame(
			'<!-- wp:navigation {"layout":{"type":"flex"}} -->'
			. '<!-- wp:navigation-link {"label":"Home"} /-->'
			. '<!-- /wp:navigation -->',
			ResolvedTemplateNormalizer::strip_navigation_refs( $markup )
		);
	}

	public function test_it_leaves_markup_unchanged_when_the_attributes_do_not_decode_as_json(): void {
		$markup = '<!-- wp:navigation {"ref":oops} /-->';

		self::assertSame( $markup, ResolvedTemplateNormalizer::strip_navigation_refs( $markup ) );
	}

	public function test_it_leaves_a_block_without_attributes_unchanged(): void {
		$markup = '<!-- wp:navigation /-->';

		self::assertSame( $markup, ResolvedTemplateNormalizer::strip_navigation_refs( $markup ) );
	}

	public function test_stripping_is_a_fixed_point_when_the_target_attribute_is_absent(): void {
		$markup = '<!-- wp:template-part {"area":"header","slug":"site-header"} /-->';

		self::assertSame( $markup, ResolvedTemplateNormalizer::strip_theme_attribute( $markup ) );
	}

	public function test_strip_theme_attribute_only_touches_template_parts(): void {
		$markup = '<!-- wp:navigation {"theme":"kept"} /-->';

		self::assertSame( $markup, ResolvedTemplateNormalizer::strip_theme_attribute( $markup ) );
	}

	public function test_strip_navigation_refs_only_touches_navigation_blocks(): void {
		$markup = '<!-- wp:template-part {"ref":12,"slug":"site-header"} /-->';

		self::assertSame( $markup, ResolvedTemplateNormalizer::strip_navigation_refs( $markup ) );
	}
}
