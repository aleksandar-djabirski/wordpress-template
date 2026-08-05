<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * Slug-keyed registry of promotion strategies, extended through the
 * `agency_platform_promotion_strategies` filter. Ships EMPTY from this task:
 * with no strategy registered, promotion_strategy() returns null everywhere
 * and Release 2 is export-and-diff only, exactly as §13 requires.
 *
 * Registrants supply named classes, never closures (§4): every value the
 * filter returns must be a PromotionStrategy instance, and anything else is
 * a hard error naming the offending key rather than a silent skip.
 */
final class PromotionStrategies {

	public const FILTER = 'agency_platform_promotion_strategies';

	/** @var array<string, PromotionStrategy>|null */
	private static ?array $memoised = null;

	private function __construct() {
		// Static-only registry; never instantiated.
	}

	/**
	 * The resolved strategies, keyed and sorted by provider slug. The result
	 * is memoised so the filter runs once per request; reset() clears the
	 * memoisation for tests.
	 *
	 * @return array<string, PromotionStrategy> keyed and sorted by provider slug.
	 */
	public static function all(): array {
		if ( null !== self::$memoised ) {
			return self::$memoised;
		}

		$filtered = apply_filters( self::FILTER, array() );

		if ( ! is_array( $filtered ) ) {
			throw StateException::hard_error( 'The ' . self::FILTER . ' filter must return an array of PromotionStrategy instances; it returned ' . gettype( $filtered ) . '.' );
		}

		$strategies = array();

		foreach ( $filtered as $key => $strategy ) {
			if ( ! $strategy instanceof PromotionStrategy ) {
				throw StateException::hard_error( 'The ' . self::FILTER . ' filter returned ' . gettype( $strategy ) . ' for key ' . (string) $key . '; every value must be a PromotionStrategy instance supplied as a named class.' );
			}

			$strategies[ $strategy->provider_slug() ] = $strategy;
		}

		ksort( $strategies );

		self::$memoised = $strategies;

		return $strategies;
	}

	/**
	 * The strategy serving a provider slug, or null when none is registered.
	 */
	public static function for_provider( string $provider_slug ): ?PromotionStrategy {
		$strategies = self::all();

		return $strategies[ $provider_slug ] ?? null;
	}

	/** Test seam: drop the memoised result so the next all() re-reads the filter. */
	public static function reset(): void {
		self::$memoised = null;
	}
}
