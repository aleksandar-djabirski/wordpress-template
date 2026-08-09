<?php
/**
 * Enforces the native block theme's file contract: HTML templates and parts
 * only, flat parts/, every referenced part present and declared in theme.json,
 * and no environment-specific record IDs baked into Git-owned markup.
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\FormatsArchitectureFailures;

require_once dirname( __DIR__ ) . '/support/FormatsArchitectureFailures.php';

final class BlockThemeStructureTest extends TestCase {

	use FormatsArchitectureFailures;

	/**
	 * The BASE-PROFILE templates: the request types the theme must keep
	 * covering after the conversion, one block template each, matching the
	 * classic hierarchy it replaces.
	 *
	 * This is a MINIMUM, never a closed set. The commerce profile adds its own
	 * block templates beside these (single-product, archive-product,
	 * taxonomy-product_cat, page-cart, page-checkout, order-confirmation and
	 * friends), and a client project adds its own. Every test below therefore
	 * checks "each of these exists" and "every file here matches the template
	 * naming and format contract" — never "these and nothing else".
	 *
	 * @var string[]
	 */
	private const BASE_PROFILE_TEMPLATES = array( '404', 'archive', 'index', 'page', 'search', 'single' );

	/**
	 * Template and part filenames must be lowercase, hyphen-or-underscore
	 * separated, and .html. Underscores are permitted because WordPress's own
	 * template hierarchy uses them for taxonomy templates
	 * (taxonomy-product_cat.html).
	 */
	private const FILENAME_PATTERN = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*\.html$/';

	/**
	 * @var string[]
	 */
	private const REQUIRED_PARTS = array( 'site-footer', 'site-header' );

	/**
	 * Git-owned templates and parts must not hard-code a database record id.
	 * A `ref` binds a Navigation or synced-pattern row that exists only in one
	 * environment; promoting or deploying that file elsewhere silently points
	 * at the wrong record (or nothing). Add a slug here only with a written
	 * review note.
	 *
	 * @var string[]
	 */
	private const HARDCODED_REF_ALLOWLIST = array();

	private function theme(): string {
		return $this->repo_root() . '/web/app/themes/site-theme';
	}

	public function test_the_theme_is_a_block_theme(): void {
		self::assertFileExists(
			$this->theme() . '/templates/index.html',
			$this->architecture_failure(
				'templates/index.html is missing',
				'web/app/themes/site-theme/templates/index.html',
				'templates/index.html is the file WordPress uses to decide a theme is a block theme (WP_Theme::is_block_theme()); without it the Site Editor and every block template are unavailable.',
				'Add templates/index.html with the posts-index block markup.'
			)
		);
	}

	public function test_every_base_profile_template_exists_as_html(): void {
		foreach ( self::BASE_PROFILE_TEMPLATES as $slug ) {
			self::assertFileExists(
				$this->theme() . '/templates/' . $slug . '.html',
				$this->architecture_failure(
					'Required base-profile block template is missing',
					'web/app/themes/site-theme/templates/' . $slug . '.html',
					'Each classic template the theme used to ship needs a block equivalent, or that request type falls back to index.html and loses its behaviour.',
					'Add templates/' . $slug . '.html with block markup only.'
				)
			);
		}
	}

	/**
	 * Deliberately an ALLOW-PATTERN, not an enumeration: additional templates
	 * (the commerce profile's product/cart/checkout templates, a client
	 * project's own) are expected and must not need an edit here.
	 */
	public function test_every_template_file_matches_the_naming_and_format_contract(): void {
		foreach ( $this->entries( $this->theme() . '/templates' ) as $entry ) {
			self::assertDirectoryDoesNotExist(
				$this->theme() . '/templates/' . $entry,
				$this->architecture_failure(
					'Nested directory under templates/',
					'web/app/themes/site-theme/templates/' . $entry,
					'WordPress resolves block templates as flat templates/<slug>.html files; a nested directory is never loaded. In particular, WooCommerce block overrides belong at templates/single-product.html, NOT templates/woocommerce/single-product.html.',
					'Move the markup to templates/<slug>.html and delete the directory.'
				)
			);

			self::assertMatchesRegularExpression(
				self::FILENAME_PATTERN,
				$entry,
				$this->architecture_failure(
					'Template filename breaks the block-template contract',
					'web/app/themes/site-theme/templates/' . $entry,
					'A block theme has exactly one rendering path, and WordPress matches templates by lowercase slug filename; a PHP file here would resurrect the classic path the migration removed, and a mixed-case or oddly named file is silently never matched.',
					'Rename to a lowercase hyphen/underscore-separated .html file, or delete it.'
				)
			);
		}
	}

	public function test_parts_directory_is_flat_and_html_only(): void {
		$parts = $this->theme() . '/parts';

		foreach ( $this->entries( $parts ) as $entry ) {
			self::assertFileExists( $parts . '/' . $entry );

			self::assertDirectoryDoesNotExist(
				$parts . '/' . $entry,
				$this->architecture_failure(
					'Nested directory under parts/',
					'web/app/themes/site-theme/parts/' . $entry,
					'WordPress resolves template parts as flat parts/<slug>.html files; a nested directory is never loaded.',
					'Move the markup into parts/' . $entry . '.html and delete the directory.'
				)
			);

			self::assertMatchesRegularExpression(
				self::FILENAME_PATTERN,
				$entry,
				$this->architecture_failure(
					'Part filename breaks the template-part contract',
					'web/app/themes/site-theme/parts/' . $entry,
					'Template parts are lowercase-slug HTML block markup; PHP or CSS here belongs to the removed classic part convention.',
					'Move styles into assets/global/shared.css and markup into parts/<slug>.html.'
				)
			);
		}

		foreach ( self::REQUIRED_PARTS as $slug ) {
			self::assertFileExists( $parts . '/' . $slug . '.html' );
		}
	}

	public function test_every_referenced_template_part_exists_and_is_declared(): void {
		$declared = $this->declared_template_parts();

		foreach ( $this->markup_files() as $file ) {
			preg_match_all( '/"slug"\s*:\s*"([a-z0-9-]+)"/', $this->read( $file ), $matches );

			foreach ( $matches[1] as $slug ) {
				self::assertFileExists(
					$this->theme() . '/parts/' . $slug . '.html',
					$this->architecture_failure(
						'Referenced template part does not exist',
						$this->to_relative( $file ),
						'A template-part reference with no file renders nothing and gives the client an empty, unfixable area in the Site Editor.',
						'Add parts/' . $slug . '.html, or correct the slug in this file.'
					)
				);

				self::assertContains(
					$slug,
					$declared,
					$this->architecture_failure(
						'Template part is not declared in theme.json',
						'web/app/themes/site-theme/theme.json',
						'The Site Editor lists and names template parts from theme.json.templateParts; an undeclared part is unnamed and unassigned to an area.',
						'Add { "name": "' . $slug . '", "title": "...", "area": "..." } to theme.json templateParts.'
					)
				);
			}
		}
	}

	public function test_every_declared_template_part_has_a_file(): void {
		foreach ( $this->declared_template_parts() as $slug ) {
			self::assertFileExists( $this->theme() . '/parts/' . $slug . '.html' );
		}
	}

	public function test_no_hardcoded_database_refs_in_git_owned_markup(): void {
		foreach ( $this->markup_files() as $file ) {
			$slug = pathinfo( $file, PATHINFO_FILENAME );
			/** @var string[] $allowlist */
			$allowlist = self::HARDCODED_REF_ALLOWLIST;

			if ( in_array( $slug, $allowlist, true ) ) {
				continue;
			}

			self::assertDoesNotMatchRegularExpression(
				'/"ref"\s*:\s*\d+/',
				$this->read( $file ),
				$this->architecture_failure(
					'Hard-coded database record id in a Git-owned template',
					$this->to_relative( $file ),
					'A "ref" binds a Navigation or synced-pattern row that exists only in the environment it was authored in; deploying or promoting that file elsewhere points it at the wrong record or nothing at all.',
					'Use a ref-less block (core/navigation resolves the site\'s navigation at render time), or add this slug to HARDCODED_REF_ALLOWLIST with a review note.'
				)
			);
		}
	}

	public function test_git_owned_pattern_markup_is_included_in_ref_guard(): void {
		$method = new \ReflectionMethod( $this, 'markup_files' );
		$files  = $method->invoke( $this );

		self::assertContains(
			$this->theme() . '/patterns/content-page.php',
			$files,
			'Git-owned pattern markup must be checked for database-bound ref attributes.'
		);
	}

	public function test_no_classic_php_remains_under_templates_or_parts(): void {
		foreach ( array( '/templates', '/parts' ) as $directory ) {
			foreach ( $this->entries( $this->theme() . $directory ) as $entry ) {
				self::assertStringEndsNotWith( '.php', $entry, $this->to_relative( $this->theme() . $directory . '/' . $entry ) . ' must not exist in a block theme.' );
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function declared_template_parts(): array {
		$decoded = json_decode( $this->read( $this->theme() . '/theme.json' ), true );

		self::assertIsArray( $decoded, 'theme.json must be valid JSON.' );

		$slugs = array();

		foreach ( (array) ( $decoded['templateParts'] ?? array() ) as $part ) {
			$slugs[] = (string) ( $part['name'] ?? '' );
		}

		sort( $slugs );

		return $slugs;
	}

	/**
	 * @return list<string>
	 */
	private function markup_files(): array {
		$files = array();

		foreach ( array( '/templates', '/parts', '/patterns' ) as $directory ) {
			foreach ( $this->entries( $this->theme() . $directory ) as $entry ) {
				$files[] = $this->theme() . $directory . '/' . $entry;
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * @return list<string>
	 */
	private function entries( string $dir ): array {
		$entries = is_dir( $dir ) ? scandir( $dir ) : false;

		if ( false === $entries ) {
			return array();
		}

		return array_values(
			array_filter( $entries, static fn( string $entry ): bool => '.' !== $entry && '..' !== $entry )
		);
	}

	private function read( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- static source inspection in the architecture suite; no WordPress runtime here.
		$source = file_get_contents( $file );

		return false === $source ? '' : $source;
	}
}
