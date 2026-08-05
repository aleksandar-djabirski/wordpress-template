<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Providers;

use AgencyPlatform\State\BaseStateProvider;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\StateRecord;

/**
 * The §7.1 provider for navigation menus (BLOCK_THEME_PROPOSAL.md §6): slug
 * `navigation`, records `navigation:<post_name>` — with `navigation:nav-<ID>`
 * as the fallback when the row has no post_name. Navigation is
 * database-owned (menus only exist in the database, never in Git) and
 * export-and-diff in v1: its records are exported and diffed, and its
 * references carry the target hash the §7.4 navigation policy judges at
 * finalisation.
 *
 * Content is the normalised block markup plus the menu title, so two menus
 * that differ only in cosmetics never register as drift. References come
 * from the base detect_references() default — the shared ReferenceScanner
 * — and record() honours $with_references = false so ReferenceResolver can
 * re-read a target without restarting the scan cycle.
 */
final class NavigationState extends BaseStateProvider {

	public function slug(): string {
		return 'navigation';
	}

	public function ownership(): string {
		return Ownership::DATABASE;
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
	 * The live wp_navigation rows (publish and draft), sorted by record key
	 * ascending so exports are deterministic.
	 *
	 * @return list<StateRecord>
	 */
	public function records(): array {
		$records = array();

		foreach ( $this->posts_for_name( null ) as $post ) {
			$records[] = $this->record_from_post( $post, true );
		}

		usort( $records, array( $this, 'compare_by_key' ) );

		return $records;
	}

	/**
	 * Live single-record re-read by key, so --select=navigation:primary can
	 * be served without exporting the whole provider. The key is split and
	 * the query re-run filtered to that record slug; the fallback slug form
	 * navigation:nav-<ID> names no row, so it is resolved through the post
	 * ID.
	 */
	public function record( string $key, bool $with_references = true ): ?StateRecord {
		$parts = explode( ':', $key, 2 );

		if ( 2 !== count( $parts ) || $this->slug() !== $parts[0] || '' === $parts[1] ) {
			return null;
		}

		$post = $this->post_for_slug( $parts[1] );

		if ( null === $post ) {
			return null;
		}

		return $this->record_from_post( $post, $with_references );
	}

	/**
	 * The wp_navigation rows, optionally narrowed to one post_name.
	 *
	 * @return array<int, \WP_Post>
	 */
	private function posts_for_name( ?string $post_name ): array {
		$args = array(
			'post_type'      => 'wp_navigation',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'orderby'        => 'ID',
		);

		if ( null !== $post_name ) {
			$args['name'] = $post_name;
		}

		return get_posts( $args );
	}

	/**
	 * Resolve a record slug to its post: the regular form is the post_name,
	 * the fallback form nav-<ID> is looked up by ID.
	 */
	private function post_for_slug( string $slug ): ?\WP_Post {
		$posts = $this->posts_for_name( $slug );

		if ( array() !== $posts ) {
			return $posts[0];
		}

		if ( 1 === preg_match( '/^nav-(\d+)$/', $slug, $matches ) ) {
			$post = get_post( (int) $matches[1] );

			if ( null !== $post && 'wp_navigation' === $post->post_type ) {
				return $post;
			}
		}

		return null;
	}

	/**
	 * One database row as a record: slug from post_name (or nav-<ID>),
	 * content the normalised markup plus the title, references from the
	 * shared scanner unless $with_references suppresses them — the
	 * recursion guard ReferenceResolver relies on.
	 */
	private function record_from_post( \WP_Post $post, bool $with_references ): StateRecord {
		$slug    = '' !== $post->post_name ? $post->post_name : 'nav-' . $post->ID;
		$content = Normalizer::normalize_content(
			array(
				'markup' => Normalizer::normalize_block_markup( $post->post_content ),
				'title'  => $post->post_title,
			)
		);
		$key     = $this->record_key( $slug );

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
