<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

use AgencyPlatform\State\Providers\TemplatePartsState;
use AgencyPlatform\State\Providers\TemplatesState;

/**
 * The two-mode differ (BLOCK_THEME_PROPOSAL.md §5.3, §6, §11.10): Git mode
 * compares live database records against the Git baseline files, bundle
 * mode compares them against a previously exported bundle. The deploy gate
 * reads `has_drift()` on the report, so the classification contract is the
 * whole point of this class: a database-owned provider can NEVER register
 * as Git drift (a client editing navigation, synced patterns, or content in
 * the Site Editor is expected, not a breach), while a real Git-baseline
 * divergence must be reported, and in bundle mode every post-export change
 * — including a database-owned one — is drift, because the bundle is the
 * promotion track's record of what it exported.
 *
 * Git mode overlays the database records onto the Git baseline before
 * comparing: the effective current state is the baseline plus any override
 * of the same key plus every database record with no baseline counterpart.
 * Without the overlay a clean block theme — every template in Git and no
 * wp_template row — would report every template as `removed` and make the
 * gate exit non-zero on a healthy site. Bundle mode never overlays: both
 * of its sides are database records, so `removed` there genuinely means an
 * override that existed at export time has been deleted.
 *
 * The pure half — compare(), overlay(), summarize(), has_drift() — is
 * WordPress-free and unit-tested without a live install; the WordPress-
 * coupled gathers (diff_against_git(), diff_against_bundle()) resolve every
 * provider through the map captured at construction, never through a fresh
 * registry lookup, and are covered by the integration suite.
 */
final class StateDiffer {

	public const MODE_GIT    = 'git';
	public const MODE_BUNDLE = 'bundle';

	/** @var array<string, StateProvider> */
	private array $providers;

	private ?GitBaseline $git;

	/**
	 * @param array<string, StateProvider>|null $providers Null resolves the
	 *        production registry once. Tests inject a provider map, including
	 *        providers constructed with a temporary GitBaseline.
	 */
	public function __construct( ?GitBaseline $git = null, ?array $providers = null ) {
		$this->git = $git;

		if ( null === $providers ) {
			$providers = StateRegistry::providers();

			if ( null !== $this->git ) {
				$providers['templates']      = new TemplatesState( $this->git );
				$providers['template-parts'] = new TemplatePartsState( $this->git );
			}
		}

		foreach ( $providers as $key => $provider ) {
			if ( ! $provider instanceof StateProvider ) {
				throw StateException::hard_error( 'The provider map passed to StateDiffer contains ' . gettype( $provider ) . ' for key ' . (string) $key . '; every entry must be a StateProvider.' );
			}

			if ( $key !== $provider->slug() ) {
				throw StateException::hard_error( 'The provider map passed to StateDiffer keys "' . $key . '" with a provider whose slug is "' . $provider->slug() . '"; the map key must match the provider slug.' );
			}
		}

		ksort( $providers );

		$this->providers = $providers;
	}

	/**
	 * The Git-mode gather: for every requested slug, the live database
	 * records are overlaid onto the Git baseline records, and the baseline
	 * becomes the target side. The keys of the live records are collected
	 * BEFORE the overlay — an override that is byte-identical to Git must
	 * still be visible as an override — and passed through compare() as the
	 * explicit database-override-key metadata. The report source is null:
	 * Git mode compares against the working tree, not against an export.
	 *
	 * @param list<string> $provider_slugs
	 * @return array<string, mixed>
	 * @throws StateException Exit 1 on an unknown provider slug.
	 */
	public function diff_against_git( array $provider_slugs ): array {
		$current                = array();
		$target                 = array();
		$meta                   = array();
		$database_override_keys = array();

		foreach ( $provider_slugs as $slug ) {
			$provider               = $this->provider( $slug );   // hard error when unknown
			$baseline               = $provider->baseline_records();
			$database               = $provider->records();
			$database_override_keys = array_merge( $database_override_keys, array_map( static fn ( StateRecord $record ): string => $record->key(), $database ) );

			$meta[ $slug ] = array(
				'ownership'      => $provider->ownership(),
				'promotion'      => $provider->promotion(),
				'hasGitBaseline' => $provider->has_git_baseline(),
			);

			$current = array_merge( $current, self::overlay( $baseline, $database ) );
			$target  = array_merge( $target, $baseline );
		}

		sort( $database_override_keys, SORT_STRING );
		$database_override_keys = array_values( array_unique( $database_override_keys ) );

		return self::report( self::compare( $current, $target, self::MODE_GIT, $meta, $database_override_keys ), self::MODE_GIT, null, array() );
	}

