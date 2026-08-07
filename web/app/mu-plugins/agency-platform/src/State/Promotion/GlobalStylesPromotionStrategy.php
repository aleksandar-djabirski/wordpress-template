<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\StateRecord;

/**
 * The Global Styles promotion strategy (master spec §7.6, plan Task 23):
 * the Release 4 gate that promotes the user origin of the active theme's
 * Global Styles into theme.json. It implements the PreparablePromotionStrategy
 * contract like the templates and template-parts strategies — prepare()
 * REFUSES and stage() does the work, because the lifecycle stages every
 * record through StagedPromotionEntry and owns the all-or-nothing commit —
 * and additionally implements DeferredHashPromotionStrategy: Global Styles
 * cannot know its expected post-reset hash at prepare time, because the
 * fully resolved settings/styles depend on the TARGET host (the bundle
 * exports the user origin, never a resolved snapshot). The expectation is
 * therefore captured on the target immediately before the reset and the
 * post-reset resolved output must equal it, or the finalizer restores the
 * row from the backup and refuses with resolved-output-drift.
 *
 * stage() reads the exported user origin ONLY from $record->content() —
 * never from the adapter's user_origin(), which is the LOCAL origin and
 * would leak a different site's customisation into the promoted file. The
 * exported origin carries no version key (the provider strips it), so the
 * merge supplies WP_Theme_JSON::LATEST_SCHEMA itself.
 *
 * The equivalence hashes are computed over the adapter's canonical
 * resolved view — WP_Theme_JSON_Resolver::get_merged_data()->get_data()
 * split into settings and styles — with a recursive key sort. No preset
 * flattening is needed there: the canonical view is already the flattened,
 * origin-free shape, so a user preset that promotion legitimately MOVES
 * from the custom origin to the theme origin resolves identically on both
 * sides of the reset.
 */
final class GlobalStylesPromotionStrategy implements DeferredHashPromotionStrategy {

	public function __construct(
		private ThemeJsonAdapter $adapter,
		private StateGateway $gateway
	) {}

	public function provider_slug(): string {
		return 'global-styles';
	}

	/**
	 * The theme-relative path the prepared theme.json lands at. The slug is
	 * gated fail-closed: only the single Global Styles record promotes this
	 * file, and the path never interpolates the slug.
	 */
	public function theme_relative_path( string $record_slug ): string {
		if ( 'active' !== $record_slug ) {
			throw PromotionException::hard(
				sprintf( 'The slug "%s" is not the Global Styles record; only "active" promotes theme.json.', $record_slug )
			);
		}

		return 'theme.json';
	}

	/** The one Global Styles record is the active theme's user origin. */
	public function declares( ThemeDeclaredSlugs $declared, string $record_slug ): bool {
		return 'active' === $record_slug;
	}

	/** WordPress always keeps exactly one Global Styles post per theme; the reset updates it, never deletes it. */
	public function post_finalize_record_state(): string {
		return 'present';
	}

	/**
	 * True: the expected post-reset hash is computed on the target host by
	 * capture_pre_reset_state(), so expectedPostResetHash stays null in the
	 * manifest and the finalizer's equivalence branch is used instead.
	 */
	public function defers_expected_hash(): bool {
		return true;
	}

	/**
	 * PromotionStrategy::prepare() is not the promotion entry point; the
	 * lifecycle stages every record with stage() and commits the run through
	 * the staged entry. Refusing is fail-closed, exactly like the template
	 * strategies.
	 *
	 * @return array{preparedPath: string, preparedHash: string, originalHash: string|null}
	 */
	public function prepare( StateRecord $record, string $target_path ): array {
		throw PromotionException::hard(
			'PromotionStrategy::prepare() is not the promotion entry point; the lifecycle stages '
			. 'every record with stage(). Called for ' . $record->key() . '.'
		);
	}

