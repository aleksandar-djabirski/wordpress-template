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
 *
 * The web-root guard is the SHARED one: `wp agency state-export --output=<path>`
 * resolves and validates its output path through the same check
 * (resolve_output()), so a bundle can never be written anywhere the web
 * server can publish it, no matter which surface supplied the path. The
 * guard also resolves symlinks in the existing components of a path before
 * comparing, so a symlinked directory cannot smuggle a path inside the web
 * root after the string comparison.
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

		$path = self::resolve_against_repo_root( $configured, self::SETTING, $repo_root );

		self::assert_outside_web_root( $path, self::SETTING );

		return $path;
	}

	/**
	 * Resolves a CLI output path for writing — `wp agency state-export
	 * --output=<path>`. The same resolution rules as the state-directory
	 * override: relative paths resolve against the repository root (never
	 * the current working directory), backslashes are normalised, `.` and
	 * `..` are collapsed lexically, and the result is rejected when it
	 * lands inside the web root — the SHARED web-root guard, so the
	 * containment rule has exactly one implementation. The returned
	 * canonical path is what the caller must write, so the guard and the
	 * write can never disagree.
	 *
	 * @return string The canonical path to write to.
	 */
	public static function resolve_output( string $path, string $setting ): string {
		return self::resolve_against_repo_root( $path, $setting, ( new GitBaseline() )->repo_root() );
	}

	/**
	 * The named public web-root guard: rejects any path that resolves inside
	 * <repo root>/web — directly, through `..` segments, or through a
	 * symlink in an existing component. Shared by path() and resolve_output()
	 * so the containment rule has exactly one implementation.
	 */
	public static function assert_outside_web_root( string $path, string $setting ): void {
		self::assert_path_outside_web_root( $path, $setting, ( new GitBaseline() )->repo_root() );
	}

	/**
	 * The one resolution pipeline for every path this class accepts:
	 * normalise slashes, resolve relative paths against the repository root
	 * (rejecting `..` in them — a relative path must never escape the
	 * repository), canonicalise lexically, and enforce the web-root guard on
	 * the canonical form.
	 */
	private static function resolve_against_repo_root( string $path, string $setting, string $repo_root ): string {
		$normalised = rtrim( str_replace( '\\', '/', $path ), '/' );

		if ( self::is_absolute( $normalised ) ) {
			$canonical = self::canonicalise( $normalised );
		} else {
			self::assert_no_traversal( $normalised, $setting );

			$canonical = self::canonicalise( $repo_root . '/' . ltrim( $normalised, '/' ) );
		}

		self::assert_path_outside_web_root( $canonical, $setting, $repo_root );

		return $canonical;
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
	 * Rejects a relative path that contains a `..` segment: it would
	 * resolve against the repository root and could escape it, which is
	 * exactly how a misconfigured AGENCY_STATE_DIR — or a typed --output —
	 * could scatter customer state anywhere on the host.
	 */
	private static function assert_no_traversal( string $relative, string $setting ): void {
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '..' === $segment ) {
				throw StateException::hard_error(
					sprintf( 'The %s path "%s" must not contain ".." segments — it must resolve inside the repository root. Set %s to a path inside the repository.', $setting, $relative, $setting )
				);
			}
		}
	}

	/**
	 * Rejects a resolved path that lands inside the repository's web root:
	 * everything under <root>/web/ is served over HTTP, and the path carries
	 * customer state that must never be published. The comparison runs on
	 * the form returned by resolve_symlinks() — the lexically canonical
	 * form with any symlink in an existing component resolved — so neither a
	 * `..` segment nor a symlink can dodge the prefix comparison.
	 */
	private static function assert_path_outside_web_root( string $path, string $setting, string $repo_root ): void {
		$resolved = self::resolve_symlinks( self::canonicalise( $path ) );
		$web_root = $repo_root . '/web';

		if ( $resolved === $web_root || str_starts_with( $resolved, $web_root . '/' ) ) {
			throw StateException::hard_error(
				sprintf( 'The %s path "%s" resolves to "%s", inside the web root "%s/" — customer state would be published over HTTP. Set %s to a path outside the web root.', $setting, $path, $resolved, $web_root, $setting )
			);
		}
	}

	/**
	 * Resolves symlinks in the existing components of a path without
	 * requiring the path itself to exist yet: the deepest existing ancestor
	 * is realpath()'d (which follows symlinks) and the non-existing tail is
	 * re-appended lexically. A symlinked directory therefore cannot smuggle
	 * a path inside the web root after the string comparison — the web-root
	 * guard runs on this resolved form. A path whose components do not
	 * exist yet returns unchanged: there is nothing a symlink can hide in a
	 * path that is not on the filesystem.
	 */
	private static function resolve_symlinks( string $path ): string {
		$tail   = '';
		$prefix = $path;

		while ( '' !== $prefix ) {
			$real = realpath( $prefix );

			if ( false !== $real ) {
				return '' === $tail ? self::canonicalise( $real ) : self::canonicalise( $real . '/' . $tail );
			}

			$parent = dirname( $prefix );

			if ( $parent === $prefix ) {
				break;
			}

			$tail   = basename( $prefix ) . ( '' === $tail ? '' : '/' . $tail );
			$prefix = $parent;
		}

		return $path;
	}
}