	/**
	 * The bundle-mode gather: only providers present in BOTH the requested
	 * set and the bundle are compared — a requested provider missing from
	 * the bundle produces a skippedProviders entry, never drift, because a
	 * provider that was never exported cannot be post-export drift. The
	 * bundle's records are the target side, the live records the current
	 * side, and the live records' keys travel as the explicit
	 * database-override-key metadata. The site check comes first: a bundle
	 * from another site must never be diffed, and the site_uuid() call
	 * throws exit 4 on an unverified bundle before any content is read.
	 *
	 * @param list<string> $provider_slugs
	 * @return array<string, mixed>
	 * @throws StateException Exit 1 when the bundle belongs to another site.
	 */
	public function diff_against_bundle( array $provider_slugs, StateBundle $bundle ): array {
		if ( $bundle->site_uuid() !== SiteIdentity::uuid() ) {
			throw StateException::hard_error( 'This bundle belongs to another site: the bundle siteUuid is "' . $bundle->site_uuid() . '" but this site\'s UUID is "' . SiteIdentity::uuid() . '". Refusing to diff.' );
		}

		$bundle_slugs           = $bundle->provider_slugs();
		$skipped                = array();
		$current                = array();
		$target                 = array();
		$meta                   = array();
		$database_override_keys = array();

		foreach ( $provider_slugs as $slug ) {
			$provider = $this->provider( $slug );

			if ( ! in_array( $slug, $bundle_slugs, true ) ) {
				$skipped[] = $slug;

				continue;
			}

			$records = $provider->records();

			$current                = array_merge( $current, $records );
			$target                 = array_merge( $target, $bundle->records( $slug ) );
			$database_override_keys = array_merge( $database_override_keys, array_map( static fn ( StateRecord $record ): string => $record->key(), $records ) );

			$meta[ $slug ] = array(
				'ownership'      => $provider->ownership(),
				'promotion'      => $provider->promotion(),
				'hasGitBaseline' => $provider->has_git_baseline(),
			);
		}

		sort( $database_override_keys, SORT_STRING );
		$database_override_keys = array_values( array_unique( $database_override_keys ) );

		$source = array(
			'exportId'      => $bundle->export_id(),
			'exportedAtUtc' => $bundle->exported_at_utc(),
			'stateHash'     => $bundle->state_hash(),
		);

		return self::report( self::compare( $current, $target, self::MODE_BUNDLE, $meta, $database_override_keys ), self::MODE_BUNDLE, $source, $skipped );
	}

	/**
	 * Pure. The whole classification contract, unit-testable without WordPress.
	 *
	 * @param list<StateRecord>                                                                $current
	 * @param list<StateRecord>                                                                $target
	 * @param array<string, array{ownership: string, promotion: string, hasGitBaseline: bool}> $provider_meta
	 * @param list<string>                                                                     $database_override_keys
	 * @return list<array<string, mixed>> Diff entries, sorted by key ascending.
	 */
	public static function compare( array $current, array $target, string $mode, array $provider_meta, array $database_override_keys = array() ): array {
		$by_key = array();

		foreach ( $current as $record ) {
			$by_key[ $record->key() ] = array(
				'current' => $record,
				'target'  => null,
			);
		}

		foreach ( $target as $record ) {
			$key            = $record->key();
			$by_key[ $key ] = array(
				'current' => isset( $by_key[ $key ] ) ? $by_key[ $key ]['current'] : null,
				'target'  => $record,
			);
		}

		$entries = array();

		foreach ( $by_key as $key => $pair ) {
			$entries[] = self::entry( $key, $pair['current'], $pair['target'], $mode, $provider_meta, $database_override_keys );
		}

		usort( $entries, array( self::class, 'compare_entries' ) );

		return $entries;
	}

