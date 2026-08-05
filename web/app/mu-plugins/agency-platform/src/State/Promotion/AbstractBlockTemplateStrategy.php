<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\StateRecord;

/**
 * Everything the templates and template-parts strategies share. The two
 * concrete classes supply only the provider slug, the WordPress post type,
 * the theme sub-directory, and the ThemeDeclaredSlugs method they call.
 *
 * The inherited PromotionStrategy::prepare() REFUSES on purpose: the
 * lifecycle stages every record with stage() and owns the all-or-nothing
 * commit/discard decision through PreparedFileWriter::commit_all(). A
 * single-record commit would be a second write path outside the run-level
 * transaction and would silently break that guarantee.
 */
abstract class AbstractBlockTemplateStrategy implements PreparablePromotionStrategy {

	private StateGateway $gateway;

	/**
	 * The expected post-reset hashes of the current prepare attempt, keyed
	 * by canonical record key. stage() records them from the staged entry's
	 * manifest fields; expected_post_reset_hash() reads them back.
	 *
	 * @var array<string, string>
	 */
	private array $expected_hashes = array();

	/**
	 * One UUID per strategy instance, used as the temp-file suffix of every
	 * staged prepared file. The temp name is throwaway — it never appears in
	 * a manifest — so it only has to be unique within the run.
	 */
	private ?string $promotion_id = null;

	public function __construct( ?StateGateway $gateway = null ) {
		$this->gateway = $gateway ?? new StateGateway();
	}

	/** The WordPress post type the provider's rows use (wp_template or wp_template_part). */
	abstract protected function post_type(): string;

	/** The theme sub-directory prepared files land in (templates or parts). */
	abstract protected function theme_subdirectory(): string;

	/**
	 * PromotionStrategy::prepare() is not the promotion entry point; the
	 * lifecycle stages every record with stage() and commits the run through
	 * PreparedFileWriter::commit_all(). Refusing is fail-closed: a
	 * single-record commit would silently break the all-or-nothing guarantee
	 * commit_all() exists to provide.
	 *
	 * @return array{preparedPath: string, preparedHash: string, originalHash: string|null}
	 */
	public function prepare( StateRecord $record, string $target_path ): array {
		throw PromotionException::hard(
			'PromotionStrategy::prepare() is not the promotion entry point; the lifecycle stages '
			. 'every record with stage() and commits the run through PreparedFileWriter::commit_all(). '
			. 'Called for ' . $record->key() . '.'
		);
	}

	/**
	 * The two-phase staging entry point the lifecycle actually uses: stage
	 * the record into a temp file next to its final theme path and return
	 * the entry. Nothing is published here — the run-level writer owns the
	 * all-record commit/discard decision.
	 */
	public function stage( StateRecord $record, string $theme_root ): StagedPromotionEntry {
		$markup = $record->content()['markup'] ?? null;

		if ( ! is_string( $markup ) ) {
			throw PromotionException::hard(
				sprintf( 'Record %s carries no string "markup" content; block templates stage their markup.', $record->key() )
			);
		}

		$entry = ( new PreparedFileWriter( $this->gateway, $theme_root ) )->stage(
			$this->theme_relative_path( $record->slug() ),
			$markup,
			$this->promotion_id()
		);

		$this->expected_hashes[ $record->key() ] = (string) $entry->manifest_fields()['expectedPostResetHash'];

		return $entry;
	}

