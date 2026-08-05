<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\StateBundle;
use AgencyPlatform\State\StateRecord;

/**
 * The read-only view of a VERIFIED state bundle: StateGateway::load_bundle()
 * is the only construction path and it goes through StateBundle::load(), so
 * every accessor here runs on a bundle whose signature and stateHash have
 * already been confirmed. Record arrays are StateRecord::to_array() plus a
 * derived `provider` key, so the view is self-describing no matter which
 * provider node a record was read from. The underlying value objects stay
 * reachable through state_record() for the strategy layer.
 */
final class BundleView {

	public function __construct( private StateBundle $bundle ) {}

	public function export_id(): string {
		return $this->bundle->export_id();
	}

	public function exported_at_utc(): string {
		return $this->bundle->exported_at_utc();
	}

	public function site_uuid(): string {
		return $this->bundle->site_uuid();
	}

	public function site_url(): string {
		return $this->bundle->site_url();
	}

	public function environment(): string {
		return $this->bundle->environment();
	}

	/**
	 * @return array{stylesheet: string, version: string, gitCommit: string|null}
	 */
	public function active_theme(): array {
		return $this->bundle->active_theme();
	}

	/**
	 * The single record as a view array, or null when the bundle has no such
	 * record. StateRecord::to_array() plus a derived `provider` key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function record( string $record_key ): ?array {
		$record = $this->bundle->record( $record_key );

		if ( null === $record ) {
			return null;
		}

		return $record->to_array() + array( 'provider' => $record->provider_slug() );
	}

	/**
	 * Every record of one provider as view arrays; an empty list when the
	 * bundle has no such provider.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function records( string $provider_slug ): array {
		$records = array();

		foreach ( $this->bundle->records( $provider_slug ) as $record ) {
			$records[] = $record->to_array() + array( 'provider' => $record->provider_slug() );
		}

		return $records;
	}

	public function has_provider( string $provider_slug ): bool {
		return in_array( $provider_slug, $this->bundle->provider_slugs(), true );
	}

	/**
	 * The underlying verified record, for handing to a PromotionStrategy.
	 */
	public function state_record( string $record_key ): ?StateRecord {
		return $this->bundle->record( $record_key );
	}

	/**
	 * The manifest header subset: everything PromotionManifest::create()
	 * needs from the bundle, in one call.
	 *
	 * @return array{exportId: string, exportedAtUtc: string, siteUuid: string, siteUrl: string, environment: string, activeTheme: array<string, mixed>}
	 */
	public function header(): array {
		return array(
			'exportId'      => $this->export_id(),
			'exportedAtUtc' => $this->exported_at_utc(),
			'siteUuid'      => $this->site_uuid(),
			'siteUrl'       => $this->site_url(),
			'environment'   => $this->environment(),
			'activeTheme'   => $this->active_theme(),
		);
	}
}
