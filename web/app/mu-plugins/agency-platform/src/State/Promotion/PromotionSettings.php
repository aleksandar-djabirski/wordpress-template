<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\EnvironmentConfig;

/**
 * Reads the promotion tuning knobs through EnvironmentConfig so that
 * constants declared in config/environments/*.php work exactly like .env
 * values. Every setting is optional and has a documented default; a missing,
 * blank or malformed value always resolves to the default, never to a
 * failure.
 */
final class PromotionSettings {

	public const LOCK_TTL       = 'AGENCY_PROMOTION_LOCK_TTL';
	public const MUTEX_TTL      = 'AGENCY_PROMOTION_MUTEX_TTL';
	public const RETENTION_DAYS = 'AGENCY_PROMOTION_BACKUP_RETENTION_DAYS';
	public const CHUNK_BYTES    = 'AGENCY_PROMOTION_BACKUP_CHUNK_BYTES';
	public const DEPLOYMENT_ID  = 'AGENCY_DEPLOYMENT_ID';
	public const VERIFICATION   = 'AGENCY_VERIFICATION_COMMANDS';

	/** @var list<string> */
	private const DEFAULT_VERIFICATION_COMMANDS = array( 'npm run test:e2e', 'npm run test:visual' );

	private function __construct() {
		// Static-only utility class; never instantiated.
	}

	public static function integer( string $name, int $default_value ): int {
		$value = EnvironmentConfig::get( $name );

		if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
			return (int) $value;
		}

		return $default_value;
	}

	public static function text( string $name, string $default_value ): string {
		$value = EnvironmentConfig::get( $name );

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return $value;
		}

		return $default_value;
	}

	public static function flag( string $name ): bool {
		$value = EnvironmentConfig::get( $name );

		if ( ! is_string( $value ) ) {
			return false;
		}

		return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes' ), true );
	}

	/**
	 * Splits AGENCY_VERIFICATION_COMMANDS on commas, trims every entry and
	 * drops empties. The value is an advisory list written into the manifest,
	 * never executed by this code.
	 *
	 * @return list<string>
	 */
	public static function verification_commands(): array {
		$value = EnvironmentConfig::get( self::VERIFICATION );

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return self::DEFAULT_VERIFICATION_COMMANDS;
		}

		$commands = array();

		foreach ( explode( ',', $value ) as $command ) {
			$command = trim( $command );

			if ( '' !== $command ) {
				$commands[] = $command;
			}
		}

		return $commands;
	}
}
