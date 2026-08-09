<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The pure half of the reference pipeline (§7.4, decision 20). scan_parsed()
 * walks already-parsed block arrays — the shape parse_blocks() returns —
 * and emits one reference per matcher hit. Every reference carries the
 * eleven documented keys in a fixed order; the last three (targetKey,
 * targetHash, targetIdentity) are always null here because resolving them
 * needs WordPress and the owning provider, both of which belong to
 * ReferenceResolver (Task 9). This class is WordPress-free by design: it is
 * unit-tested without a live install, so nothing here may call WordPress.
 *
 * Reference order feeds the exported bundle and therefore the hash every
 * later drift and tamper gate compares, so scan_parsed() always ends with
 * sort_references(): attribute-map traversal order is not a contract, and
 * the tuple sort makes the same markup produce the same list on any host.
 */
final class ReferenceScanner {

	public const KIND_NAVIGATION     = 'navigation';
	public const KIND_SYNCED_PATTERN = 'synced-pattern';
	public const KIND_ATTACHMENT     = 'attachment';
	public const KIND_SITE_LOGO      = 'site-logo';
	public const KIND_GALLERY        = 'gallery';
	public const KIND_FONT_FILE      = 'font-file';
	public const KIND_PLUGIN_BLOCK   = 'plugin-block';
	public const KIND_POST_META      = 'post-meta';
	public const KIND_UNKNOWN_REF    = 'unknown-ref';

	public const RESOLUTION_RESOLVED    = 'resolved';
	public const RESOLUTION_ENVIRONMENT = 'environment-specific';
	public const RESOLUTION_UNKNOWN     = 'unknown';

	/** Core media blocks whose integer attrs.id is an attachment reference. */
	private const MEDIA_BLOCKS = array( 'core/image', 'core/cover', 'core/media-text', 'core/video', 'core/audio', 'core/file' );

	/** Block namespaces that never count as plugin blocks. */
	private const KNOWN_NAMESPACES = array( 'core', 'agency', 'woocommerce' );

	/** String-attribute suffixes that mark a font-file reference. */
	private const FONT_EXTENSIONS = array( '.woff', '.woff2', '.ttf', '.otf' );

	private function __construct() {
		// Static-only scanner; never instantiated.
	}

	/**
	 * Walk the block tree and collect every reference, sorted into the
	 * deterministic order the bundle hash depends on. The provider side of
	 * the record key is everything before its first colon, so a record key
	 * may itself contain colons.
	 *
	 * @param list<array<string, mixed>> $blocks
	 * @return list<array<string, mixed>>
	 */
	public static function scan_parsed( array $blocks, string $record_key ): array {
		$provider = explode( ':', $record_key, 2 )[0];
		$found    = array();

		foreach ( $blocks as $block ) {
			self::scan_block( $block, $record_key, $provider, $found );
		}

		return self::sort_references( $found );
	}

	/**
	 * WordPress-coupled: parse_blocks(), scan_parsed(), then
	 * ReferenceResolver::resolve() fills the three target fields.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function scan( string $markup, string $record_key ): array {
		return ReferenceResolver::resolve( self::scan_parsed( parse_blocks( $markup ), $record_key ) );
	}

	/**
	 * A reference is unresolved until ReferenceResolver has filled its three
	 * target fields; only a resolved binding can ever satisfy this.
	 *
	 * @param array<string, mixed> $reference
	 */
	public static function is_unresolved( array $reference ): bool {
		return self::RESOLUTION_RESOLVED !== $reference['resolution'];
	}

	/**
	 * The subset of references that still need resolution.
	 *
	 * @param list<array<string, mixed>> $references
	 * @return list<array<string, mixed>>
	 */
	public static function unresolved( array $references ): array {
		$unresolved = array();

		foreach ( $references as $reference ) {
			if ( self::is_unresolved( $reference ) ) {
				$unresolved[] = $reference;
			}
		}

		return $unresolved;
	}

	/**
	 * Deterministic order independent of block-attribute map traversal:
	 * strcmp on the blockName/attribute/value/kind tuple. The same markup
	 * must yield the same list on any host and any PHP build, because the
	 * list feeds the bundle hash.
	 *
	 * @param list<array<string, mixed>> $references
	 * @return list<array<string, mixed>>
	 */
	public static function sort_references( array $references ): array {
		usort( $references, array( self::class, 'compare_references' ) );

		return $references;
	}

