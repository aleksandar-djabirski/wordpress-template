<?php

declare(strict_types=1);

namespace AgencyPlatform\Health;

/**
 * Detects database records that shadow what Git already owns.
 *
 * This project's templates/template-parts/styles are authored as code
 * (theme.json, block templates on disk); WordPress can still write
 * competing copies into the database the moment someone customizes a
 * template or global style in the Site Editor. Those DB rows silently win
 * over the code on disk, which is exactly what this check surfaces.
 */
final class DatabaseOverrideCheck {

	/**
	 * Pure classification: given post-like rows, decide which are
	 * Git-shadowing overrides, which are WordPress's own expected
	 * housekeeping records, and which are informational-only.
	 *
	 * - `wp_template` / `wp_template_part` rows with `post_status` of
	 *   `publish` are overrides: Git owns templates, so any published DB
	 *   copy is shadowing code on disk. Non-published rows (drafts, trash)
	 *   aren't live, so they're ignored.
	 * - `wp_global_styles` rows are expected (WordPress auto-creates one
	 *   per theme), UNLESS their `post_content` (when provided) contains a
	 *   non-empty "css" key anywhere in the structure, or any other key
	 *   beyond the default `{"version":N,"isGlobalStylesUserThemeJSON":true}`
	 *   shape — that indicates a real user customization, so it becomes an
	 *   override.
	 * - `wp_block` rows (synced patterns) are reported separately as
	 *   `synced_patterns` — informational, not a pass/fail signal.
	 * - Anything else is ignored entirely.
	 *
	 * @param array<int, array<string, mixed>> $records Rows shaped
	 *                                                   ['post_type' => ..., 'post_name' => ..., 'post_status' => ..., 'post_content'? => ...].
	 * @return array{overrides: array<int, array<string, mixed>>, expected: array<int, array<string, mixed>>, synced_patterns: array<int, array<string, mixed>>}
	 */
	public static function classify( array $records ): array {
		$result = array(
			'overrides'       => array(),
			'expected'        => array(),
			'synced_patterns' => array(),
		);

		foreach ( $records as $record ) {
			$post_type = $record['post_type'] ?? null;

			switch ( $post_type ) {
				case 'wp_template':
				case 'wp_template_part':
					if ( 'publish' === ( $record['post_status'] ?? null ) ) {
						$result['overrides'][] = $record;
					}
					break;

				case 'wp_global_styles':
					if ( self::global_styles_is_customized( $record ) ) {
						$result['overrides'][] = $record;
					} else {
						$result['expected'][] = $record;
					}
					break;

				case 'wp_block':
					$result['synced_patterns'][] = $record;
					break;

				default:
					// Not a type this check cares about.
					break;
			}
		}

		return $result;
	}

	/**
	 * Runs the live check against the current database.
	 *
	 * @return array{overrides: array<int, array<string, mixed>>, expected: array<int, array<string, mixed>>, synced_patterns: array<int, array<string, mixed>>}
	 */
	public function run(): array {
		$posts = get_posts(
			array(
				'post_type'      => array( 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_block' ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$records = array();

		foreach ( $posts as $post ) {
			$records[] = array(
				'post_type'    => $post->post_type,
				'post_name'    => $post->post_name,
				'post_status'  => $post->post_status,
				'post_content' => $post->post_content,
			);
		}

		return self::classify( $records );
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private static function global_styles_is_customized( array $record ): bool {
		if ( ! array_key_exists( 'post_content', $record ) ) {
			return false;
		}

		$post_content = $record['post_content'];

		if ( ! is_string( $post_content ) || '' === trim( $post_content ) ) {
			return false;
		}

		$decoded = json_decode( $post_content, true );

		if ( ! is_array( $decoded ) ) {
			// Malformed/non-JSON content is unexpected for this post type;
			// treat conservatively as a customization so it surfaces for
			// human review rather than being silently swallowed.
			return true;
		}

		if ( self::has_non_empty_css_key( $decoded ) ) {
			return true;
		}

		$allowed_keys = array( 'version', 'isGlobalStylesUserThemeJSON' );

		foreach ( $decoded as $key => $value ) {
			if ( in_array( $key, $allowed_keys, true ) ) {
				continue;
			}

			if ( self::is_empty_value( $value ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Recursively looks for a non-empty "css" key anywhere in the decoded
	 * global-styles content (WordPress nests custom CSS under
	 * `styles.css`, not at the top level).
	 *
	 * @param array<mixed, mixed> $data
	 */
	private static function has_non_empty_css_key( array $data ): bool {
		foreach ( $data as $key => $value ) {
			if ( 'css' === $key && ! self::is_empty_value( $value ) ) {
				return true;
			}

			if ( is_array( $value ) && self::has_non_empty_css_key( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A value counts as "empty" if it's an empty/blank string, null, an
	 * empty array, OR a non-empty array whose leaves are ALL themselves
	 * empty (recursively) — e.g. `['typography' => ['fontSize' => '']]`
	 * carries no real customization even though the array isn't literally
	 * `[]`. Mirrors the depth-agnostic traversal in has_non_empty_css_key().
	 */
	private static function is_empty_value( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return '' === trim( $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! self::is_empty_value( $item ) ) {
					return false;
				}
			}

			return true;
		}

		return null === $value;
	}
}
