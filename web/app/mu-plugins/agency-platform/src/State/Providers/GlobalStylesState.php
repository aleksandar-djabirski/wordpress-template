<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Providers;

use AgencyPlatform\State\BaseStateProvider;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\ReferenceScanner;
use AgencyPlatform\State\StateRecord;

/**
 * The §7.1 provider for Global Styles (BLOCK_THEME_PROPOSAL.md §6): slug
 * `global-styles`, exactly one record `global-styles:active` — the user
 * origin of the active theme, so §6's `global-styles:active` selector
 * resolves. Global Styles are git-baseline-plus-db-user-origin state (the
 * empty user origin is the baseline; anything the customer saves is an
 * override) and promotable.
 *
 * The wp_theme tax_query uses field => slug for the same reason every other
 * provider does — the default term_id lookup would silently match nothing.
 * WP_Theme_JSON_Resolver is deliberately NOT used: it is an internal Core
 * API and belongs behind Task 3's adapter (spec §7.6).
 *
 * Content normalisation is deliberately ordered (plan decision 5), and
 * getting any step wrong makes a normal, uncustomised site report permanent
 * drift:
 *   1. decode the JSON (a missing/blank/non-array result is array());
 *   2. strip the bookkeeping keys `version` and `isGlobalStylesUserThemeJSON`
 *      — WordPress's own markers, present on every row including a
 *      brand-new one, and neither expresses user intent;
 *   3. strip every `css` key at every depth — Additional CSS is the
 *      custom-css provider's record, and leaving it here would classify the
 *      same bytes as both promotable and forbidden;
 *   4. prune_empty() then normalize_content().
 * An uncustomised row therefore normalises to array(), exactly matching the
 * empty baseline, while a customised row reads as changed -> drift ->
 * promotable.
 */
final class GlobalStylesState extends BaseStateProvider {

	public function slug(): string {
		return 'global-styles';
	}

	public function ownership(): string {
		return Ownership::GIT_BASELINE_PLUS_DB_USER_ORIGIN;
	}

	public function promotion(): string {
		return PromotionPolicy::PROMOTABLE;
	}

	public function includes_content(): bool {
		return false;
	}

	public function has_git_baseline(): bool {
		return true;
	}

	/**
	 * The active theme's wp_global_styles row as the single record, slug
	 * `active` regardless of the row's post_name.
	 *
	 * @return list<StateRecord>
	 */
	public function records(): array {
		$records = array();

		foreach ( $this->global_styles_posts() as $post ) {
			$decoded    = $this->decode_content( $post->post_content );
			$decoded    = $this->strip_css_keys( $decoded );
			$record_key = $this->record_key( 'active' );
			$references = array();

			$this->collect_font_file_references( $decoded, $record_key, $references );

			$records[] = StateRecord::create(
				$this->slug(),
				'active',
				(int) $post->ID,
				$post->post_status,
				$post->post_modified_gmt,
				Normalizer::normalize_content( Normalizer::prune_empty( $decoded ) ),
				$references,
				$this->ownership(),
				$this->promotion()
			);
		}

		return $records;
	}

	/**
	 * The Git side of the global-styles story is the empty user origin: one
	 * synthetic record, slug `active`, content array(). This is why an
	 * uncustomised row matches its baseline exactly while a customised row
	 * reads as changed -> drift -> promotable, reproducing today's
	 * DatabaseOverrideCheck semantics through the generic differ.
	 *
	 * @return list<StateRecord>
	 */
	public function baseline_records(): array {
		return array(
			StateRecord::create(
				$this->slug(),
				'active',
				null,
				'baseline',
				null,
				array(),
				array(),
				$this->ownership(),
				$this->promotion()
			),
		);
	}

