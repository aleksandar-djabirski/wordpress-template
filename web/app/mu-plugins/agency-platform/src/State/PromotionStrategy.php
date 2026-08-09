<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The promotion delegation point. This task DEFINES it and ships the empty
 * registry; the promotion track IMPLEMENTS it, one strategy per promotable
 * provider slug, and registers them through the filter below. Nothing in
 * this task calls prepare()/reset()/restore().
 */
interface PromotionStrategy {

	/** The StateProvider slug this strategy serves. */
	public function provider_slug(): string;

	/**
	 * §7.5: write the record's normalised content to $target_path atomically.
	 *
	 * @return array{preparedPath: string, preparedHash: string, originalHash: string|null}
	 */
	public function prepare( StateRecord $record, string $target_path ): array;

	/** §7.8: remove the database override through WordPress APIs. */
	public function reset( StateRecord $record ): void;

	/**
	 * §7.9: recreate the database override from a backup payload.
	 *
	 * @param array<string, mixed> $backup
	 */
	public function restore( StateRecord $record, array $backup ): void;

	/** §7.8 step 9: the semantic hash the resolved state must equal after reset(). */
	public function expected_post_reset_hash( StateRecord $record ): string;
}
