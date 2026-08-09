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
 * The §7.1 provider for referenced media (BLOCK_THEME_PROPOSAL.md §6):
 * slug `media-references`, records `media-references:attachment-<ID>`, one
 * per attachment the site's editable markup actually references.
 *
 * The source set is FIXED — the active theme's templates and template
 * parts, every navigation and synced pattern, plus the site_logo option —
 * so this provider is independent of --include-content and of registry
 * ordering (plan decision 15). Metadata is read with the public APIs
 * (get_post_mime_type(), wp_get_attachment_metadata()); the file bytes are
 * never read, never hashed (spec §7.3).
 */
final class MediaReferencesState extends BaseStateProvider {

	public function slug(): string {
		return 'media-references';
	}

	public function ownership(): string {
		return Ownership::DATABASE_PLUS_UPLOADS;
	}

	public function promotion(): string {
		return PromotionPolicy::NEVER_PROMOTE;
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
	 * The media records, sorted by record key ascending so exports are
	 * deterministic. Deleted or non-attachment IDs are skipped.
	 *
	 * @return list<StateRecord>
	 */
	public function records(): array {
		$records = array();

		foreach ( $this->referenced_attachment_ids() as $attachment_id ) {
			$record = $this->record_for_attachment( $attachment_id );

			if ( null !== $record ) {
				$records[] = $record;
			}
		}

		usort( $records, array( $this, 'compare_by_key' ) );

		return $records;
	}

	/**
	 * The attachment IDs the fixed source set references, de-duplicated and
	 * sorted numerically so the record set is deterministic. The site-logo
	 * block carries no id in the markup, so the option is read separately —
	 * it is part of the fixed source set.
	 *
	 * @return list<int>
	 */
	private function referenced_attachment_ids(): array {
		$ids = array();

		foreach ( $this->source_posts() as $post ) {
			foreach ( $this->attachment_ids_in_markup( $post->post_content ) as $id ) {
				$ids[] = $id;
			}
		}

		$logo = get_option( 'site_logo' );

		if ( is_numeric( $logo ) && (int) $logo > 0 ) {
			$ids[] = (int) $logo;
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * The source posts: the active theme's templates and template parts
	 * (the same field => slug tax_query every other theme provider uses),
	 * plus every navigation and synced pattern.
	 *
	 * @return array<int, \WP_Post>
	 */
	private function source_posts(): array {
		$theme_posts = get_posts(
			array(
				'post_type'      => array( 'wp_template', 'wp_template_part' ),
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
			)
		);

		$database_posts = get_posts(
			array(
				'post_type'      => array( 'wp_navigation', 'wp_block' ),
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
			)
		);

		return array_merge( $theme_posts, $database_posts );
	}

	/**
	 * The attachment ids one post's markup references, via the shared
	 * scanner's attachment and gallery matchers — read without resolution,
	 * because this provider only needs the values and must stay independent
	 * of the registry.
	 *
	 * @return list<int>
	 */
	private function attachment_ids_in_markup( string $markup ): array {
		$ids = array();

		foreach ( ReferenceScanner::scan_parsed( parse_blocks( $markup ), 'media-references:source' ) as $reference ) {
			if ( ReferenceScanner::KIND_ATTACHMENT === $reference['kind'] || ReferenceScanner::KIND_GALLERY === $reference['kind'] ) {
				$value = $reference['value'];

				if ( is_int( $value ) || is_string( $value ) ) {
					$ids[] = (int) $value;
				}
			}
		}

		return $ids;
	}

	/**
	 * One attachment's record: title and metadata, never the file bytes.
	 * Deleted or non-attachment IDs yield null, so the record set can never
	 * carry a dangling media record.
	 */
	private function record_for_attachment( int $attachment_id ): ?StateRecord {
		$post = get_post( $attachment_id );

		if ( null === $post || 'attachment' !== $post->post_type ) {
			return null;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		$content = Normalizer::normalize_content(
			array(
				'mimeType'     => (string) get_post_mime_type( $attachment_id ),
				'relativePath' => is_array( $metadata ) && isset( $metadata['file'] ) ? (string) $metadata['file'] : '',
				'width'        => is_array( $metadata ) && isset( $metadata['width'] ) ? (int) $metadata['width'] : 0,
				'height'       => is_array( $metadata ) && isset( $metadata['height'] ) ? (int) $metadata['height'] : 0,
				'title'        => $post->post_title,
			)
		);
		$key     = $this->record_key( 'attachment-' . $attachment_id );

		return StateRecord::create(
			$this->slug(),
			'attachment-' . $attachment_id,
			$attachment_id,
			$post->post_status,
			$post->post_modified_gmt,
			$content,
			$this->detect_references( $content, $key ),
			$this->ownership(),
			$this->promotion()
		);
	}

	public function compare_by_key( StateRecord $a, StateRecord $b ): int {
		return strcmp( $a->key(), $b->key() );
	}
}
