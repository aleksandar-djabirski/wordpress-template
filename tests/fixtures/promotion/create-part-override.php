<?php
/**
 * E2E fixture for the promotion lifecycle spec (plan Task 20): inserts a
 * wp_template_part override named site-header whose markup renders the
 * literal marker PROMOTION E2E OVERRIDE, and attaches the active theme's
 * wp_theme term — without that term the override is invisible to the
 * template resolver. Idempotent: a second run updates the existing row
 * instead of inserting a new one.
 *
 * Run with: wp eval-file tests/fixtures/promotion/create-part-override.php
 *
 * @package Tests\E2e\Fixtures
 */

// NOTE: no declare(strict_types=1) here, deliberately. wp eval-file wraps
// this file in eval(), where a declare() is not the first statement in the
// script. CI proved it:
//   Fatal error: strict_types declaration must be the very first statement
//   in the script ... EvalFile_Command.php(85) : eval()d code on line 17

$markup = "<!-- wp:paragraph -->\n<p>PROMOTION E2E OVERRIDE</p>\n<!-- /wp:paragraph -->";

$existing = get_posts(
	array(
		'post_type'      => 'wp_template_part',
		'name'           => 'site-header',
		'post_status'    => 'any',
		'posts_per_page' => 1,
	)
);

if ( isset( $existing[0] ) ) {
	$part_id = wp_update_post(
		array(
			'ID'           => (int) $existing[0]->ID,
			'post_status'  => 'publish',
			'post_content' => $markup,
		),
		true
	);
} else {
	$part_id = wp_insert_post(
		array(
			'post_type'    => 'wp_template_part',
			'post_name'    => 'site-header',
			'post_status'  => 'publish',
			'post_content' => $markup,
		),
		true
	);
}

if ( is_wp_error( $part_id ) ) {
	WP_CLI::error( sprintf( 'create-part-override: %s', $part_id->get_error_message() ) );
}

wp_set_object_terms( $part_id, get_stylesheet(), 'wp_theme' );

WP_CLI::log( sprintf( 'create-part-override: wp_template_part %d is the site-header override.', $part_id ) );
