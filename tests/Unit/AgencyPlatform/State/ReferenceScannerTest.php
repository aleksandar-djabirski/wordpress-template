<?php
/**
 * The pure half of the reference pipeline: scan_parsed() walks already-parsed
 * block arrays (the shape parse_blocks() returns) and emits one reference per
 * matcher hit, always with the three target fields null — ReferenceResolver
 * (Task 9) fills them. This suite is WordPress-free by design: it builds block
 * trees by hand, so nothing here may call a WordPress function.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\ReferenceScanner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\ReferenceScanner
 */
final class ReferenceScannerTest extends TestCase {

	/**
	 * The array shape parse_blocks() produces, built by hand so this suite
	 * needs no WordPress. blockName is null for raw HTML, attrs is always an
	 * array, innerBlocks is always a list.
	 *
	 * @param array<string, mixed>       $attrs
	 * @param list<array<string, mixed>> $inner_blocks
	 * @return array<string, mixed>
	 */
	private function block( ?string $name, array $attrs = array(), array $inner_blocks = array() ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	public function test_a_navigation_ref_is_environment_specific(): void {
		$references = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/navigation', array( 'ref' => 12 ) ) ),
			'templates:page'
		);

		self::assertCount( 1, $references );
		self::assertSame( 'templates:page', $references[0]['record'] );
		self::assertSame( 'templates', $references[0]['provider'] );
		self::assertSame( 'core/navigation', $references[0]['blockName'] );
		self::assertSame( 'ref', $references[0]['attribute'] );
		self::assertSame( 12, $references[0]['value'] );
		self::assertSame( ReferenceScanner::KIND_NAVIGATION, $references[0]['kind'] );
		self::assertSame( ReferenceScanner::RESOLUTION_ENVIRONMENT, $references[0]['resolution'] );
		self::assertNotSame( '', $references[0]['policy'] );
		self::assertTrue( ReferenceScanner::is_unresolved( $references[0] ) );

		// scan_parsed() is pure: it always emits the three target fields as
		// null. ReferenceResolver fills them (integration-tested).
		self::assertNull( $references[0]['targetKey'] );
		self::assertNull( $references[0]['targetHash'] );
		self::assertNull( $references[0]['targetIdentity'] );
	}

	public function test_every_reference_carries_the_full_key_set_in_a_fixed_order(): void {
		$references = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/image', array( 'id' => 5 ) ) ),
			'templates:page'
		);

