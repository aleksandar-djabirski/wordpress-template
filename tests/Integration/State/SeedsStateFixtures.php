<?php
/**
 * Shared fixture seeders for the state provider integration tests
 * (BLOCK_THEME_PROPOSAL.md §7.1): each make_*() creates one database row of
 * the right post type with the right post_name and attaches the active
 * theme's wp_theme term — the term assignment is what makes the provider's
 * tax_query find the row, since every provider filters on field => slug.
 *
 * Tasks 9, 11, and 13 reuse these same fixtures, so this trait deliberately
 * lives in tests/Integration/State/ — never under tests/support/, which
 * Task 1 also edits.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

trait SeedsStateFixtures {

	protected function make_template( string $slug, string $markup, string $status = 'publish' ): int {
		return $this->entry( 'wp_template', $slug, $markup, $status );
	}

	protected function make_part( string $slug, string $markup, string $status = 'publish' ): int {
		return $this->entry( 'wp_template_part', $slug, $markup, $status );
	}

	protected function make_navigation( string $slug, string $markup, string $status = 'publish' ): int {
		return $this->entry( 'wp_navigation', $slug, $markup, $status );
	}

	protected function make_global_styles( string $json, string $status = 'publish' ): int {
		return $this->entry( 'wp_global_styles', get_stylesheet(), $json, $status );
	}

	/**
	 * The one low-level creator every make_*() delegates to: creates the
	 * post, then attaches the active theme's wp_theme term for the three
	 * post types the providers read through that taxonomy.
	 */
	protected function entry( string $post_type, string $post_name, string $content, string $status = 'publish' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'    => $post_type,
				'post_name'    => $post_name,
				'post_content' => $content,
				'post_status'  => $status,
			)
		);

		if ( in_array( $post_type, array( 'wp_template', 'wp_template_part', 'wp_global_styles' ), true ) ) {
			wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );
		}

		return $id;
	}
}
