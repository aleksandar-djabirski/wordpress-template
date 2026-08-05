<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The staged writer for prepared theme files (plan Task 6). render() turns
 * exported markup into the promotion-safe body: the injected "theme"
 * attribute and every navigation "ref" are stripped first, then Task 2's
 * block-markup normalisation runs, then line endings are collapsed to LF
 * with exactly one trailing newline.
 *
 * stage() writes ONLY a temp file next to the target — nothing lands at the
 * final path until commit_all() renames the entries into place, and a
 * failure part-way through commit_all() rolls every already-renamed entry
 * back to its previous bytes (or removes a newly-created file).
 *
 * Prepared theme files legitimately live inside the web root
 * (web/app/themes/<theme>/templates/*.html), so the containment rule here
 * is deliberately NOT StateDirectory::resolve_output() — that guard refuses
 * everything under <repo root>/web because it protects operator-supplied
 * state-artifact paths. This writer's rule is theme-directory containment:
 * the path must be relative, its first segment must be templates/ or parts/,
 * and it must stay inside the theme directory after lexical resolution of
 * "." and ".." — realpath() cannot resolve a file that does not exist yet,
 * so the guard is pure string work performed BEFORE any filesystem call.
 */
final class PreparedFileWriter {

	public function __construct( private StateGateway $gateway, private string $theme_dir ) {}

	/** Normalised, promotion-safe body for the exported markup. */
	public function render( string $exported_markup ): string {
		$markup = ResolvedTemplateNormalizer::strip_theme_attribute( $exported_markup );
		$markup = ResolvedTemplateNormalizer::strip_navigation_refs( $markup );
		$markup = $this->gateway->normalize_block_markup( $markup );
		$markup = str_replace( array( "\r\n", "\r" ), "\n", $markup );

		return rtrim( $markup, "\n" ) . "\n";
	}

	/**
	 * Stages one record: the theme-relative path is guarded and resolved, the
	 * previous bytes are captured for rollback, and the rendered body is
	 * written to a temp file. Nothing lands at the final path yet.
	 *
	 * The returned entry's manifest_fields() carries:
	 * array{themeRelativePath:string, absolutePath:string,
	 *       preparedFileHash:string, originalFileHash:string|null,
	 *       expectedPostResetHash:string}
	 *
	 * @throws PromotionException Exit 1 for any refusal or failure.
	 */
	public function stage( string $theme_relative_path, string $exported_markup, string $promotion_id ): StagedPromotionEntry {
		self::assert_promotion_id( $promotion_id );

		$absolute = $this->resolve_contained_path( $theme_relative_path );

		if ( ! is_dir( dirname( $absolute ) ) ) {
			throw PromotionException::hard( sprintf( 'The theme directory for "%s" does not exist.', $theme_relative_path ) );
		}

		$previous_bytes = $this->previous_bytes( $absolute );

		$body = $this->render( $exported_markup );

		$temporary = $absolute . '.' . $promotion_id . '.tmp';

		$this->write_temp( $temporary, $body );

		return new StagedPreparedFile(
			$theme_relative_path,
			$absolute,
			hash( 'sha256', $body ),
			null === $previous_bytes ? null : hash( 'sha256', $previous_bytes ),
			$this->gateway->hash_markup( $this->gateway->normalize_block_markup( $body ) ),
			$temporary,
			$previous_bytes
		);
	}

	/**
	 * Commits every staged entry in order. If one commit fails, every entry
	 * already committed is rolled back to its previous bytes, every entry not
	 * committed is discarded, and a hard error is thrown.
	 *
	 * @param list<StagedPromotionEntry> $staged
	 * @throws PromotionException Exit 1; the failure of the first commit.
	 */
	public function commit_all( array $staged ): void {
		$committed = array();

		try {
			foreach ( $staged as $entry ) {
				$entry->commit();

				$committed[] = $entry;
			}
		} catch ( \Throwable $failure ) {
			foreach ( $committed as $entry ) {
				$entry->rollback_committed();
			}

			foreach ( $staged as $entry ) {
				$entry->discard();
			}

			if ( $failure instanceof PromotionException ) {
				throw $failure;
			}

			throw PromotionException::hard(
				sprintf( 'The prepared files could not be committed: %s', $failure->getMessage() ),
				$failure
			);
		}
	}

	/**
	 * Discards every staged entry. Every entry's discard() is idempotent, so
	 * this method is safe to call twice.
	 *
	 * @param list<StagedPromotionEntry> $staged
	 */
	public function discard_all( array $staged ): void {
		foreach ( $staged as $entry ) {
			$entry->discard();
		}
	}

	/**
	 * The current bytes of the target file, or null when no file exists yet.
	 * The promotion refuses to proceed when an existing file cannot be read:
	 * committing over an unreadable file would make rollback impossible.
	 */
	private function previous_bytes( string $absolute ): ?string {
		if ( ! is_file( $absolute ) ) {
			return null;
		}

		if ( ! is_readable( $absolute ) ) {
			throw PromotionException::hard( sprintf( 'The existing prepared file "%s" could not be read.', $absolute ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local on-disk artifact, never a URL.
		$bytes = file_get_contents( $absolute );

		if ( false === $bytes ) {
			throw PromotionException::hard( sprintf( 'The existing prepared file "%s" could not be read.', $absolute ) );
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
			throw PromotionException::hard( sprintf( 'Could not open the temporary prepared file "%s".', $temporary ) );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
			$written = fwrite( $handle, $body );

			if ( false === $written || strlen( $body ) !== $written ) {
				throw PromotionException::hard( sprintf( 'The prepared file could not be fully written to "%s".', $temporary ) );
			}

			if ( ! fflush( $handle ) ) {
				throw PromotionException::hard( sprintf( 'The prepared file could not be flushed to "%s".', $temporary ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
			if ( ! fclose( $handle ) ) {
				throw PromotionException::hard( sprintf( 'The prepared file "%s" could not be closed.', $temporary ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
			if ( ! chmod( $temporary, 0644 ) ) {
				throw PromotionException::hard( sprintf( 'The prepared file "%s" could not be made readable.', $temporary ) );
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
				sprintf( 'The prepared file could not be staged: %s', $failure->getMessage() ),
				$failure
			);
		}
	}

	/**
	 * Resolves a theme-relative path against the theme directory. Refuses an
	 * absolute path, a Windows drive or backslash path, a first segment that
	 * is not templates/ or parts/, and any path that escapes the theme
	 * directory after lexical resolution of "." and "..". The resolution is
	 * pure string work performed BEFORE any filesystem call.
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

		$first_segment = explode( '/', $theme_relative_path )[0];

		if ( ! in_array( $first_segment, array( 'templates', 'parts' ), true ) ) {
			throw PromotionException::hard(
				sprintf( 'The theme-relative path "%s" must start with templates/ or parts/.', $theme_relative_path )
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
