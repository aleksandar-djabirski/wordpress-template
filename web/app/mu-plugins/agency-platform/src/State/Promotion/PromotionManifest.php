<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The promotion lifecycle's signed document (BLOCK_THEME_PROPOSAL.md §7.6):
 * one per promotion run, mutated through the with_* methods and finally
 * HMAC-signed at seal time. Every with_* method returns a NEW instance —
 * the payload is signed once, and an in-place mutation after signing would
 * corrupt it without any reader noticing.
 *
 * The schema (resources/schemas/promotion-manifest-v1.json) describes the
 * SIGNED, STORED manifest: hmacKeyId and hmac are required there, and a
 * freshly create()d manifest legitimately carries neither — the store adds
 * them from HmacSigner::sign() before it validates on the write path. This
 * class does no I/O and no schema validation; from_array() accepts a
 * document with or without the signature fields.
 */
final class PromotionManifest {

	public const SCHEMA_VERSION = 1;

	/** @var array<string, mixed> */
	private array $data;

	/**
	 * @param array<string, mixed> $data
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * The one way to read a stored manifest back into a value object. The
	 * schema version is the only thing checked here: anything else is the
	 * store's validation job. A foreign version means a newer reader wrote
	 * the document, and this reader must not guess at it.
	 *
	 * @param array<string, mixed> $data
	 * @throws PromotionException Exit 1 when the document is not schemaVersion 1.
	 */
	public static function from_array( array $data ): self {
		$schema_version = $data['schemaVersion'] ?? null;

		if ( self::SCHEMA_VERSION !== $schema_version ) {
			throw PromotionException::hard(
				'Unsupported manifest schemaVersion '
				. ( is_int( $schema_version ) ? (string) $schema_version : get_debug_type( $schema_version ) )
				. '; expected ' . self::SCHEMA_VERSION . '.'
			);
		}

		return new self( $data );
	}

	/**
	 * Builds a fresh, unsigned manifest for a promotion run. siteUuid is the
	 * TARGET site uuid from the operator's configuration, never the export
	 * site's — that distinction is what lets a bundle travel between
	 * environments at all. The signature fields are deliberately absent
	 * until the store signs the document.
	 *
	 * @param array<string, mixed> $bundle_header       exportId/exportedAtUtc/siteUrl/environment/activeTheme
	 * @param list<string>         $verification_commands
	 */
	public static function create(
		string $promotion_id,
		string $prepared_at_utc,
		array $bundle_header,
		string $target_site_uuid,
		string $base_commit,
		array $verification_commands
	): self {
		return new self(
			array(
				'schemaVersion'        => self::SCHEMA_VERSION,
				'promotionId'          => $promotion_id,
				'exportId'             => self::bundle_string( $bundle_header, 'exportId' ),
				'exportedAtUtc'        => self::bundle_string( $bundle_header, 'exportedAtUtc' ),
				'preparedAtUtc'        => $prepared_at_utc,
				'siteUuid'             => $target_site_uuid,
				'siteUrl'              => self::bundle_string( $bundle_header, 'siteUrl' ),
				'environment'          => self::bundle_string( $bundle_header, 'environment' ),
				'activeTheme'          => self::bundle_active_theme( $bundle_header ),
				'baseCommit'           => $base_commit,
				'deployCommit'         => null,
				'sealedAtUtc'          => null,
				'records'              => array(),
				'refusals'             => array(),
				'verificationCommands' => array_values( $verification_commands ),
				'finalizeStatus'       => 'pending',
				'finalizedAtUtc'       => null,
				'backupId'             => null,
				'settlementStatus'     => 'pending',
				'settledAtUtc'         => null,
				'retentionUntilUtc'    => null,
			)
		);
	}

