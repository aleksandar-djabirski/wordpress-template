<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The immutable per-record value object of the state subsystem: one
 * provider's one record (a template, a template part, the global-styles row,
 * …) with everything §7.2 requires. The content hash is computed once at
 * construction from the content values alone — never from a caller-supplied
 * value and never from array order — because that hash is what the two-mode
 * diff and every promotion concurrency check compare.
 */
final class StateRecord {

	/** @var list<string> */
	private const BUNDLE_FIELDS = array( 'key', 'objectId', 'slug', 'status', 'modifiedGmt', 'content', 'contentHash', 'references', 'ownership', 'promotion' );

	private string $provider_slug;
	private string $slug;
	private ?int $object_id;
	private string $status;
	private ?string $modified_gmt;

	/** @var array<string, mixed> */
	private array $content;
	private string $content_hash;

	/** @var list<array<string, mixed>> */
	private array $references;

	private string $ownership;
	private string $promotion;

	/**
	 * @param array<string, mixed>       $content
	 * @param list<array<string, mixed>> $references
	 */
	private function __construct(
		string $provider_slug,
		string $slug,
		?int $object_id,
		string $status,
		?string $modified_gmt,
		array $content,
		array $references,
		string $ownership,
		string $promotion
	) {
		$this->provider_slug = $provider_slug;
		$this->slug          = $slug;
		$this->object_id     = $object_id;
		$this->status        = $status;
		$this->modified_gmt  = $modified_gmt;
		$this->content       = Normalizer::sort_recursive( $content );
		$this->references    = $references;
		$this->ownership     = $ownership;
		$this->promotion     = $promotion;
		$this->content_hash  = Normalizer::hash( $this->content );
	}

	/**
	 * The one way providers build records. Every field is validated before
	 * the record exists, so a malformed record can never leak into an
	 * export.
	 *
	 * @param array<string, mixed>       $content
	 * @param list<array<string, mixed>> $references
	 */
	public static function create(
		string $provider_slug,
		string $slug,
		?int $object_id,
		string $status,
		?string $modified_gmt,
		array $content,
		array $references,
		string $ownership,
		string $promotion
	): self {
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $provider_slug ) ) {
			throw StateException::hard_error( 'Invalid provider slug ' . $provider_slug . ' for record ' . $slug . ': provider slugs match /^[a-z0-9-]+$/, because they prefix every record key.' );
		}

		if ( '' === $slug || 1 === preg_match( '/[:\s]/', $slug ) ) {
			throw StateException::hard_error( 'Invalid record slug ' . $slug . ' for provider ' . $provider_slug . ': a record slug is non-empty and contains neither ":" nor whitespace, because ":" is the key separator.' );
		}

		if ( ! in_array( $ownership, Ownership::all(), true ) ) {
			throw StateException::hard_error( 'Unknown ownership value ' . $ownership . ' for ' . $provider_slug . ':' . $slug . '; expected one of: ' . implode( ', ', Ownership::all() ) . '.' );
		}

		if ( ! in_array( $promotion, PromotionPolicy::all(), true ) ) {
			throw StateException::hard_error( 'Unknown promotion value ' . $promotion . ' for ' . $provider_slug . ':' . $slug . '; expected one of: ' . implode( ', ', PromotionPolicy::all() ) . '.' );
		}

		return new self( $provider_slug, $slug, $object_id, $status, $modified_gmt, $content, $references, $ownership, $promotion );
	}

	/**
	 * The only sanctioned way to turn a bundle record back into a value
	 * object. Requires every bundle field, re-derives the provider slug and
	 * record slug from the key, and recomputes the content hash — a
	 * hand-edited or corrupt record fails here, before any diff or
	 * promotion step can act on it.
	 *
	 * @param array<string, mixed> $record
	 * @throws StateException on a malformed record.
	 */
	public static function from_array( array $record ): self {
		$key = isset( $record['key'] ) && is_string( $record['key'] ) ? $record['key'] : '';

		foreach ( self::BUNDLE_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $record ) ) {
				throw StateException::hard_error( 'Record ' . $key . ' is missing the ' . $field . ' field; every bundle record must carry all ten.' );
			}
		}

		$parts = explode( ':', $key, 2 );

		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			throw StateException::hard_error( 'Record key ' . $key . ' is malformed; it must be "<provider-slug>:<record-slug>".' );
		}

		if ( ! is_array( $record['content'] ) ) {
			throw StateException::hard_error( 'Record ' . $key . ' has a non-array content field.' );
		}

		if ( ! is_array( $record['references'] ) ) {
			throw StateException::hard_error( 'Record ' . $key . ' has a non-array references field.' );
		}

		if ( ! is_string( $record['status'] ) || ! is_string( $record['contentHash'] ) || ! is_string( $record['ownership'] ) || ! is_string( $record['promotion'] ) ) {
			throw StateException::hard_error( 'Record ' . $key . ' has a non-string status, contentHash, ownership, or promotion field.' );
		}

		if ( ! is_string( $record['slug'] ) ) {
			throw StateException::hard_error( 'Record ' . $key . ' has a non-string slug field.' );
		}

		if ( null !== $record['modifiedGmt'] && ! is_string( $record['modifiedGmt'] ) ) {
			throw StateException::hard_error( 'Record ' . $key . ' has a non-string modifiedGmt field.' );
		}

		if ( null !== $record['objectId'] && ! is_int( $record['objectId'] ) ) {
			throw StateException::hard_error( 'Record ' . $key . ' has a non-integer objectId field.' );
		}

		$recomputed_hash = Normalizer::hash( $record['content'] );

		if ( $recomputed_hash !== $record['contentHash'] ) {
			throw StateException::hard_error( 'Record ' . $key . ' failed verification: its contentHash does not match its content. It was hand-edited or corrupted.' );
		}

		return new self(
			$parts[0],
			$parts[1],
			$record['objectId'],
			$record['status'],
			$record['modifiedGmt'],
			$record['content'],
			$record['references'],
			$record['ownership'],
			$record['promotion']
		);
	}

	/**
	 * The stable record key: "<provider-slug>:<record-slug>", the same
	 * format §6's --select=templates:page syntax uses.
	 */
	public function key(): string {
		return $this->provider_slug . ':' . $this->slug;
	}

	public function provider_slug(): string {
		return $this->provider_slug;
	}

	public function slug(): string {
		return $this->slug;
	}

	public function object_id(): ?int {
		return $this->object_id;
	}

	public function status(): string {
		return $this->status;
	}

	public function modified_gmt(): ?string {
		return $this->modified_gmt;
	}

	/**
	 * The normalised content, in deterministic key order.
	 *
	 * @return array<string, mixed>
	 */
	public function content(): array {
		return $this->content;
	}

	public function content_hash(): string {
		return $this->content_hash;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function references(): array {
		return $this->references;
	}

	public function ownership(): string {
		return $this->ownership;
	}

	public function promotion(): string {
		return $this->promotion;
	}

	/**
	 * The bundle record shape, with the camelCase JSON field names §7.2
	 * fixes, in exactly that order.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'key'         => $this->key(),
			'objectId'    => $this->object_id,
			'slug'        => $this->slug,
			'status'      => $this->status,
			'modifiedGmt' => $this->modified_gmt,
			'content'     => $this->content,
			'contentHash' => $this->content_hash,
			'references'  => $this->references,
			'ownership'   => $this->ownership,
			'promotion'   => $this->promotion,
		);
	}
}