		self::assertSame(
			array( 'record', 'provider', 'blockName', 'attribute', 'value', 'kind', 'resolution', 'policy', 'targetKey', 'targetHash', 'targetIdentity' ),
			array_keys( $references[0] )
		);
	}

	/**
	 * The fixture needs at least two attributes the unknown-ref catch-all
	 * matches, and none a block-level matcher consumes: core/cover is a media
	 * block, so match_block_level() eats id before the attribute loop, and a
	 * customRef-style attribute matches nothing — a fixture of those would
	 * emit one reference and make this assertion vacuous. mediaId + someId
	 * are both emitted from inside the attribute loop, so traversal order
	 * genuinely changes emission unless sort_references() runs.
	 */
	public function test_reference_order_is_independent_of_attribute_map_order(): void {
		$forward = ReferenceScanner::scan_parsed(
			array(
				$this->block(
					'core/cover',
					array(
						'mediaId' => 9,
						'someId'  => 3,
					)
				),
			),
			'templates:page'
		);
		$reverse = ReferenceScanner::scan_parsed(
			array(
				$this->block(
					'core/cover',
					array(
						'someId'  => 3,
						'mediaId' => 9,
					)
				),
			),
			'templates:page'
		);

		self::assertCount( 2, $forward, 'A one-reference fixture makes this assertion vacuous.' );
		self::assertSame( array( 'mediaId', 'someId' ), array_column( $forward, 'attribute' ) );
		self::assertSame( $forward, $reverse, 'Attribute traversal order must not change a hashed field.' );
	}

	public function test_a_gallery_emits_one_reference_per_id(): void {
		$references = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/gallery', array( 'ids' => array( 7, 9 ) ) ) ),
			'templates:page'
		);

		self::assertSame( array( 7, 9 ), array_column( $references, 'value' ) );
		self::assertSame( array( 'ids', 'ids' ), array_column( $references, 'attribute' ) );
	}

	public function test_references_are_found_inside_nested_inner_blocks(): void {
		$references = ReferenceScanner::scan_parsed(
			array(
				$this->block(
					'core/group',
					array(),
					array( $this->block( 'core/columns', array(), array( $this->block( 'core/image', array( 'id' => 42 ) ) ) ) )
				),
			),
			'parts:site-header'
		);

		self::assertCount( 1, $references );
		self::assertSame( 42, $references[0]['value'] );
		self::assertSame( ReferenceScanner::KIND_ATTACHMENT, $references[0]['kind'] );
	}

	public function test_a_core_post_meta_binding_is_resolved_and_not_unresolved(): void {
		$references = ReferenceScanner::scan_parsed(
			array(
				$this->block(
					'core/paragraph',
					array(
						'metadata' => array(
							'bindings' => array(
								'content' => array(
									'source' => 'core/post-meta',
									'args'   => array( 'key' => 'subtitle' ),
								),
							),
						),
					)
				),
			),
			'content:page-1'
		);

		self::assertSame( ReferenceScanner::KIND_POST_META, $references[0]['kind'] );
		self::assertSame( ReferenceScanner::RESOLUTION_RESOLVED, $references[0]['resolution'] );
		self::assertSame( array(), ReferenceScanner::unresolved( $references ) );
	}

	public function test_a_core_block_synced_pattern_ref_is_environment_specific(): void {
		$references = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/block', array( 'ref' => 24 ) ) ),
			'templates:page'
		);

		self::assertSame( ReferenceScanner::KIND_SYNCED_PATTERN, $references[0]['kind'] );
		self::assertSame( 'ref', $references[0]['attribute'] );
		self::assertSame( 24, $references[0]['value'] );
		self::assertSame( ReferenceScanner::RESOLUTION_ENVIRONMENT, $references[0]['resolution'] );
		self::assertTrue( ReferenceScanner::is_unresolved( $references[0] ) );
	}

	public function test_a_core_image_id_is_an_attachment(): void {
		$references = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/image', array( 'id' => 5 ) ) ),
			'templates:page'
		);

		self::assertSame( ReferenceScanner::KIND_ATTACHMENT, $references[0]['kind'] );
		self::assertSame( 'id', $references[0]['attribute'] );
		self::assertSame( 5, $references[0]['value'] );
		self::assertSame( ReferenceScanner::RESOLUTION_ENVIRONMENT, $references[0]['resolution'] );
	}

	public function test_a_core_cover_id_is_an_attachment(): void {
		$references = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/cover', array( 'id' => 3 ) ) ),
			'templates:page'
		);

		self::assertSame( ReferenceScanner::KIND_ATTACHMENT, $references[0]['kind'] );
		self::assertSame( 'id', $references[0]['attribute'] );
		self::assertSame( 3, $references[0]['value'] );
		self::assertSame( ReferenceScanner::RESOLUTION_ENVIRONMENT, $references[0]['resolution'] );
	}

	public function test_a_core_site_logo_reference_has_a_null_value(): void {
		$references = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/site-logo' ) ),
			'templates:page'
		);

		self::assertSame( ReferenceScanner::KIND_SITE_LOGO, $references[0]['kind'] );
		self::assertSame( 'site_logo', $references[0]['attribute'] );
		self::assertNull( $references[0]['value'] );
		self::assertSame( ReferenceScanner::RESOLUTION_ENVIRONMENT, $references[0]['resolution'] );
		self::assertTrue( ReferenceScanner::is_unresolved( $references[0] ) );
	}

	public function test_a_font_path_attribute_is_a_font_file_reference(): void {
		$font_url   = 'https://cdn.example.com/fonts/Inter.woff2';
		$references = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/paragraph', array( 'fontFamily' => $font_url ) ) ),
			'templates:page'
		);

		self::assertSame( ReferenceScanner::KIND_FONT_FILE, $references[0]['kind'] );
		self::assertSame( 'fontFamily', $references[0]['attribute'] );
		self::assertSame( $font_url, $references[0]['value'] );
		self::assertSame( ReferenceScanner::RESOLUTION_ENVIRONMENT, $references[0]['resolution'] );
	}

	public function test_a_third_party_block_namespace_is_a_plugin_block(): void {
		$references = ReferenceScanner::scan_parsed(
			array( $this->block( 'vendor/custom-block' ) ),
			'templates:page'
		);

		self::assertSame( ReferenceScanner::KIND_PLUGIN_BLOCK, $references[0]['kind'] );
		self::assertSame( 'blockName', $references[0]['attribute'] );
		self::assertSame( 'vendor/custom-block', $references[0]['value'] );
		self::assertSame( ReferenceScanner::RESOLUTION_UNKNOWN, $references[0]['resolution'] );
		self::assertTrue( ReferenceScanner::is_unresolved( $references[0] ) );
	}

	public function test_a_bare_some_id_attribute_is_an_unknown_reference(): void {
		$references = ReferenceScanner::scan_parsed(
			array( $this->block( 'core/group', array( 'someId' => 7 ) ) ),
			'templates:page'
		);

		self::assertSame( ReferenceScanner::KIND_UNKNOWN_REF, $references[0]['kind'] );
		self::assertSame( 'someId', $references[0]['attribute'] );
		self::assertSame( 7, $references[0]['value'] );
		self::assertSame( ReferenceScanner::RESOLUTION_UNKNOWN, $references[0]['resolution'] );
	}

	public function test_unresolved_returns_only_the_non_resolved_entries(): void {
		$references    = ReferenceScanner::scan_parsed(
			array(
				$this->block(
					'core/paragraph',
					array(
						'metadata' => array(
							'bindings' => array(
								'content' => array(
									'source' => 'core/post-meta',
									'args'   => array( 'key' => 'subtitle' ),
								),
							),
						),
					)
				),
				$this->block( 'core/navigation', array( 'ref' => 12 ) ),
			),
			'content:page-1'
		);
		$resolved_only = ReferenceScanner::scan_parsed(
			array(
				$this->block(
					'core/paragraph',
					array(
						'metadata' => array(
							'bindings' => array(
								'content' => array(
									'source' => 'core/post-meta',
									'args'   => array( 'key' => 'subtitle' ),
								),
							),
						),
					)
				),
			),
			'content:page-1'
		);

		$unresolved = ReferenceScanner::unresolved( $references );

		self::assertCount( 1, $unresolved );
		self::assertSame( ReferenceScanner::KIND_NAVIGATION, $unresolved[0]['kind'] );
		self::assertTrue( ReferenceScanner::is_unresolved( $unresolved[0] ) );
		self::assertFalse( ReferenceScanner::is_unresolved( $resolved_only[0] ), 'The post-meta binding is resolved, so it must not count as unresolved.' );
	}
}
