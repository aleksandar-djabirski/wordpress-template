<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

use AgencyPlatform\State\Providers\ContentState;
use AgencyPlatform\State\Providers\CustomCssState;
use AgencyPlatform\State\Providers\FontLibraryState;
use AgencyPlatform\State\Providers\GlobalStylesState;
use AgencyPlatform\State\Providers\MediaReferencesState;
use AgencyPlatform\State\Providers\NavigationState;
use AgencyPlatform\State\Providers\SyncedPatternsState;
use AgencyPlatform\State\Providers\TemplatePartsState;
use AgencyPlatform\State\Providers\TemplatesState;

/**
 * The §7.1 provider registry: the single place the exporter, differ, and
 * promotion track learn which providers exist. The built-in provider map
 * passes through the `agency_platform_state_providers` filter, so project
 * plugins add providers here without modifying agency-platform — and the
 * filter contract is enforced, not assumed: any value the filter returns
 * that is not a StateProvider is a hard error naming the offending key,
 * never a silent skip, and registrants must supply named classes, never
 * closures (§4).
 *
 * resolve() turns the raw --providers option into the deterministic slug
 * list the exporter runs. The default set is derived from the REGISTERED
 * providers, never from STRUCTURAL_SLUGS: that constant documents the full
 * eight-slug structural set, and the registry now ships all of them — but
 * the constant must never drive resolution, or a filter-narrowed registry
 * would silently export providers that do not exist. An explicitly named
 * provider always wins: naming a content provider includes it even without
 * --include-content.
 */
final class StateRegistry {

	public const FILTER = 'agency_platform_state_providers';

	/**
	 * The structural default set from BLOCK_THEME_PROPOSAL.md §6, declared in
	 * the canonical ASCENDING SLUG order this subsystem uses everywhere
	 * (registry, resolver, bundle providers map, tests). The master spec lists
	 * the same eight slugs in reading order; that is informational prose, not
	 * the wire order.
	 *
	 * @var list<string>
	 */
	public const STRUCTURAL_SLUGS = array( 'custom-css', 'fonts', 'global-styles', 'media-references', 'navigation', 'synced-patterns', 'template-parts', 'templates' );

	/** @var array<string, StateProvider>|null */
	private static ?array $memoised = null;

	private function __construct() {
		// Static-only registry; never instantiated.
	}

	/**
	 * The resolved provider map, keyed and sorted by provider slug. The
	 * result is memoised so the filter runs once per request; reset() clears
	 * the memoisation for tests.
	 *
	 * @return array<string, StateProvider> Keyed by slug, sorted by slug.
	 */
	public static function providers(): array {
		if ( null !== self::$memoised ) {
			return self::$memoised;
		}

		$builtin = array(
			'custom-css'       => new CustomCssState(),
			'content'          => new ContentState(),
			'fonts'            => new FontLibraryState(),
			'global-styles'    => new GlobalStylesState(),
			'media-references' => new MediaReferencesState(),
			'navigation'       => new NavigationState(),
			'synced-patterns'  => new SyncedPatternsState(),
			'template-parts'   => new TemplatePartsState(),
			'templates'        => new TemplatesState(),
		);

		$filtered = apply_filters( self::FILTER, $builtin );

		if ( ! is_array( $filtered ) ) {
			throw StateException::hard_error( 'The ' . self::FILTER . ' filter must return an array of StateProvider instances; it returned ' . gettype( $filtered ) . '.' );
		}

		$providers = array();

		foreach ( $filtered as $key => $provider ) {
			if ( ! $provider instanceof StateProvider ) {
				throw StateException::hard_error( 'The ' . self::FILTER . ' filter returned ' . gettype( $provider ) . ' for key ' . (string) $key . '; every value must be a StateProvider instance supplied as a named class.' );
			}

			$providers[ $provider->slug() ] = $provider;
		}

		ksort( $providers );

		self::$memoised = $providers;

		return $providers;
	}

	/**
	 * The provider serving a slug, or null when none is registered.
	 */
	public static function provider( string $slug ): ?StateProvider {
		$providers = self::providers();

		return $providers[ $slug ] ?? null;
	}

	/**
	 * Every registered provider slug, sorted ascending.
	 *
	 * @return list<string>
	 */
	public static function slugs(): array {
		return array_keys( self::providers() );
	}

	/**
	 * The deterministic provider list an export runs, derived from the
	 * REGISTERED providers only — never from STRUCTURAL_SLUGS, which
	 * documents the full set while the registry may legitimately ship a
	 * subset. A null option selects every registered structural provider
	 * (includes_content() === false, so a third-party structural provider is
	 * exported by default too), plus the content providers when
	 * $include_content is true. A non-null option selects exactly those
	 * slugs, whitespace-trimmed, de-duplicated, sorted — and an explicitly
	 * named content provider is included even without --include-content
	 * (explicit beats default).
	 *
	 * @return list<string> Sorted, de-duplicated provider slugs.
	 * @throws StateException Exit 1 on an unknown slug or an empty result.
	 */
	public static function resolve( ?string $providers_option, bool $include_content ): array {
		$registered = self::providers();

		if ( null === $providers_option ) {
			$resolved = array();

			foreach ( $registered as $slug => $provider ) {
				if ( $include_content || ! $provider->includes_content() ) {
					$resolved[] = $slug;
				}
			}

			if ( array() === $resolved ) {
				throw StateException::hard_error( 'No state providers are registered for this export; register at least one provider on the ' . self::FILTER . ' filter.' );
			}

			return $resolved;
		}

		$candidates = array();

		foreach ( explode( ',', $providers_option ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( '' !== $candidate ) {
				$candidates[] = $candidate;
			}
		}

		$candidates = array_values( array_unique( $candidates ) );

		foreach ( $candidates as $slug ) {
			if ( ! array_key_exists( $slug, $registered ) ) {
				throw StateException::hard_error( 'Unknown provider slug "' . $slug . '"; valid slugs are: ' . implode( ', ', array_keys( $registered ) ) . '.' );
			}
		}

		if ( array() === $candidates ) {
			throw StateException::hard_error( 'The --providers value selected no providers; name at least one of the registered slugs: ' . implode( ', ', array_keys( $registered ) ) . '.' );
		}

		sort( $candidates, SORT_STRING );

		return $candidates;
	}

	/** Test seam: drop the memoised provider map so the next providers() re-reads the filter. */
	public static function reset(): void {
		self::$memoised = null;
	}
}
