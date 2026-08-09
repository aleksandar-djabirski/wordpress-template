<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The §5.4 "promotion policy" strings: whether a provider's drift may ever
 * be written back into the theme's Git files, and how. Release 2 ships
 * export-and-diff only, so no provider is PROMOTABLE until the promotion
 * track implements PromotionStrategy for its slug.
 */
final class PromotionPolicy {

	public const PROMOTABLE      = 'promotable';       // §5.4 "Promotable"
	public const EXPORT_AND_DIFF = 'export-and-diff';  // §5.4 "Export and diff; do not promote in v1"
	public const NEVER_PROMOTE   = 'never-promote';    // §5.4 "never promote to theme files"
	public const REFUSE          = 'refuse';           // §5.4 "Detect and refuse automatic promotion"

	private function __construct() {
		// Static-only constant holder; never instantiated.
	}

	/**
	 * Every valid promotion policy value, for validation and the CLI legend.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::PROMOTABLE,
			self::EXPORT_AND_DIFF,
			self::NEVER_PROMOTE,
			self::REFUSE,
		);
	}
}
