<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\Normalizer;

/**
 * The resolved-form normaliser of the promotion lifecycle (plan decision 8
 * and the §7.4 v1 policy). WordPress injects a `theme` attribute into
 * file-backed core/template-part blocks when it builds a template from a
 * file, and a resolved template carries the environment-owned navigation
 * `ref`; without stripping both, a promoted file and its resolved form never
 * hash the same and every finalize would self-restore. Both methods are
 * WordPress-free by design — the unit suite covers them with no live
 * install — so attribute objects are decoded and re-encoded directly.
 *
 * Attributes are decoded as strict JSON first, then as the legacy unquoted
 * short-hand form (keys and string values without quotes) that older
 * serializers emit; the emitted form mirrors the input form, so a round-trip
 * changes nothing except the stripped key. Markup is left byte-identical
 * when the attribute object does not decode, and both the self-closing and
 * the paired (`<!-- wp:navigation ... --> ... <!-- /wp:navigation -->`)
 * forms are preserved.
 */
final class ResolvedTemplateNormalizer {

	private const BLOCK_PATTERN = '/<!--\s+wp:(template-part|navigation)\s*(\{.*?\})?\s*(\/)?-->/s';

	private const FORM_JSON       = 'json';
	private const FORM_SHORT_HAND = 'short-hand';

	private function __construct() {
		// Static-only utility class; never instantiated.
	}

	/**
	 * Removes the `theme` attribute WordPress injects into file-backed
	 * templates, from every core/template-part block.
	 */
	public static function strip_theme_attribute( string $markup ): string {
		return self::strip_attribute( $markup, 'template-part', 'theme' );
	}

	/**
	 * Removes `ref` from every core/navigation block — the §7.4 v1 policy:
	 * navigation stays database-owned, so a resolved template must carry no
	 * ref into the file.
	 */
	public static function strip_navigation_refs( string $markup ): string {
		return self::strip_attribute( $markup, 'navigation', 'ref' );
	}

	/**
	 * Walks every block-comment token and rewrites the ones of the target
	 * block type. Block comments that fail to match — other block types,
	 * closing tags, undecodable attributes — pass through byte-identical.
	 */
	private static function strip_attribute( string $markup, string $block_name, string $attribute ): string {
		return (string) preg_replace_callback(
			self::BLOCK_PATTERN,
			static function ( array $comment ) use ( $block_name, $attribute ): string {
				if ( ( $comment[1] ?? '' ) !== $block_name ) {
					return $comment[0];
				}

				$attrs_raw = $comment[2] ?? '';

				if ( '' === $attrs_raw ) {
					return $comment[0];
				}

				$decoded = self::decode_attributes( $attrs_raw );

				if ( null === $decoded ) {
					return $comment[0];
				}

				unset( $decoded['data'][ $attribute ] );

				$void = ( $comment[3] ?? '' ) === '/';

				if ( array() === $decoded['data'] ) {
					return '<!-- wp:' . $block_name . ( $void ? ' /-->' : ' -->' );
				}

				$encoded = self::encode_attributes( Normalizer::sort_recursive( $decoded['data'] ), $decoded['form'] );

				if ( null === $encoded ) {
					return $comment[0];
				}

				return '<!-- wp:' . $block_name . ' ' . $encoded . ( $void ? ' /-->' : ' -->' );
			},
			$markup
		);
	}

	/**
	 * Decodes a block-attribute object, remembering which form it came in so
	 * the re-encoded output mirrors it.
	 *
	 * @return array{form: string, data: array<string, mixed>}|null
	 */
	private static function decode_attributes( string $attrs ): ?array {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- the resolved-form normaliser must not depend on WordPress being loaded; the unit suite covers it with no WordPress present.
			$decoded = json_decode( $attrs, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			$decoded = null;
		}

		if ( is_array( $decoded ) ) {
			return array(
				'form' => self::FORM_JSON,
				'data' => $decoded,
			);
		}

		$decoded = self::decode_short_hand( $attrs );

		if ( null === $decoded ) {
			return null;
		}

		return array(
			'form' => self::FORM_SHORT_HAND,
			'data' => $decoded,
		);
	}

	/**
	 * The legacy unquoted attribute form `{key:value,key:value}` that older
	 * serializers emit: keys and string values carry no quotes, numbers stay
	 * bare, and values may nest. Returns null when the string is not a
	 * well-formed short-hand object, so the caller leaves the markup
	 * unchanged.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function decode_short_hand( string $attrs ): ?array {
		if ( ! str_starts_with( $attrs, '{' ) || ! str_ends_with( $attrs, '}' ) ) {
			return null;
		}

		$result = array();

		foreach ( self::split_top_level( substr( $attrs, 1, -1 ), ',' ) as $pair ) {
			if ( '' === $pair ) {
				return null;
			}

			$parts = self::split_top_level( $pair, ':' );

			if ( 2 !== count( $parts ) ) {
				return null;
			}

			$key   = trim( $parts[0] );
			$value = trim( $parts[1] );

			if ( '' === $key || 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $key ) ) {
				return null;
			}

			if ( '' === $value ) {
				return null;
			}

			if ( str_starts_with( $value, '{' ) ) {
				$decoded_value = self::decode_short_hand( $value );
			} elseif ( 1 === preg_match( '/^-?\d+$/', $value ) ) {
				$decoded_value = (int) $value;
			} elseif ( is_numeric( $value ) ) {
				$decoded_value = (float) $value;
			} else {
				$decoded_value = $value;
			}

			if ( null === $decoded_value ) {
				return null;
			}

			$result[ $key ] = $decoded_value;
		}

		return $result;
	}

	/**
	 * Re-encodes a decoded attribute object in the form it arrived in: full
	 * JSON for JSON input, the quote-less short-hand form for short-hand
	 * input. Returns null when the payload cannot be encoded, so the caller
	 * leaves the markup unchanged.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function encode_attributes( array $data, string $form ): ?string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the resolved-form normaliser must not depend on WordPress being loaded; the unit suite covers it with no WordPress present.
			$encoded = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			return null;
		}

		if ( self::FORM_SHORT_HAND === $form ) {
			return str_replace( array( '"', '\\' ), '', $encoded );
		}

		return $encoded;
	}

	/**
	 * Splits a string on a delimiter character that sits outside every
	 * nested brace level, so `layout:{type:flex}` survives a top-level
	 * comma or colon split intact.
	 *
	 * @return list<string>
	 */
	private static function split_top_level( string $subject, string $delimiter ): array {
		$parts   = array();
		$depth   = 0;
		$current = '';
		$length  = strlen( $subject );

		for ( $index = 0; $index < $length; $index++ ) {
			$char = $subject[ $index ];

			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;
			}

			if ( $delimiter === $char && 0 === $depth ) {
				$parts[] = $current;
				$current = '';
				continue;
			}

			$current .= $char;
		}

		$parts[] = $current;

		return $parts;
	}
}
