<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\PromotionStrategy;
use AgencyPlatform\State\StateRecord;

/**
 * Task 3's extension of Task 2's PromotionStrategy. Task 2 defines prepare/
 * reset/restore/expected_post_reset_hash; the lifecycle in this task needs a
 * few more capabilities, so it requires this sub-interface and refuses any
 * strategy that only implements the base one.
 */
interface PreparablePromotionStrategy extends PromotionStrategy {
	/**
	 * The two-phase staging entry point this task actually uses.
	 *
	 * It does NOT redeclare Task 2's inherited `prepare( StateRecord, string ): array`.
	 * PHP return types are invariant for non-class types, so narrowing that
	 * `array` to `StagedPromotionEntry` is a fatal declaration-compatibility
	 * error, and Task 2's interface is merged and out of this task's grant.
	 * See the CORRECTION note under Step 1 for how `prepare()` is implemented.
	 */
	public function stage( StateRecord $record, string $theme_root ): StagedPromotionEntry;
	public function theme_relative_path( string $record_slug ): string;
	public function declares( ThemeDeclaredSlugs $declared, string $record_slug ): bool;
	/** 'absent' when finalisation deletes the row; 'present' when it resets one in place. */
	public function post_finalize_record_state(): string;
	/** True when expected_post_reset_hash() can only be computed on the target host. */
	public function defers_expected_hash(): bool;
	/** Semantic hash of the state the site resolves to right now, or null when unresolvable. */
	public function resolve_current_hash( string $record_slug ): ?string;
	/**
	 * @param array<string, mixed> $bundle_record
	 * @param list<string>         $selected_keys
	 * @return list<RecordRefusal> per-record refusals discovered at prepare time
	 */
	public function validate_for_promotion( array $bundle_record, BundleView $bundle, array $selected_keys ): array;
	/** @return array<string,mixed> everything needed to recreate the row */
	public function capture_backup( StateRecord $record ): array;
}
