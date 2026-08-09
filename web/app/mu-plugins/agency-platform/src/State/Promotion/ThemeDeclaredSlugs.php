<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The declared-slug guard of the promotion lifecycle: prepare must refuse
 * to run when a selected record names a template or template part the
 * theme does not declare. WordPress core hierarchy slugs are implicitly
 * declared by every theme; any other template needs an entry in the
 * theme's customTemplates, and every template part needs an entry in its
 * templateParts. The bundle carries the theme.json document as parsed by
 * Task 2, so from_file() exists for the prepare step that reads the live
 * theme.json from disk instead.
 */
final class ThemeDeclaredSlugs {

	/** Built-in hierarchy slugs never need a customTemplates entry. */
	public const CORE_TEMPLATE_SLUGS = array(
		'404',
		'archive',
		'index',
		'page',
		'search',
		'single',
		'home',
		'front-page',
		'singular',
		'attachment',
		'author',
		'category',
		'date',
		'tag',
		'taxonomy',
		'privacy-policy',
	);

	/** @param array<string,mixed> $theme_json */
	public function __construct( private array $theme_json ) {}

	public static function from_file( string $theme_json_path ): self {
		// is_readable() keeps the failure path warning-free: file_get_contents()
		// would emit a PHP warning on a missing file BEFORE returning false.
		if ( ! is_readable( $theme_json_path ) ) {
			throw PromotionException::hard( sprintf( 'The theme.json file could not be read: "%s".', $theme_json_path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local on-disk artifact, never a URL.
		$raw = file_get_contents( $theme_json_path );

		if ( false === $raw ) {
			throw PromotionException::hard( sprintf( 'The theme.json file could not be read: "%s".', $theme_json_path ) );
		}

		$decoded = json_decode( $raw, true );

		// A valid JSON document whose root is not an object (an array, a
		// scalar) decodes as an array or scalar and must be refused just like
		// broken JSON: a guard that accepts a list root would silently declare
		// nothing, and a guard must fail closed, never open.
		if ( ! is_array( $decoded ) || ! str_starts_with( ltrim( $raw ), '{' ) ) {
			throw PromotionException::hard( sprintf( 'The theme.json file "%s" is not a valid JSON object.', $theme_json_path ) );
		}

		return new self( $decoded );
	}

	public function declares_template( string $record_slug ): bool {
		return in_array( $record_slug, self::CORE_TEMPLATE_SLUGS, true )
			|| in_array( $record_slug, $this->declared_names( 'customTemplates' ), true );
	}

	public function declares_template_part( string $record_slug ): bool {
		return in_array( $record_slug, $this->declared_names( 'templateParts' ), true );
	}

	/**
	 * The declared names of one theme.json list: every entry that carries a
	 * string "name" key. Entries without a name are malformed theme.json
	 * and declare nothing — a guard must fail closed, never open.
	 *
	 * @return list<string>
	 */
	private function declared_names( string $key ): array {
		if ( ! isset( $this->theme_json[ $key ] ) || ! is_array( $this->theme_json[ $key ] ) ) {
			return array();
		}

		$names = array();

		foreach ( $this->theme_json[ $key ] as $entry ) {
			if ( is_array( $entry ) && isset( $entry['name'] ) && is_string( $entry['name'] ) ) {
				$names[] = $entry['name'];
			}
		}

		return $names;
	}
}
