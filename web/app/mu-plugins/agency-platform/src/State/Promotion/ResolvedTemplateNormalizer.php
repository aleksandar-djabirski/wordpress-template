<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The resolved-template seam of the promotion lifecycle (plan §7.4):
 * WordPress injects a "theme" attribute into every core/template-part block
 * when it builds a template from a file, and the v1 policy strips the "ref"
 * from every core/navigation block — without both, a promoted file and its
 * resolved form would never hash the same and every finalize would
 * self-restore. Both methods are pure string rewriting over the block-comment
 * syntax WordPress's own serialiser emits, so they run without WordPress and
 * are unit-tested.
 *
 * The attribute JSON is decoded, the target key is removed, the remaining
 * keys are sorted, and the comment is re-emitted with
 * JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE — deterministic bytes on
 * every host. Markup is left byte-for-byte unchanged when the attributes do
 * not decode as JSON, when the matched block is not the target of the call,
 * or when the comment carries no attributes at all.
 */
final class ResolvedTemplateNormalizer {

	private const BLOCK_OPEN_PATTERN = '/<!--\s+wp:(template-part|navigation)\s*(\{.*?\})?\s*(\/)?-->/s';

	private function __construct() {
		// Static-only utility class; never instantiated.
	}

	/** Removes the `theme` attribute WordPress injects into file-backed templates. */
	public static function strip_theme_attribute( string $markup ): string {
		return self::rewrite( $markup, 'template-part', 'theme' );
	}

	/** Removes `ref` from every core/navigation block (the §7.4 v1 policy). */
	public static function strip_navigation_refs( string $markup ): string {
		return self::rewrite( $markup, 'navigation', 'ref' );
	}

	/**
	 * Rewrites every matching block-comment delimiter: strips $target_key
	 * from the attribute object and re-emits the delimiter canonically.
	 *
	 * The self-closing marker of the ORIGINAL comment is preserved in EVERY
	 * branch, including the one where no attribute is left. Collapsing a paired
	 * OPENER to `<!-- wp:name /-->` because its attributes ran out would orphan
	 * the matching `<!-- /wp:name -->` and strand every inner block outside its
	 * parent. That is the COMMON case here, not an edge case: most
	 * core/navigation blocks carry `ref` and nothing else, and `ref` is exactly
	 * what the v1 navigation policy removes.
	 */
	private static function rewrite( string $markup, string $block_name, string $target_key ): string {
		return (string) preg_replace_callback(
			self::BLOCK_OPEN_PATTERN,
			static function ( array $matches ) use ( $block_name, $target_key ): string {
				if ( $block_name !== $matches[1] ) {
					return $matches[0];
				}

				if ( ! isset( $matches[2] ) || '' === $matches[2] ) {
					return $matches[0];
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- block-attribute JSON must decode without WordPress; this class is unit-tested with no WordPress present.
				$attributes = json_decode( $matches[2], true );

				if ( ! is_array( $attributes ) ) {
					return $matches[0];
				}

				unset( $attributes[ $target_key ] );

				$closing = isset( $matches[3] ) && '/' === $matches[3] ? ' /-->' : ' -->';

				if ( array() === $attributes ) {
					return '<!-- wp:' . $matches[1] . $closing;
				}

				ksort( $attributes, SORT_STRING );

				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- block-attribute JSON must re-encode without WordPress; this class is unit-tested with no WordPress present.
				$encoded = json_encode( $attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

				if ( false === $encoded ) {
					return $matches[0];
				}

				return '<!-- wp:' . $matches[1] . ' ' . $encoded . $closing;
			},
			$markup
		);
	}
}
