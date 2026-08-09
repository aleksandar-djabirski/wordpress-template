<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * Two-phase writer. Every body is staged as a temp file first; only when every
 * record in the run has staged successfully does commit_all() rename them into
 * place. A failure part-way through restores whatever was already renamed.
 */
interface StagedPromotionEntry {

	/** @return array<string,mixed> manifest fields for this staged file */
	public function manifest_fields(): array;

	public function commit(): void;

	public function discard(): void;

	public function rollback_committed(): void;
}
