<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * Shared defaults so each provider stays small and final. Concrete providers
 * extend this and implement only slug(), ownership(), promotion(),
 * includes_content(), has_git_baseline(), records(), and baseline_records().
 *
 * The one deliberately non-final class in the state subsystem: every
 * concrete provider is expected to extend it, and the concrete classes stay
 * final.
 */
abstract class BaseStateProvider implements StateProvider {

	/**
	 * §7.1 "Stable record key": the provider slug, the key separator, and
	 * the record slug — the same format §6's --select=templates:page syntax
	 * uses.
	 */
	public function record_key( string $record_slug ): string {
		return $this->slug() . ':' . $record_slug;
	}

	/** §7.1 "Whether it is promotable" — the policy, resolved to a boolean. */
	public function is_promotable(): bool {
		return PromotionPolicy::PROMOTABLE === $this->promotion();
	}

	/**
	 * §7.1 "Normalisation behaviour": canonical scalar values first, then
	 * deterministic key order, so the provider only needs to read its
	 * fields.
	 *
	 * @param array<string, mixed> $content
	 * @return array<string, mixed>
	 */
	public function normalize( array $content ): array {
		return Normalizer::normalize_content( $content );
	}

	/**
	 * §7.1 "Dependency/reference detection". The base default detects
	 * nothing: markup-bearing providers (templates, template parts,
	 * navigation, synced patterns) override this once ReferenceScanner
	 * ships, and content without a markup key can never reference another
	 * record.
	 *
	 * @param array<string, mixed> $content
	 * @return list<array<string, mixed>>
	 */
	public function detect_references( array $content, string $record_key ): array {
		return array();
	}

	/**
	 * §7.1 "Diff behaviour": pure content-hash comparison. "added" when the
	 * record exists in $current but not in $target, "removed" when it exists
	 * in $target but not in $current, "changed" when both exist but the
	 * content hashes differ, "unchanged" otherwise.
	 */
	public function compare_records( ?StateRecord $current, ?StateRecord $target ): string {
		if ( null === $current && null === $target ) {
			return 'unchanged';
		}

		if ( null === $current ) {
			return 'removed';
		}

		if ( null === $target ) {
			return 'added';
		}

		if ( $current->content_hash() === $target->content_hash() ) {
			return 'unchanged';
		}

		return 'changed';
	}

	/**
	 * §7.1 "Validation behaviour". The base default accepts every record;
	 * providers whose records have extra invariants (e.g. markup that must
	 * parse) override this to return human-readable problems.
	 *
	 * @return list<string>
	 */
	public function validate( StateRecord $record ): array {
		return array();
	}

	/**
	 * §7.1 "Export behaviour", single-record form: a linear lookup over
	 * records(). $with_references is part of the contract so ReferenceResolver
	 * can re-read a live record without re-running reference detection.
	 */
	public function record( string $key, bool $with_references = true ): ?StateRecord {
		foreach ( $this->records() as $record ) {
			if ( $key === $record->key() ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * §7.1 "Prepare/finalise/reset/restore behaviour", delegated to the
	 * promotion track. Null until a strategy registers for this provider's
	 * slug on the agency_platform_promotion_strategies filter.
	 */
	public function promotion_strategy(): ?PromotionStrategy {
		return PromotionStrategies::for_provider( $this->slug() );
	}
}
