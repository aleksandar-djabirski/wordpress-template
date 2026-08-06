<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * Parses the operator-facing --select syntax ("<provider>:<slug>" items,
 * comma-separated) and gates every item on the strategy allow-list and the
 * bundle: a provider with no registered promotion strategy is invalid
 * operator input (exit 1) — that gate is what keeps global-styles out of
 * Release 3 — and a record absent from the bundle is refused the same way.
 * Both checks are injected as callables so this class stays WordPress-free
 * and unit-testable.
 */
final class PromotionSelector {

	private function __construct() {
		// Static-only utility class; never instantiated.
	}

	/**
	 * @param callable(string):bool $has_strategy provider slug -> a preparable strategy is registered
	 * @param callable(string):bool $has_record   canonical record key -> present in the bundle
	 * @return list<array{provider:string, slug:string, key:string}> sorted by key
	 */
	public static function parse( string $select, callable $has_strategy, callable $has_record ): array {
		$parsed = array();

		foreach ( explode( ',', $select ) as $item ) {
			$item = trim( $item );

			if ( '' === $item ) {
				throw PromotionException::hard(
					sprintf( 'Selector item "%s" is empty; every item must be <provider>:<slug>.', $item )
				);
			}

			if ( 1 !== preg_match( '/^([a-z0-9-]+):([a-z0-9_-]+)$/', $item, $matches ) ) {
				throw PromotionException::hard(
					sprintf( 'Selector "%s" must be <provider>:<slug>.', $item )
				);
			}

			$provider = $matches[1];
			$slug     = $matches[2];
			$key      = $provider . ':' . $slug;

			if ( ! $has_strategy( $provider ) ) {
				throw PromotionException::hard(
					sprintf( 'No promotion strategy is registered for provider "%s". Templates and template parts are promotable in this release; Global Styles promotion arrives with its own release gate.', $provider )
				);
			}

			if ( ! $has_record( $key ) ) {
				throw PromotionException::hard(
					sprintf( 'Record "%s" is not in the state bundle.', $key )
				);
			}

			$parsed[ $key ] = array(
				'provider' => $provider,
				'slug'     => $slug,
				'key'      => $key,
			);
		}

		$result = array_values( $parsed );

		usort(
			$result,
			static function ( array $a, array $b ): int {
				return strcmp( $a['key'], $b['key'] );
			}
		);

		return $result;
	}
}
