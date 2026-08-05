<?php
/**
 * The §7.4 navigation policy (BLOCK_THEME_PROPOSAL.md §7.4): on the prepare
 * side, evaluate() matches every scanned core/navigation block to the
 * exported reference and records the identity/hash that makes Task 3's
 * ref-removal safe — a ref-less source block and a block whose explicit ref
 * is removed resolve through the SAME deterministic target fallback. On the
 * finalize side, verify_target() only lets a record proceed when the target
 * environment's core fallback resolves the exported hash.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\NavigationPolicy;
use AgencyPlatform\State\ReferenceScanner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\NavigationPolicy
 */
final class NavigationPolicyTest extends TestCase {

	public function test_a_single_ref_records_the_exported_hash(): void {
		$outcome = NavigationPolicy::evaluate(
			'templates:page',
			'templates',
			'page',
			array( array( 'ref' => 12 ) ),
			array( $this->navigation_reference( 12, 'abc', array( 'slug' => 'primary' ) ) )
		);

		self::assertSame( array(), $outcome['refusals'] );
		self::assertSame( 'abc', $outcome['navigationExpectation'][0]['exportedNavigationHash'] );
		self::assertSame( 12, $outcome['navigationExpectation'][0]['originalRef'] );
	}

	public function test_a_ref_less_source_block_records_the_exported_fallback_hash(): void {
		$outcome = NavigationPolicy::evaluate(
			'templates:page',
			'templates',
			'page',
			array( array( 'ref' => null ) ),
			array( $this->navigation_reference( null, 'fallback-hash', array( 'slug' => 'primary' ) ) )
		);

		self::assertSame( array(), $outcome['refusals'] );
		self::assertSame( null, $outcome['navigationExpectation'][0]['originalRef'] );
		self::assertSame( 'fallback-hash', $outcome['navigationExpectation'][0]['exportedNavigationHash'] );
	}

	public function test_a_ref_less_source_block_with_no_exported_fallback_is_refused(): void {
		$outcome = NavigationPolicy::evaluate( 'templates:page', 'templates', 'page', array( array( 'ref' => null ) ), array() );

		self::assertSame( 'unresolved-navigation-ref', $outcome['refusals'][0]->reason_code );
	}

	public function test_two_refs_to_different_menus_cannot_stay_equivalent(): void {
		// Removing both refs makes both blocks resolve to the SAME fallback, which
		// silently merges two different menus.
		$outcome = NavigationPolicy::evaluate(
			'templates:page',
			'templates',
			'page',
			array( array( 'ref' => 12 ), array( 'ref' => 13 ) ),
			array(
				$this->navigation_reference( 12, 'abc', array( 'slug' => 'primary' ) ),
				$this->navigation_reference( 13, 'def', array( 'slug' => 'footer' ) ),
			)
		);

		self::assertSame( 'navigation-refs-not-equivalent', $outcome['refusals'][0]->reason_code );
	}

	public function test_two_refs_to_the_same_content_are_allowed(): void {
		$outcome = NavigationPolicy::evaluate(
			'templates:page',
			'templates',
			'page',
			array( array( 'ref' => 12 ), array( 'ref' => 13 ) ),
			array(
				$this->navigation_reference( 12, 'abc', array( 'slug' => 'primary' ) ),
				$this->navigation_reference( 13, 'abc', array( 'slug' => 'primary-copy' ) ),
			)
		);

		self::assertSame( array(), $outcome['refusals'] );
		self::assertCount( 1, $outcome['navigationExpectation'] );
	}

	public function test_a_ref_with_no_exported_target_hash_is_refused(): void {
		$outcome = NavigationPolicy::evaluate(
			'templates:page',
			'templates',
			'page',
			array( array( 'ref' => 12 ) ),
			array( $this->navigation_reference( 12, null, null ) )
		);

		self::assertSame( 'unresolved-navigation-ref', $outcome['refusals'][0]->reason_code );
	}

