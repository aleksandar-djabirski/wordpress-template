<?php
/**
 * The staged prepared-file writer (plan Task 6). Prepared theme files live
 * inside the web root BY DESIGN (web/app/themes/<theme>/templates/*.html),
 * so this writer implements its own containment rule — a staged path must
 * stay inside the theme directory it was given and start with templates/
 * or parts/ — instead of the manifest store's web-root guard, which would
 * refuse every write this class makes. The containment guard is proven BY
 * ATTACK: every refusal asserts both the throw and that no file was created
 * anywhere.
 *
 * The two-phase contract is the whole point: stage() writes only a temp
 * file, nothing lands at its final path until commit_all(), and a failure
 * part-way through commit_all() rolls every already-renamed entry back to
 * its previous bytes (or removes a newly-created file). The rollback test
 * stages three entries and breaks the SECOND commit, so the happy path
 * alone can never satisfy it.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\Promotion\PreparedFileWriter;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\StateGateway;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\PreparedFileWriter
 */
final class PreparedFileWriterTest extends IntegrationTestCase {

	/** A fixed, schema-valid promotion id so temp file names are deterministic. */
	private const PROMOTION_ID = '11111111-2222-4333-8444-555555555555';

	private string $theme_dir;
	private StateGateway $gateway;
	private PreparedFileWriter $writer;

	public function set_up(): void {
		parent::set_up();

		$this->theme_dir = sys_get_temp_dir() . '/prepared-file-writer-' . uniqid( '', true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->theme_dir . '/templates', 0755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->theme_dir . '/parts', 0755, true );

		$this->gateway = new StateGateway();
		$this->writer  = new PreparedFileWriter( $this->gateway, $this->theme_dir );
	}

	public function tear_down(): void {
		$this->remove_tree( $this->theme_dir );

		parent::tear_down();
	}

