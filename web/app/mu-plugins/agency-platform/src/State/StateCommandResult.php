<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The outcome of one command run: the exit code plus the two streams,
 * captured rather than written. This is the seam that makes the exit-code and
 * stream contracts (BLOCK_THEME_PROPOSAL.md section 6) testable in PHPUnit,
 * where WP_CLI is not loaded at all.
 */
final class StateCommandResult {

	public function __construct(
		public readonly int $exit_code,
		public readonly string $stdout,
		public readonly string $stderr
	) {
	}
}
