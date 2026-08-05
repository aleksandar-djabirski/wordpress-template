<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Providers;

use AgencyPlatform\State\BaseStateProvider;
use AgencyPlatform\State\GitBaseline;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\ReferenceScanner;
use AgencyPlatform\State\StateRecord;

/**
 * The §7.1 provider for theme template parts (BLOCK_THEME_PROPOSAL.md §6):
 * slug `template-parts`, records `template-parts:<part-slug>`. Template
 * parts are Git baseline-plus-database state — the theme's parts/*.html
 * files ship in Git, the database may override them — and they are
 * promotable, so a live edit that drifts from the baseline surfaces as
 * promotable drift.
 *
 * Identical to TemplatesState but for post type wp_template_part and the
 * Git baseline from the theme's parts/ directory. The same rules apply:
 * the wp_theme tax_query uses field => slug (the default term_id lookup
 * would silently match nothing), content is the block markup only
 * (plan decision 3), and the reference scanner is the shared one.
 */
final class TemplatePartsState extends BaseStateProvider {

	public function slug(): string {
		return 'template-parts';
	}

	public function ownership(): string {
		return Ownership::GIT_BASELINE_PLUS_DB;
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
	 * The live wp_template_part rows of the active theme, sorted by record
	 * key ascending so exports are deterministic. Rows with an empty
	 * post_name are skipped — a record slug must be non-empty by
	 * StateRecord contract.
	 *
	 * @return list<StateRecord>
	 */
	public function records(): array {
		$records = array();

		foreach ( $this->posts_for_name( null ) as $post ) {
			if ( '' === $post->post_name ) {
				continue;
			}

			$records[] = $this->record_from_post( $post );
		}

		usort( $records, array( $this, 'compare_by_key' ) );

		return $records;
	}

	/**
	 * The Git side: one record per parts/*.html file of the active theme,
	 * normalised through the same serialiser the database side uses.
	 * object_id is null, status is 'baseline', and modified_gmt is null —
	 * a file has no modification timestamp worth comparing.
	 *
	 * @return list<StateRecord>
	 */
	public function baseline_records(): array {
		$records = array();

		foreach ( ( new GitBaseline() )->part_markup() as $slug => $markup ) {
			$records[] = StateRecord::create(
				$this->slug(),
				$slug,
				null,
				'baseline',
				null,
				Normalizer::normalize_content( array( 'markup' => $markup ) ),
				array(),
				$this->ownership(),
				$this->promotion()
			);
		}

		return $records;
	}

	/**
	 * Live single-record re-read by key, so --select=template-parts:header
	 * can be served without exporting the whole provider. The key is split,
	 * the query re-run filtered to that post_name; null when no such row
	 * exists in the active theme.
	 */
	public function record( string $key, bool $with_references = true ): ?StateRecord {
		$parts = explode( ':', $key, 2 );

		if ( 2 !== count( $parts ) || $this->slug() !== $parts[0] || '' === $parts[1] ) {
			return null;
		}

		foreach ( $this->posts_for_name( $parts[1] ) as $post ) {
			return $this->record_from_post( $post );
		}

		return null;
	}

	/**
	 * §7.1 "Validation behaviour": a template part's markup must round-trip
	 * through the WordPress parser. If re-normalising the already-normalised
	 * markup changes it, the parser cannot reproduce the record and the
	 * record must not be promoted.
	 *
	 * @return list<string>
	 */
	public function validate( StateRecord $record ): array {
		$content = $record->content();
		$markup  = isset( $content['markup'] ) && is_string( $content['markup'] ) ? $content['markup'] : '';

		if ( Normalizer::normalize_block_markup( $markup ) !== $markup ) {
			return array( 'Block markup does not round-trip through the WordPress parser.' );
		}

		return array();
	}

	/**
	 * The wp_template_part rows of the active theme, optionally narrowed to
	 * one post_name. The wp_theme tax_query is the whole point: without
	 * field => slug the stylesheet string matches nothing and zero rows come
	 * back.
	 *
	 * @return array<int, \WP_Post>
	 */
	private function posts_for_name( ?string $post_name ): array {
		$args = array(
			'post_type'      => 'wp_template_part',
			'post_status'    => array( 'publish', 'draft', 'private' ),
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
		);

		if ( null !== $post_name ) {
			$args['name'] = $post_name;
		}

		return get_posts( $args );
	}

	/**
	 * One database row as a record: slug from post_name, content the
	 * normalised block markup only, references from the shared scanner.
	 */
	private function record_from_post( \WP_Post $post ): StateRecord {
		$key = $this->record_key( $post->post_name );

		return StateRecord::create(
			$this->slug(),
			$post->post_name,
			(int) $post->ID,
			$post->post_status,
			$post->post_modified_gmt,
			Normalizer::normalize_content( array( 'markup' => Normalizer::normalize_block_markup( $post->post_content ) ) ),
			ReferenceScanner::scan( $post->post_content, $key ),
			$this->ownership(),
			$this->promotion()
		);
	}

	public function compare_by_key( StateRecord $a, StateRecord $b ): int {
		return strcmp( $a->key(), $b->key() );
	}
}
