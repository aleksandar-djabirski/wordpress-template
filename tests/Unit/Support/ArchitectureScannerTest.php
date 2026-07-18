<?php
/**
 * Unit tests for the shared architecture scanner. Exercises the tokenizer
 * logic against tiny fixtures written to temp files so we can prove the
 * hard cases the architecture suite relies on: closures in hooks are caught,
 * named callables are not, and commented-out / docblock references never
 * false-positive.
 *
 * This test does raw filesystem I/O (mkdir/file_put_contents/unlink/rmdir)
 * to build and tear down fixture trees — the WordPress filesystem
 * abstractions the AlternativeFunctions sniff points to don't exist in this
 * WordPress-free suite, so that sniff is disabled for this file only.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package Tests\Unit\Support
 */

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\ArchitectureScanner;

require_once dirname( __DIR__, 2 ) . '/support/ArchitectureScanner.php';

/**
 * @covers \Tests\Support\ArchitectureScanner
 */
final class ArchitectureScannerTest extends TestCase {

	private string $workdir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->workdir = sys_get_temp_dir() . '/arch-scanner-' . uniqid( '', true );
		mkdir( $this->workdir, 0777, true );
	}

	protected function tearDown(): void {
		$this->delete_tree( $this->workdir );

		parent::tearDown();
	}

	public function test_php_files_recurses_and_skips_dependency_and_build_dirs(): void {
		$this->write( 'a.php', "<?php\n" );
		$this->write( 'sub/b.php', "<?php\n" );
		$this->write( 'notes.txt', 'plain text' );
		$this->write( 'vendor/c.php', "<?php\n" );
		$this->write( 'node_modules/d.php', "<?php\n" );
		$this->write( 'blocks/example/build/e.php', "<?php\n" );

		$found = ArchitectureScanner::php_files( $this->workdir );

		$relative = array_map(
			fn( string $path ): string => str_replace( $this->workdir . '/', '', str_replace( '\\', '/', $path ) ),
			$found
		);

		self::assertContains( 'a.php', $relative );
		self::assertContains( 'sub/b.php', $relative );
		self::assertNotContains( 'notes.txt', $relative );
		self::assertNotContains( 'vendor/c.php', $relative );
		self::assertNotContains( 'node_modules/d.php', $relative );
		self::assertNotContains( 'blocks/example/build/e.php', $relative );
	}

	public function test_closure_in_hook_is_detected(): void {
		$file = $this->write(
			'closure.php',
			implode(
				"\n",
				array(
					'<?php',
					"add_action( 'init', function () {} );",
				)
			)
		);

		$violations = ArchitectureScanner::tokens_contain_closure_hook( $file );

		self::assertCount( 1, $violations );
		self::assertSame( 2, $violations[0]['line'] );
		self::assertSame( 'add_action', $violations[0]['hook'] );
	}

	public function test_static_closure_and_arrow_function_hooks_are_detected(): void {
		$file = $this->write(
			'static-closure.php',
			implode(
				"\n",
				array(
					'<?php',
					"add_action( 'save_post', static function () {} );",
					"add_filter( 'the_content', fn( \$c ) => \$c );",
				)
			)
		);

		$violations = ArchitectureScanner::tokens_contain_closure_hook( $file );

		self::assertCount( 2, $violations );
	}

	public function test_named_callable_hook_is_not_flagged(): void {
		$file = $this->write(
			'named.php',
			implode(
				"\n",
				array(
					'<?php',
					"add_action( 'init', array( self::class, 'setup' ) );",
					"add_filter( 'the_title', array( \$this, 'filter_title' ) );",
					"add_action( 'wp_footer', 'some_named_function' );",
				)
			)
		);

		self::assertSame( array(), ArchitectureScanner::tokens_contain_closure_hook( $file ) );
	}

	public function test_commented_out_closure_hook_is_ignored(): void {
		$file = $this->write(
			'commented.php',
			implode(
				"\n",
				array(
					'<?php',
					"// add_action( 'init', function () {} );",
					"/* add_action( 'init', function () {} ); */",
					"add_action( 'init', array( self::class, 'boot' ) );",
				)
			)
		);

		self::assertSame( array(), ArchitectureScanner::tokens_contain_closure_hook( $file ) );
	}

	public function test_closure_in_first_argument_is_not_mistaken_for_callback(): void {
		$file = $this->write(
			'first-arg.php',
			implode(
				"\n",
				array(
					'<?php',
					"add_action( wrap( function () {} ), array( \$this, 'run' ) );",
				)
			)
		);

		self::assertSame( array(), ArchitectureScanner::tokens_contain_closure_hook( $file ) );
	}

	public function test_symbol_reference_in_code_is_detected_but_ignored_in_docblock(): void {
		$file = $this->write(
			'wc.php',
			implode(
				"\n",
				array(
					'<?php',
					'/**',
					' * Mentions WooCommerce in prose only.',
					' */',
					"if ( class_exists( 'WooCommerce' ) ) {",
					'	return;',
					'}',
					'// WooCommerce in a line comment too.',
				)
			)
		);

		$matches = ArchitectureScanner::find_symbol_references( $file, array( '/\bWooCommerce\b/' ) );

		self::assertCount( 1, $matches );
		self::assertSame( 5, $matches[0]['line'] );
		self::assertSame( 'WooCommerce', $matches[0]['match'] );
	}

	public function test_outbound_http_calls_are_detected(): void {
		$file = $this->write(
			'http.php',
			implode(
				"\n",
				array(
					'<?php',
					'use GuzzleHttp\Client;',
					'wp_remote_post( $url, array() );',
					"file_get_contents( 'http://example.test/feed' );",
				)
			)
		);

		$calls = ArchitectureScanner::outbound_http_calls( $file );
		$names = array_column( $calls, 'call' );

		self::assertContains( 'GuzzleHttp', $names );
		self::assertContains( 'wp_remote_post', $names );
		self::assertContains( 'file_get_contents(http)', $names );
	}

	public function test_local_file_get_contents_and_commented_http_are_not_flagged(): void {
		$file = $this->write(
			'local.php',
			implode(
				"\n",
				array(
					'<?php',
					"file_get_contents( __DIR__ . '/local.json' );",
					"file_get_contents( '/etc/hostname' );",
					'// wp_remote_get( $url ) is described here but not called.',
				)
			)
		);

		self::assertSame( array(), ArchitectureScanner::outbound_http_calls( $file ) );
	}

	private function write( string $relative_path, string $contents ): string {
		$path      = $this->workdir . '/' . $relative_path;
		$directory = dirname( $path );

		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0777, true );
		}

		file_put_contents( $path, $contents );

		return $path;
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