	/**
	 * The two-phase staging entry point: read the theme origin from the
	 * on-disk theme.json of $theme_root, merge the EXPORTED user origin
	 * from $record->content() through the adapter (never the local database
	 * origin), validate the merged document, and stage it through the
	 * canonical JSON writer — the ONLY writer Global Styles uses. theme.json
	 * is not block markup, so it never passes through PreparedFileWriter or
	 * any block-markup normaliser.
	 */
	public function stage( StateRecord $record, string $theme_root ): StagedPromotionEntry {
		$theme_json = $this->read_theme_origin( $theme_root );

		$merged = $this->adapter->merge_user_into_theme( $theme_json, $record->content() );

		$this->adapter->validate_theme_json( $merged );

		return ( new CanonicalJsonFileWriter( $theme_root ) )->stage(
			'theme.json',
			$merged,
			wp_generate_uuid4()
		);
	}

	/**
	 * Step 3 of §7.6, on the target: the fully resolved settings and styles
	 * of the site right now, captured AFTER the concurrency check has proven
	 * the live user origin still matches the exported record — so the
	 * snapshot is provably produced by the same origin the bundle exported.
	 *
	 * @return array{settings: array<string, mixed>, styles: array<string, mixed>}
	 */
	public function capture_pre_reset_state(): array {
		$this->adapter->refresh_caches();

		return $this->adapter->resolved();
	}

	/**
	 * §7.8 step 8: the reset is an update of the user origin post to the
	 * empty document, never a delete — WordPress keeps exactly one Global
	 * Styles post per theme, which is why post_finalize_record_state() is
	 * 'present'. The deployed theme.json already carries the promoted
	 * content, so the resolved output must not change.
	 */
	public function reset( StateRecord $record ): void {
		$this->adapter->reset_user_origin();
	}

	/**
	 * Steps 8-9 of §7.6: refresh every Theme JSON cache, re-read the
	 * resolved output, and compare canonical hashes. It never names a Theme
	 * JSON internal itself — the adapter owns every cache entry point. A
	 * difference reports the first divergent key path; the finalizer then
	 * restores the user origin from the backup and refuses with
	 * resolved-output-drift.
	 *
	 * @param array{settings: array<string, mixed>, styles: array<string, mixed>} $expected_resolved
	 * @return array{equivalent: bool, difference: string|null, hash: string}
	 */
	public function verify_resolved_equivalence( array $expected_resolved ): array {
		$this->adapter->refresh_caches();

		$actual      = $this->adapter->resolved();
		$actual_hash = $this->resolved_hash( $actual );

		if ( $this->resolved_hash( $expected_resolved ) === $actual_hash ) {
			return array(
				'equivalent' => true,
				'difference' => null,
				'hash'       => $actual_hash,
			);
		}

		return array(
			'equivalent' => false,
			'difference' => $this->first_difference( $expected_resolved, $actual, '' ),
			'hash'       => $actual_hash,
		);
	}

	/**
	 * The semantic hash of the state the site resolves to right now, or
	 * null when the record slug resolves to nothing. The resolved output of
	 * the active theme always exists, so only an unknown slug is null —
	 * exactly the contract resolve_current_hash() has for templates.
	 */
	public function resolve_current_hash( string $record_slug ): ?string {
		if ( 'active' !== $record_slug ) {
			return null;
		}

		$this->adapter->refresh_caches();

		return $this->resolved_hash( $this->adapter->resolved() );
	}

	/**
	 * Global Styles computes its expectation on the target host; the
	 * manifest records expectedPostResetHash as null, so a mis-wired caller
	 * fails loudly instead of comparing against nothing.
	 */
	public function expected_post_reset_hash( StateRecord $record ): string {
		throw PromotionException::hard(
			'Global Styles computes its expectation on the target host; call capture_pre_reset_state() instead.'
		);
	}

