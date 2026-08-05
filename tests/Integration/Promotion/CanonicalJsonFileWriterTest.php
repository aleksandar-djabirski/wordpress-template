<?php
/**
 * The canonical JSON writer (plan Task 6): the ONLY writer Global Styles
 * uses for theme.json. It recursively key-sorts object maps while preserving
 * list order, then serialises with wp_json_encode() and one trailing newline.
 * It must never block-normalise the document — the staged theme.json carries
 * a core/navigation-shaped string value and CRLF inside a string, and both
 * must round-trip byte-for-byte unchanged; a single pass through block-markup
 * normalisation would strip the navigation ref and collapse the CRLF.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\Promotion\CanonicalJsonFileWriter;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Normalizer;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\CanonicalJsonFileWriter
 */
final class CanonicalJsonFileWriterTest extends IntegrationTestCase {

	/** A fixed, schema-valid promotion id so temp file names are deterministic. */
	private const PROMOTION_ID = '11111111-2222-4333-8444-555555555555';

	private string $theme_dir;

	public function set_up(): void {
		parent::set_up();

		$this->theme_dir = sys_get_temp_dir() . '/canonical-json-writer-' . uniqid( '', true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->theme_dir, 0755, true );
	}

	public function tear_down(): void {
		$this->remove_tree( $this->theme_dir );

		parent::tear_down();
	}

	public function test_commit_writes_the_document_without_block_normalisation(): void {
		$document = array(
			'version'          => 3,
			'templateParts'    => array(
				array(
					'name' => 'site-header',
					'area' => 'header',
				),
				array(
					'name' => 'site-footer',
					'area' => 'footer',
				),
			),
			'navigationMarkup' => '<!-- wp:navigation {"ref":42} /-->',
			'crlfInsideString' => "line one\r\nline two",
		);

		$writer = new CanonicalJsonFileWriter( $this->theme_dir );
		$writer->stage( 'theme.json', $document, self::PROMOTION_ID )->commit();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		$raw     = file_get_contents( $this->theme_dir . '/theme.json' );
		$decoded = json_decode( $raw, true );

		self::assertEquals( $document, $decoded, 'Decoding the committed file must return the staged document.' );
		self::assertSame(
			'<!-- wp:navigation {"ref":42} /-->',
			$decoded['navigationMarkup'],
			'A navigation ref inside a JSON string must survive — block-markup normalisation would strip it.'
		);
		self::assertSame(
			"line one\r\nline two",
			$decoded['crlfInsideString'],
			'CRLF inside a JSON string must survive — line-ending normalisation would collapse it.'
		);
		self::assertSame(
			array( 'site-header', 'site-footer' ),
			array_column( $decoded['templateParts'], 'name' ),
			'List order must be preserved; only object maps are key-sorted.'
		);
		self::assertLessThan(
			strpos( $raw, '"templateParts"' ),
			strpos( $raw, '"crlfInsideString"' ),
			'The committed file must be key-sorted: crlfInsideString comes before templateParts.'
		);
		self::assertStringEndsWith( "\n", $raw );
		self::assertStringEndsNotWith( "\n\n", $raw );
	}

	public function test_the_manifest_fields_describe_the_staged_file(): void {
		$document = array( 'version' => 3 );
		$writer   = new CanonicalJsonFileWriter( $this->theme_dir );
		$entry    = $writer->stage( 'theme.json', $document, self::PROMOTION_ID );

		$fields = $entry->manifest_fields();

		self::assertSame( 'theme.json', $fields['themeRelativePath'] );
		self::assertSame( $this->theme_dir . '/theme.json', $fields['absolutePath'] );
		self::assertNull( $fields['originalFileHash'] );
		self::assertNull( $fields['expectedPostResetHash'], 'theme.json is not block markup; no post-reset hash applies.' );

		$entry->commit();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		$raw = file_get_contents( $this->theme_dir . '/theme.json' );

		self::assertSame( hash( 'sha256', $raw ), $fields['preparedFileHash'] );
	}

	public function test_the_original_file_hash_captures_the_previous_bytes_at_stage_time(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing an integration fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->theme_dir . '/theme.json', '{"version":3}' );

