<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The promotion lifecycle's exit codes (BLOCK_THEME_PROPOSAL.md §6). They
 * mirror the state subsystem's codes on purpose: a promotion run that fails
 * inside Task 2 code must surface the same code to the operator.
 */
final class PromotionExitCode {

	public const SUCCESS         = 0;
	public const HARD_ERROR      = 1;
	public const PARTIAL_SUCCESS = 2;
	public const LOCK_CONFLICT   = 3;
	public const TAMPER          = 4;

	private function __construct() {
		// Static-only constant holder; never instantiated.
	}
}