	/**
	 * Pure. Builds the EFFECTIVE current state for Git mode: the Git baseline
	 * records, with any database override of the same key replacing its
	 * baseline counterpart, plus every database record that has no baseline
	 * counterpart. Sorted by key ascending.
	 *
	 * @param list<StateRecord> $baseline
	 * @param list<StateRecord> $database
	 * @return list<StateRecord>
	 */
	public static function overlay( array $baseline, array $database ): array {
		$by_key = array();

		foreach ( $baseline as $record ) {
			$by_key[ $record->key() ] = $record;
		}

		foreach ( $database as $record ) {
			$by_key[ $record->key() ] = $record;
		}

		$overlaid = array_values( $by_key );

		usort( $overlaid, array( self::class, 'compare_record_keys' ) );

		return $overlaid;
	}

	/**
	 * Pure. Whether the report contains at least one drift entry, by the
	 * summary the same report carries.
	 *
	 * @param array<string, mixed> $report
	 */
	public static function has_drift( array $report ): bool {
		$drift = $report['summary']['drift'] ?? 0;

		return 0 < (int) $drift;
	}

	/**
	 * Pure. The six summary keys, counted from the entries: drift is the
	 * number of entries with countsAsDrift true, the other five are the
	 * per-classification counts.
	 *
	 * @param list<array<string, mixed>> $entries
	 * @return array<string, int>
	 */
	public static function summarize( array $entries ): array {
		$summary = array(
			'drift'      => 0,
			'promotable' => 0,
			'dbOwned'    => 0,
			'forbidden'  => 0,
			'unresolved' => 0,
			'unchanged'  => 0,
		);

		foreach ( $entries as $entry ) {
			if ( $entry['countsAsDrift'] ) {
				++$summary['drift'];
			}

			switch ( $entry['classification'] ) {
				case DriftClassification::PROMOTABLE:
					++$summary['promotable'];
					break;
				case DriftClassification::DB_OWNED:
					++$summary['dbOwned'];
					break;
				case DriftClassification::FORBIDDEN:
					++$summary['forbidden'];
					break;
				case DriftClassification::UNRESOLVED:
					++$summary['unresolved'];
					break;
				case DriftClassification::UNCHANGED:
					++$summary['unchanged'];
					break;
			}
		}

		return $summary;
	}

	/**
	 * Pure record-key comparison for the overlay sort; named so usort()
	 * never carries a closure.
	 */
	public static function compare_record_keys( StateRecord $a, StateRecord $b ): int {
		return strcmp( $a->key(), $b->key() );
	}

