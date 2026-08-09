<?php
/**
 * Ensures the composite content-page pattern stays aligned with its source
 * component patterns.
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ContentPagePatternParityTest extends TestCase {

	public function test_content_page_documents_its_deliberate_component_copy(): void {
		self::assertStringContainsString(
			'Component markup below deliberately copies hero, split-content, feature-grid, and cta.',
			$this->read_pattern( 'content-page' )
		);
	}

	public function test_content_page_keeps_each_component_pattern_markup_in_sync(): void {
		$content_page = $this->read_pattern( 'content-page' );

		foreach ( array( 'hero', 'split-content', 'feature-grid', 'cta' ) as $pattern ) {
			self::assertStringContainsString(
				$this->pattern_markup( $pattern ),
				$content_page,
				'content-page.php must keep the copied ' . $pattern . '.php markup in sync.'
			);
		}
	}

	private function pattern_markup( string $pattern ): string {
		$sections = explode( '?>', $this->read_pattern( $pattern ), 2 );

		self::assertCount( 2, $sections, $pattern . '.php must contain a PHP header followed by block markup.' );

		return trim( $sections[1] );
	}

	private function read_pattern( string $pattern ): string {
		$path = dirname( __DIR__, 2 ) . '/web/app/themes/site-theme/patterns/' . $pattern . '.php';

		self::assertFileExists( $path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- architecture test reads a local pattern source without WordPress.
		$source = file_get_contents( $path );

		self::assertNotFalse( $source );

		return $source;
	}
}