	public function test_stage_writes_only_a_temp_file_and_leaves_the_target_untouched(): void {
		$body  = $this->writer->render( '<p>hello</p>' );
		$entry = $this->writer->stage( 'templates/page.html', '<p>hello</p>', self::PROMOTION_ID );

		self::assertSame(
			array( 'page.html.' . self::PROMOTION_ID . '.tmp' ),
			$this->listing( 'templates' ),
			'stage() must leave exactly one temp file and nothing else.'
		);
		self::assertSame( array(), $this->listing( 'parts' ) );
		self::assertFalse( file_exists( $this->theme_dir . '/templates/page.html' ), 'stage() must not touch the final path.' );
		self::assertSame(
			$body,
			file_get_contents( $this->theme_dir . '/templates/page.html.' . self::PROMOTION_ID . '.tmp' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
			'The temp file must hold the rendered body.'
		);

		$fields = $entry->manifest_fields();

		self::assertSame( 'templates/page.html', $fields['themeRelativePath'] );
		self::assertSame( $this->theme_dir . '/templates/page.html', $fields['absolutePath'] );
		self::assertSame( 64, strlen( $fields['preparedFileHash'] ) );
		self::assertSame( hash( 'sha256', $body ), $fields['preparedFileHash'] );
		self::assertNull( $fields['originalFileHash'] );
		self::assertSame( 64, strlen( $fields['expectedPostResetHash'] ) );
	}

	public function test_commit_all_leaves_exactly_one_file_per_record_and_no_temp_residue(): void {
		$first  = $this->writer->stage( 'templates/page.html', '<p>one</p>', self::PROMOTION_ID );
		$second = $this->writer->stage( 'parts/site-header.html', '<p>two</p>', self::PROMOTION_ID );

		$this->writer->commit_all( array( $first, $second ) );

		self::assertSame( array( 'page.html' ), $this->listing( 'templates' ) );
		self::assertSame( array( 'site-header.html' ), $this->listing( 'parts' ) );
	}

	public function test_the_written_body_ends_with_exactly_one_newline_and_contains_no_carriage_returns(): void {
		$entry = $this->writer->stage( 'templates/page.html', "<p>crlf</p>\r\n\r\n", self::PROMOTION_ID );

		$this->writer->commit_all( array( $entry ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		$raw = file_get_contents( $this->theme_dir . '/templates/page.html' );

		self::assertStringEndsWith( "\n", $raw );
		self::assertStringEndsNotWith( "\n\n", $raw );
		self::assertStringNotContainsString( "\r", $raw );
	}

	public function test_a_navigation_ref_is_stripped_from_the_written_body(): void {
		$entry = $this->writer->stage( 'templates/page.html', '<!-- wp:navigation {"ref":42,"layout":{"type":"flex"}} /-->', self::PROMOTION_ID );

		$this->writer->commit_all( array( $entry ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		$raw = file_get_contents( $this->theme_dir . '/templates/page.html' );

		self::assertStringNotContainsString( '"ref"', $raw );
		self::assertStringContainsString( 'wp:navigation', $raw );
	}

	public function test_a_template_part_theme_attribute_is_stripped_from_the_written_body(): void {
		$entry = $this->writer->stage( 'templates/page.html', '<!-- wp:template-part {"slug":"site-header","theme":"site-theme","area":"header"} /-->', self::PROMOTION_ID );

		$this->writer->commit_all( array( $entry ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		$raw = file_get_contents( $this->theme_dir . '/templates/page.html' );

		self::assertStringNotContainsString( '"theme"', $raw );
		self::assertStringContainsString( 'wp:template-part', $raw );
	}

	public function test_rendering_the_same_markup_produces_the_same_prepared_file_hash(): void {
		$markup = '<!-- wp:paragraph --><p>stable</p><!-- /wp:paragraph -->';
		$first  = $this->writer->stage( 'templates/a.html', $markup, self::PROMOTION_ID );
		$second = $this->writer->stage( 'templates/b.html', $markup, self::PROMOTION_ID );
		$body   = $this->writer->render( $markup );

		self::assertSame( $first->manifest_fields()['preparedFileHash'], $second->manifest_fields()['preparedFileHash'] );
		self::assertSame( $body, $this->writer->render( $markup ), 'render() must be byte-stable.' );
		self::assertSame( hash( 'sha256', $body ), $first->manifest_fields()['preparedFileHash'] );
	}

	public function test_discard_all_after_stage_restores_the_directory_to_its_original_listing(): void {
		$first  = $this->writer->stage( 'templates/a.html', '<p>one</p>', self::PROMOTION_ID );
		$second = $this->writer->stage( 'parts/site-header.html', '<p>two</p>', self::PROMOTION_ID );

		$this->writer->discard_all( array( $first, $second ) );

		self::assertSame( array(), $this->listing( 'templates' ) );
		self::assertSame( array(), $this->listing( 'parts' ) );

		$this->writer->discard_all( array( $first, $second ) );

		self::assertSame( array(), $this->listing( 'templates' ), 'discard_all() must be safe to call twice.' );
	}

	/**
	 * The rollback path, the one that runs on a bad day: three entries, the
	 * SECOND commit fails. The first entry must be back to its previous
	 * bytes, the third must never have been written, and no temp file may
	 * survive anywhere.
	 */
	public function test_a_failed_commit_all_rolls_back_every_already_renamed_entry(): void {
		$original = $this->writer->render( '<p>original</p>' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing an integration fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->theme_dir . '/templates/first.html', $original );

		$first  = $this->writer->stage( 'templates/first.html', '<p>one</p>', self::PROMOTION_ID );
		$second = $this->writer->stage( 'templates/blocked.html', '<p>two</p>', self::PROMOTION_ID );
		$third  = $this->writer->stage( 'templates/third.html', '<p>three</p>', self::PROMOTION_ID );

		// A directory at the second entry's final path makes its rename() fail
		// (EISDIR), even when the suite runs as root — a read-only chmod would
		// not. The @ suppresses PHP's rename warning, which is the expected
		// condition this test asserts the writer detects.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->theme_dir . '/templates/blocked.html', 0755 );

		$this->assert_exit_code(
			1,
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- rename() onto an existing directory raises a PHP warning by design; the test asserts that commit_all() DETECTS that failure, and the warning itself is the expected condition rather than a defect.
			fn() => @$this->writer->commit_all( array( $first, $second, $third ) )
		);

		self::assertSame(
			$original,
			file_get_contents( $this->theme_dir . '/templates/first.html' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
			'The already-committed entry must be rolled back to its previous bytes.'
		);
		self::assertDirectoryExists( $this->theme_dir . '/templates/blocked.html', 'The failing entry must not have committed.' );
		self::assertFalse( file_exists( $this->theme_dir . '/templates/third.html' ), 'The third entry must never have been written.' );
		self::assertSame(
			array( 'blocked.html', 'first.html' ),
			$this->listing( 'templates' ),
			'After a failed commit_all() no temp file may survive.'
		);
		self::assertSame( array(), $this->listing( 'parts' ) );
	}

	public function test_discard_is_safe_to_call_twice(): void {
		$entry = $this->writer->stage( 'templates/page.html', '<p>one</p>', self::PROMOTION_ID );

		$entry->discard();
		$entry->discard();

		self::assertSame( array(), $this->listing( 'templates' ) );
		self::assertFalse( file_exists( $this->theme_dir . '/templates/page.html' ), 'discard() must never touch the final path.' );
	}

	public function test_commit_is_safe_to_call_twice(): void {
		$entry = $this->writer->stage( 'templates/page.html', '<p>one</p>', self::PROMOTION_ID );

		$entry->commit();
		$entry->commit();

		self::assertSame( array( 'page.html' ), $this->listing( 'templates' ) );
		self::assertSame( array(), $this->listing( 'parts' ), 'A double commit() must not leave temp residue.' );
	}

	public function test_stage_refuses_a_parent_traversal_that_escapes_the_theme_directory(): void {
		$this->assert_exit_code( 1, fn() => $this->writer->stage( 'templates/../../wp-config.php', '<p>x</p>', self::PROMOTION_ID ) );

		self::assertFalse( file_exists( $this->theme_dir . '/wp-config.php' ), 'A ".." traversal must never escape the theme directory.' );
		self::assertSame( array(), $this->listing( 'templates' ) );
		self::assertSame( array(), $this->listing( 'parts' ) );
	}

	public function test_stage_refuses_an_absolute_path(): void {
		$this->assert_exit_code( 1, fn() => $this->writer->stage( '/etc/passwd', '<p>x</p>', self::PROMOTION_ID ) );

		self::assertSame( array(), $this->listing( 'templates' ) );
		self::assertSame( array(), $this->listing( 'parts' ) );
	}

	public function test_stage_refuses_a_deep_parent_traversal(): void {
		$this->assert_exit_code( 1, fn() => $this->writer->stage( '../../../../evil.html', '<p>x</p>', self::PROMOTION_ID ) );

		self::assertFalse( file_exists( dirname( $this->theme_dir ) . '/evil.html' ), 'A deep traversal must never create a file anywhere.' );
		self::assertSame( array(), $this->listing( 'templates' ) );
		self::assertSame( array(), $this->listing( 'parts' ) );
	}

	public function test_stage_refuses_a_path_outside_templates_and_parts(): void {
		$this->assert_exit_code( 1, fn() => $this->writer->stage( 'theme.json', '<p>x</p>', self::PROMOTION_ID ) );

		self::assertSame( array(), $this->listing( 'templates' ) );
		self::assertSame( array(), $this->listing( 'parts' ) );
	}

	public function test_stage_refuses_a_path_whose_directory_does_not_exist(): void {
		$this->assert_exit_code( 1, fn() => $this->writer->stage( 'templates/nested/page.html', '<p>x</p>', self::PROMOTION_ID ) );

		self::assertSame( array(), $this->listing( 'templates' ) );
	}

	public function test_stage_refuses_a_promotion_id_that_is_not_a_uuid(): void {
		$this->assert_exit_code( 1, fn() => $this->writer->stage( 'templates/page.html', '<p>x</p>', 'not-a-uuid' ) );

		self::assertSame( array(), $this->listing( 'templates' ) );
	}

	public function test_stage_writes_a_legitimate_template_path(): void {
		$entry = $this->writer->stage( 'templates/page.html', '<p>ok</p>', self::PROMOTION_ID );

		$this->writer->commit_all( array( $entry ) );

		self::assertFileExists( $this->theme_dir . '/templates/page.html' );
		self::assertSame( array( 'page.html' ), $this->listing( 'templates' ) );
	}

	/**
	 * @return list<string>
	 */
	private function listing( string $subdir ): array {
		return array_values( array_diff( scandir( $this->theme_dir . '/' . $subdir ), array( '.', '..' ) ) );
	}

	/**
	 * Asserts that the operation refuses with exactly the expected exit code,
	 * and returns the exception so the caller can also assert the message.
	 * Fails when no exception is thrown, when a non-PromotionException
	 * escapes, or when the code differs.
	 *
	 * @param callable():void $operation
	 */
	private function assert_exit_code( int $expected, callable $operation ): PromotionException {
		try {
			$operation();
		} catch ( PromotionException $exception ) {
			self::assertSame(
				$expected,
				$exception->exit_code(),
				sprintf( 'Expected exit code %d, got %d: %s', $expected, $exception->exit_code(), $exception->getMessage() )
			);

			return $exception;
		}

		self::fail( sprintf( 'Expected a PromotionException with exit code %d, but no exception was thrown.', $expected ) );
	}

	/**
	 * Removes an integration fixture directory and everything inside it.
	 */
	private function remove_tree( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		foreach ( scandir( $directory ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $directory . '/' . $entry;

			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_tree( $path );

				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- deleting an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		rmdir( $directory );
	}
}
