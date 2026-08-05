<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The only sanctioned bundle reader (BLOCK_THEME_PROPOSAL.md §9.5): content
 * stays mechanically unreadable until the signature verifies. records(),
 * record(), and provider_meta() throw exit 4 while is_verified() is false,
 * so no code path can ever hand out records from an unverified document and
 * defeat the signer. load() is the full path Task 3 consumes: read, decode,
 * schema-validate, verify the HMAC, then recompute stateHash from the
 * providers and compare — a mismatch is tamper, not a hard error, because
 * content that no longer hashes to its recorded hash has been altered.
 */
final class StateBundle {

	/** @var array<string, mixed> */
	private array $document;

	private bool $verified = false;

	/**
	 * @param array<string, mixed> $document
	 */
	private function __construct( array $document ) {
		$this->document = $document;
	}

	/**
	 * Stores a document and checks only that providers is an array; schema
	 * validation, signature verification, and the stateHash recomputation
	 * belong to load(). This exists so the reader's tamper gate can be
	 * tested without a file or a full load path.
	 *
	 * @param array<string, mixed> $document
	 * @throws StateException Exit 1 on a malformed document.
	 */
	public static function from_array( array $document ): self {
		if ( ! isset( $document['providers'] ) || ! is_array( $document['providers'] ) ) {
			throw StateException::hard_error( 'The bundle document is malformed: "providers" must be an array.' );
		}

		return new self( $document );
	}

	/**
	 * @throws StateException Exit 1 on malformed JSON.
	 */
	public static function from_json( string $json ): self {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- the bundle reader must not depend on WordPress being loaded; the unit suite covers this method with no WordPress present.
			$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw StateException::hard_error( 'The bundle is not valid JSON: ' . $error->getMessage(), $error );
		}

		if ( ! is_array( $decoded ) ) {
			throw StateException::hard_error( 'The bundle JSON must decode to an object.' );
		}

		return self::from_array( $decoded );
	}

	/**
	 * Reads a bundle from a filesystem path, or from STDIN when the path is
	 * '-'.
	 *
	 * @param string $path A filesystem path, or '-' to read STDIN.
	 * @throws StateException Exit 1 when the file is unreadable or the JSON is malformed.
	 */
	public static function from_file( string $path ): self {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundle file on disk from the CLI; WP_Filesystem needs a credentials-bearing admin request context that bundle reads do not have.
		$raw = '-' === $path ? file_get_contents( 'php://stdin' ) : file_get_contents( $path );

		if ( false === $raw ) {
			throw StateException::hard_error( 'The bundle file could not be read: "' . $path . '".' );
		}

		return self::from_json( $raw );
	}

	/**
	 * Read, schema-validate, verify the signature, and confirm stateHash —
	 * the one call Task 3 should use. Records stay unreadable until every
	 * step has succeeded, and the document returned by this method has
	 * is_verified() true.
	 *
	 * @throws StateException Exit 1 (malformed/invalid) or exit 4 (tamper).
	 */
	public static function load( string $path, ?HmacSigner $signer = null, ?SchemaValidator $validator = null ): self {
		$bundle = self::from_file( $path );

		$resolved_validator = $validator ?? new SchemaValidator();
		$resolved_validator->validate( $bundle->document, SchemaValidator::SCHEMA_STATE_BUNDLE );

		$bundle->verify_signature( $signer ?? HmacSigner::from_environment() );

		$recomputed = Normalizer::hash( StateExporter::canonical_provider_records( $bundle->document['providers'] ) );
		$recorded   = $bundle->document['stateHash'] ?? null;

		if ( ! is_string( $recorded ) || ! hash_equals( $recomputed, $recorded ) ) {
			throw StateException::tamper( 'The bundle stateHash does not match its providers: the content was altered after signing.' );
		}

		return $bundle;
	}

	/**
	 * @throws StateException Exit 4 when the signature does not verify.
	 */
	public function verify_signature( HmacSigner $signer ): void {
		$signer->verify( $this->document, HmacSigner::PURPOSE_BUNDLE );

		$this->verified = true;
	}

	public function is_verified(): bool {
		return $this->verified;
	}

	public function schema_version(): int {
		return (int) $this->document['schemaVersion'];
	}

	public function export_id(): string {
		return (string) $this->document['exportId'];
	}

	public function exported_at_utc(): string {
		return (string) $this->document['exportedAtUtc'];
	}

	public function site_uuid(): string {
		return (string) $this->document['siteUuid'];
	}

	public function site_url(): string {
		return (string) $this->document['siteUrl'];
	}

	public function environment(): string {
		return (string) $this->document['environment'];
	}

	public function wordpress_version(): string {
		return (string) $this->document['wordpressVersion'];
	}

	/**
	 * @return array{stylesheet: string, version: string, gitCommit: string|null}
	 */
	public function active_theme(): array {
		$git_commit = $this->document['activeTheme']['gitCommit'] ?? null;

		return array(
			'stylesheet' => (string) $this->document['activeTheme']['stylesheet'],
			'version'    => (string) $this->document['activeTheme']['version'],
			'gitCommit'  => is_string( $git_commit ) ? $git_commit : null,
		);
	}

	public function state_hash(): string {
		return (string) $this->document['stateHash'];
	}

	/**
	 * @return list<string> Sorted provider slugs present in this bundle.
	 */
	public function provider_slugs(): array {
		$slugs = array_keys( $this->document['providers'] );
		sort( $slugs, SORT_STRING );

		return $slugs;
	}

	/**
	 * @return array{ownership: string, promotion: string, hasGitBaseline: bool}
	 * @throws StateException Exit 4 when unverified.
	 */
	public function provider_meta( string $provider_slug ): array {
		$this->assert_verified();

		if ( ! isset( $this->document['providers'][ $provider_slug ] ) ) {
			throw StateException::hard_error( 'The bundle has no provider named "' . $provider_slug . '"; providers present: ' . implode( ', ', $this->provider_slugs() ) . '.' );
		}

		$node = $this->document['providers'][ $provider_slug ];

		return array(
			'ownership'      => (string) $node['ownership'],
			'promotion'      => (string) $node['promotion'],
			'hasGitBaseline' => (bool) $node['hasGitBaseline'],
		);
	}

	/**
	 * Every record of one provider as a validated value object; an empty
	 * list when the bundle has no such provider.
	 *
	 * @return list<StateRecord>
	 * @throws StateException Exit 4 when unverified.
	 */
	public function records( string $provider_slug ): array {
		$this->assert_verified();

		$records = array();

		if ( ! isset( $this->document['providers'][ $provider_slug ] ) ) {
			return $records;
		}

		foreach ( $this->document['providers'][ $provider_slug ]['records'] as $record ) {
			$records[] = StateRecord::from_array( $record );
		}

		return $records;
	}

	/**
	 * The single record with the given key, or null when the bundle has no
	 * such record.
	 *
	 * @throws StateException Exit 4 when unverified.
	 */
	public function record( string $key ): ?StateRecord {
		$this->assert_verified();

		foreach ( $this->document['providers'] as $node ) {
			foreach ( $node['records'] as $record ) {
				if ( $key === $record['key'] ) {
					return StateRecord::from_array( $record );
				}
			}
		}

		return null;
	}

	/**
	 * The raw document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->document;
	}

	/**
	 * The mechanical guarantee behind spec §9.5's "verify bundle HMAC before
	 * reading": every content accessor throws exit 4 while the signature is
	 * unverified.
	 */
	private function assert_verified(): void {
		if ( ! $this->verified ) {
			throw StateException::tamper( 'The bundle signature has not been verified; refusing to read bundle content.' );
		}
	}
}
