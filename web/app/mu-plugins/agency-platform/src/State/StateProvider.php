<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The complete §7.1 provider contract. Every bullet §7.1 lists has a home
 * here: stable slug, export behaviour, normalisation behaviour, stable record
 * key, diff behaviour, promotability, dependency/reference detection,
 * validation behaviour, and — through promotion_strategy() — prepare,
 * finalise/reset, and restore behaviour.
 *
 * A provider's slug is stable for the life of the site: it appears in every
 * record key and is the value `--providers` and `--select` accept. records()
 * returns the live database records sorted by key() ascending (strcmp), so
 * exports are deterministic. baseline_records() returns the Git-side
 * counterparts of the same records — empty when has_git_baseline() is false.
 * Prepare/finalise/restore behaviour is delegated to a PromotionStrategy so
 * another track can supply it without editing a provider file, and project
 * plugins add providers through the `agency_platform_state_providers` filter
 * without modifying agency-platform.
 */
interface StateProvider {

	/** §7.1 "Stable provider slug". */
	public function slug(): string;

	/** §7.1 "Stable record key": "<provider-slug>:<record-slug>". */
	public function record_key( string $record_slug ): string;

	/** An Ownership::* constant: who owns the underlying state. */
	public function ownership(): string;

	/** §7.1 "Whether it is promotable" — the policy. A PromotionPolicy::* constant. */
	public function promotion(): string;

	/** promotion() === PromotionPolicy::PROMOTABLE */
	public function is_promotable(): bool;

	/** True only for providers gated behind --include-content. */
	public function includes_content(): bool;

	public function has_git_baseline(): bool;

	/**
	 * §7.1 "Normalisation behaviour".
	 *
	 * @param array<string, mixed> $content
	 * @return array<string, mixed>
	 */
	public function normalize( array $content ): array;

	/**
	 * §7.1 "Dependency/reference detection".
	 *
	 * @param array<string, mixed> $content
	 * @return list<array<string, mixed>>
	 */
	public function detect_references( array $content, string $record_key ): array;

	/** §7.1 "Diff behaviour". @return string One of 'added'|'removed'|'changed'|'unchanged'. */
	public function compare_records( ?StateRecord $current, ?StateRecord $target ): string;

	/**
	 * §7.1 "Validation behaviour".
	 *
	 * @return list<string> Human-readable problems; empty when valid.
	 */
	public function validate( StateRecord $record ): array;

	/**
	 * §7.1 "Export behaviour".
	 *
	 * @return list<StateRecord> Live database records, sorted by key ascending (strcmp).
	 */
	public function records(): array;

	/**
	 * Live single-record re-read; null when the record no longer exists.
	 * $with_references = false suppresses reference detection, which
	 * ReferenceResolver uses to break the scan -> resolve -> scan cycle.
	 */
	public function record( string $key, bool $with_references = true ): ?StateRecord;

	/** @return list<StateRecord> Git-baseline counterparts; empty when has_git_baseline() is false. */
	public function baseline_records(): array;

	/**
	 * §7.1 "Prepare behaviour when promotable", "Finalise/reset behaviour when
	 * promotable", "Restore behaviour" — delegated to a strategy so the
	 * promotion track can supply them without editing a provider file.
	 * Returns null when nothing is registered for this slug.
	 */
	public function promotion_strategy(): ?PromotionStrategy;
}
