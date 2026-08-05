<?php
/**
 * The §7.4 per-record reference refusals: every unresolved reference kind
 * EXCEPT navigation is a per-record refusal carrying the block name, the
 * attribute, the referenced value and the suggested policy, so an operator
 * can act on it — a resolved reference never refuses, and navigation is the
 * NavigationPolicy's job, never this one's.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\ReferenceRefusalPolicy;
use AgencyPlatform\State\ReferenceScanner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\ReferenceRefusalPolicy
 */
final class ReferenceRefusalPolicyTest extends TestCase {

	public function test_an_attachment_reference_is_refused(): void {
		$refusals = ReferenceRefusalPolicy::evaluate(
			'templates:page',
			'templates',
			'page',
			array( $this->reference( ReferenceScanner::KIND_ATTACHMENT, 'core/image', 'id', 42 ) )
		);

		self::assertCount( 1, $refusals );
		self::assertSame( 'unresolved-reference', $refusals[0]->reason_code );
		self::assertSame( 'core/image', $refusals[0]->block_name );
		self::assertSame( 'id', $refusals[0]->attribute );
		self::assertSame( '42', $refusals[0]->referenced_value );
		self::assertNotSame( '', (string) $refusals[0]->suggested_policy );
	}

	public function test_a_synced_pattern_reference_is_refused(): void {
		$refusals = ReferenceRefusalPolicy::evaluate(
			'templates:page',
			'templates',
			'page',
			array( $this->reference( ReferenceScanner::KIND_SYNCED_PATTERN, 'core/block', 'ref', 3 ) )
		);

		self::assertCount( 1, $refusals );
		self::assertSame( 'unresolved-reference', $refusals[0]->reason_code );
		self::assertSame( 'core/block', $refusals[0]->block_name );
		self::assertSame( '3', $refusals[0]->referenced_value );
	}

	public function test_a_site_logo_reference_carries_the_real_id(): void {
		$refusals = ReferenceRefusalPolicy::evaluate(
			'template-parts:site-header',
			'template-parts',
			'site-header',
			array( $this->reference( ReferenceScanner::KIND_SITE_LOGO, 'core/site-logo', 'siteLogo', 7 ) )
		);

		self::assertCount( 1, $refusals );
		self::assertSame( '7', $refusals[0]->referenced_value );
	}

	public function test_a_resolved_reference_is_not_refused(): void {
		$reference               = $this->reference( ReferenceScanner::KIND_ATTACHMENT, 'core/image', 'id', 42 );
		$reference['resolution'] = ReferenceScanner::RESOLUTION_RESOLVED;

		self::assertSame( array(), ReferenceRefusalPolicy::evaluate( 'templates:page', 'templates', 'page', array( $reference ) ) );
	}

	public function test_navigation_references_are_ignored_here(): void {
		self::assertSame(
			array(),
			ReferenceRefusalPolicy::evaluate(
				'templates:page',
				'templates',
				'page',
				array( $this->reference( ReferenceScanner::KIND_NAVIGATION, 'core/navigation', 'ref', 12 ) )
			)
		);
	}

	public function test_a_mixed_report_refuses_only_the_unresolved_entries(): void {
		$resolved               = $this->reference( ReferenceScanner::KIND_ATTACHMENT, 'core/image', 'id', 42 );
		$resolved['resolution'] = ReferenceScanner::RESOLUTION_RESOLVED;
		$unresolved             = $this->reference( ReferenceScanner::KIND_GALLERY, 'core/gallery', 'ids', 9 );

		$refusals = ReferenceRefusalPolicy::evaluate(
			'templates:page',
			'templates',
			'page',
			array( $resolved, $unresolved )
		);

		self::assertCount( 1, $refusals, 'Exactly one refusal: the unresolved gallery reference, never the resolved image reference.' );
		self::assertSame( 'core/gallery', $refusals[0]->block_name );
		self::assertSame( 'ids', $refusals[0]->attribute );
		self::assertSame( '9', $refusals[0]->referenced_value );
	}

	/**
	 * A full eleven-key reference in the shape Task 2's scanner emits, with
	 * the three target fields null and an environment-specific resolution —
	 * the unresolved default this policy refuses.
	 *
	 * @return array<string, mixed>
	 */
	private function reference( string $kind, string $block_name, string $attribute, ?int $value ): array {
		return array(
			'record'         => 'templates:page',
			'provider'       => 'templates',
			'blockName'      => $block_name,
			'attribute'      => $attribute,
			'value'          => $value,
			'kind'           => $kind,
			'resolution'     => ReferenceScanner::RESOLUTION_ENVIRONMENT,
			'policy'         => 'Media IDs are environment-specific; export the media reference and remap it manually.',
			'targetKey'      => null,
			'targetHash'     => null,
			'targetIdentity' => null,
		);
	}
}