	/**
	 * The manifest document. Records and refusals are always emitted as
	 * lists, never as associative arrays, so the signed bytes never depend
	 * on how a caller assembled them.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$data = $this->data;

		if ( isset( $data['records'] ) && is_array( $data['records'] ) ) {
			$data['records'] = array_values( $data['records'] );
		}

		if ( isset( $data['refusals'] ) && is_array( $data['refusals'] ) ) {
			$data['refusals'] = array_values( $data['refusals'] );
		}

		return $data;
	}

	public function promotion_id(): string {
		return $this->string_field( 'promotionId' );
	}

	public function export_id(): string {
		return $this->string_field( 'exportId' );
	}

	public function site_uuid(): string {
		return $this->string_field( 'siteUuid' );
	}

	public function site_url(): string {
		return $this->string_field( 'siteUrl' );
	}

	public function environment(): string {
		return $this->string_field( 'environment' );
	}

	/**
	 * @return array{stylesheet: string, version: string, gitCommit: string|null}
	 */
	public function active_theme(): array {
		$theme = $this->data['activeTheme'] ?? null;

		if ( ! is_array( $theme ) ) {
			return array(
				'stylesheet' => '',
				'version'    => '',
				'gitCommit'  => null,
			);
		}

		return array(
			'stylesheet' => self::array_string( $theme, 'stylesheet' ),
			'version'    => self::array_string( $theme, 'version' ),
			'gitCommit'  => self::array_nullable_string( $theme, 'gitCommit' ),
		);
	}

	public function base_commit(): string {
		return $this->string_field( 'baseCommit' );
	}

	public function deploy_commit(): ?string {
		return $this->nullable_string_field( 'deployCommit' );
	}

	/**
	 * A manifest is sealed once --seal has recorded the deploy commit.
	 * Before that, prepare can still modify it; after that, every lifecycle
	 * step must refuse to touch the payload.
	 */
	public function is_sealed(): bool {
		return null !== $this->deploy_commit();
	}

