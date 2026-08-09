<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\StateException;

/**
 * The single failure type of the promotion lifecycle. It carries the exit
 * code the command surface must produce. from_state_exception() rethrows a
 * Task 2 failure PRESERVING its exit code verbatim — the code comes from
 * StateException::exit_code(), never from inspecting the message text.
 */
final class PromotionException extends \RuntimeException {

	private int $promotion_exit_code;

	public function __construct( string $message, int $exit_code = PromotionExitCode::HARD_ERROR, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );

		$this->promotion_exit_code = $exit_code;
	}

	public static function hard( string $message, ?\Throwable $previous = null ): self {
		return new self( $message, PromotionExitCode::HARD_ERROR, $previous );
	}

	public static function tamper( string $message, ?\Throwable $previous = null ): self {
		return new self( $message, PromotionExitCode::TAMPER, $previous );
	}

	public static function lock_conflict( string $message, ?\Throwable $previous = null ): self {
		return new self( $message, PromotionExitCode::LOCK_CONFLICT, $previous );
	}

	public static function from_state_exception( StateException $exception ): self {
		return new self( $exception->getMessage(), $exception->exit_code(), $exception );
	}

	public function exit_code(): int {
		return $this->promotion_exit_code;
	}
}
