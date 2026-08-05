<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\ResolvedTemplateNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The resolved-template normaliser's contract (plan decision 8 and §7.4 v1
 * policy): WordPress injects a `theme` attribute into file-backed
 * core/template-part blocks when it builds a template from a file, and a
 * resolved template carries the navigation `ref` that is environment-owned.
 * Without stripping both, a promoted file and its resolved form never hash
 * the same and every finalize would self-restore.
 *
 * Both methods are WordPress-free by design — the unit suite covers them
 * with no live install, so nothing here may call WordPress. Attributes are
 * decoded both as strict JSON and as the legacy unquoted short-hand form
 * that older serializers emit, and the emitted form mirrors the input form
 * so a round-trip changes nothing except the stripped key.
 *
 * @covers \AgencyPlatform\State\Promotion\ResolvedTemplateNormalizer
 */
final class ResolvedTemplateNormalizerTest extends TestCase {

	public function test_it_strips_the_injected_theme_attribute(): void {
		$markup = '<!-- wp:template-part {slug:site-header,theme:site-theme,area:header} /-->';

		self::assertSame(
			'<!-- wp:template-part {area:header,slug:site-header} /-->',
			ResolvedTemplateNormalizer::strip_theme_attribute( $markup )
		);
	}

	public function test_it_leaves_other_blocks_untouched(): void {
		$markup = '<!-- wp:paragraph {theme:kept} --><p>x</p><!-- /wp:paragraph -->';

		self::assertSame( $markup, ResolvedTemplateNormalizer::strip_theme_attribute( $markup ) );
	}

	public function test_it_strips_every_navigation_ref(): void {
		$markup = '<!-- wp:navigation {ref:12,layout:{type:flex}} /--><!-- wp:navigation {ref:13} /-->';

		self::assertSame(
			'<!-- wp:navigation {layout:{type:flex}} /--><!-- wp:navigation /-->',
			ResolvedTemplateNormalizer::strip_navigation_refs( $markup )
		);
	}

	public function test_it_preserves_the_paired_form_of_a_navigation_block(): void {
		$markup = '<!-- wp:navigation {ref:12} --><!-- wp:home-link /--><!-- /wp:navigation -->';

		self::assertSame(
			'<!-- wp:navigation --><!-- wp:home-link /--><!-- /wp:navigation -->',
			ResolvedTemplateNormalizer::strip_navigation_refs( $markup )
		);
	}

	public function test_it_strips_theme_from_full_json_attributes(): void {
		$markup = '<!-- wp:template-part {"slug":"site-header","theme":"site-theme","area":"header"} /-->';

		self::assertSame(
			'<!-- wp:template-part {"area":"header","slug":"site-header"} /-->',
			ResolvedTemplateNormalizer::strip_theme_attribute( $markup )
		);
	}

	public function test_it_leaves_undecodable_attributes_unchanged(): void {
		$markup = '<!-- wp:template-part {this is not decodable} /-->';

		self::assertSame( $markup, ResolvedTemplateNormalizer::strip_theme_attribute( $markup ) );
	}

	public function test_strip_theme_attribute_never_touches_navigation_refs(): void {
		$markup = '<!-- wp:navigation {ref:12} /-->';

		self::assertSame( $markup, ResolvedTemplateNormalizer::strip_theme_attribute( $markup ) );
	}

	public function test_strip_navigation_refs_never_touches_template_parts(): void {
		$markup = '<!-- wp:template-part {slug:site-header,theme:site-theme} /-->';

		self::assertSame( $markup, ResolvedTemplateNormalizer::strip_navigation_refs( $markup ) );
	}
}
