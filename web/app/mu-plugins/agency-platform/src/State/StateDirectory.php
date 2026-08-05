<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * Resolves and creates the protected state-artifact directory. The default
 * location is <repo root>/var/agency-state — gitignored and outside the web
 * root, so nothing written here is ever published over HTTP; AGENCY_STATE_DIR
 * overrides it. A relative override resolves against the repository root,
 * never the current working directory, and a path that would land inside the
 * web root is rejected outright because the directory carries customer
 * content that must never be served. Every resolved path is canonicalised
 * lexically — `.` and `..` segments collapsed with no filesystem access — and
 * the web-root check runs on the canonical form, so a `..` can never smuggle
 * the path back inside the web root after the string comparison. Absolute
 * overrides outside the repository are legitimate and stay accepted.
 */
final class StateDirectory {

	public const SETTING = 'AGENCY_STATE_DIR';

	private function __construct() {
		// Static-only utility class.
	}

	public static function path(): string {
		$repo_root  = ( new GitBaseline() )->repo_root();
		$configured = EnvironmentConfig::get( self::SETTING );

		if ( null === $configured ) {
			return $repo_root . '/var/agency-state';
		}

		$configured = rtrim( str_replace( '\\', '/', $configured ), '/' );

		if ( self::is_absolute( $configured ) ) {
			$path = self::canonicalise( $configured );
		} else {
			self::assert_no_traversal( $configured );

			$path = self::canonicalise( $repo_root . '/' . ltrim( $configured, '/' ) );
		}

		self::assert_outside_web_root( $path, $repo_root );

		return $path;
	}

	public static function ensure(): string {
		$path = self::path();

		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			throw StateException::hard_error( sprintf( 'Could not create the state directory "%s". Set %s to a writable path.', $path, self::SETTING ) );
		}

		return $path;
	}

	private static function is_absolute( string $path ): bool {
		return str_starts_with( $path, '/' ) || 1 === preg_match( '#^[A-Za-z]:/#', $path );
	}

	/**
	 * Collapses `.` and `..` segments lexically, with no filesystem access:
	 * `/var/www/html/var/../web/leak` becomes `/var/www/html/web/leak`. The
	 * web-root containment check must run on this canonical form — a raw
	 * string-prefix comparison against `<root>/web` is defeated by a `..`
	 * segment — and path() returns the canonical form so no caller ever
	 * sees or writes a path containing `..`. Purely lexical on purpose:
	 * realpath() touches the filesystem and returns false for a directory
	 * that does not exist yet, while this guard must decide before any
	 * write.
	 */
	public static function canonicalise( string $path ): string {
		$prefix   = str_starts_with( $path, '/' ) ? '/' : '';
		$segments = explode( '/', rtrim( $path, '/' ) );
		$resolved = array();

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $resolved );

				continue;
			}

			$resolved[] = $segment;
		}

		return $prefix . implode( '/', $resolved );
	}

	/**
	 * Rejects a relative override that contains a `..` segment: it would
	 * resolve against the repository root and could escape it, which is
	 * exactly how a misconfigured AGENCY_STATE_DIR could scatter customer
	 * state anywhere on the host.
	 */
	private static function assert_no_traversal( string $relative ): void {
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '..' === $segment ) {
				throw StateException::hard_error(
					sprintf( 'The state directory "%s" must not contain ".." segments — it must resolve inside the repository root. Set %s to a path inside the repository.', $relative, self::SETTING )
				);
			}
		}
	}

	/**
	 * Rejects a resolved state directory that lands inside the repository's
	 * web root: everything under <root>/web/ is served over HTTP, and the
	 * state directory carries customer content that must never be published.
	 * $path is the lexically canonicalised form, so a `..` segment cannot
	 * dodge the prefix comparison.
	 */
	private static function assert_outside_web_root( string $path, string $repo_root ): void {
		$web_root = $repo_root . '/web';

		if ( $path === $web_root || str_starts_with( $path, $web_root . '/' ) ) {
			throw StateException::hard_error(
				sprintf( 'The state directory "%s" must not resolve inside the web root "%s/" — customer state would be published over HTTP. Set %s to a path outside the web root.', $path, $web_root, self::SETTING )
			);
		}
	}
}
