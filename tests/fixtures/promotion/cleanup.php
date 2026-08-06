<?php
/**
 * E2E fixture for the promotion lifecycle spec (plan Task 20): force-deletes
 * every wp_template_part row named site-header, then deletes every option
 * the promotion lifecycle can have left behind — the per-record lock for
 * the spec's record key, the backup chunks and metadata for every
 * promotion id in the backup index, and the index itself. Everything is
 * reached through get_option()/delete_option() over the index, never a
 * raw query. Prints the promotion ids it cleaned so the spec can also
 * unlink their canonical manifests.
 *
 * Run with: wp eval-file tests/fixtures/promotion/cleanup.php
 *
 * @package Tests\E2e\Fixtures
 */

declare(strict_types=1);

$part_ids = get_posts(
	array(
		'post_type'      => 'wp_template_part',
		'name'           => 'site-header',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $part_ids as $part_id ) {
	wp_delete_post( (int) $part_id, true );
}

// The spec promotes exactly one record key; its lock option name follows
// RecordLockManager::option_name().
delete_option( 'agency_promotion_lock_' . hash( 'sha256', 'template-parts:site-header' ) );

$promotion_ids = array();
$index         = get_option( 'agency_promotion_backups_index' );

if ( is_array( $index ) ) {
	foreach ( $index as $promotion_id => $entry ) {
		$promotion_ids[] = (string) $promotion_id;

		if ( ! is_array( $entry ) || ! isset( $entry['recordKeys'] ) || ! is_array( $entry['recordKeys'] ) ) {
			continue;
		}

		foreach ( $entry['recordKeys'] as $record_key ) {
			$prefix = 'agency_promotion_backup_' . $promotion_id . '_' . hash( 'sha256', (string) $record_key );

			$meta = get_option( $prefix . '_meta' );

			if ( ! is_array( $meta ) ) {
				delete_option( $prefix . '_meta' );

				continue;
			}

			$chunk_count = is_int( $meta['chunks'] ?? null ) ? $meta['chunks'] : 0;

			for ( $chunk = 0; $chunk < $chunk_count; $chunk++ ) {
				delete_option( $prefix . '_c' . str_pad( (string) $chunk, 4, '0', STR_PAD_LEFT ) );
			}

			delete_option( $prefix . '_meta' );
		}
	}

	delete_option( 'agency_promotion_backups_index' );
}

WP_CLI::log( 'cleanup: promotion ids cleaned: ' . ( '' === implode( ' ', $promotion_ids ) ? '(none)' : implode( ' ', $promotion_ids ) ) );