	/**
	 * The backup payload that can recreate the user origin row: the raw
	 * wp_global_styles post row. terms stay empty on purpose — the reset is
	 * an update, never a delete, so the row keeps its wp_theme term and
	 * every meta key untouched; only post_content needs restoring.
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
			'terms' => array(),
			'meta'  => get_post_meta( $object_id ),
		);
	}

	/**
	 * §7.9: restore the user origin post content on the EXISTING row — the
	 * reset updated it, never deleted it, so wp_insert_post() would create
	 * a duplicate — and check is_wp_error().
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

		$result = wp_update_post(
			array(
				'ID'           => (int) $post['ID'],
				'post_content' => isset( $post['post_content'] ) && is_string( $post['post_content'] ) ? $post['post_content'] : '',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			throw PromotionException::hard(
				sprintf( 'Could not restore %s: %s', $record->key(), $result->get_error_message() )
			);
		}
	}

	/**
	 * Global Styles carries no block markup and no template-part references,
	 * so there are no strategy-level per-record refusals: an unresolved
	 * font-file reference is already refused generically by
	 * ReferenceRefusalPolicy inside PromotionPreparer, before the strategy
	 * is reached. The empty list is the contract, never a stub.
	 *
	 * @param array<string, mixed> $bundle_record
	 * @param list<string>         $selected_keys
	 * @return list<RecordRefusal>
	 */
	public function validate_for_promotion( array $bundle_record, BundleView $bundle, array $selected_keys ): array {
		return array();
	}

	/**
	 * The canonical hash of the resolved settings/styles: the gateway's
	 * content hash, whose recursive key sort makes the result independent
	 * of array assembly order — exactly what the equivalence gate needs.
	 *
	 * @param array{settings: array<string, mixed>, styles: array<string, mixed>} $resolved
	 */
	public function resolved_hash( array $resolved ): string {
		return $this->gateway->hash_content( $resolved );
	}

	/**
	 * The on-disk theme origin of a theme root: decoded theme.json as an
	 * object-shaped array. Missing, unreadable or non-object documents are
	 * refused fail-closed, exactly like ThemeDeclaredSlugs::from_file().
	 *
	 * @return array<string, mixed>
	 */
	private function read_theme_origin( string $theme_root ): array {
		$path = rtrim( $theme_root, '/' ) . '/theme.json';

		if ( ! is_readable( $path ) ) {
			throw PromotionException::hard( sprintf( 'The theme.json file could not be read: "%s".', $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local on-disk artifact, never a URL.
		$raw = file_get_contents( $path );

		if ( false === $raw ) {
			throw PromotionException::hard( sprintf( 'The theme.json file could not be read: "%s".', $path ) );
		}

		$decoded = json_decode( $raw, true );

		// A valid JSON document whose root is not an object (an array, a
		// scalar) decodes as an array or scalar and must be refused just
		// like broken JSON: a guard that accepts a list root would silently
		// merge nothing, and a guard must fail closed, never open.
		if ( ! is_array( $decoded ) || ! str_starts_with( ltrim( $raw ), '{' ) ) {
			throw PromotionException::hard( sprintf( 'The theme.json file "%s" is not a valid JSON object.', $path ) );
		}

		return $decoded;
	}

	/**
	 * The first key path at which two documents differ, or null when they
	 * are equal. Lists compare positionally; object maps compare by key.
	 *
	 * @param array<mixed> $expected
	 * @param array<mixed> $actual
	 */
	private function first_difference( array $expected, array $actual, string $path ): ?string {
		$keys = array_unique( array_merge( array_keys( $expected ), array_keys( $actual ) ) );
		sort( $keys, SORT_STRING );

		foreach ( $keys as $key ) {
			$child_path = '' === $path ? (string) $key : $path . '.' . (string) $key;

			if ( ! array_key_exists( $key, $expected ) || ! array_key_exists( $key, $actual ) ) {
				return $child_path;
			}

			$left  = $expected[ $key ];
			$right = $actual[ $key ];

			if ( is_array( $left ) && is_array( $right ) ) {
				$nested = $this->first_difference( $left, $right, $child_path );

				if ( null !== $nested ) {
					return $nested;
				}

				continue;
			}

			if ( $left !== $right ) {
				return $child_path;
			}
		}

		return null;
	}
}