		$writer = new CanonicalJsonFileWriter( $this->theme_dir );
		$entry  = $writer->stage(
			'theme.json',
			array(
				'version'  => 3,
				'settings' => array(),
			),
			self::PROMOTION_ID
		);

		self::assertSame( hash( 'sha256', '{"version":3}' ), $entry->manifest_fields()['originalFileHash'] );

		$entry->discard();

		self::assertSame(
			'{"version":3}',
			file_get_contents( $this->theme_dir . '/theme.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
			'discard() must leave the previous file untouched.'
		);
	}

	public function test_discard_after_stage_leaves_no_residue_and_is_safe_to_call_twice(): void {
		$writer = new CanonicalJsonFileWriter( $this->theme_dir );
		$entry  = $writer->stage( 'theme.json', array( 'version' => 3 ), self::PROMOTION_ID );

		$entry->discard();
		$entry->discard();

		self::assertSame( array(), $this->listing() );
		self::assertFalse( file_exists( $this->theme_dir . '/theme.json' ), 'discard() must never touch the final path.' );
	}

	public function test_commit_is_safe_to_call_twice(): void {
		$writer = new CanonicalJsonFileWriter( $this->theme_dir );
		$entry  = $writer->stage( 'theme.json', array( 'version' => 3 ), self::PROMOTION_ID );

		$entry->commit();
		$entry->commit();

		self::assertSame( array( 'theme.json' ), $this->listing() );
	}

	public function test_committing_the_same_document_again_produces_the_same_bytes(): void {
		$writer = new CanonicalJsonFileWriter( $this->theme_dir );
		$first  = $writer->stage(
			'theme.json',
			array(
				'version'         => 3,
				'customTemplates' => array(),
			),
			self::PROMOTION_ID
		);
		$second = $writer->stage(
			'other.json',
			array(
				'version'         => 3,
				'customTemplates' => array(),
			),
			'22222222-3333-4444-8555-666666666666'
		);

		$first->commit();
		$second->commit();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		$raw_first = file_get_contents( $this->theme_dir . '/theme.json' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		$raw_second = file_get_contents( $this->theme_dir . '/other.json' );

		self::assertSame( $raw_first, $raw_second, 'The canonical serialisation must be byte-stable across files.' );
	}

	public function test_stage_refuses_a_parent_traversal_that_escapes_the_theme_directory(): void {
		$writer = new CanonicalJsonFileWriter( $this->theme_dir );

		$this->assert_exit_code( 1, fn() => $writer->stage( '../evil.json', array( 'version' => 3 ), self::PROMOTION_ID ) );

		self::assertFalse( file_exists( dirname( $this->theme_dir ) . '/evil.json' ), 'A ".." traversal must never escape the theme directory.' );
		self::assertSame( array(), $this->listing() );
	}

	public function test_stage_refuses_an_absolute_path(): void {
		$writer = new CanonicalJsonFileWriter( $this->theme_dir );

		$this->assert_exit_code( 1, fn() => $writer->stage( '/etc/passwd', array( 'version' => 3 ), self::PROMOTION_ID ) );

		self::assertSame( array(), $this->listing() );
	}

	public function test_stage_refuses_a_path_whose_directory_does_not_exist(): void {
		$writer = new CanonicalJsonFileWriter( $this->theme_dir );

		$this->assert_exit_code( 1, fn() => $writer->stage( 'sub/theme.json', array( 'version' => 3 ), self::PROMOTION_ID ) );

		self::assertSame( array(), $this->listing() );
	}

	public function test_stage_refuses_a_promotion_id_that_is_not_a_uuid(): void {
		$writer = new CanonicalJsonFileWriter( $this->theme_dir );

		$this->assert_exit_code( 1, fn() => $writer->stage( 'theme.json', array( 'version' => 3 ), 'not-a-uuid' ) );

		self::assertSame( array(), $this->listing() );
	}

	/**
	 * @return list<string>
	 */
	private function listing(): array {
		return array_values( array_diff( scandir( $this->theme_dir ), array( '.', '..' ) ) );
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
