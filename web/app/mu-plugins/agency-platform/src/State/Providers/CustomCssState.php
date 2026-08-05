<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Providers;

use AgencyPlatform\State\BaseStateProvider;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\StateRecord;

/**
 * The §7.1 provider for Additional CSS (BLOCK_THEME_PROPOSAL.md §6): slug
 * `custom-css`, exactly two records — `custom-css:global-styles` and
 * `custom-css:custom-css-post` — covering both §11.10 locations. Both
 * records are ALWAYS emitted, with css => '' when absent, so the record
 * set is deterministic (plan decision 6).
 *
 * Custom CSS is FORBIDDEN state: it is detected, diffed, and refused —
 * never promoted. The one provider with a Git baseline that is not a file
 * set: Git owns the theme's stylesheets and client roles cannot author
 * CSS, so "no Additional CSS" IS the baseline. The generic drift rule then
 * needs no special case — empty CSS is unchanged, any non-empty CSS is
 * changed -> drift -> classified forbidden by the REFUSE policy.
 *
 * The css value goes through Normalizer::normalize_content() like every
 * other string leaf, so a CRLF in a stylesheet is not drift (decision 22).
 */
final class CustomCssState extends BaseStateProvider {

	public function slug(): string {
		return 'custom-css';
	}

	public function ownership(): string {
		return Ownership::FORBIDDEN;
	}

	public function promotion(): string {
		return PromotionPolicy::REFUSE;
	}

	public function includes_content(): bool {
		return false;
	}

	public function has_git_baseline(): bool {
		return true;
	}

	/**
	 * Both §11.10 records, always emitted, sorted by record key ascending.
	 *
	 * @return list<StateRecord>
	 */
	public function records(): array {
		$records = array(
			$this->record_with_css( 'global-styles', $this->global_styles_css(), 'publish' ),
			$this->record_with_css( 'custom-css-post', $this->custom_css_post_css(), 'publish' ),
		);

		usort( $records, array( $this, 'compare_by_key' ) );

		return $records;
	}

	/**
	 * The Git side of the custom-css story: the same two record keys with
	 * empty CSS — the "no Additional CSS" baseline — sorted by record key
	 * ascending exactly like records().
	 *
	 * @return list<StateRecord>
	 */
	public function baseline_records(): array {
		$records = array(
			$this->record_with_css( 'global-styles', '', 'baseline' ),
			$this->record_with_css( 'custom-css-post', '', 'baseline' ),
		);

		usort( $records, array( $this, 'compare_by_key' ) );

		return $records;
	}

	/**
	 * One §11.10 record: source, css, and the normalised css length — the
	 * length is computed AFTER line-ending normalisation so a CRLF-only
	 * edit never registers as drift.
	 */
	private function record_with_css( string $source, string $css, string $status ): StateRecord {
		$normalized_css = Normalizer::normalize_line_endings( $css );
		$content        = Normalizer::normalize_content(
			array(
				'source' => $source,
				'css'    => $normalized_css,
				'length' => strlen( $normalized_css ),
			)
		);
		$key            = $this->record_key( $source );

		return StateRecord::create(
			$this->slug(),
			$source,
			null,
			$status,
			null,
			$content,
			$this->detect_references( $content, $key ),
			$this->ownership(),
			$this->promotion()
		);
	}

	/**
	 * The active theme's custom CSS: every non-empty string value under a
	 * css key at any depth of the wp_global_styles row, joined with LF.
	 * WordPress nests Additional CSS under styles.css and under
	 * styles.blocks.<block>.css, so the search must be recursive.
	 */
	private function global_styles_css(): string {
		$css = array();

		foreach ( $this->global_styles_posts() as $post ) {
			$this->collect_css_values( $this->decode_global_styles( $post->post_content ), $css );
		}

		return implode( "\n", $css );
	}

	/**
	 * The recursive css-key walk.
	 *
	 * @param array<string, mixed> $data
	 * @param list<string>         $found
	 */
	private function collect_css_values( array $data, array &$found ): void {
		foreach ( $data as $key => $value ) {
			if ( 'css' === $key ) {
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$found[] = $value;
				}

				continue;
			}

			if ( is_array( $value ) ) {
				$this->collect_css_values( $value, $found );
			}
		}
	}

	/**
	 * Decode the Global Styles JSON; anything non-array is array().
	 *
	 * @return array<string, mixed>
	 */
	private function decode_global_styles( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * The Customizer's Additional CSS for the active theme, via the public
	 * API — never a raw query. '' when no custom_css post exists.
	 */
	private function custom_css_post_css(): string {
		$post = wp_get_custom_css_post();

		if ( null === $post ) {
			return '';
		}

		return $post->post_content;
	}

	/**
	 * The active theme's wp_global_styles rows, via the same field => slug
	 * tax_query every other theme provider uses.
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

	public function compare_by_key( StateRecord $a, StateRecord $b ): int {
		return strcmp( $a->key(), $b->key() );
	}
}
