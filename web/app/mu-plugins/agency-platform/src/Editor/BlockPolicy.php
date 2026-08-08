<?php

declare(strict_types=1);

namespace AgencyPlatform\Editor;

/**
 * The client editing policy, expressed as pure functions.
 *
 * The editor allow-list is DERIVED from the blocks actually registered on the
 * site, filtered to approved namespaces — not a hand-maintained list that goes
 * stale the moment a core release ships a new block. That matters for a block
 * theme: the Site Editor needs core's template, query, navigation and post
 * blocks, and a fixed list would quietly break it.
 *
 * The allow-list is a UI convenience, NOT a security boundary. The same policy
 * is re-applied server side on save by AgencyPlatform\Editor\SaveValidation,
 * because a direct REST request can post whatever markup it likes.
 */
final class BlockPolicy {

	/**
	 * @var string[]
	 */
	public const DEFAULT_NAMESPACES = array( 'core', 'agency', 'woocommerce' );

	/**
	 * Never insertable and never savable by a client, whatever any filter
	 * says. `core/html` and `core/shortcode` are arbitrary-markup escape
	 * hatches; `core/freeform` (the Classic block) stores raw HTML directly in
	 * post content, which is the same hole through a different door.
	 *
	 * `woocommerce` is listed in DEFAULT_NAMESPACES unconditionally: when
	 * WooCommerce is absent no woocommerce/* block is registered, so the
	 * resolved set is identical, and gating on it would put a WooCommerce
	 * symbol inside agency-platform for no benefit.
	 *
	 * @var string[]
	 */
	public const ALWAYS_DENIED = array( 'core/html', 'core/shortcode', 'core/freeform' );

	/**
	 * @param bool|string[] $incoming               Whatever WordPress/other filters passed in.
	 * @param string[]      $registered_block_names Every currently registered block name.
	 * @param string[]      $allowed_namespaces     Namespace prefixes without the slash.
	 * @param string[]      $extra_allowed          Explicitly allowed block names.
	 * @param string[]      $extra_denied           Explicitly denied block names.
	 * @return bool|string[]
	 */
	public static function resolve(
		bool $is_privileged,
		$incoming,
		array $registered_block_names,
		array $allowed_namespaces,
		array $extra_allowed,
		array $extra_denied
	) {
		if ( $is_privileged ) {
			return $incoming;
		}

		// An incoming `false` means another filter has already decided this
		// context gets NO blocks at all. Widening that to an allow-list would
		// be a privilege escalation dressed up as a policy, so `false` is
		// preserved untouched. Only `true` (no restriction yet) and an array
		// (a narrower list to intersect with) are ours to act on.
		if ( false === $incoming ) {
			return false;
		}

		$allowed = array();

		foreach ( array_merge( $registered_block_names, $extra_allowed ) as $block_name ) {
			if ( in_array( $block_name, $extra_allowed, true ) ) {
				$allowed[] = $block_name;
				continue;
			}

			if ( in_array( self::namespace_of( $block_name ), $allowed_namespaces, true ) ) {
				$allowed[] = $block_name;
			}
		}

		$allowed = array_diff( $allowed, $extra_denied, self::ALWAYS_DENIED );

		if ( is_array( $incoming ) ) {
			$allowed = array_intersect( $allowed, $incoming );
		}

		$allowed = array_values( array_unique( $allowed ) );
		sort( $allowed );

		return $allowed;
	}

	/**
	 * @param array<int, array<string, mixed>> $parsed_blocks      Output of parse_blocks().
	 * @param string[]                         $allowed_block_names
	 * @return list<array{block: string, path: string}>
	 */
	public static function forbidden_blocks( array $parsed_blocks, array $allowed_block_names ): array {
		$violations = array();

		foreach ( $parsed_blocks as $block ) {
			$name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';

			if ( '' === $name ) {
				// Handled by raw_html_violations(); a null-name block is never
				// a "forbidden block", it is raw markup.
				continue;
			}

			if ( ! in_array( $name, $allowed_block_names, true ) ) {
				$violations[] = array(
					'block' => $name,
					'path'  => $name,
				);
			}

			foreach ( self::forbidden_blocks( (array) ( $block['innerBlocks'] ?? array() ), $allowed_block_names ) as $nested ) {
				$violations[] = array(
					'block' => $nested['block'],
					'path'  => $name . ' > ' . $nested['path'],
				);
			}
		}

		return $violations;
	}

