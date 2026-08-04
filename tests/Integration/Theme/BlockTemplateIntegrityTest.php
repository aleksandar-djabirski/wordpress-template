<?php
/**
 * Parses and renders every block template and part with WordPress loaded, so a
 * typo in hand-authored block markup fails a test rather than silently
 * rendering an empty region on a client's site.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Theme;

use Tests\Integration\IntegrationTestCase;

final class BlockTemplateIntegrityTest extends IntegrationTestCase {

	/**
	 * Block namespaces that belong to an OPTIONAL profile this suite may not
	 * load. Only these may cause a template or part to be skipped, and only
	 * when the namespace really has no registered blocks in the current run.
	 * Anything else that is unregistered is a bug, not a profile.
	 *
	 * Adding an entry here is a deliberate act: it silences a whole file, so it
	 * needs the same review a woocommerce-allowlist.php entry does.
	 *
	 * @var string[]
	 */
	private const FOREIGN_PROFILE_NAMESPACES = array( 'woocommerce' );

	private function theme(): string {
		return dirname( __DIR__, 3 ) . '/web/app/themes/site-theme';
	}

	/**
	 * Every template and part on disk, including files that belong to a
	 * profile this suite does not load.
	 *
	 * @return list<string>
	 */
	private function all_markup_files(): array {
		$files = array();

		foreach ( array( '/templates', '/parts' ) as $directory ) {
			$found = glob( $this->theme() . $directory . '/*.html' );
			$files = array_merge( $files, false === $found ? array() : $found );
		}

		sort( $files );

		return $files;
	}

	/**
	 * PROFILE SCOPING (Â§11.12: "every referenced block is registered FOR THE
	 * TESTED PROFILE").
	 *
	 * The base `integration` suite never loads WooCommerce, so a commerce
	 * profile's templates/single-product.html legitimately references blocks
	 * that are not registered here. Failing on those would make the base suite
	 * red the moment the commerce track lands its templates.
	 *
	 * The rule is deliberately NARROW: a file is skipped only when it
	 * references a namespace on the EXPLICIT, named FOREIGN_PROFILE_NAMESPACES
	 * list AND that namespace has no registered blocks in this run. A
	 * mis-spelled `cor/paragraph` therefore does not skip the file — `cor` is
	 * not a known profile namespace, so the file stays in scope and
	 * test_every_referenced_block_is_registered_for_this_profile() fails on it,
	 * which is exactly what should happen.
	 *
	 * @return list<string>
	 */
	private function markup_files(): array {
		$inactive = array_values( array_diff( self::FOREIGN_PROFILE_NAMESPACES, $this->active_namespaces() ) );

		if ( array() === $inactive ) {
			return $this->all_markup_files();
		}

		$files = array();

		foreach ( $this->all_markup_files() as $file ) {
			$foreign = false;

			foreach ( $this->flatten( parse_blocks( $this->read( $file ) ) ) as $block ) {
				if ( ! is_string( $block['blockName'] ) ) {
					continue;
				}

				$separator = strpos( $block['blockName'], '/' );
				$namespace = false === $separator ? '' : substr( $block['blockName'], 0, $separator );

				if ( in_array( $namespace, $inactive, true ) ) {
					$foreign = true;
					break;
				}
			}

			if ( ! $foreign ) {
				$files[] = $file;
			}
		}

		return $files;
	}

	/**
	 * @return list<string> Block namespaces with at least one registered block.
	 */
	private function active_namespaces(): array {
		$namespaces = array();

		foreach ( array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ) as $block_name ) {
			$separator = strpos( (string) $block_name, '/' );

			if ( false !== $separator ) {
				$namespaces[] = substr( (string) $block_name, 0, $separator );
			}
		}

		return array_values( array_unique( $namespaces ) );
	}

	private function read( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a theme file from disk in an integration test.
		$source = file_get_contents( $file );

		return false === $source ? '' : $source;
	}

	public function test_wordpress_recognises_the_theme_as_a_block_theme(): void {
		self::assertTrue( wp_is_block_theme() );
		self::assertSame( 'site-theme', get_stylesheet() );
	}

	public function test_every_template_and_part_parses_into_named_blocks_only(): void {
		// Profile-independent: raw markup outside any block is wrong in every
		// profile, so this one scans EVERY file on disk.
		foreach ( $this->all_markup_files() as $file ) {
			foreach ( $this->flatten( parse_blocks( $this->read( $file ) ) ) as $block ) {
				if ( null !== $block['blockName'] ) {
					continue;
				}

				self::assertSame(
					'',
					trim( (string) $block['innerHTML'] ),
					basename( $file ) . ' contains raw markup outside any block: ' . trim( (string) $block['innerHTML'] )
				);
			}
		}
	}

	/**
	 * Â§11.12: "confirm every referenced block is registered FOR THE TESTED
	 * PROFILE". markup_files() drops files whose blocks belong to a profile
	 * this suite does not load (see its docblock); every remaining block must
	 * be registered here.
	 */
	public function test_every_referenced_block_is_registered_for_this_profile(): void {
		$registry = \WP_Block_Type_Registry::get_instance();

		self::assertNotEmpty( $this->markup_files(), 'At least the base-profile templates must be in scope for this profile.' );

		foreach ( $this->markup_files() as $file ) {
			foreach ( $this->flatten( parse_blocks( $this->read( $file ) ) ) as $block ) {
				if ( null === $block['blockName'] ) {
					continue;
				}

				self::assertTrue(
					$registry->is_registered( $block['blockName'] ),
					basename( $file ) . ' references the unregistered block ' . $block['blockName'] . '.'
				);
			}
		}
	}

	public function test_every_referenced_template_part_file_exists(): void {
		// Profile-independent: a missing part file is broken in every profile.
		foreach ( $this->all_markup_files() as $file ) {
			foreach ( $this->flatten( parse_blocks( $this->read( $file ) ) ) as $block ) {
				if ( 'core/template-part' !== $block['blockName'] ) {
					continue;
				}

				$slug = (string) ( $block['attrs']['slug'] ?? '' );

				self::assertFileExists( $this->theme() . '/parts/' . $slug . '.html' );
			}
		}
	}

	/**
	 * A MINIMUM set, never a closed one — the commerce profile and client
	 * projects add their own templates, and this test must not need an edit
	 * when they do.
	 */
	public function test_core_resolves_a_block_template_for_every_base_profile_slug(): void {
		$slugs = wp_list_pluck( get_block_templates(), 'slug' );

		foreach ( array( '404', 'archive', 'index', 'page', 'search', 'single' ) as $slug ) {
			self::assertContains( $slug, $slugs );
		}
	}

	public function test_core_resolves_both_template_parts(): void {
		$slugs = wp_list_pluck( get_block_templates( array(), 'wp_template_part' ), 'slug' );

		self::assertContains( 'site-header', $slugs );
		self::assertContains( 'site-footer', $slugs );
	}

	public function test_every_template_and_part_renders_without_a_fatal(): void {
		foreach ( $this->markup_files() as $file ) {
			$rendered = do_blocks( $this->read( $file ) );

			self::assertIsString( $rendered, basename( $file ) . ' must render to a string.' );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return list<array<string, mixed>>
	 */
	private function flatten( array $blocks ): array {
		$flat = array();

		foreach ( $blocks as $block ) {
			$flat[] = $block;

			foreach ( $this->flatten( (array) ( $block['innerBlocks'] ?? array() ) ) as $nested ) {
				$flat[] = $nested;
			}
		}

		return $flat;
	}
}
