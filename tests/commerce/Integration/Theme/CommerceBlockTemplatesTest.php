<?php
/**
 * Proves the theme's commerce block templates are real, resolvable block
 * templates in the commerce profile: they parse, every block they name is
 * registered, every template part they reference exists, and WordPress
 * resolves each slug to the THEME's file rather than the plugin's default.
 *
 * Runs only in the `commerce-integration` suite, which is the only place
 * WooCommerce is loaded.
 *
 * @package Tests\Commerce\Integration
 */

declare(strict_types=1);

namespace Tests\Commerce\Integration\Theme;

use Tests\Integration\IntegrationTestCase;

final class CommerceBlockTemplatesTest extends IntegrationTestCase {

	/**
	 * @return array{templates: list<string>, excluded: array<string, string>}
	 */
	private function declared(): array {
		/** @var array{templates: list<string>, excluded: array<string, string>} $declared */
		$declared = require dirname( __DIR__, 3 ) . '/Architecture/commerce-template-list.php';

		return $declared;
	}

	private function theme_templates_dir(): string {
		return dirname( __DIR__, 4 ) . '/web/app/themes/site-theme/templates';
	}

	/**
	 * Every block name a template names, flattened through inner blocks.
	 *
	 * @param list<array<string, mixed>> $blocks
	 * @return list<string>
	 */
	private function block_names( array $blocks ): array {
		$names = array();

		foreach ( $blocks as $block ) {
			if ( is_string( $block['blockName'] ) && '' !== $block['blockName'] ) {
				$names[] = $block['blockName'];
			}

			if ( is_array( $block['innerBlocks'] ) && array() !== $block['innerBlocks'] ) {
				$names = array_merge( $names, $this->block_names( $block['innerBlocks'] ) );
			}
		}

		return array_values( array_unique( $names ) );
	}

	public function test_every_declared_template_parses_into_named_blocks(): void {
		foreach ( $this->declared()['templates'] as $slug ) {
			$path = $this->theme_templates_dir() . '/' . $slug . '.html';

			self::assertFileExists( $path );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local on-disk read of theme template files; wp_remote_get() is for remote URLs, not on-disk reads.
			$blocks = parse_blocks( (string) file_get_contents( $path ) );
			$names  = $this->block_names( $blocks );

			self::assertNotSame( array(), $names, "templates/{$slug}.html must contain block markup." );
		}
	}

	public function test_every_referenced_block_is_registered_in_the_commerce_profile(): void {
		$registry     = \WP_Block_Type_Registry::get_instance();
		$unregistered = array();

		foreach ( $this->declared()['templates'] as $slug ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local on-disk read of theme template files; wp_remote_get() is for remote URLs, not on-disk reads.
			$blocks = parse_blocks( (string) file_get_contents( $this->theme_templates_dir() . '/' . $slug . '.html' ) );

			foreach ( $this->block_names( $blocks ) as $name ) {
				if ( null === $registry->get_registered( $name ) ) {
					$unregistered[] = $slug . ' -> ' . $name;
				}
			}
		}

		self::assertSame(
			array(),
			$unregistered,
			"A commerce template names a block that is not registered even with the commerce plugin active:\n" . implode( "\n", $unregistered )
		);
	}

	public function test_every_referenced_template_part_file_exists(): void {
		$missing = array();

		foreach ( $this->declared()['templates'] as $slug ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local on-disk read of theme template files; wp_remote_get() is for remote URLs, not on-disk reads.
			$content = (string) file_get_contents( $this->theme_templates_dir() . '/' . $slug . '.html' );

			preg_match_all( '/wp:template-part\s+\{[^}]*"slug":"([^"]+)"/', $content, $matches );

			foreach ( $matches[1] as $part_slug ) {
				$part_path = dirname( $this->theme_templates_dir() ) . '/parts/' . $part_slug . '.html';

				if ( ! is_file( $part_path ) ) {
					$missing[] = $slug . ' -> parts/' . $part_slug . '.html';
				}
			}
		}

		self::assertSame(
			array(),
			$missing,
			"A commerce template references a template part that does not exist:\n" . implode( "\n", $missing )
		);
	}