	/**
	 * The theme-relative path a record's prepared file lands at. The slug is
	 * restricted to plain template-file characters, so nothing can escape
	 * the theme directory.
	 */
	public function theme_relative_path( string $record_slug ): string {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $record_slug ) ) {
			throw PromotionException::hard(
				sprintf( 'The slug "%s" is not a valid template file name; a promotion must never write outside the theme directory.', $record_slug )
			);
		}

		return $this->theme_subdirectory() . '/' . $record_slug . '.html';
	}

	/** 'absent' when finalisation deletes the row; 'present' when it resets one in place. */
	public function post_finalize_record_state(): string {
		return 'absent';
	}

	/** True when expected_post_reset_hash() can only be computed on the target host. */
	public function defers_expected_hash(): bool {
		return false;
	}

	/**
	 * The semantic hash of the state the site resolves to right now, or null
	 * when the record slug resolves to nothing. The gateway strips the
	 * injected theme attribute on this side too, so the result is directly
	 * comparable with expected_post_reset_hash().
	 */
	public function resolve_current_hash( string $record_slug ): ?string {
		$template = get_block_template( get_stylesheet() . '//' . $record_slug, $this->post_type() );

		if ( null === $template ) {
			return null;
		}

		return $this->gateway->hash_markup(
			$this->gateway->normalize_block_markup( (string) $template->content )
		);
	}

	/**
	 * The semantic hash the resolved state must equal after reset() — read
	 * from the staged entry's manifest fields and cached by canonical record
	 * key for the current prepare attempt. Calling it before stage() is a
	 * hard error.
	 */
	public function expected_post_reset_hash( StateRecord $record ): string {
		if ( ! isset( $this->expected_hashes[ $record->key() ] ) ) {
			throw PromotionException::hard(
				sprintf( 'expected_post_reset_hash() was called for %s before stage(); the lifecycle stages every record first.', $record->key() )
			);
		}

		return $this->expected_hashes[ $record->key() ];
	}

	/**
	 * §7.9: the backup payload that can recreate the database row: the raw
	 * post row, the wp_theme term slugs — a row without its term is
	 * invisible to the resolver, so the terms are not optional — and every
	 * meta value.
	 *
	 * @return array<string, mixed>
	 */
	public function capture_backup( StateRecord $record ): array {
		$object_id = $record->object_id();

		if ( null === $object_id ) {
			throw PromotionException::hard(
				sprintf( 'Record %s has no database object id; nothing to back up.', $record->key() )
			);
		}

		return array(
			'post'  => get_post( $object_id, ARRAY_A ),
			'terms' => wp_get_object_terms( $object_id, 'wp_theme', array( 'fields' => 'slugs' ) ),
			'meta'  => get_post_meta( $object_id ),
		);
	}

	/**
	 * §7.8: remove the database override. A falsy delete result — or a row
	 * that still resolves afterwards — throws, so the finalizer refuses
	 * instead of restoring something that was never removed.
	 */
	public function reset( StateRecord $record ): void {
		$object_id = $record->object_id();

		if ( null === $object_id ) {
			throw PromotionException::hard(
				sprintf( 'Record %s has no database object id; nothing to delete.', $record->key() )
			);
		}

		if ( ! wp_delete_post( $object_id, true ) || null !== get_post( $object_id ) ) {
			throw PromotionException::hard(
				sprintf( 'Could not delete the database override for %s.', $record->key() )
			);
		}
	}

	/**
	 * §7.9: recreate the database override from the backup payload captured
	 * by capture_backup(). The restored row is a NEW row — the old one was
	 * deleted by reset() — so the backup's ID is dropped, and the wp_theme
	 * terms and every meta value are re-attached.
	 *
	 * @param array<string, mixed> $backup
	 */
	public function restore( StateRecord $record, array $backup ): void {
		$post = isset( $backup['post'] ) && is_array( $backup['post'] ) ? $backup['post'] : null;

		if ( null === $post ) {
			throw PromotionException::hard(
				sprintf( 'Cannot restore %s: its backup carries no post row.', $record->key() )
			);
		}

		unset( $post['ID'], $post['meta_input'] );

		$new_id = wp_insert_post( $post, true );

		if ( is_wp_error( $new_id ) ) {
			throw PromotionException::hard(
				sprintf( 'Could not restore %s: %s', $record->key(), $new_id->get_error_message() )
			);
		}

		$terms = isset( $backup['terms'] ) && is_array( $backup['terms'] ) ? $backup['terms'] : array();

		if ( array() !== $terms ) {
			wp_set_object_terms( $new_id, $terms, 'wp_theme' );
		}

		$meta = isset( $backup['meta'] ) && is_array( $backup['meta'] ) ? $backup['meta'] : array();

		foreach ( $meta as $meta_key => $values ) {
			if ( ! is_string( $meta_key ) || ! is_array( $values ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				if ( is_scalar( $value ) || null === $value ) {
					update_post_meta( $new_id, $meta_key, $value );
				}
			}
		}
	}

	/**
	 * The three §7.5 markup checks, as per-record refusals: the markup must
	 * round-trip through the WordPress parser, every block must be
	 * registered, and every referenced template part must be present in the
	 * bundle or among the selected keys. Reference refusals beyond these
	 * come from the reference track and are added by the preparer.
	 *
	 * @param array<string, mixed> $bundle_record
	 * @param list<string>         $selected_keys
	 * @return list<RecordRefusal>
	 */
	public function validate_for_promotion( array $bundle_record, BundleView $bundle, array $selected_keys ): array {
		$refusals   = array();
		$record_key = isset( $bundle_record['key'] ) && is_string( $bundle_record['key'] ) ? $bundle_record['key'] : '';
		$provider   = isset( $bundle_record['provider'] ) && is_string( $bundle_record['provider'] ) ? $bundle_record['provider'] : '';
		$slug       = isset( $bundle_record['slug'] ) && is_string( $bundle_record['slug'] ) ? $bundle_record['slug'] : '';
		$content    = isset( $bundle_record['content'] ) && is_array( $bundle_record['content'] ) ? $bundle_record['content'] : array();
		$markup     = isset( $content['markup'] ) && is_string( $content['markup'] ) ? $content['markup'] : '';

		// Normalisation is a TRANSFORM, not an identity: it strips the injected
		// theme attribute and ksorts the remaining ones. Comparing its output
		// to the RAW input therefore refuses any record whose attributes are
		// not already in sorted order — which is every template the shipped
		// theme contains, including templates/page.html, templates/index.html
		// and parts/site-header.html. That made the whole theme unpromotable.
		//
		// The property actually worth checking is that normalisation is STABLE:
		// markup the parser cannot represent losslessly keeps changing on a
		// second pass, and that is what "does not round-trip" means here.
		$normalized = $this->gateway->normalize_block_markup( $markup );

		if ( $this->gateway->normalize_block_markup( $normalized ) !== $normalized ) {
			$refusals[] = new RecordRefusal(
				$record_key,
				$provider,
				$slug,
				'unparseable-markup',
				'The block markup does not round-trip through the WordPress parser: normalising it a second time produces different markup.'
			);
		}

		foreach ( parse_blocks( $markup ) as $block ) {
			$block_name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';

			if ( '' === $block_name ) {
				continue;
			}

			if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
				$refusals[] = new RecordRefusal(
					$record_key,
					$provider,
					$slug,
					'unregistered-block',
					sprintf( 'The block "%s" is not registered on the target site.', $block_name ),
					$block_name
				);
			}

			if ( 'core/template-part' === $block_name ) {
				$attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$part_slug  = isset( $attributes['slug'] ) && is_string( $attributes['slug'] ) ? $attributes['slug'] : '';

				if ( '' !== $part_slug ) {
					$part_key = 'template-parts:' . $part_slug;

					if ( null === $bundle->record( $part_key ) && ! in_array( $part_key, $selected_keys, true ) ) {
						$refusals[] = new RecordRefusal(
							$record_key,
							$provider,
							$slug,
							'missing-template-part',
							sprintf( 'The template references template part "%s", which is neither in the bundle nor selected.', $part_slug ),
							$block_name,
							'slug',
							$part_slug
						);
					}
				}
			}
		}

		return $refusals;
	}

	/**
	 * One UUID per strategy instance for the temp-file suffix of staged
	 * prepared files. Generated lazily so constructing a strategy never
	 * touches WordPress.
	 */
	private function promotion_id(): string {
		if ( null === $this->promotion_id ) {
			$this->promotion_id = wp_generate_uuid4();
		}

		return $this->promotion_id;
	}
}
