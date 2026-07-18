<?php

declare(strict_types=1);

namespace SiteCore\Testimonials;

use SiteCore\Contracts\Testimonials;

/**
 * Registers the `testimonial` custom post type and its
 * `testimonial_author` meta field.
 *
 * The post type slug and meta key live on SiteCore\Contracts\Testimonials
 * rather than here, since SiteCore\Contracts must stay free of dependencies
 * on the rest of site-core (see deptrac.yaml's SiteCoreContracts layer) —
 * this class depends on the contract's constants, not the other way round.
 */
final class TestimonialsProvider {

	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
	}

	public function register_post_type(): void {
		register_post_type(
			Testimonials::POST_TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Testimonials', 'site-core' ),
					'singular_name'      => __( 'Testimonial', 'site-core' ),
					'add_new'            => __( 'Add New', 'site-core' ),
					'add_new_item'       => __( 'Add New Testimonial', 'site-core' ),
					'edit_item'          => __( 'Edit Testimonial', 'site-core' ),
					'new_item'           => __( 'New Testimonial', 'site-core' ),
					'view_item'          => __( 'View Testimonial', 'site-core' ),
					'search_items'       => __( 'Search Testimonials', 'site-core' ),
					'not_found'          => __( 'No testimonials found', 'site-core' ),
					'not_found_in_trash' => __( 'No testimonials found in Trash', 'site-core' ),
					'all_items'          => __( 'All Testimonials', 'site-core' ),
					'menu_name'          => __( 'Testimonials', 'site-core' ),
				),
				'public'       => true,
				'has_archive'  => false,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'menu_icon'    => 'dashicons-format-quote',
			)
		);
	}

	public function register_meta(): void {
		register_post_meta(
			Testimonials::POST_TYPE,
			Testimonials::META_AUTHOR,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( $this, 'can_edit_author_meta' ),
			)
		);
	}

	/**
	 * Restricts editing of the `testimonial_author` meta field to users who
	 * can edit posts, rather than relying on register_meta()'s implicit
	 * default capability mapping.
	 */
	public function can_edit_author_meta(): bool {
		return current_user_can( 'edit_posts' );
	}
}
