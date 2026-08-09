<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\ReferenceScanner;

/**
 * The §7.4 per-record reference refusals (BLOCK_THEME_PROPOSAL.md §7.4):
 * every unresolved reference kind EXCEPT navigation is a per-record refusal
 * — it refuses THAT record, never the run. Each refusal carries the block
 * name, the attribute, the referenced value and the suggested policy Task 2
 * already wrote, so an operator can act on it. Navigation is the
 * NavigationPolicy's job and is skipped here; a RESOLUTION_RESOLVED
 * reference never refuses.
 */
final class ReferenceRefusalPolicy {

	/** The fallback policy when the reference carries no usable policy text. */
	private const FALLBACK_POLICY = 'This reference is environment-specific. Keep the record database-owned, or replace the reference with a theme asset before promoting.';

	private function __construct() {
		// Static-only policy; never instantiated.
	}

	/**
	 * Every reference kind EXCEPT navigation. Navigation is
	 * NavigationPolicy's job.
	 *
	 * @param list<array<string, mixed>> $references
	 * @return list<RecordRefusal>
	 */
	public static function evaluate( string $record_key, string $provider, string $slug, array $references ): array {
		$refusals = array();

		foreach ( $references as $reference ) {
			if ( ReferenceScanner::KIND_NAVIGATION === ( $reference['kind'] ?? null ) ) {
				continue;
			}

			if ( ! ReferenceScanner::is_unresolved( $reference ) ) {
				continue;
			}

			$kind      = is_string( $reference['kind'] ?? null ) ? $reference['kind'] : '';
			$block     = isset( $reference['blockName'] ) && is_string( $reference['blockName'] ) ? $reference['blockName'] : null;
			$attribute = isset( $reference['attribute'] ) && is_string( $reference['attribute'] ) ? $reference['attribute'] : null;

			$refusals[] = new RecordRefusal(
				$record_key,
				$provider,
				$slug,
				'unresolved-reference',
				'The record carries an unresolved ' . $kind . ' reference' . ( null === $block ? '' : ' on ' . $block ) . '; promotion is refused until the reference is resolved.',
				$block,
				$attribute,
				self::referenced_value( $reference['value'] ?? null ),
				self::policy( $reference['policy'] ?? null )
			);
		}

		return $refusals;
	}

	/**
	 * @param mixed $value
	 */
	private static function referenced_value( mixed $value ): ?string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}

		if ( is_string( $value ) ) {
			return $value;
		}

		return null;
	}

	/**
	 * @param mixed $policy
	 */
	private static function policy( mixed $policy ): string {
		if ( is_string( $policy ) && '' !== $policy ) {
			return $policy;
		}

		return self::FALLBACK_POLICY;
	}
}
