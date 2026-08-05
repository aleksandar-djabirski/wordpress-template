<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Providers;

use AgencyPlatform\State\BaseStateProvider;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\StateRecord;

/**
 * The §7.1 provider for pages, posts, and every other public content type
 * (BLOCK_THEME_PROPOSAL.md §6): slug `content`, records
 * `content:<post_type>-<ID>`. The only provider gated behind
 * --include-content, and never promoted (spec §7.3).
 *
 * §7.3's sensitive-data rule is a hard invariant of the exported shape:
 * a password is exported only as the hasPassword boolean, an author only
 * as the authorId integer — never post_password, never an email address,
 * never a session or application token. No other user-identifying field
 * exists in the exported shape.
 *
 * References come from the base detect_references() default, and record()
 * honours $with_references = false so ReferenceResolver can re-read a
 * target without restarting the scan cycle.
 */
final class ContentState extends BaseStateProvider {

	public function slug(): string {
		return 'content';
	}

	public function ownership(): string {
		return Ownership::DATABASE;
	}

	public function promotion(): string {
		return PromotionPolicy::NEVER_PROMOTE;
	}

	public function includes_content(): bool {
		return true;
	}

	public function has_git_baseline(): bool {
		return false;
	}

	/** Database-owned: no Git counterpart exists. @return list<StateRecord> */
	public function baseline_records(): array {
		return array();
	}

	/**
	 * Every public content row in the exportable statuses, sorted by record
	 * key ascending so exports are deterministic.
	 *
	 * @return list<StateRecord>
	 */
	public function records(): array {
		$records = array();

		foreach ( $this->content_post_types() as $post_type ) {
			foreach ( $this->posts_for_type( $post_type ) as $post ) {
				$records[] = $this->record_from_post( $post, true );
			}
		}

		usort( $records, array( $this, 'compare_by_key' ) );

		return $records;
	}

	/**
	 * Live single-record re-read by key, so --select=content:page-12 can be
	 * served without exporting the whole provider. The record slug is
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

		if ( null === $post || $matches[1] !== $post->post_type || ! $this->is_exportable( $post ) ) {
			return null;
		}

		return $this->record_from_post( $post, $with_references );
	}

	/**
	 * Every public post type except attachments — attachments belong to
	 * MediaReferencesState, never to content.
	 *
	 * @return list<string>
	 */
	private function content_post_types(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( 'attachment' !== $post_type ) {
				$types[] = $post_type;
			}
		}

		return $types;
	}

	/**
	 * The rows of one content post type in the exportable statuses.
	 *
	 * @return array<int, \WP_Post>
	 */
	private function posts_for_type( string $post_type ): array {
		return get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
			)
		);
	}

	private function is_exportable( \WP_Post $post ): bool {
		return in_array( $post->post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true );
	}

	/**
	 * One database row as a record. The §7.3 shape: identifying fields are
	 * reduced to authorId (an integer) and hasPassword (a boolean); the
	 * password, email, and any session or token data never enter the
	 * content.
	 */
	private function record_from_post( \WP_Post $post, bool $with_references ): StateRecord {
		$slug    = $post->post_type . '-' . $post->ID;
		$content = Normalizer::normalize_content(
			array(
				'postType'     => $post->post_type,
				'title'        => $post->post_title,
				'status'       => $post->post_status,
				'slug'         => $post->post_name,
				'parent'       => (int) $post->post_parent,
				'menuOrder'    => (int) $post->menu_order,
				'pageTemplate' => get_page_template_slug( $post ),
				'authorId'     => (int) $post->post_author,
				'hasPassword'  => '' !== $post->post_password,
				'markup'       => Normalizer::normalize_block_markup( $post->post_content ),
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
