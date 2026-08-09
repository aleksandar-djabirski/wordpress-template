<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The §11.10 drift classification vocabulary StateDiffer emits per record,
 * so the CLI's --format=table legend and any automation keying off the
 * report share one set of strings. UNRESOLVED is a runtime failure, never a
 * quiet default: any record the differ cannot classify must surface as one.
 */
final class DriftClassification {

	public const PROMOTABLE = 'promotable';
	public const DB_OWNED   = 'db-owned';
	public const FORBIDDEN  = 'forbidden';
	public const UNRESOLVED = 'unresolved';
	public const UNCHANGED  = 'unchanged';

	private function __construct() {
		// Static-only constant holder; never instantiated.
	}

	/**
	 * Every valid classification value, for validation and the CLI legend.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::PROMOTABLE,
			self::DB_OWNED,
			self::FORBIDDEN,
			self::UNRESOLVED,
			self::UNCHANGED,
		);
	}
}
