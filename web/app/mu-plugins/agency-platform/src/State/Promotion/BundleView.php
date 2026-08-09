<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\StateBundle;
use AgencyPlatform\State\StateException;
use AgencyPlatform\State\StateRecord;

/**
 * The read-only view over a VERIFIED state bundle that the promotion
 * lifecycle works with (plan Task 3): every accessor passes straight through
 * to StateBundle after load() has verified the schema, the PURPOSE_BUNDLE
 * signature, and the stateHash, and every Task 2 failure is rethrown as a
 * PromotionException carrying the SAME exit code — never a raw StateException
 * and never a code re-derived from a message.
 */
final class BundleView {

	public function __construct( private StateBundle $bundle ) {}

	public function export_id(): string {
		try {
			return $this->bundle->export_id();
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	public function exported_at_utc(): string {
		try {
			return $this->bundle->exported_at_utc();
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	public function site_uuid(): string {
		try {
			return $this->bundle->site_uuid();
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	public function site_url(): string {
		try {
			return $this->bundle->site_url();
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	public function environment(): string {
		try {
			return $this->bundle->environment();
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * @return array{stylesheet: string, version: string, gitCommit: string|null}
	 */
	public function active_theme(): array {
		try {
			return $this->bundle->active_theme();
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * The single record of the bundle as StateRecord::to_array() plus a
	 * derived `provider` key, or null when the bundle has no such record.
	 *
	 * @return array<string, mixed>|null
	 */
	public function record( string $record_key ): ?array {
		try {
			$record = $this->bundle->record( $record_key );

			if ( null === $record ) {
				return null;
			}

			return $record->to_array() + array( 'provider' => $record->provider_slug() );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * Every record of one provider as StateRecord::to_array() plus the
	 * derived `provider` key; an empty list when the bundle has no such
	 * provider.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function records( string $provider_slug ): array {
		try {
			$rows = array();

			foreach ( $this->bundle->records( $provider_slug ) as $record ) {
				$rows[] = $record->to_array() + array( 'provider' => $record->provider_slug() );
			}

			return $rows;
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	public function has_provider( string $provider_slug ): bool {
		try {
			return in_array( $provider_slug, $this->bundle->provider_slugs(), true );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * The underlying verified record, for handing to a PromotionStrategy.
	 */
	public function state_record( string $record_key ): ?StateRecord {
		try {
			return $this->bundle->record( $record_key );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}
	}

	/**
	 * The bundle wrapper metadata in one array, the shape the manifest
	 * builder consumes.
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