	/**
	 * Recurse into a block tree. Raw-HTML blocks (null or empty blockName)
	 * carry no references of their own, but their inner blocks still do.
	 *
	 * @param array<string, mixed>       $block
	 * @param list<array<string, mixed>> $found
	 */
	private static function scan_block( array $block, string $record, string $provider, array &$found ): void {
		$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : null;

		if ( '' !== $name && null !== $name ) {
			self::match_block( $name, $block, $record, $provider, $found );
		}

		if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $inner_block ) {
				if ( is_array( $inner_block ) ) {
					self::scan_block( $inner_block, $record, $provider, $found );
				}
			}
		}
	}

	/**
	 * Run the matcher table against one block: block-level matches first,
	 * then bindings, then the attribute-level catch-alls, then the plugin
	 * namespace check. Attributes consumed by a block-level match never
	 * reach the catch-alls.
	 *
	 * @param array<string, mixed>       $block
	 * @param list<array<string, mixed>> $found
	 */
	private static function match_block( string $name, array $block, string $record, string $provider, array &$found ): void {
		$attrs    = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$consumed = array();

		self::match_block_level( $name, $attrs, $record, $provider, $consumed, $found );
		self::match_bindings( $name, $attrs, $record, $provider, $found );
		self::match_attributes( $name, $attrs, $record, $provider, $consumed, $found );
		self::match_namespace( $name, $record, $provider, $found );
	}

	/**
	 * The per-block-type matchers: navigation and synced-pattern refs, media
	 * ids, gallery id lists, and the site logo (whose id lives in the
	 * site_logo option, never in the markup — the resolver supplies it).
	 *
	 * @param array<string, mixed>       $attrs
	 * @param list<string>               $consumed
	 * @param list<array<string, mixed>> $found
	 */
	private static function match_block_level( string $name, array $attrs, string $record, string $provider, array &$consumed, array &$found ): void {
		// Every core/navigation block emits exactly one navigation
		// reference. An explicit integer ref is the referenced post's ID; a
		// ref-less block (or a non-integer ref) emits the reference with a
		// null value and lets ReferenceResolver apply WordPress core's
		// deterministic most-recently-published fallback during export.
		// The ref attribute is always consumed so the catch-alls can never
		// emit a second reference for the same block.
		if ( 'core/navigation' === $name ) {
			$value = null;

			if ( isset( $attrs['ref'] ) ) {
				$consumed[] = 'ref';

				if ( is_int( $attrs['ref'] ) ) {
					$value = $attrs['ref'];
				}
			}

			$found[] = self::reference( $record, $provider, $name, 'ref', $value, self::KIND_NAVIGATION, self::RESOLUTION_ENVIRONMENT, self::policy_for( self::KIND_NAVIGATION, null ) );
		}

		if ( 'core/block' === $name && isset( $attrs['ref'] ) && is_int( $attrs['ref'] ) ) {
			$consumed[] = 'ref';
			$found[]    = self::reference( $record, $provider, $name, 'ref', $attrs['ref'], self::KIND_SYNCED_PATTERN, self::RESOLUTION_ENVIRONMENT, self::policy_for( self::KIND_SYNCED_PATTERN, null ) );
		}

		if ( in_array( $name, self::MEDIA_BLOCKS, true ) && isset( $attrs['id'] ) && is_int( $attrs['id'] ) ) {
			$consumed[] = 'id';
			$found[]    = self::reference( $record, $provider, $name, 'id', $attrs['id'], self::KIND_ATTACHMENT, self::RESOLUTION_ENVIRONMENT, self::policy_for( self::KIND_ATTACHMENT, null ) );
		}

		if ( 'core/gallery' === $name && isset( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
			$consumed[] = 'ids';

			foreach ( $attrs['ids'] as $id ) {
				if ( ! is_int( $id ) && ! is_string( $id ) ) {
					continue;
				}

				$found[] = self::reference( $record, $provider, $name, 'ids', $id, self::KIND_GALLERY, self::RESOLUTION_ENVIRONMENT, self::policy_for( self::KIND_GALLERY, null ) );
			}
		}

		if ( 'core/site-logo' === $name ) {
			$found[] = self::reference( $record, $provider, $name, 'site_logo', null, self::KIND_SITE_LOGO, self::RESOLUTION_ENVIRONMENT, self::policy_for( self::KIND_SITE_LOGO, null ) );
		}
	}

	/**
	 * Block-bindings references: each metadata.bindings.* entry whose source
	 * is core/post-meta travels with the record and is resolved; any other
	 * source names a provider nobody can map and stays unknown.
	 *
	 * @param array<string, mixed>       $attrs
	 * @param list<array<string, mixed>> $found
	 */
	private static function match_bindings( string $name, array $attrs, string $record, string $provider, array &$found ): void {
		if ( ! isset( $attrs['metadata'] ) || ! is_array( $attrs['metadata'] ) || ! isset( $attrs['metadata']['bindings'] ) || ! is_array( $attrs['metadata']['bindings'] ) ) {
			return;
		}

		foreach ( $attrs['metadata']['bindings'] as $bound_attribute => $binding ) {
			if ( ! is_string( $bound_attribute ) || ! is_array( $binding ) || ! isset( $binding['source'] ) || ! is_string( $binding['source'] ) ) {
				continue;
			}

			$source = $binding['source'];

			if ( 'core/post-meta' === $source ) {
				$value   = self::bound_meta_key( $binding );
				$found[] = self::reference( $record, $provider, $name, 'metadata.bindings.' . $bound_attribute, $value, self::KIND_POST_META, self::RESOLUTION_RESOLVED, self::policy_for( self::KIND_POST_META, $source ) );

				continue;
			}

			$found[] = self::reference( $record, $provider, $name, 'metadata.bindings.' . $bound_attribute, $source, self::KIND_POST_META, self::RESOLUTION_UNKNOWN, self::policy_for( self::KIND_POST_META, $source ) );
		}
	}

	/**
	 * The attribute-level catch-alls: font path attributes, then any
	 * remaining ref-like attribute holding an integer. Attributes consumed
	 * by a block-level match are skipped.
	 *
	 * @param array<string, mixed>       $attrs
	 * @param list<string>               $consumed
	 * @param list<array<string, mixed>> $found
	 */
	private static function match_attributes( string $name, array $attrs, string $record, string $provider, array $consumed, array &$found ): void {
		foreach ( $attrs as $attribute => $value ) {
			if ( in_array( $attribute, $consumed, true ) ) {
				continue;
			}

			if ( is_string( $value ) && self::is_font_path( $value ) ) {
				$found[] = self::reference( $record, $provider, $name, (string) $attribute, $value, self::KIND_FONT_FILE, self::RESOLUTION_ENVIRONMENT, self::policy_for( self::KIND_FONT_FILE, null ) );

				continue;
			}

			if ( self::is_unknown_ref( $attribute, $value ) ) {
				$found[] = self::reference( $record, $provider, $name, (string) $attribute, $value, self::KIND_UNKNOWN_REF, self::RESOLUTION_UNKNOWN, self::policy_for( self::KIND_UNKNOWN_REF, null ) );
			}
		}
	}

	/**
	 * Any block outside the core/agency/woocommerce namespaces is a plugin
	 * block and must be confirmed present in the target environment.
	 *
	 * @param list<array<string, mixed>> $found
	 */
	private static function match_namespace( string $name, string $record, string $provider, array &$found ): void {
		$namespace = explode( '/', $name, 2 )[0];

		if ( in_array( $namespace, self::KNOWN_NAMESPACES, true ) ) {
			return;
		}

		$found[] = self::reference( $record, $provider, $name, 'blockName', $name, self::KIND_PLUGIN_BLOCK, self::RESOLUTION_UNKNOWN, self::policy_for( self::KIND_PLUGIN_BLOCK, null ) );
	}

	/**
	 * The §7.4 refusal-report policy string per kind; Task 3 surfaces these
	 * verbatim, so they must not drift. Post-meta policy depends on the
	 * binding source.
	 */
	private static function policy_for( string $kind, ?string $binding_source ): string {
		if ( self::KIND_POST_META === $kind ) {
			if ( 'core/post-meta' === $binding_source ) {
				return 'Block bindings to core/post-meta travel with the record; no mapping needed.';
			}

			return 'Unrecognised reference. v1 invents no mappings: promotion of this record is refused until the reference is classified (BLOCK_THEME_PROPOSAL.md §7.4).';
		}

		$policies = array(
			self::KIND_NAVIGATION     => 'Navigation stays database-owned in v1. Promote the template or part without the ref; finalisation must resolve exactly one navigation in the target environment whose normalised content hash matches the exported navigation, and must refuse otherwise (BLOCK_THEME_PROPOSAL.md §7.4).',
			self::KIND_SYNCED_PATTERN => 'Synced patterns (wp_block) stay database-owned in v1. Promotion of a record that references one is refused; no ID mapping is invented (BLOCK_THEME_PROPOSAL.md §7.4).',
			self::KIND_ATTACHMENT     => 'Media IDs are environment-specific. Promotion of a record that references one is refused in v1; export the media reference and remap it manually (BLOCK_THEME_PROPOSAL.md §5.4, §7.4).',
			self::KIND_GALLERY        => 'Media IDs are environment-specific. Promotion of a record that references one is refused in v1; export the media reference and remap it manually (BLOCK_THEME_PROPOSAL.md §5.4, §7.4).',
			self::KIND_SITE_LOGO      => 'Media IDs are environment-specific. Promotion of a record that references one is refused in v1; export the media reference and remap it manually (BLOCK_THEME_PROPOSAL.md §5.4, §7.4).',
			self::KIND_FONT_FILE      => 'Font Library records and files stay database/filesystem-owned in v1. Promotion of a record that references a font file is refused (BLOCK_THEME_PROPOSAL.md §5.4).',
			self::KIND_PLUGIN_BLOCK   => 'This block is registered by a plugin outside the core/agency/woocommerce namespaces. Confirm it is registered in the target environment before promoting the record.',
			self::KIND_UNKNOWN_REF    => 'Unrecognised reference. v1 invents no mappings: promotion of this record is refused until the reference is classified (BLOCK_THEME_PROPOSAL.md §7.4).',
		);

		return $policies[ $kind ];
	}

	/**
	 * A string attribute holding a font path: one of the known font
	 * extensions, or a path that passes through a /fonts/ directory.
	 */
	private static function is_font_path( string $value ): bool {
		foreach ( self::FONT_EXTENSIONS as $extension ) {
			if ( str_ends_with( $value, $extension ) ) {
				return true;
			}
		}

		return str_contains( $value, '/fonts/' );
	}

	/**
	 * The unknown-ref catch-all: an attribute named ref, or ending in Id,
	 * Ids, or _id, holding an integer or a numeric string.
	 *
	 * @param int|string $attribute
	 */
	private static function is_unknown_ref( int|string $attribute, mixed $value ): bool {
		if ( ! is_int( $value ) && ! self::is_numeric_string( $value ) ) {
			return false;
		}

		return 'ref' === $attribute
			|| str_ends_with( (string) $attribute, 'Ids' )
			|| str_ends_with( (string) $attribute, 'Id' )
			|| str_ends_with( (string) $attribute, '_id' );
	}

	/**
	 * The bound meta key of a core/post-meta binding, or the source as a
	 * fallback when the binding carries no args.key.
	 *
	 * @param array<string, mixed> $binding
	 */
	private static function bound_meta_key( array $binding ): string {
		if ( isset( $binding['args'] ) && is_array( $binding['args'] ) && isset( $binding['args']['key'] ) && is_string( $binding['args']['key'] ) ) {
			return $binding['args']['key'];
		}

		return (string) $binding['source'];
	}

	/** @param mixed $value */
	private static function is_numeric_string( mixed $value ): bool {
		return is_string( $value ) && '' !== $value && ctype_digit( $value );
	}

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	private static function compare_references( array $a, array $b ): int {
		return strcmp( self::sort_key( $a ), self::sort_key( $b ) );
	}

	/**
	 * The tuple every reference sorts on: blockName, attribute, value, kind.
	 *
	 * @param array<string, mixed> $reference
	 */
	private static function sort_key( array $reference ): string {
		return (string) $reference['blockName'] . "\0" . (string) $reference['attribute'] . "\0" . (string) $reference['value'] . "\0" . (string) $reference['kind'];
	}

	/**
	 * One reference row, all eleven keys in the documented order. The three
	 * target fields are always null here; ReferenceResolver fills them.
	 *
	 * @return array<string, mixed>
	 */
	private static function reference( string $record, string $provider, string $block_name, string $attribute, int|string|null $value, string $kind, string $resolution, string $policy ): array {
		return array(
			'record'         => $record,
			'provider'       => $provider,
			'blockName'      => $block_name,
			'attribute'      => $attribute,
			'value'          => $value,
			'kind'           => $kind,
			'resolution'     => $resolution,
			'policy'         => $policy,
			'targetKey'      => null,
			'targetHash'     => null,
			'targetIdentity' => null,
		);
	}
}
