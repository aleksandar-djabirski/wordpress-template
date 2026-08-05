<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The file side of every state comparison: resolves the repository root and
 * the active theme's directory, and reads the current git commit plus the
 * theme's template/part markup and theme.json — all normalised through the
 * SAME serialiser the database side uses (Normalizer::normalize_block_markup),
 * so a healthy site can never report phantom drift.
 *
 * git metadata is read from disk, never by shelling out: proc_open()/exec()
 * are disabled on many production hosts, and this reader must work there.
 * The .git layout is worktree-aware, because this migration's branch model
 * runs one git worktree per task and inside a worktree <root>/.git is a FILE
 * carrying a `gitdir:` line, not a directory.
 */
final class GitBaseline {

	public const SETTING_REPO_ROOT = 'AGENCY_REPO_ROOT';

	private ?string $repo_root;
	private ?string $theme_dir;

	public function __construct( ?string $repo_root = null, ?string $theme_dir = null ) {
		$this->repo_root = $repo_root;
		$this->theme_dir = $theme_dir;
	}

	/**
	 * The repository root: AGENCY_REPO_ROOT when set, else the first
	 * directory at most six levels up from ABSPATH that contains both a
	 * composer.json and a web directory (the Bedrock layout this repo uses),
	 * else dirname( ABSPATH, 2 ) — Bedrock puts core at <root>/web/wp/.
	 * Always returned as a forward-slash path with no trailing slash.
	 */
	public function repo_root(): string {
		if ( null !== $this->repo_root ) {
			return self::normalise( $this->repo_root );
		}

		$configured = EnvironmentConfig::get( self::SETTING_REPO_ROOT );

		if ( null !== $configured ) {
			return self::normalise( $configured );
		}

		$candidate = rtrim( ABSPATH, '/' );

		for ( $level = 0; $level < 6; $level++ ) {
			if ( is_dir( $candidate . '/web' ) && is_file( $candidate . '/composer.json' ) ) {
				return self::normalise( $candidate );
			}

			$parent = dirname( $candidate );

			if ( $parent === $candidate ) {
				break;
			}

			$candidate = $parent;
		}

		return self::normalise( dirname( ABSPATH, 2 ) );
	}

	/**
	 * The active theme's directory: the constructor argument when given, else
	 * get_stylesheet_directory(). Never hard-coded to site-theme — a client
	 * rename must keep working.
	 */
	public function theme_dir(): string {
		if ( null !== $this->theme_dir ) {
			return self::normalise( $this->theme_dir );
		}

		return self::normalise( get_stylesheet_directory() );
	}

	/**
	 * The current HEAD commit, or null when there is no readable git
	 * metadata. A detached HEAD yields its sha directly; a symbolic ref is
	 * looked up loose-first in this worktree, then in the shared git dir,
	 * then in packed-refs.
	 */
	public function head_commit(): ?string {
		$git_dir = $this->git_dir();

		if ( null === $git_dir ) {
			return null;
		}

		$head = self::read_file( $git_dir . '/HEAD' );

		if ( null === $head ) {
			return null;
		}

		$parsed = self::parse_head( $head );

		if ( null !== $parsed['sha'] ) {
			return $parsed['sha'];
		}

		$ref = $parsed['ref'];

		if ( null === $ref ) {
			return null;
		}

		$loose_ref = self::read_file( $git_dir . '/' . $ref );

		if ( null !== $loose_ref && self::is_full_sha( trim( $loose_ref ) ) ) {
			return trim( $loose_ref );
		}

		$common_dir = $this->git_common_dir();

		if ( null !== $common_dir ) {
			$shared_ref = self::read_file( $common_dir . '/' . $ref );

			if ( null !== $shared_ref && self::is_full_sha( trim( $shared_ref ) ) ) {
				return trim( $shared_ref );
			}

			$packed_refs = self::read_file( $common_dir . '/packed-refs' );

			if ( null !== $packed_refs ) {
				$sha = self::parse_packed_refs( $packed_refs, $ref );

				if ( null !== $sha ) {
					return $sha;
				}
			}
		}

		return null;
	}

	/**
	 * True when the active theme is a native block theme — the spec's own
	 * block-theme test (§5.1).
	 */
	public function has_block_templates(): bool {
		return is_file( $this->theme_dir() . '/templates/index.html' );
	}

	/**
	 * The resolved git metadata directory for THIS checkout, or null.
	 * Worktree-aware: a plain <root>/.git directory is its own git dir, while
	 * a .git FILE (the worktree layout this migration's branch model
	 * produces) is read for its `gitdir:` line and resolved against the
	 * repository root.
	 */
	public function git_dir(): ?string {
		$candidate = $this->repo_root() . '/.git';

		if ( is_dir( $candidate ) ) {
			return $candidate;
		}

		if ( ! is_file( $candidate ) ) {
			return null;
		}

		$contents = self::read_file( $candidate );

		if ( null === $contents || 1 !== preg_match( '/^gitdir:\s*(.+)$/m', $contents, $matches ) ) {
			return null;
		}

		$git_dir = trim( $matches[1] );

		if ( str_starts_with( $git_dir, '/' ) ) {
			return self::normalise( $git_dir );
		}

		return self::resolve_relative( $this->repo_root(), $git_dir );
	}

