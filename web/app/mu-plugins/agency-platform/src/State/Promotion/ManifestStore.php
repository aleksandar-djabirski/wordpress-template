<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\StateDirectory;
use AgencyPlatform\State\StateException;

/**
 * The manifest store: the ONE reader and writer of promotion manifests
 * (BLOCK_THEME_PROPOSAL.md §7.6). load() verifies in a fixed order — decode,
 * require the signature fields, verify the HMAC, validate the schema — so a
 * signed document edited into an invalid shape reports tamper (exit 4), never
 * a hard error (exit 1): an attacker stripping fields from a signed artifact
 * must not look like an operator with a malformed file. render() signs and
 * validates but writes nothing; only write() touches the filesystem, through
 * the SHARED web-root guard (StateDirectory::resolve_output()) — the single
 * implementation of the containment rule, never a second copy — and through
 * one atomic temp-write + rename. The command layer alone decides whether a
 * document goes to STDOUT: this class refuses '-'.
 */
final class ManifestStore {

	/** @param callable():string|null $stdin_reader null reads php://stdin. */
	public function __construct( private StateGateway $gateway, private $stdin_reader = null ) {}

	/**
	 * Reads a path, or STDIN when $path_or_dash is '-'. Verification order is
	 * fixed: decode -> require signature fields -> verify HMAC -> validate schema.
	 *
	 * @throws PromotionException Exit 1 for malformed/invalid documents, exit 4 for tamper.
	 */
	public function load( string $path_or_dash ): PromotionManifest {
		$document = json_decode( $this->read( $path_or_dash ), true );

		if ( ! is_array( $document ) ) {
			throw PromotionException::hard( sprintf( 'Manifest "%s" is not valid JSON.', $path_or_dash ) );
		}

		// 1. A transportable artifact with no signature is indistinguishable from
		//    a stripped one, so treat it as tampering, not as a shape problem.
		if ( ! isset( $document['hmac'], $document['hmacKeyId'] )
			|| ! is_string( $document['hmac'] ) || ! is_string( $document['hmacKeyId'] ) ) {
			throw PromotionException::tamper( sprintf( 'Manifest "%s" carries no signature.', $path_or_dash ) );
		}

		// 2. Verify BEFORE validating the shape: a signed document edited into an
		//    invalid shape must report exit 4, not exit 1.
		$this->gateway->verify_manifest( $document );

		// 3. Only now is the content trusted enough to schema-check.
		$this->gateway->validate_manifest_schema( $document );

		return PromotionManifest::from_array( $document );
	}

	/**
	 * Signs, schema-validates and serialises. Writes NOTHING: the caller (or
	 * write()) decides where the bytes land.
	 */
	public function render( PromotionManifest $manifest ): string {
		$data = $manifest->to_array();

		unset( $data['hmac'], $data['hmacKeyId'] );

		$signature = $this->gateway->sign_manifest( $data );

		$data['hmacKeyId'] = $signature['hmacKeyId'];
		$data['hmac']      = $signature['hmac'];

		// The schema describes the SIGNED, STORED manifest; validating the
		// signed document catches a schema break introduced by this code.
		$this->gateway->validate_manifest_schema( $data );

		return $this->gateway->canonical_json_document( $data );
	}

	/**
	 * Atomic temp-write + rename through the SHARED web-root guard
	 * (StateDirectory::resolve_output()): a manifest carries the target site
	 * UUID, every prepared file path and every content hash, so it must never
	 * land under <repo root>/web. Never writes to a stream, never accepts '-'.
	 *
	 * @throws PromotionException Exit 1 on any refusal or failure.
	 */
	public function write( PromotionManifest $manifest, string $path ): void {
		if ( '-' === $path ) {
			throw PromotionException::hard( 'The manifest store cannot write to STDOUT; only the command layer may write to STDOUT.' );
		}

		try {
			$resolved = StateDirectory::resolve_output( $path, '--manifest' );
		} catch ( StateException $exception ) {
			throw PromotionException::from_state_exception( $exception );
		}

		$directory = dirname( $resolved );

		if ( ! wp_mkdir_p( $directory ) ) {
			throw PromotionException::hard( sprintf( 'Could not create the manifest directory "%s".', $directory ) );
		}

		$temporary = $resolved . '.' . wp_generate_uuid4() . '.tmp';

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
			$handle = fopen( $temporary, 'wb' );

			if ( false === $handle ) {
				throw PromotionException::hard( sprintf( 'Could not open the temporary manifest file "%s".', $temporary ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
			$written = fwrite( $handle, $this->render( $manifest ) );

			if ( false === $written ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
				fclose( $handle );

				throw PromotionException::hard( sprintf( 'Could not write the manifest to "%s".', $temporary ) );
			}

			fflush( $handle );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
			fclose( $handle );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
			chmod( $temporary, 0600 );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
			rename( $temporary, $resolved );
		} catch ( \Throwable $failure ) {
			if ( is_file( $temporary ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
				unlink( $temporary );
			}

			if ( $failure instanceof PromotionException ) {
				throw $failure;
			}

			if ( $failure instanceof StateException ) {
				throw PromotionException::from_state_exception( $failure );
			}

			throw PromotionException::hard(
				sprintf( 'The manifest could not be written to "%s": %s', $resolved, $failure->getMessage() ),
				$failure
			);
		}
	}

	/**
	 * The authoritative post-finalisation manifest location on the host:
	 * <state dir>/promotions/<promotionId>.json.
	 */
	public function canonical_path( string $promotion_id ): string {
		return $this->gateway->state_dir() . '/promotions/' . $promotion_id . '.json';
	}

	/**
	 * Routes through write() so there is still only one write path.
	 */
	public function write_canonical( PromotionManifest $manifest ): void {
		$path      = $this->canonical_path( $manifest->promotion_id() );
		$directory = dirname( $path );

		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			throw PromotionException::hard( sprintf( 'Could not create the promotions directory "%s".', $directory ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- the promotions directory must be host-private; WP_Filesystem exposes no permission primitive equivalent to this chmod.
		chmod( $directory, 0700 );

		$this->write( $manifest, $path );
	}

	/**
	 * The canonical host copy, read and fully verified. A missing canonical
	 * copy is exit 1: nothing was finalised on this host.
	 */
	public function load_canonical( string $promotion_id ): PromotionManifest {
		if ( ! $this->canonical_exists( $promotion_id ) ) {
			throw PromotionException::hard( sprintf( 'No finalized promotion found for %s. Run --finalize on this host first.', $promotion_id ) );
		}

		return $this->load( $this->canonical_path( $promotion_id ) );
	}

	public function canonical_exists( string $promotion_id ): bool {
		return is_file( $this->canonical_path( $promotion_id ) );
	}

	/**
	 * The injected stdin reader, or php://stdin when none was injected.
	 *
	 * @throws PromotionException Exit 1 when a file is unreadable.
	 */
	private function read( string $path_or_dash ): string {
		if ( '-' === $path_or_dash ) {
			if ( null !== $this->stdin_reader ) {
				return ( $this->stdin_reader )();
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local on-disk artifact, never a URL.
			return (string) file_get_contents( 'php://stdin' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local on-disk artifact, never a URL.
		$raw = file_get_contents( $path_or_dash );

		if ( false === $raw ) {
			throw PromotionException::hard( sprintf( 'The manifest file could not be read: "%s".', $path_or_dash ) );
		}

		return $raw;
	}
}
