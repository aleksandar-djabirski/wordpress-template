<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The §5.4 "default owner" strings every record and provider carries, so
 * drift classification and the CLI's --format=table legend agree on one
 * vocabulary. GIT_BASELINE_PLUS_DB marks the block-theme files Git owns but
 * the database may override; DATABASE_PLUS_UPLOADS marks media the database
 * points at; FORBIDDEN marks state that must never be promoted into Git.
 */
final class Ownership {

	public const GIT_BASELINE_PLUS_DB             = 'git-baseline+db';
	public const GIT_BASELINE_PLUS_DB_USER_ORIGIN = 'git-baseline+db-user-origin';
	public const DATABASE                         = 'database';
	public const DATABASE_PLUS_UPLOADS            = 'database+uploads';
	public const FORBIDDEN                        = 'forbidden';

	private function __construct() {
		// Static-only constant holder; never instantiated.
	}

	/**
	 * Every valid ownership value, for validation and the CLI legend.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::GIT_BASELINE_PLUS_DB,
			self::GIT_BASELINE_PLUS_DB_USER_ORIGIN,
			self::DATABASE,
			self::DATABASE_PLUS_UPLOADS,
			self::FORBIDDEN,
		);
	}
}
