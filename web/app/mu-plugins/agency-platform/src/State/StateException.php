<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The single failure type of the state subsystem. It carries the WP-CLI
 * exit code the command surface must produce (BLOCK_THEME_PROPOSAL.md §6):
 * 1 hard error, 2 partial success/drift, 3 lock conflict, 4 tamper. Task 3
 * reuses this type for the promotion lifecycle, so the code is a
 * constructor argument rather than a fixed per-subclass value.
 */
final class StateException extends \RuntimeException {

	public const EXIT_HARD_ERROR = 1;
	public const EXIT_DRIFT      = 2;
	public const EXIT_LOCKED     = 3;
	public const EXIT_TAMPER     = 4;

	private int $exit_code;

	public function __construct( string $message, int $exit_code = self::EXIT_HARD_ERROR, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );

		$this->exit_code = $exit_code;
	}

	public static function hard_error( string $message, ?\Throwable $previous = null ): self {
		return new self( $message, self::EXIT_HARD_ERROR, $previous );
	}

	public static function tamper( string $message, ?\Throwable $previous = null ): self {
		return new self( $message, self::EXIT_TAMPER, $previous );
	}

	public function exit_code(): int {
		return $this->exit_code;
	}
}
