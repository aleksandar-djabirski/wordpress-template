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

	public const OUTCOME_PREPARED = 'prepared';
	public const OUTCOME_PROMOTED = 'promoted';
	public const OUTCOME_RESTORED = 'restored';
	public const OUTCOME_SKIPPED  = 'skipped';
	public const OUTCOME_REFUSED  = 'refused';

	/** @var list<string> */
	private const SUCCESS_OUTCOMES = array(
		self::OUTCOME_PREPARED,
		self::OUTCOME_PROMOTED,
		self::OUTCOME_RESTORED,
		self::OUTCOME_SKIPPED,
	);

	/** @var array<string, string> */
	private array $outcomes;

	/** @var list<RecordRefusal> */
	private array $refusals;

	/**
	 * @param array<string, string> $outcomes record key => prepared|promoted|restored|skipped|refused
	 * @param list<RecordRefusal>   $refusals
	 */
	public function __construct( array $outcomes, array $refusals ) {
		self::assert_known_outcomes( $outcomes );
		self::assert_refusals_agree( $outcomes, $refusals );

		$this->outcomes = $outcomes;
		$this->refusals = $refusals;
	}

	/**
	 * An unrecognised status is a hard error rather than a silent success.
	 * successes() counted every value that was not 'refused', so a typo or a
	 * status this class does not know about became a success and the run
	 * exited 0.
	 *
	 * @param array<string, string> $outcomes
	 */
	private static function assert_known_outcomes( array $outcomes ): void {
		$known = array_merge( self::SUCCESS_OUTCOMES, array( self::OUTCOME_REFUSED ) );

		foreach ( $outcomes as $record_key => $outcome ) {
			if ( ! in_array( $outcome, $known, true ) ) {
				throw PromotionException::hard(
					'Unknown promotion outcome "' . $outcome . '" for record ' . $record_key
					. '; expected one of ' . implode( ', ', $known ) . '.'
				);
			}
		}
	}

	/**
	 * The outcome map and the refusal report are two views of one fact and must
	 * not disagree. A record refused in the report but not marked 'refused' in
	 * the map would exit 0 while the manifest carried refusals; a record marked
	 * 'refused' with no report entry would give the operator an exit code with
	 * nothing to act on. One record may carry SEVERAL refusals — several
	 * unresolved references in one template — so this compares the SETS of
	 * record keys, never the counts.
	 *
	 * @param array<string, string> $outcomes
	 * @param list<RecordRefusal>   $refusals
	 */
	private static function assert_refusals_agree( array $outcomes, array $refusals ): void {
		$marked = array();

		foreach ( $outcomes as $record_key => $outcome ) {
			if ( self::OUTCOME_REFUSED === $outcome ) {
				$marked[ $record_key ] = true;
			}
		}

		$reported = array();

		foreach ( $refusals as $refusal ) {
			$reported[ $refusal->record_key ] = true;
		}

		$missing_report = array_keys( array_diff_key( $marked, $reported ) );

		if ( array() !== $missing_report ) {
			throw PromotionException::hard(
				'Record(s) marked refused with no refusal report entry: ' . implode( ', ', $missing_report ) . '.'
			);
		}

		$missing_mark = array_keys( array_diff_key( $reported, $marked ) );

		if ( array() !== $missing_mark ) {
			throw PromotionException::hard(
				'Refusal report names record(s) that are not marked refused: ' . implode( ', ', $missing_mark ) . '.'
			);
		}
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
			if ( in_array( $outcome, self::SUCCESS_OUTCOMES, true ) ) {
				++$successes;
			}
		}

		return $successes;
	}

	private function refused_count(): int {
		$refused = 0;

		foreach ( $this->outcomes as $outcome ) {
			if ( self::OUTCOME_REFUSED === $outcome ) {
				++$refused;
			}
		}

		return $refused;
	}
}