	/**
	 * §7.1 "Validation behaviour": a non-blank post_content that did not
	 * decode to an array is not valid Global Styles JSON and must never be
	 * promoted. Baseline records have no database row behind them, so they
	 * are always valid.
	 *
	 * @return list<string>
	 */
	public function validate( StateRecord $record ): array {
		if ( null === $record->object_id() ) {
			return array();
		}

		$post = get_post( $record->object_id() );

		if ( null === $post || ! is_string( $post->post_content ) ) {
			return array();
		}

		if ( '' !== trim( $post->post_content ) && ! is_array( json_decode( $post->post_content, true ) ) ) {
			return array( 'Global Styles content is not valid JSON.' );
		}

		return array();
	}

	/**
	 * The wp_global_styles rows of the active theme. The wp_theme tax_query
	 * is the whole point: without field => slug the stylesheet string
	 * matches nothing and zero rows come back.
	 *
	 * @return array<int, \WP_Post>
	 */
	private function global_styles_posts(): array {
		return get_posts(
			array(
				'post_type'      => 'wp_global_styles',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'tax_query'      => array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'slug',
						'terms'    => get_stylesheet(),
					),
				),
			)
		);
	}

	/**
	 * The first normalisation step: decode, with a missing/blank/non-array
	 * result yielding array() so a malformed row can never crash the export.
	 *
	 * @return array<string, mixed>
	 */
	private function decode_content( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		unset( $decoded['version'], $decoded['isGlobalStylesUserThemeJSON'] );

		return $decoded;
	}

	/**
	 * The second normalisation step: every `css` key at every depth, so
	 * Additional CSS never appears in both the global-styles record and the
	 * custom-css provider's record.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function strip_css_keys( array $data ): array {
		$stripped = array();

		foreach ( $data as $key => $value ) {
			if ( 'css' === $key ) {
				continue;
			}

			$stripped[ $key ] = is_array( $value ) ? $this->strip_css_keys( $value ) : $value;
		}

		return $stripped;
	}

	/**
	 * The reference walk for Global Styles: scan_parsed() is not meaningful
	 * here (there are no blocks), so every string value matching the
	 * font-path matcher — the same matcher ReferenceScanner applies to block
	 * attributes: a known font extension, or a path through a /fonts/
	 * directory — emits one KIND_FONT_FILE reference. The reference carries
	 * the same eleven keys in the same order ReferenceScanner emits, with
	 * the same policy text, so resolver and refusal report cannot tell the
	 * two sources apart.
	 *
	 * @param array<string, mixed>       $data
	 * @param list<array<string, mixed>> $found
	 */
	private function collect_font_file_references( array $data, string $record_key, array &$found, string $path = '' ): void {
		foreach ( $data as $key => $value ) {
			$current_path = '' === $path ? (string) $key : $path . '.' . (string) $key;

			if ( is_array( $value ) ) {
				$this->collect_font_file_references( $value, $record_key, $found, $current_path );

				continue;
			}

			if ( is_string( $value ) && $this->is_font_path( $value ) ) {
				$found[] = array(
					'record'         => $record_key,
					'provider'       => $this->slug(),
					'blockName'      => '',
					'attribute'      => $current_path,
					'value'          => $value,
					'kind'           => ReferenceScanner::KIND_FONT_FILE,
					'resolution'     => ReferenceScanner::RESOLUTION_ENVIRONMENT,
					'policy'         => 'Font Library records and files stay database/filesystem-owned in v1. Promotion of a record that references a font file is refused (BLOCK_THEME_PROPOSAL.md §5.4).',
					'targetKey'      => null,
					'targetHash'     => null,
					'targetIdentity' => null,
				);
			}
		}
	}

	/**
	 * The font-path matcher, kept in lockstep with ReferenceScanner's: a
	 * known font extension, or a path that passes through a /fonts/
	 * directory.
	 */
	private function is_font_path( string $value ): bool {
		foreach ( array( '.woff', '.woff2', '.ttf', '.otf' ) as $extension ) {
			if ( str_ends_with( $value, $extension ) ) {
				return true;
			}
		}

		return str_contains( $value, '/fonts/' );
	}
}