	public function test_the_theme_template_wins_over_the_plugin_default(): void {
		self::assertNotSame( array(), $this->declared()['templates'], 'The declared list must not be empty, or this check passes vacuously.' );

		foreach ( $this->declared()['templates'] as $slug ) {
			$template = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template' );

			self::assertNotNull( $template, "WordPress must resolve a block template for '{$slug}'." );
			self::assertSame(
				'theme',
				$template->source,
				"The theme's templates/{$slug}.html must win over the commerce plugin's default template; source is still '{$template->source}'."
			);
			// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- renders a nullable origin into a failure message; the string is never echoed outside a test failure.
			self::assertNull(
				$template->origin,
				"A theme-owned template has a null origin; '{$slug}' still reports origin '" . var_export( $template->origin, true ) . "'."
			);
			// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}
	}

	public function test_no_upstream_commerce_template_slug_is_unaccounted_for(): void {
		$declared    = $this->declared();
		$upstream    = $this->upstream_slugs();
		$unaccounted = array();

		foreach ( $upstream as $slug ) {
			if ( in_array( $slug, $declared['templates'], true ) ) {
				continue;
			}

			if ( array_key_exists( $slug, $declared['excluded'] ) ) {
				continue;
			}

			$unaccounted[] = $slug;
		}

		self::assertSame(
			array(),
			$unaccounted,
			"The commerce plugin ships block template slugs this theme has never decided about:\n"
			. implode( "\n", $unaccounted )
			. "\nAdd each to tests/Architecture/commerce-template-list.php — either as an owned template or as an excluded slug with a reason."
		);
	}

	/**
	 * The other direction: a slug this theme claims to override must still exist
	 * upstream. When the commerce plugin stops shipping a template, the theme's
	 * override becomes an unjustified fork of a template nothing renders — and
	 * the test above cannot see that, because it only walks upstream slugs.
	 */
	public function test_every_owned_slug_still_exists_upstream(): void {
		$upstream = $this->upstream_slugs();
		$orphans  = array();

		foreach ( $this->declared()['templates'] as $slug ) {
			if ( ! in_array( $slug, $upstream, true ) ) {
				$orphans[] = $slug;
			}
		}

		self::assertSame(
			array(),
			$orphans,
			"This theme overrides commerce template slugs the plugin no longer ships:\n"
			. implode( "\n", $orphans )
			. "\nDelete the override and its entry in tests/Architecture/commerce-template-list.php, or record why the fork is still wanted."
		);
	}

	/**
	 * Slugs the commerce plugin ships block templates for, read from ITS OWN
	 * template directory rather than from get_block_templates().
	 *
	 * get_block_templates() is the wrong source here twice over: it hides any
	 * plugin template the theme already overrides (so an owned slug could never
	 * be checked against upstream), and it includes templates registered by
	 * other plugins entirely. The directory is located by finding the file that
	 * every version of the plugin ships, so a plugin that relocates its
	 * templates fails loudly instead of emptying this check.
	 *
	 * @return list<string>
	 */
	private function upstream_slugs(): array {
		$directory = $this->upstream_template_dir();
		$slugs     = array();

		foreach ( (array) glob( $directory . '/*.html' ) as $file ) {
			if ( is_string( $file ) ) {
				$slugs[] = basename( $file, '.html' );
			}
		}

		sort( $slugs );

		self::assertNotSame(
			array(),
			$slugs,
			"No upstream block templates were found in {$directory}; this check must never pass vacuously."
		);

		return array_values( array_unique( $slugs ) );
	}

	private function upstream_template_dir(): string {
		$plugin_root = dirname( __DIR__, 4 ) . '/web/app/plugins/woocommerce';
		$anchor      = 'archive-product.html';

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $plugin_root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( $item instanceof \SplFileInfo && $item->isFile() && $anchor === $item->getFilename() ) {
				return $item->getPath();
			}
		}

		self::fail(
			"Could not locate {$anchor} under {$plugin_root}. The commerce plugin has moved its block templates; "
			. 'update this resolver and re-derive the theme overrides.'
		);
	}
}