	/**
	 * The shared metadata directory (packed-refs, main refs), or null.
	 * Equals git_dir() outside a worktree; inside one it follows the
	 * commondir file.
	 */
	public function git_common_dir(): ?string {
		$git_dir = $this->git_dir();

		if ( null === $git_dir ) {
			return null;
		}

		$commondir_file = $git_dir . '/commondir';

		if ( ! is_file( $commondir_file ) ) {
			return $git_dir;
		}

		$contents = self::read_file( $commondir_file );

		if ( null === $contents ) {
			return $git_dir;
		}

		$commondir = trim( $contents );

		if ( '' === $commondir ) {
			return $git_dir;
		}

		if ( str_starts_with( $commondir, '/' ) ) {
			return self::normalise( $commondir );
		}

		return self::resolve_relative( $git_dir, $commondir );
	}

	/**
	 * Pure: parses a HEAD file's contents into either a sha or a ref name.
	 *
	 * @return array{sha: string|null, ref: string|null} Exactly one is set.
	 */
	public static function parse_head( string $head_contents ): array {
		$head_contents = trim( $head_contents );

		if ( 1 === preg_match( '/^[0-9a-f]{40}$/', $head_contents ) ) {
			return array(
				'sha' => $head_contents,
				'ref' => null,
			);
		}

		if ( 1 === preg_match( '/^ref:\s*(.+)$/', $head_contents, $matches ) ) {
			return array(
				'sha' => null,
				'ref' => trim( $matches[1] ),
			);
		}

		return array(
			'sha' => null,
			'ref' => null,
		);
	}

	/**
	 * Pure: finds a ref's sha in packed-refs contents, ignoring comment lines
	 * and peeled-tag lines.
	 */
	public static function parse_packed_refs( string $packed_refs, string $ref ): ?string {
		$packed_refs = str_replace( array( "\r\n", "\r" ), "\n", $packed_refs );

		foreach ( explode( "\n", $packed_refs ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || '#' === $line[0] || '^' === $line[0] ) {
				continue;
			}

			$parts = explode( ' ', $line, 2 );

			if ( 2 !== count( $parts ) || $ref !== $parts[1] ) {
				continue;
			}

			if ( self::is_full_sha( $parts[0] ) ) {
				return $parts[0];
			}
		}

		return null;
	}

	/**
	 * @return array<string, string> slug => normalised block markup, sorted by slug.
	 */
	public function template_markup(): array {
		return self::markup_from_dir( $this->theme_dir() . '/templates' );
	}

	/**
	 * @return array<string, string> slug => normalised block markup, sorted by slug.
	 */
	public function part_markup(): array {
		return self::markup_from_dir( $this->theme_dir() . '/parts' );
	}

	/**
	 * @return array<string, mixed>|null Decoded theme.json, null when unreadable.
	 */
	public function theme_json(): ?array {
		$contents = self::read_file( $this->theme_dir() . '/theme.json' );

		if ( null === $contents ) {
			return null;
		}

		$decoded = json_decode( $contents, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Reads one theme template/part directory: every *.html file keyed by its
	 * basename without the extension, the value passed through the SAME
	 * normalisation call the database side uses, sorted by slug. An empty
	 * result is normal and not an error — a pre-migration hybrid theme has no
	 * .html templates, and every database template then reads as `added`.
	 *
	 * @return array<string, string>
	 */
	private static function markup_from_dir( string $directory ): array {
		$markup = array();
		$files  = glob( $directory . '/*.html' );

		if ( false === $files ) {
			return $markup;
		}

		foreach ( $files as $file ) {
			$contents = self::read_file( $file );

			if ( null === $contents ) {
				continue;
			}

			$markup[ basename( $file, '.html' ) ] = Normalizer::normalize_block_markup( $contents );
		}

		ksort( $markup );

		return $markup;
	}

	/**
	 * Reads a local file, returning null when it is missing or unreadable.
	 */
	private static function read_file( string $path ): ?string {
		if ( ! is_file( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local git metadata and theme files on disk; CLI-only, and WP_Filesystem needs a credentials-bearing admin request context this subsystem does not have.
		$contents = file_get_contents( $path );

		return false === $contents ? null : $contents;
	}

	/**
	 * Joins a relative path onto an absolute base and collapses `.`/`..`
	 * segments lexically (no filesystem access), so a gitdir/commondir
	 * value like "../.." resolves exactly the way the shell would.
	 */
	private static function resolve_relative( string $base, string $relative ): string {
		$prefix   = str_starts_with( $base, '/' ) ? '/' : '';
		$segments = explode( '/', rtrim( $base, '/' ) . '/' . ltrim( $relative, '/' ) );
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

	private static function is_full_sha( string $candidate ): bool {
		return 1 === preg_match( '/^[0-9a-f]{40}$/', $candidate );
	}

	private static function normalise( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}
}
