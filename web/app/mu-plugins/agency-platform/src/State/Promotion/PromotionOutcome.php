<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The per-record verdict of a promotion run plus the refusals behind it.
 * exit_code() implements master spec §6's run-level mapping: prepared,
 * promoted, restored and skipped count as successes; refused records never
 * do. Zero successes with at least one refusal is a hard error (exit 1); a
 * mix of successes and refusals is partial success (exit 2).
 */
final class PromotionOutcome {

	/** @var array<string, string> */
	private array $outcomes;

	/** @var list<RecordRefusal> */
	private array $refusals;

	/**
	 * @param array<string, string> $outcomes record key => prepared|promoted|restored|skipped|refused
	 * @param list<RecordRefusal>   $refusals
	 */
	public function __construct( array $outcomes, array $refusals ) {
		$this->outcomes = $outcomes;
		$this->refusals = $refusals;
	}

	public function exit_code(): int {
		$successes = $this->successes();
		$refusals  = $this->refused_count();

		if ( 0 === $refusals ) {
			return PromotionExitCode::SUCCESS;
		}

		if ( $successes > 0 ) {
			return PromotionExitCode::PARTIAL_SUCCESS;
		}

		return PromotionExitCode::HARD_ERROR;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$outcomes = $this->outcomes;
		ksort( $outcomes );

		$refusals = array();

		foreach ( $this->refusals as $refusal ) {
			$refusals[] = $refusal->to_array();
		}

		return array(
			'outcomes' => $outcomes,
			'refusals' => $refusals,
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function outcomes(): array {
		return $this->outcomes;
	}

	/**
	 * @return list<RecordRefusal>
	 */
	public function refusals(): array {
		return $this->refusals;
	}

	public function successes(): int {
		$successes = 0;

		foreach ( $this->outcomes as $outcome ) {
			if ( 'refused' !== $outcome ) {
				++$successes;
			}
		}

		return $successes;
	}

	private function refused_count(): int {
		$refused = 0;

		foreach ( $this->outcomes as $outcome ) {
			if ( 'refused' === $outcome ) {
				++$refused;
			}
		}

		return $refused;
	}
}
