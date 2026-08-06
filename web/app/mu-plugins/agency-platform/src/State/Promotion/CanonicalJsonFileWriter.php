<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\Normalizer;

/**
 * The staged writer for canonical JSON documents (plan Task 6): the ONLY
 * writer Global Styles uses for theme.json. The document is recursively
 * key-sorted (object maps sorted, list order preserved) and serialised with
 * wp_json_encode() plus one trailing newline. It never block-normalises the
 * document — theme.json is not block markup, so a navigation-shaped string
 * inside it must round-trip byte-for-byte, and it never calls
 * PreparedFileWriter::render(), ResolvedTemplateNormalizer or
 * Normalizer::normalize_block_markup().
 *
 * Containment follows the same rule as PreparedFileWriter — theme files
 * legitimately live inside the web root, so the guard is theme-directory
 * containment, not StateDirectory::resolve_output(): the path must be
 * relative, and it must stay inside the theme directory after lexical
 * resolution of "." and "..", performed BEFORE any filesystem call.
 */
final class CanonicalJsonFileWriter {

	public function __construct( private string $theme_dir ) {}

	/**
	 * Stages one document: guarded and resolved like a prepared file, then
	 * serialised canonically and written to a temp file. Nothing lands at the
	 * final path until the returned entry is committed.
	 *
	 * @param array<string,mixed> $document
	 * @throws PromotionException Exit 1 for any refusal or failure.
	 */
	public function stage( string $theme_relative_path, array $document, string $promotion_id ): StagedPromotionEntry {
		self::assert_promotion_id( $promotion_id );

		$absolute = $this->resolve_contained_path( $theme_relative_path );

		if ( ! is_dir( dirname( $absolute ) ) ) {
			throw PromotionException::hard( sprintf( 'The theme directory for "%s" does not exist.', $theme_relative_path ) );
		}

		$previous_bytes = $this->previous_bytes( $absolute );

		$canonical_document = Normalizer::sort_recursive( $document );

		$encoded = wp_json_encode( $canonical_document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $encoded ) {
			throw PromotionException::hard( sprintf( 'The document for "%s" could not be encoded as JSON.', $theme_relative_path ) );
		}

		$body = $encoded . "\n";

		$temporary = $absolute . '.' . $promotion_id . '.tmp';

		$this->write_temp( $temporary, $body );

		return new StagedJsonFile(
			$theme_relative_path,
			$absolute,
			hash( 'sha256', $body ),
			null === $previous_bytes ? null : hash( 'sha256', $previous_bytes ),
			$temporary,
			$previous_bytes
		);
	}

	/**
	 * The current bytes of the target file, or null when no file exists yet.
	 * Committing over an unreadable file would make rollback impossible, so
	 * an existing-but-unreadable file is refused.
	 */
	private function previous_bytes( string $absolute ): ?string {
		if ( ! is_file( $absolute ) ) {
			return null;
		}

		if ( ! is_readable( $absolute ) ) {
			throw PromotionException::hard( sprintf( 'The existing document "%s" could not be read.', $absolute ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local on-disk artifact, never a URL.
		$bytes = file_get_contents( $absolute );

		if ( false === $bytes ) {
			throw PromotionException::hard( sprintf( 'The existing document "%s" could not be read.', $absolute ) );
		}

		return $bytes;
	}

	/**
	 * Atomic temp-write: the temp file is flushed and closed before anything
	 * can rename it. A failure part-way through unlinks the temp file, so no
	 * path out of stage() leaves residue.
	 */
	private function write_temp( string $temporary, string $body ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
		$handle = fopen( $temporary, 'wb' );

		if ( false === $handle ) {
			throw PromotionException::hard( sprintf( 'Could not open the temporary file "%s".', $temporary ) );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
			$written = fwrite( $handle, $body );

			if ( false === $written || strlen( $body ) !== $written ) {
				throw PromotionException::hard( sprintf( 'The file could not be fully written to "%s".', $temporary ) );
			}

			if ( ! fflush( $handle ) ) {
				throw PromotionException::hard( sprintf( 'The file could not be flushed to "%s".', $temporary ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
			if ( ! fclose( $handle ) ) {
				throw PromotionException::hard( sprintf( 'The file "%s" could not be closed.', $temporary ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
			if ( ! chmod( $temporary, 0644 ) ) {
				throw PromotionException::hard( sprintf( 'The file "%s" could not be made readable.', $temporary ) );
			}
		} catch ( \Throwable $failure ) {
			if ( is_resource( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
				fclose( $handle );
			}

			if ( is_file( $temporary ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
				unlink( $temporary );
			}

			if ( $failure instanceof PromotionException ) {
				throw $failure;
			}

			throw PromotionException::hard(
				sprintf( 'The document could not be staged: %s', $failure->getMessage() ),
				$failure
			);
		}
	}

	/**
	 * Resolves a theme-relative path against the theme directory. Refuses an
	 * absolute path, a Windows drive or backslash path, and any path that
	 * escapes the theme directory after lexical resolution of "." and "..".
	 * The resolution is pure string work performed BEFORE any filesystem
	 * call.
	 *
	 * @throws PromotionException Exit 1 for any refusal.
	 */
	private function resolve_contained_path( string $theme_relative_path ): string {
		if ( '' === $theme_relative_path
			|| 0 === strpos( $theme_relative_path, '/' )
			|| 1 === preg_match( '/^[A-Za-z]:\//', $theme_relative_path )
			|| false !== strpos( $theme_relative_path, '\\' ) ) {
			throw PromotionException::hard(
				sprintf( 'The theme-relative path "%s" is not a relative theme path; a promotion must never write outside the theme directory.', $theme_relative_path )
			);
		}

		$theme_segments = self::canonical_segments( $this->theme_dir );
		$depth          = count( $theme_segments );
		$resolved       = $theme_segments;

		foreach ( explode( '/', $theme_relative_path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				if ( count( $resolved ) <= $depth ) {
					throw PromotionException::hard(
						sprintf( 'The theme-relative path "%s" escapes the theme directory.', $theme_relative_path )
					);
				}

				array_pop( $resolved );

				continue;
			}

			$resolved[] = $segment;
		}

		$joined = implode( '/', $resolved );

		// canonical_segments() drops the leading slash, so an absolute theme
		// directory must have its root restored or every staged path would
		// silently become relative to the process working directory.
		return str_starts_with( $this->theme_dir, '/' ) ? '/' . $joined : $joined;
	}

	/**
	 * Lexically resolves a path to its canonical segments: empty and "."
	 * segments collapse, ".." pops the previous segment. Pure string work —
	 * realpath() returns false for a file that does not exist yet, so it can
	 * never be the containment guard.
	 *
	 * @return list<string>
	 */
	private static function canonical_segments( string $path ): array {
		$segments = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $segments );

				continue;
			}

			$segments[] = $segment;
		}

		return $segments;
	}

	/**
	 * A promotion id is interpolated straight into a temp file name, so it
	 * must be a bare UUID and nothing else — the same rule the manifest store
	 * applies to its own canonical path.
	 */
	private static function assert_promotion_id( string $promotion_id ): void {
		if ( 1 !== preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $promotion_id ) ) {
			throw PromotionException::hard(
				sprintf( 'The promotion id "%s" is not a UUID; it must never be used to build a filesystem path.', $promotion_id )
			);
		}
	}
}
