<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * Reads an AGENCY_* setting from a PHP constant first, then the process
 * environment. Both matter in this repository: config/environments/*.php
 * declares AGENCY_* through Roots\WPConfig\Config::define() (constants),
 * while Bedrock's dotenv loader also registers a PutenvAdapter, so a value
 * placed in .env is visible to getenv(). A blank value counts as absent in
 * both sources, so an empty .env line never masks a constant.
 *
 * resolve() is the pure decision this class exists to make; get()/has()
 * only gather the two candidate values. That split keeps the precedence
 * rule unit-testable without defining constants or mutating the process
 * environment.
 */
final class EnvironmentConfig {

	private function __construct() {
		// Static-only utility class; never instantiated.
	}

	public static function get( string $name ): ?string {
		$source = array();

		if ( defined( $name ) ) {
			$value = constant( $name );

			if ( is_scalar( $value ) ) {
				$source['constant'] = (string) $value;
			}
		}

		$from_environment = getenv( $name );

		if ( is_string( $from_environment ) ) {
			$source['environment'] = $from_environment;
		}

		return self::resolve( $source, $name );
	}

	public static function has( string $name ): bool {
		return null !== self::get( $name );
	}

	/**
	 * Pure: picks the winning value from the two candidate sources.
	 *
	 * @param array<string, string> $source Keys 'constant' and/or 'environment'.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $name is part of the documented signature so callers and failure messages can name the setting; the precedence rule itself does not depend on it.
	public static function resolve( array $source, string $name ): ?string {
		foreach ( array( 'constant', 'environment' ) as $key ) {
			$value = $source[ $key ] ?? '';

			if ( '' !== trim( $value ) ) {
				return $value;
			}
		}

		return null;
	}
}