	/**
	 * The canonical record keys in ascending order, regardless of the order
	 * records were added in.
	 *
	 * @return list<string>
	 */
	public function record_keys(): array {
		$keys = array();

		foreach ( $this->records() as $record ) {
			if ( isset( $record['key'] ) && is_string( $record['key'] ) ) {
				$keys[] = $record['key'];
			}
		}

		sort( $keys, SORT_STRING );

		return $keys;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function record( string $record_key ): ?array {
		foreach ( $this->records() as $record ) {
			if ( ( $record['key'] ?? null ) === $record_key ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function records(): array {
		return $this->list_of_arrays( 'records' );
	}

	/**
	 * Adds or replaces one record and re-sorts the list by key, so the
	 * signed payload is order-stable no matter the insertion order.
	 *
	 * @param array<string, mixed> $record
	 */
	public function with_record( string $record_key, array $record ): self {
		$clone    = clone $this;
		$records  = $clone->records();
		$replaced = false;

		foreach ( $records as $index => $existing ) {
			if ( ( $existing['key'] ?? null ) === $record_key ) {
				$records[ $index ] = $record;
				$replaced          = true;
				break;
			}
		}

		if ( ! $replaced ) {
			$records[] = $record;
		}

		$clone->data['records'] = self::sort_records_by_key( $records );

		return $clone;
	}

	/**
	 * Merges $changes into the record with the given key without dropping
	 * any untouched field. A key that is not present yet is appended.
	 *
	 * @param array<string, mixed> $changes
	 */
	public function with_record_changes( string $record_key, array $changes ): self {
		$clone    = clone $this;
		$records  = $clone->records();
		$replaced = false;

		foreach ( $records as $index => $existing ) {
			if ( ( $existing['key'] ?? null ) === $record_key ) {
				$records[ $index ] = array_merge( $existing, $changes );
				$replaced          = true;
				break;
			}
		}

		if ( ! $replaced ) {
			$records[] = $changes;
		}

		$clone->data['records'] = self::sort_records_by_key( $records );

		return $clone;
	}

	/**
	 * Records the deploy commit and its timestamp: the act of sealing.
	 */
	public function with_deploy_commit( string $sha, string $sealed_at_utc ): self {
		$clone                       = clone $this;
		$clone->data['deployCommit'] = $sha;
		$clone->data['sealedAtUtc']  = $sealed_at_utc;

		return $clone;
	}

	/**
	 * Sets one arbitrary root field. The store uses it to attach the HMAC
	 * signature fields (hmacKeyId, hmac) before validation on the write
	 * path; lifecycle steps use it for finalizeStatus and friends.
	 *
	 * @param mixed $value
	 */
	public function with_field( string $key, $value ): self {
		$clone               = clone $this;
		$clone->data[ $key ] = $value;

		return $clone;
	}

	/**
	 * Replaces the whole refusal report with the given refusals, rendered
	 * through RecordRefusal::to_array() so the manifest shape and the
	 * exception surface can never drift apart.
	 *
	 * @param list<RecordRefusal> $refusals
	 */
	public function with_refusals( array $refusals ): self {
		$clone = clone $this;

		$reported = array();

		foreach ( $refusals as $refusal ) {
			$reported[] = $refusal->to_array();
		}

		$clone->data['refusals'] = $reported;

		return $clone;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function refusals(): array {
		return $this->list_of_arrays( 'refusals' );
	}

	public function finalize_status(): string {
		$status = $this->data['finalizeStatus'] ?? null;

		return is_string( $status ) ? $status : 'pending';
	}

	public function finalized_at_utc(): ?string {
		return $this->nullable_string_field( 'finalizedAtUtc' );
	}

	public function settlement_status(): string {
		$status = $this->data['settlementStatus'] ?? null;

		return is_string( $status ) ? $status : 'pending';
	}

	public function settled_at_utc(): ?string {
		return $this->nullable_string_field( 'settledAtUtc' );
	}

	public function retention_until_utc(): ?string {
		return $this->nullable_string_field( 'retentionUntilUtc' );
	}

	public function backup_id(): ?string {
		return $this->nullable_string_field( 'backupId' );
	}

	/**
	 * @return list<string>
	 */
	public function verification_commands(): array {
		$commands = $this->data['verificationCommands'] ?? array();

		if ( ! is_array( $commands ) ) {
			return array();
		}

		$list = array();

		foreach ( $commands as $command ) {
			if ( is_string( $command ) ) {
				$list[] = $command;
			}
		}

		return $list;
	}

	private function string_field( string $key ): string {
		$value = $this->data[ $key ] ?? null;

		return is_string( $value ) ? $value : '';
	}

	private function nullable_string_field( string $key ): ?string {
		$value = $this->data[ $key ] ?? null;

		return is_string( $value ) ? $value : null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function list_of_arrays( string $key ): array {
		$values = $this->data[ $key ] ?? array();

		if ( ! is_array( $values ) ) {
			return array();
		}

		$list = array();

		foreach ( $values as $value ) {
			if ( is_array( $value ) ) {
				$list[] = $value;
			}
		}

		return $list;
	}

	/**
	 * @param array<string, mixed> $bundle_header
	 */
	private static function bundle_string( array $bundle_header, string $key ): string {
		$value = $bundle_header[ $key ] ?? null;

		return is_string( $value ) ? $value : '';
	}

	/**
	 * StateBundle::active_theme() carries THREE keys — stylesheet, version and a
	 * nullable gitCommit — so the manifest carries all three. Dropping gitCommit
	 * would make every manifest built from a real bundle header fail schema
	 * validation, because the schema forbids additional properties, and it would
	 * throw away the provenance an operator needs to tell which theme commit a
	 * promotion was prepared against.
	 *
	 * @param array<string, mixed> $bundle_header
	 * @return array{stylesheet: string, version: string, gitCommit: string|null}
	 */
	private static function bundle_active_theme( array $bundle_header ): array {
		$theme = $bundle_header['activeTheme'] ?? null;

		if ( ! is_array( $theme ) ) {
			return array(
				'stylesheet' => '',
				'version'    => '',
				'gitCommit'  => null,
			);
		}

		return array(
			'stylesheet' => self::array_string( $theme, 'stylesheet' ),
			'version'    => self::array_string( $theme, 'version' ),
			'gitCommit'  => self::array_nullable_string( $theme, 'gitCommit' ),
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function array_nullable_string( array $data, string $key ): ?string {
		$value = $data[ $key ] ?? null;

		return is_string( $value ) ? $value : null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function array_string( array $data, string $key ): string {
		$value = $data[ $key ] ?? null;

		return is_string( $value ) ? $value : '';
	}

	/**
	 * @param list<array<string, mixed>> $records
	 * @return list<array<string, mixed>>
	 */
	private static function sort_records_by_key( array $records ): array {
		usort( $records, array( self::class, 'record_key_cmp' ) );

		return $records;
	}

	/**
	 * @param array<string, mixed> $left
	 * @param array<string, mixed> $right
	 */
	private static function record_key_cmp( array $left, array $right ): int {
		$left_key  = isset( $left['key'] ) && is_string( $left['key'] ) ? $left['key'] : '';
		$right_key = isset( $right['key'] ) && is_string( $right['key'] ) ? $right['key'] : '';

		return strcmp( $left_key, $right_key );
	}
}