	public function test_verify_target_refuses_when_nothing_resolves(): void {
		$refusal = NavigationPolicy::verify_target( 'templates:page', 'templates', 'page', $this->expectation( 'abc' ), null, null );

		self::assertSame( 'missing-navigation', $refusal->reason_code );
	}

	public function test_verify_target_refuses_a_content_mismatch(): void {
		$refusal = NavigationPolicy::verify_target( 'templates:page', 'templates', 'page', $this->expectation( 'abc' ), 'zzz', array( 'slug' => 'other' ) );

		self::assertSame( 'navigation-content-mismatch', $refusal->reason_code );
	}

	public function test_verify_target_accepts_a_hash_match(): void {
		self::assertNull(
			NavigationPolicy::verify_target( 'templates:page', 'templates', 'page', $this->expectation( 'abc' ), 'abc', array( 'slug' => 'primary' ) )
		);
	}

	public function test_a_record_with_no_navigation_blocks_is_not_checked(): void {
		$outcome = NavigationPolicy::evaluate( 'templates:page', 'templates', 'page', array(), array() );

		self::assertSame( array(), $outcome['refusals'] );
		self::assertSame( array(), $outcome['navigationExpectation'] );
	}

	public function test_verify_target_with_no_expectation_allows_the_record(): void {
		self::assertNull(
			NavigationPolicy::verify_target( 'templates:page', 'templates', 'page', array(), null, null )
		);
	}

	public function test_non_navigation_references_are_ignored_by_the_match(): void {
		// The attachment reference shares the block's value, so an unfiltered
		// report would match it and refuse on its null targetHash; only the
		// kind filter lets the navigation reference win.
		$outcome = NavigationPolicy::evaluate(
			'templates:page',
			'templates',
			'page',
			array( array( 'ref' => 12 ) ),
			array(
				$this->navigation_reference( 12, 'abc', array( 'slug' => 'primary' ) ),
				array(
					'record'         => 'templates:page',
					'provider'       => 'templates',
					'blockName'      => 'core/image',
					'attribute'      => 'id',
					'value'          => 12,
					'kind'           => ReferenceScanner::KIND_ATTACHMENT,
					'resolution'     => ReferenceScanner::RESOLUTION_ENVIRONMENT,
					'policy'         => 'Media IDs are environment-specific.',
					'targetKey'      => null,
					'targetHash'     => null,
					'targetIdentity' => null,
				),
			)
		);

		self::assertSame( array(), $outcome['refusals'] );
		self::assertSame( 'abc', $outcome['navigationExpectation'][0]['exportedNavigationHash'] );
	}

	/**
	 * One exported navigation reference in Task 2's report shape: the value
	 * is an integer for an explicit ref and null for a source ref-less
	 * fallback reference.
	 *
	 * @param array<string, mixed>|null $target_identity
	 * @return array<string, mixed>
	 */
	private function navigation_reference( ?int $value, ?string $target_hash, ?array $target_identity ): array {
		return array(
			'record'         => 'templates:page',
			'provider'       => 'templates',
			'blockName'      => 'core/navigation',
			'attribute'      => 'ref',
			'value'          => $value,
			'kind'           => ReferenceScanner::KIND_NAVIGATION,
			'resolution'     => ReferenceScanner::RESOLUTION_ENVIRONMENT,
			'policy'         => 'Navigation stays database-owned in v1.',
			'targetKey'      => null,
			'targetHash'     => $target_hash,
			'targetIdentity' => $target_identity,
		);
	}

	/**
	 * One manifest navigationExpectation entry.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function expectation( string $hash ): array {
		return array(
			array(
				'originalRef'                => 12,
				'exportedNavigationHash'     => $hash,
				'exportedNavigationIdentity' => array(
					'slug'   => 'primary',
					'status' => 'publish',
				),
			),
		);
	}
}
