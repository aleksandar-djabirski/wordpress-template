<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\ReferenceScanner;

/**
 * The §7.4 navigation policy (BLOCK_THEME_PROPOSAL.md §7.4): navigation
 * menus are database-owned in v1, so the promotion contract removes every
 * ref and must make that removal safe. On the prepare side, evaluate()
 * matches every scanned core/navigation block to Task 2's exported
 * navigation reference — an explicit block to its record, a ref-less block
 * to the deterministic most-recently-published fallback the export saw —
 * and records the identity/hash that finalisation must reproduce. A block
 * with no exported reference, a reference with no target hash, or two
 * different navigation contents in one record all refuse. On the finalize
 * side, verify_target() only lets a record proceed when the target's core
 * fallback resolves the exported hash — the SAME check for a source block
 * that was ref-less and for a block whose explicit ref was removed.
 */
final class NavigationPolicy {

	private function __construct() {
		// Static-only policy; never instantiated.
	}

	/**
	 * Prepare side. Pure: matches the scanned blocks against the exported
	 * references and returns either the refusals, or exactly one
	 * navigationExpectation entry built from the first block and its
	 * matched reference.
	 *
	 * @param list<array{ref:int|null}> $navigation_blocks every core/navigation block in the record
	 * @param list<array<string, mixed>> $references        Task 2's scan report for this record
	 * @return array{refusals: list<RecordRefusal>,
	 *               navigationExpectation: list<array{originalRef:int|null,
	 *                   exportedNavigationHash:string, exportedNavigationIdentity:array<string, mixed>|null}>}
	 */
	public static function evaluate( string $record_key, string $provider, string $slug, array $navigation_blocks, array $references ): array {
		if ( array() === $navigation_blocks ) {
			return array(
				'refusals'              => array(),
				'navigationExpectation' => array(),
			);
		}

		$navigation_references = array();

		foreach ( $references as $reference ) {
			if ( ReferenceScanner::KIND_NAVIGATION === ( $reference['kind'] ?? null ) ) {
				$navigation_references[] = $reference;
			}
		}

		$refusals = array();
		$matched  = array();

		foreach ( $navigation_blocks as $block ) {
			$block_ref = is_int( $block['ref'] ) ? $block['ref'] : null;
			$entry     = null;

			foreach ( $navigation_references as $index => $reference ) {
				if ( ( $reference['value'] ?? null ) === $block_ref ) {
					$entry = $reference;
					unset( $navigation_references[ $index ] );
					break;
				}
			}

			if ( null === $entry || ! is_string( $entry['targetHash'] ?? null ) ) {
				$refusals[] = new RecordRefusal(
					$record_key,
					$provider,
					$slug,
					'unresolved-navigation-ref',
					'The record contains a core/navigation block whose exported reference could not be resolved to a navigation record with a content hash.',
					'core/navigation',
					'ref',
					null === $block_ref ? null : (string) $block_ref
				);

				continue;
			}

			$matched[] = $entry;
		}

		$distinct_hashes = array();

		foreach ( $matched as $entry ) {
			$distinct_hashes[ $entry['targetHash'] ] = true;
		}

		if ( 1 < count( $distinct_hashes ) ) {
			$refusals[] = new RecordRefusal(
				$record_key,
				$provider,
				$slug,
				'navigation-refs-not-equivalent',
				sprintf(
					'This record points at %d different navigations. Removing every ref would make them all resolve to the same fallback menu on the target. Promote a record with one navigation, or keep this one database-owned.',
					count( $distinct_hashes )
				)
			);
		}

		if ( array() !== $refusals ) {
			return array(
				'refusals'              => $refusals,
				'navigationExpectation' => array(),
			);
		}

		$first_block = $navigation_blocks[0];
		$first_entry = $matched[0];
		$first_ref   = is_int( $first_block['ref'] ) ? $first_block['ref'] : null;

		return array(
			'refusals'              => array(),
			'navigationExpectation' => array(
				array(
					'originalRef'                => $first_ref,
					'exportedNavigationHash'     => $first_entry['targetHash'],
					'exportedNavigationIdentity' => is_array( $first_entry['targetIdentity'] ) ? $first_entry['targetIdentity'] : null,
				),
			),
		);
	}

	/**
	 * Finalize side. Pure comparison against an already-resolved target
	 * hash: no expectation means nothing to check, a null resolved hash
	 * means the target resolves no navigation, and any other hash that
	 * differs from the exported one is a content mismatch carrying the
	 * resolved identity's slug for a readable report.
	 *
	 * @param list<array<string, mixed>> $expectation        the manifest's navigationExpectation
	 * @param array<string, mixed>|null  $resolved_identity
	 */
	public static function verify_target( string $record_key, string $provider, string $slug, array $expectation, ?string $resolved_target_hash, ?array $resolved_identity ): ?RecordRefusal {
		if ( array() === $expectation ) {
			return null;
		}

		if ( null === $resolved_target_hash ) {
			return new RecordRefusal(
				$record_key,
				$provider,
				$slug,
				'missing-navigation',
				'The target environment resolves no published wp_navigation post, so the record cannot resolve the exported navigation.',
				'core/navigation',
				'ref'
			);
		}

		$exported_hash = is_array( $expectation[0] ) && isset( $expectation[0]['exportedNavigationHash'] ) && is_string( $expectation[0]['exportedNavigationHash'] ) ? $expectation[0]['exportedNavigationHash'] : '';

		if ( $resolved_target_hash !== $exported_hash ) {
			$resolved_slug = is_array( $resolved_identity ) && isset( $resolved_identity['slug'] ) && is_string( $resolved_identity['slug'] ) ? $resolved_identity['slug'] : null;

			return new RecordRefusal(
				$record_key,
				$provider,
				$slug,
				'navigation-content-mismatch',
				'The navigation the target environment resolves differs in content from the navigation the source exported.',
				'core/navigation',
				'ref',
				$resolved_slug
			);
		}

		return null;
	}
}
