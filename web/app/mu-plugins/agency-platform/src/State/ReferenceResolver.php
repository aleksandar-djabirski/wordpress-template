<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The WordPress-coupled half of the reference pipeline (§7.4, decision 20):
 * resolve() fills the three target fields that scan_parsed() always emits
 * as null. Every reference is resolved through its target's OWNING provider
 * — StateRegistry::provider( 'navigation' )->record( $target_key ) — never
 * by re-hashing raw post_content, so the hash inside a reference is
 * byte-identical to the hash that provider exports in the bundle.
 *
 * The recursion guard: a provider's record() builds references for its
 * targets too, so resolve() re-reads every target with $with_references =
 * false, which stops the scan -> resolve -> scan cycle. A self-referencing
 * synced pattern is legal markup and must terminate.
 */
final class ReferenceResolver {

	private function __construct() {
		// Static-only resolver; never instantiated.
	}

	/**
	 * Fill the three target fields of every reference. The scanner's
	 * deterministic order is preserved — resolve() only fills fields, it
	 * never re-sorts.
	 *
	 * @param list<array<string, mixed>> $references
	 * @return list<array<string, mixed>>
	 */
	public static function resolve( array $references ): array {
		$resolved = array();

		foreach ( $references as $reference ) {
			$resolved[] = self::resolve_one( $reference );
		}

		return $resolved;
	}

	/**
	 * Dispatch one reference to its kind's resolver. Kinds that are not
	 * resolvable to a record — font-file, plugin-block, post-meta,
	 * unknown-ref — travel unchanged with their three target fields null.
	 *
	 * @param array<string, mixed> $reference
	 * @return array<string, mixed>
	 */
	private static function resolve_one( array $reference ): array {
		$kind = $reference['kind'];

		if ( ReferenceScanner::KIND_NAVIGATION === $kind ) {
			return self::resolve_post_reference( $reference, 'wp_navigation', 'nav' );
		}

		if ( ReferenceScanner::KIND_SYNCED_PATTERN === $kind ) {
			return self::resolve_post_reference( $reference, 'wp_block', 'pattern' );
		}

		if ( ReferenceScanner::KIND_SITE_LOGO === $kind ) {
			return self::resolve_site_logo_reference( $reference );
		}

		if ( ReferenceScanner::KIND_ATTACHMENT === $kind || ReferenceScanner::KIND_GALLERY === $kind ) {
			$value = $reference['value'];

			if ( ! is_int( $value ) && ! is_string( $value ) ) {
				return $reference;
			}

			return self::resolve_attachment_reference( $reference, $value );
		}

		return $reference;
	}

	/**
	 * A navigation or synced-pattern reference: the value is the referenced
	 * post's ID. The record slug mirrors the owning provider's rule —
	 * post_name, with the provider-specific fallback for blank names — and
	 * the target is re-read with $with_references = false, which is the
	 * recursion guard.
	 *
	 * @param array<string, mixed> $reference
	 * @return array<string, mixed>
	 */
	private static function resolve_post_reference( array $reference, string $post_type, string $fallback_prefix ): array {
		$value = $reference['value'];

		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return $reference;
		}

		$post = get_post( (int) $value );

		if ( null === $post || $post_type !== $post->post_type ) {
			return $reference;
		}

		$provider = StateRegistry::provider( self::provider_slug_for( $post_type ) );

		if ( null === $provider ) {
			return $reference;
		}

		$slug       = '' !== $post->post_name ? $post->post_name : $fallback_prefix . '-' . $post->ID;
		$target_key = $provider->record_key( $slug );
		$target     = $provider->record( $target_key, false );

		if ( null === $target ) {
			return $reference;
		}

		$reference['targetKey']      = $target_key;
		$reference['targetHash']     = $target->content_hash();
		$reference['targetIdentity'] = array(
			'title'  => $post->post_title,
			'slug'   => $slug,
			'status' => $post->post_status,
		);

		return $reference;
	}

	/**
	 * A site-logo reference: the block carries no id — the id lives in the
	 * site_logo option, which the resolver reads and writes back into the
	 * reference's value (§7.4 requires the referenced ID). The value stays
	 * null only when no site logo is set.
	 *
	 * @param array<string, mixed> $reference
	 * @return array<string, mixed>
	 */
	private static function resolve_site_logo_reference( array $reference ): array {
		$logo_id = get_option( 'site_logo' );

		if ( ! is_numeric( $logo_id ) || (int) $logo_id < 1 ) {
			return $reference;
		}

		$reference['value'] = (int) $logo_id;

		return self::resolve_attachment_reference( $reference, $reference['value'] );
	}

	/**
	 * An attachment or gallery reference: the value is the attachment ID.
	 * The target is the media-references provider's record for that
	 * attachment — which only exists when the attachment is actually part
	 * of that provider's fixed source set — and the identity carries the
	 * metadata the refusal report needs.
	 *
	 * @param array<string, mixed> $reference
	 * @return array<string, mixed>
	 */
	private static function resolve_attachment_reference( array $reference, int|string $value ): array {
		$post = get_post( (int) $value );

		if ( null === $post || 'attachment' !== $post->post_type ) {
			return $reference;
		}

		$provider = StateRegistry::provider( 'media-references' );

		if ( null === $provider ) {
			return $reference;
		}

		$target_key = $provider->record_key( 'attachment-' . $post->ID );
		$target     = $provider->record( $target_key, false );

		if ( null === $target ) {
			return $reference;
		}

		$metadata = wp_get_attachment_metadata( (int) $post->ID );

		$reference['targetKey']      = $target_key;
		$reference['targetHash']     = $target->content_hash();
		$reference['targetIdentity'] = array(
			'title'        => $post->post_title,
			'slug'         => $post->post_name,
			'mimeType'     => (string) get_post_mime_type( (int) $post->ID ),
			'relativePath' => is_array( $metadata ) && isset( $metadata['file'] ) ? (string) $metadata['file'] : '',
		);

		return $reference;
	}

	/**
	 * The provider slug owning a referenced post type, or '' when nothing
	 * in the registry owns it.
	 */
	private static function provider_slug_for( string $post_type ): string {
		if ( 'wp_navigation' === $post_type ) {
			return 'navigation';
		}

		if ( 'wp_block' === $post_type ) {
			return 'synced-patterns';
		}

		if ( 'attachment' === $post_type ) {
			return 'media-references';
		}

		return '';
	}
}
