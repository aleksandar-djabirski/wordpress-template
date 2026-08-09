<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Providers;

use AgencyPlatform\State\BaseStateProvider;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\StateRecord;

/**
 * The §7.1 provider for the Font Library (BLOCK_THEME_PROPOSAL.md §6):
 * slug `fonts`, records `fonts:wp_font_family-<ID>` and
 * `fonts:wp_font_face-<ID>`. Font Library records are database-plus-uploads
 * state and export-and-diff in v1.
 *
 * Content is the post type, the title, and the decoded settings JSON the
 * Font Library stores in post_content — decoded so a settings change that
 * only reorders JSON keys never registers as drift.
 */
final class FontLibraryState extends BaseStateProvider {

	public function slug(): string {
		return 'fonts';
	}

	public function ownership(): string {
		return Ownership::DATABASE_PLUS_UPLOADS;
	}

	public function promotion(): string {
		return PromotionPolicy::EXPORT_AND_DIFF;
	}

	public function includes_content(): bool {
		return false;
	}

	public function has_git_baseline(): bool {
		return false;
	}

	/** Database-owned: no Git counterpart exists. @return list<StateRecord> */
	public function baseline_records(): array {
		return array();
	}

	/**
	 * The live wp_font_family and wp_font_face rows (publish only), sorted
	 * by record key ascending so exports are deterministic.
	 *
	 * @return list<StateRecord>
	 */
	public function records(): array {
		$records = array();

		foreach ( $this->font_posts() as $post ) {
			$records[] = $this->record_from_post( $post, true );
		}

		usort( $records, array( $this, 'compare_by_key' ) );

		return $records;
	}

	/**
	 * Live single-record re-read by key, so a selector can be served
	 * without exporting the whole provider. The record slug is
	 * <post_type>-<ID>; the key is split on the last -<ID> boundary.
	 */
	public function record( string $key, bool $with_references = true ): ?StateRecord {
		$parts = explode( ':', $key, 2 );

		if ( 2 !== count( $parts ) || $this->slug() !== $parts[0] || '' === $parts[1] ) {
			return null;
		}

		if ( 1 !== preg_match( '/^(.+)-(\d+)$/', $parts[1], $matches ) ) {
			return null;
		}

		$post = get_post( (int) $matches[2] );

		if ( null === $post || $matches[1] !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		return $this->record_from_post( $post, $with_references );
	}

	/**
	 * The Font Library's two post types in one query.
	 *
	 * @return array<int, \WP_Post>
	 */
	private function font_posts(): array {
		return get_posts(
			array(
				'post_type'      => array( 'wp_font_family', 'wp_font_face' ),
				'post_status'    => array( 'publish' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
			)
		);
	}

	/**
	 * One database row as a record: content the post type, the title, and
	 * the decoded settings, so the export carries the same values the Font
	 * Library API serves, not its storage format.
	 */
	private function record_from_post( \WP_Post $post, bool $with_references ): StateRecord {
		$decoded  = json_decode( $post->post_content, true );
		$settings = is_array( $decoded ) ? $decoded : array();
		$slug     = $post->post_type . '-' . $post->ID;
		$content  = Normalizer::normalize_content(
			array(
				'postType' => $post->post_type,
				'title'    => $post->post_title,
				'settings' => $settings,
			)
		);
		$key      = $this->record_key( $slug );

		return StateRecord::create(
			$this->slug(),
			$slug,
			(int) $post->ID,
			$post->post_status,
			$post->post_modified_gmt,
			$content,
			$with_references ? $this->detect_references( $content, $key ) : array(),
			$this->ownership(),
			$this->promotion()
		);
	}

	public function compare_by_key( StateRecord $a, StateRecord $b ): int {
		return strcmp( $a->key(), $b->key() );
	}
}
