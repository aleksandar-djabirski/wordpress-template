<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\EnvironmentConfig;

/**
 * The git context the promotion lifecycle's prepare, seal and verification
 * steps operate against (plan Task 5): HEAD, branch, working-tree dirtiness
 * and committed-file content. Every command runs through run(), which
 * invokes git with an argv array — proc_open's array form never invokes a
 * shell, so no branch name, path or commit-ish can be injected through an
 * argument. Working-tree reads are exactly the case where a subprocess is
 * the only honest answer: git's index, rename detection and quoting rules
 * are implemented once, in git.
 */
final class GitRepository {

	public const SETTING_REPO_ROOT = 'AGENCY_REPO_ROOT';

	public function __construct( private string $root ) {}

	/**
	 * The repository to operate on: AGENCY_REPO_ROOT when set, else the
	 * directory git itself reports via `rev-parse --show-toplevel`. The
	 * fallback starts from the repository root as seen from this source
	 * file — eight parents up from src/State/Promotion (Promotion, State,
	 * src, agency-platform, mu-plugins, app, web, repository) — so it works
	 * regardless of the process working directory. A non-zero git exit
	 * throws: a fallback that cannot resolve the root must fail loudly, not
	 * silently return a wrong directory.
	 */
	public static function discover(): self {
		$configured = EnvironmentConfig::get( self::SETTING_REPO_ROOT );

		if ( null !== $configured ) {
			return new self( self::normalise( $configured ) );
		}

		$result = ( new self( dirname( __DIR__, 8 ) ) )->run( array( 'rev-parse', '--show-toplevel' ) );

		if ( 0 !== $result['exit'] ) {
			throw PromotionException::hard(
				sprintf( 'Could not determine the repository root with "git rev-parse --show-toplevel"; set %s.', self::SETTING_REPO_ROOT )
			);
		}

		return new self( self::normalise( trim( $result['stdout'] ) ) );
	}

	public function root(): string {
		return $this->root;
	}

	/**
	 * The current HEAD commit as a 40-hex sha. A hard error when git cannot
	 * read HEAD, or when the value is not a full sha — the promotion
	 * contract is 40-hex, and an odd-length value flowing downstream would
	 * corrupt every hash comparison it participates in.
	 */
	public function head_commit(): string {
		$result = $this->run( array( 'rev-parse', 'HEAD' ) );

		if ( 0 !== $result['exit'] ) {
			throw PromotionException::hard( sprintf( 'Could not read HEAD in "%s"; is the repository empty?', $this->root ) );
		}

		$sha = trim( $result['stdout'] );

		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $sha ) ) {
			throw PromotionException::hard( sprintf( 'Expected a 40-hex commit sha from "%s", got "%s".', $this->root, $sha ) );
		}

		return $sha;
	}

	/**
	 * The current branch name, or null when HEAD is detached.
	 */
	public function current_branch(): ?string {
		$result = $this->run( array( 'symbolic-ref', '--quiet', '--short', 'HEAD' ) );

		if ( 0 !== $result['exit'] ) {
			return null;
		}

		$branch = trim( $result['stdout'] );

		return '' === $branch ? null : $branch;
	}

	/**
	 * Repo-relative paths with any working-tree or index change, parsed from
	 * git's porcelain v1 format: the first three characters are the XY
	 * status columns plus a separator, a rename line ("R  old -> new")
	 * contributes only its right-hand path, and a quoted path has its
	 * C-style escapes undone with stripcslashes() after the quotes are
	 * removed. This is the only reader of working-tree state the promotion
	 * lifecycle has — the run-level dirty-tree refusal in prepare depends
	 * entirely on it.
	 *
	 * @return list<string>
	 */
	public function dirty_paths(): array {
		$result = $this->run( array( 'status', '--porcelain', '--untracked-files=all' ) );

		if ( 0 !== $result['exit'] ) {
			throw PromotionException::hard( sprintf( 'Could not read the working-tree status in "%s".', $this->root ) );
		}

		$paths = array();

		foreach ( explode( "\n", $result['stdout'] ) as $line ) {
			if ( '' === $line ) {
				continue;
			}

			$path = substr( $line, 3 );

			if ( 'R' === $line[0] ) {
				$separator = strrpos( $path, ' -> ' );

				if ( false !== $separator ) {
					$path = substr( $path, $separator + 4 );
				}
			}

			if ( str_starts_with( $path, '"' ) && str_ends_with( $path, '"' ) && 1 < strlen( $path ) ) {
				$path = stripcslashes( substr( $path, 1, -1 ) );
			}

			if ( '' !== $path ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * True when the sha names an existing commit object.
	 */
	public function commit_exists( string $sha ): bool {
		return 0 === $this->run( array( 'cat-file', '-e', $sha . '^{commit}' ) )['exit'];
	}

	/**
	 * The file's raw bytes at that commit, or null when the path is not
	 * present there. The bytes are returned verbatim — this is the content
	 * the --seal prepared-file hash check runs against.
	 */
	public function file_at_commit( string $sha, string $repo_relative_path ): ?string {
		$result = $this->run( array( 'show', $sha . ':' . $repo_relative_path ) );

		if ( 0 !== $result['exit'] ) {
			return null;
		}

		return $result['stdout'];
	}

	/**
	 * The repo-relative form of an absolute path. Throws a hard error when
	 * the path is outside the repository root. Backslashes are normalised
	 * on both sides, so a Windows-style path works unchanged.
	 */
	public function relative_path( string $absolute_path ): string {
		$path = self::normalise( $absolute_path );
		$root = self::normalise( $this->root );

		if ( $path === $root ) {
			return '';
		}

		if ( str_starts_with( $path, $root . '/' ) ) {
			return substr( $path, strlen( $root ) + 1 );
		}

		throw PromotionException::hard(
			sprintf( 'Path "%s" is outside the repository root "%s".', $absolute_path, $root )
		);
	}

	/**
	 * The absolute form of a repo-relative path. Backslashes are normalised.
	 */
	public function absolute_path( string $repo_relative_path ): string {
		return self::normalise( $this->root ) . '/' . ltrim( self::normalise( $repo_relative_path ), '/' );
	}

	/**
	 * Runs one git command against the repository root. The array command
	 * form never invokes a shell, so no argument can be injected; stdout is
	 * captured, stderr passes through to the process stderr where all
	 * diagnostics belong.
	 *
	 * @param list<string> $argv
	 * @return array{stdout: string, exit: int}
	 */
	private function run( array $argv ): array {
		$command     = array_merge( array( 'git', '-C', $this->root ), $argv );
		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => STDERR,
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- git is invoked with an argv array (no shell), which is the only way to read working-tree state.
		$process = proc_open( $command, $descriptors, $pipes );

		if ( false === $process ) {
			throw PromotionException::hard( 'git is not available; --prepare and --seal require it.' );
		}

		$stdout = stream_get_contents( $pipes[1] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the git stdout pipe; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[1] );

		return array(
			'stdout' => false === $stdout ? '' : $stdout,
			'exit'   => proc_close( $process ),
		);
	}

	private static function normalise( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}
}