	/**
	 * @param array<int, array<string, mixed>> $parsed_blocks
	 * @return list<string>
	 */
	public static function raw_html_violations( array $parsed_blocks ): array {
		$violations = array();

		foreach ( $parsed_blocks as $block ) {
			$name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
			$html = (string) ( $block['innerHTML'] ?? '' );

			if ( '' === $name && '' !== trim( $html ) ) {
				$violations[] = trim( $html );
			} elseif ( '' !== $name && self::has_disallowed_markup( $html ) ) {
				$violations[] = trim( $html );
			}

			foreach ( self::raw_html_violations( (array) ( $block['innerBlocks'] ?? array() ) ) as $nested ) {
				$violations[] = $nested;
			}
		}

		return $violations;
	}

	private static function has_disallowed_markup( string $html ): bool {
		return 1 === preg_match(
			'/<\s*(?:script|style|iframe|object|embed)\b|<[^>]+\s+(?:style|on[a-z][\w-]*)\s*=|<[^>]+\s+(?:href|src)\s*=\s*["\']\s*javascript:/i',
			$html
		);
	}

	/**
	 * Per-block custom CSS lives in the block instance's `style.css` attribute
	 * (WordPress 7.0's customCSS block support). Core strips it on save for
	 * users without `edit_css`; this detects it earlier so the save is
	 * REJECTED with a clear error instead of silently losing content.
	 *
	 * @param array<int, array<string, mixed>> $parsed_blocks
	 * @return list<array{block: string, css: string}>
	 */
	public static function block_css_violations( array $parsed_blocks ): array {
		$violations = array();

		foreach ( $parsed_blocks as $block ) {
			$css = $block['attrs']['style']['css'] ?? null;

			if ( is_string( $css ) && '' !== trim( $css ) ) {
				$violations[] = array(
					'block' => is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '(unnamed)',
					'css'   => trim( $css ),
				);
			}

			foreach ( self::block_css_violations( (array) ( $block['innerBlocks'] ?? array() ) ) as $nested ) {
				$violations[] = $nested;
			}
		}

		return $violations;
	}

	/**
	 * Shortcodes run on the WHOLE post content, so a registered tag typed into
	 * a Paragraph block executes without any core/shortcode block being
	 * involved. $shortcode_regex must come from get_shortcode_regex(), which
	 * only matches REGISTERED tags — ordinary bracket text is never a match,
	 * and `[[tag]]` is the documented escape form (capture group 1 is the
	 * leading `[ `).
	 *
	 * @return list<string>
	 */
	public static function shortcode_violations( string $content, string $shortcode_regex ): array {
		if ( '' === $shortcode_regex ) {
			return array();
		}

		$matches = array();
		$found   = preg_match_all( '/' . $shortcode_regex . '/', $content, $matches );

		if ( ! is_int( $found ) || 0 === $found ) {
			return array();
		}

		$violations = array();

		foreach ( (array) ( $matches[2] ?? array() ) as $index => $tag ) {
			if ( '[' === ( $matches[1][ $index ] ?? '' ) ) {
				continue;
			}

			$violations[] = (string) $tag;
		}

		return array_values( array_unique( $violations ) );
	}

	/**
	 * Finds custom CSS anywhere in a Global Styles `styles` tree.
	 *
	 * WordPress stores Global Styles CSS in four places inside one payload:
	 * the root (`styles.css`), per block type (`styles.blocks.<name>.css`),
	 * per element (`styles.elements.<name>.css`) and inside style variations
	 * (`styles.variations.<name>.…css`). Core sanitises them away for users
	 * without `edit_css` (class-wp-theme-json.php:3718), but silently — so a
	 * client's CSS vanishes with no explanation. This finds every occurrence
	 * so the write can be REFUSED with a message instead.
	 *
	 * Returns dotted paths, e.g. `styles.css`, `styles.blocks.core/group.css`.
	 *
	 * @param array<string, mixed> $styles The `styles` node of a global-styles payload.
	 * @return list<string>
	 */
	public static function global_styles_css_violations( array $styles, string $path = 'styles' ): array {
		$violations = array();

		foreach ( $styles as $key => $value ) {
			$child = $path . '.' . (string) $key;

			if ( 'css' === $key ) {
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$violations[] = $child;
				}

				continue;
			}

			if ( is_array( $value ) ) {
				foreach ( self::global_styles_css_violations( $value, $child ) as $nested ) {
					$violations[] = $nested;
				}
			}
		}

		return $violations;
	}

	private static function namespace_of( string $block_name ): string {
		$separator = strpos( $block_name, '/' );

		return false === $separator ? '' : substr( $block_name, 0, $separator );
	}
}
