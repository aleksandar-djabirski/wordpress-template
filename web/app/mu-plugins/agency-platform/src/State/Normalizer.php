<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The determinism contract behind stateHash (BLOCK_THEME_PROPOSAL.md §7.2):
 * repeated exports of unchanged state must produce byte-identical canonical
 * JSON, and therefore identical hashes, no matter what order the source
 * arrays were built in. Every method is pure except normalize_block_markup(),
 * which needs WordPress's block parser and serialiser (covered by the
 * integration suite only).
 */
final class Normalizer {

	private function __construct() {
		// Static-only utility class; never instantiated.
	}

	/**
	 * Recursively sorts associative arrays by key (SORT_STRING) and leaves
	 * list arrays in their original order, so record fields order
	 * deterministically no matter how the caller built them.
	 *
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	public static function sort_recursive( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::sort_recursive( $value );
			}
		}

		if ( ! array_is_list( $data ) ) {
			ksort( $data, SORT_STRING );
		}

		return $data;
	}

	/**
	 * Recursively canonicalises leaf values: strings through
	 * normalize_line_endings(), integral finite floats to int, -0.0 to 0.
	 * Non-finite floats and objects are hard errors — records carry arrays
	 * and scalars only, and NAN/INF cannot be represented portably in JSON.
	 *
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	public static function normalize_scalars( array $data ): array {
		$normalized = array();

		foreach ( $data as $key => $value ) {
			$normalized[ $key ] = self::canonicalise_value( $value, 'record[' . $key . ']' );
		}

		return $normalized;
	}

	/**
	 * The one call every provider uses to build a record's content: scalar
	 * canonicalisation first, then deterministic key order.
	 *
	 * @param array<mixed> $content
	 * @return array<mixed>
	 */
	public static function normalize_content( array $content ): array {
		return self::sort_recursive( self::normalize_scalars( $content ) );
	}

	/**
	 * Canonical JSON for a record: normalized content, encoded with slashes
	 * and unicode left readable, never dependent on WordPress being loaded
	 * (the unit suite covers this method with no WordPress present).
	 *
	 * @param array<mixed> $data
	 */
	public static function canonical_json( array $data ): string {
		$previous = ini_get( 'serialize_precision' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- pinning serialize_precision to -1 is what makes float encoding deterministic; the previous value is restored in the finally below, so the process-wide setting is never left changed.
		ini_set( 'serialize_precision', '-1' );

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- canonicalisation must not depend on WordPress being loaded; the unit suite covers this method with no WordPress present.
			return json_encode(
				self::normalize_content( $data ),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
			);
		} catch ( \JsonException $error ) {
			throw StateException::hard_error( 'The payload could not be canonicalised as JSON: ' . $error->getMessage(), $error );
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- the finally guarantees the process-wide serialize_precision is never left changed by the deterministic-float pin above.
			ini_set( 'serialize_precision', false === $previous ? '-1' : $previous );
		}
	}

	/**
	 * The only thing ever written to a bundle file or to STDOUT: canonical
	 * JSON plus exactly one trailing LF, so a file's final newline never
	 * registers as drift.
	 *
	 * @param array<mixed> $data
	 */
	public static function canonical_json_document( array $data ): string {
		return self::canonical_json( $data ) . "\n";
	}

	/**
	 * Lowercase 64-char SHA-256 hex of a record's canonical JSON. This is
	 * stateHash, the fingerprint every later drift and tamper gate compares.
	 *
	 * @param array<mixed> $data
	 */
	public static function hash( array $data ): string {
		return hash( 'sha256', self::canonical_json( $data ) );
	}

	/**
	 * Lowercase 64-char SHA-256 hex of an already-normalised string. Task 3
	 * hashes normalised block markup directly with it.
	 */
	public static function hash_string( string $value ): string {
		return hash( 'sha256', $value );
	}

	/**
	 * Collapses CR and CRLF to LF, strips trailing spaces and tabs from every
	 * line, then right-trims the whole string. Two copies of the same content
	 * saved with different line endings can never register as drift.
	 */
	public static function normalize_line_endings( string $text ): string {
		$text = (string) preg_replace( '/\r\n|\r/', "\n", $text );
		$text = (string) preg_replace( '/[ \t]+\n/', "\n", $text );

		return rtrim( $text );
	}

	/**
	 * Drops empty leaves and empty branches, so a record that omits an empty
	 * setting and one that stores it as null/''/array() normalise to the same
	 * hash. Zero, zero-point-zero, false, and the string '0' are real values
	 * and always survive.
	 *
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	public static function prune_empty( array $data ): array {
		$pruned = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = self::prune_empty( $value );

				if ( array() === $value ) {
					continue;
				}
			} elseif ( null === $value || '' === $value ) {
				continue;
			} elseif ( is_string( $value ) && '' === trim( $value ) ) {
				continue;
			}

			$pruned[ $key ] = $value;
		}

		return $pruned;
	}

	/**
	 * WordPress-coupled: canonicalises block markup by parsing and
	 * re-serialising it, so cosmetic whitespace, attribute spacing, and line
	 * endings can never register as drift. Applied identically to database
	 * content and to the Git baseline file, so both sides of every comparison
	 * pass through the same serialiser.
	 *
	 * Covered by the integration suite only — parse_blocks()/serialize_blocks()
	 * need a real WordPress install, and this repository deliberately keeps
	 * tests/support/wp-stubs.php from growing into a shadow WordPress.
	 */
	public static function normalize_block_markup( string $markup ): string {
		return self::normalize_line_endings( serialize_blocks( parse_blocks( self::normalize_line_endings( $markup ) ) ) );
	}

	/**
	 * Canonicalises one value, recursing into arrays so the key path in a
	 * hard error names the offending leaf.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function canonicalise_value( $value, string $path ) {
		if ( is_string( $value ) ) {
			return self::normalize_line_endings( $value );
		}

		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				throw StateException::hard_error( 'Non-finite float at ' . $path . ': NAN and INF cannot be canonicalised as JSON.' );
			}

			return (float) (int) $value === $value ? (int) $value : $value;
		}

		if ( is_object( $value ) ) {
			throw StateException::hard_error( 'Object at ' . $path . ': records carry arrays and scalars only.' );
		}

		if ( is_array( $value ) ) {
			$normalized = array();

			foreach ( $value as $key => $child ) {
				$normalized[ $key ] = self::canonicalise_value( $child, $path . '[' . $key . ']' );
			}

			return $normalized;
		}

		return $value;
	}
}
