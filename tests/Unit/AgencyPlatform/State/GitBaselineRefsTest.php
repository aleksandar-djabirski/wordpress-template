<?php
/**
 * Unit tests for the Git baseline reader's four metadata layouts: a plain
 * .git directory, a packed-refs-only repository, a git worktree (the layout
 * this migration's own branch model produces — .git is a FILE containing a
 * `gitdir:` line), and a checkout with no git metadata at all (the
 * production-host case, §7.7 transports manifests precisely because .git may
 * be absent there). The two pure parsers are tested directly with fixture
 * strings; the filesystem-backed methods get tiny fake .git trees under
 * sys_get_temp_dir().
 *
 * This test does raw filesystem I/O (mkdir/file_put_contents/unlink/rmdir)
 * to build and tear down fixture trees — the WordPress filesystem
 * abstractions the AlternativeFunctions sniff points to don't exist in this
 * WordPress-free suite, so that sniff is disabled for this file only.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\GitBaseline;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\GitBaseline
 */
final class GitBaselineRefsTest extends TestCase {

	/**
	 * @var list<string> Fixture directories created by make_repo(), deleted
	 *                   by tearDown().
	 */
	private array $temp_dirs = array();

	public function test_parse_head_reads_a_detached_head_sha(): void {
		self::assertSame(
			array(
				'sha' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
				'ref' => null,
			),
			GitBaseline::parse_head( "a1b2c3d4e5f60718293a4b5c6d7e8f9012345678\n" )
		);
	}

	public function test_parse_head_reads_a_symbolic_ref(): void {
		self::assertSame(
			array(
				'sha' => null,
				'ref' => 'refs/heads/feat/bt-task-2-state-export-diff',
			),
			GitBaseline::parse_head( "ref: refs/heads/feat/bt-task-2-state-export-diff\n" )
		);
	}

	public function test_parse_packed_refs_finds_a_branch_and_ignores_comments_and_peeled_tags(): void {
		$packed = "# pack-refs with: peeled fully-peeled sorted \n"
			. "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa refs/heads/main\n"
			. "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb refs/tags/v1\n"
			. "^cccccccccccccccccccccccccccccccccccccccc\n";

		self::assertSame( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', GitBaseline::parse_packed_refs( $packed, 'refs/heads/main' ) );
		self::assertNull( GitBaseline::parse_packed_refs( $packed, 'refs/heads/missing' ) );
	}

	public function test_a_plain_git_directory_with_a_loose_ref_resolves(): void {
		$root = $this->make_repo(
			array(
				'.git/HEAD'            => "ref: refs/heads/main\n",
				'.git/refs/heads/main' => "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
			)
		);

		self::assertSame( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ( new GitBaseline( $root ) )->head_commit() );
	}

	public function test_a_packed_ref_resolves_when_no_loose_ref_exists(): void {
		$root = $this->make_repo(
			array(
				'.git/HEAD'        => "ref: refs/heads/main\n",
				'.git/packed-refs' => "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa refs/heads/main\n",
			)
		);

		self::assertSame( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ( new GitBaseline( $root ) )->head_commit() );
	}

	public function test_a_worktree_gitdir_file_resolves_through_commondir(): void {
		// The layout the migration's own branch model produces.
		$root = $this->make_repo(
			array(
				'main/.git/packed-refs'                => "dddddddddddddddddddddddddddddddddddddddd refs/heads/feat/bt-task-2\n",
				'main/.git/worktrees/task-2/HEAD'      => "ref: refs/heads/feat/bt-task-2\n",
				'main/.git/worktrees/task-2/commondir' => "../..\n",
				'wt/.git'                              => "gitdir: {ROOT}/main/.git/worktrees/task-2\n",
			)
		);

		$baseline = new GitBaseline( $root . '/wt' );

		self::assertSame( $root . '/main/.git/worktrees/task-2', $baseline->git_dir() );
		self::assertSame( $root . '/main/.git', $baseline->git_common_dir() );
		self::assertSame( 'dddddddddddddddddddddddddddddddddddddddd', $baseline->head_commit() );
	}

	public function test_a_detached_head_in_a_worktree_resolves(): void {
		$root = $this->make_repo(
			array(
				'main/.git/worktrees/task-2/HEAD'      => "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee\n",
				'main/.git/worktrees/task-2/commondir' => "../..\n",
				'wt/.git'                              => "gitdir: {ROOT}/main/.git/worktrees/task-2\n",
			)
		);

		self::assertSame( 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', ( new GitBaseline( $root . '/wt' ) )->head_commit() );
	}

	public function test_a_checkout_with_no_git_metadata_reports_null(): void {
		self::assertNull( ( new GitBaseline( $this->make_repo( array( 'composer.json' => '{}' ) ) ) )->head_commit() );
	}

	/**
	 * Creates a unique temporary directory, writes each file (creating
	 * parent directories, replacing the literal `{ROOT}` token with the
	 * temporary directory's absolute path), registers a tear_down cleanup,
	 * and returns the path.
	 *
	 * @param array<string, string> $files relative path => contents
	 * @return string The absolute path of the created fixture directory.
	 */
	private function make_repo( array $files ): string {
		$root = sys_get_temp_dir() . '/git-baseline-' . uniqid( '', true );
		mkdir( $root, 0777, true );

		foreach ( $files as $relative_path => $contents ) {
			$path      = $root . '/' . $relative_path;
			$directory = dirname( $path );

			if ( ! is_dir( $directory ) ) {
				mkdir( $directory, 0777, true );
			}

			file_put_contents( $path, str_replace( '{ROOT}', $root, $contents ) );
		}

		$this->temp_dirs[] = $root;

		return $root;
	}

	protected function tearDown(): void {
		foreach ( $this->temp_dirs as $path ) {
			$this->delete_tree( $path );
		}

		parent::tearDown();
	}

	private function delete_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item instanceof \SplFileInfo && $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( (string) $item );
			}
		}

		rmdir( $path );
	}
}