	/**
	 * Pure entry comparison for the report sort; named so usort() never
	 * carries a closure.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	public static function compare_entries( array $a, array $b ): int {
		return strcmp( (string) $a['key'], (string) $b['key'] );
	}

	/**
	 * One diff entry: status by content-hash comparison, classification in
	 * the §11.10 order (unchanged, refuse, promotable-with-unresolved-
	 * references, promotable, otherwise database-owned), countsAsDrift by
	 * the §6 two-mode rule — no per-provider special cases — and the
	 * hasDatabaseOverride flag from the explicit gatherer input, never from
	 * the overlaid records.
	 *
	 * @param StateRecord|null                                                                 $current
	 * @param StateRecord|null                                                                 $target
	 * @param array<string, array{ownership: string, promotion: string, hasGitBaseline: bool}> $provider_meta
	 * @param list<string>                                                                     $database_override_keys
	 * @return array<string, mixed>
	 */
	private static function entry( string $key, ?StateRecord $current, ?StateRecord $target, string $mode, array $provider_meta, array $database_override_keys ): array {
		$provider_slug = explode( ':', $key, 2 )[0];

		if ( ! isset( $provider_meta[ $provider_slug ] ) ) {
			throw StateException::hard_error( 'No provider metadata for "' . $provider_slug . '" (record ' . $key . '); every record key must name a provider present in the provider meta map.' );
		}

		$meta = $provider_meta[ $provider_slug ];

		if ( null === $current && null === $target ) {
			$status = 'unchanged';
		} elseif ( null === $current ) {
			$status = 'removed';
		} elseif ( null === $target ) {
			$status = 'added';
		} elseif ( $current->content_hash() === $target->content_hash() ) {
			$status = 'unchanged';
		} else {
			$status = 'changed';
		}

		$promotion = $meta['promotion'];

		if ( 'unchanged' === $status ) {
			$classification = DriftClassification::UNCHANGED;
		} elseif ( PromotionPolicy::REFUSE === $promotion ) {
			$classification = DriftClassification::FORBIDDEN;
		} elseif ( PromotionPolicy::PROMOTABLE === $promotion && self::has_unresolved_references( $current ) ) {
			$classification = DriftClassification::UNRESOLVED;
		} elseif ( PromotionPolicy::PROMOTABLE === $promotion ) {
			$classification = DriftClassification::PROMOTABLE;
		} else {
			$classification = DriftClassification::DB_OWNED;
		}

		if ( DriftClassification::UNCHANGED === $classification ) {
			$counts_as_drift = false;
		} elseif ( self::MODE_GIT === $mode ) {
			$counts_as_drift = $meta['hasGitBaseline'];
		} else {
			$counts_as_drift = true;
		}

		return array(
			'key'                  => $key,
			'provider'             => $provider_slug,
			'slug'                 => substr( $key, strlen( $provider_slug ) + 1 ),
			'status'               => $status,
			'classification'       => $classification,
			'countsAsDrift'        => $counts_as_drift,
			'currentHash'          => null === $current ? null : $current->content_hash(),
			'targetHash'           => null === $target ? null : $target->content_hash(),
			'hasDatabaseOverride'  => in_array( $key, $database_override_keys, true ),
			'unresolvedReferences' => null === $current ? array() : ReferenceScanner::unresolved( $current->references() ),
		);
	}

	/**
	 * The report wrapper both gathers build: the fixed wrapper fields, the
	 * source descriptor (null in Git mode, the bundle's export identity in
	 * bundle mode), the summary, and the entries.
	 *
	 * @param list<array<string, mixed>> $entries
	 * @param array<string, mixed>|null  $source
	 * @param list<string>               $skipped_providers
	 * @return array<string, mixed>
	 */
	private static function report( array $entries, string $mode, ?array $source, array $skipped_providers ): array {
		return array(
			'schemaVersion'    => 1,
			'mode'             => $mode,
			'generatedAtUtc'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'siteUuid'         => SiteIdentity::uuid(),
			'source'           => $source,
			'summary'          => self::summarize( $entries ),
			'skippedProviders' => $skipped_providers,
			'entries'          => $entries,
		);
	}

	/**
	 * All gathers resolve through this one seam: the map captured at
	 * construction time, never a fresh registry lookup.
	 *
	 * @throws StateException Exit 1 on an unknown provider slug.
	 */
	private function provider( string $slug ): StateProvider {
		if ( ! isset( $this->providers[ $slug ] ) ) {
			throw StateException::hard_error( 'Unknown provider slug "' . $slug . '"; valid slugs are: ' . implode( ', ', array_keys( $this->providers ) ) . '.' );
		}

		return $this->providers[ $slug ];
	}

	/**
	 * Whether a record carries at least one reference that is still
	 * unresolved — the classification trigger for UNRESOLVED. A removed
	 * record has no current side, so it can never be unresolved.
	 */
	private static function has_unresolved_references( ?StateRecord $record ): bool {
		return null !== $record && array() !== ReferenceScanner::unresolved( $record->references() );
	}
}
