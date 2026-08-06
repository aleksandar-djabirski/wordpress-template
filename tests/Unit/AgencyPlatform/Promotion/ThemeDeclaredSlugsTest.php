<?php
/**
 * The declared-slug guard (plan Task 6): prepare must refuse to run when a
 * selected record names a template or template part the theme does not
 * declare. WordPress core hierarchy slugs (index, page, single, ...) are
 * implicitly declared by every theme; anything else needs an entry in the
 * theme's customTemplates or templateParts.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionExitCode;
use AgencyPlatform\State\Promotion\ThemeDeclaredSlugs;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\ThemeDeclaredSlugs
 */
final class ThemeDeclaredSlugsTest extends TestCase {

	/** @var list<string> */
	private array $fixture_paths = array();

	/**
	 * The unit suite extends plain PHPUnit\Framework\TestCase, which declares
	 * tearDown(). tear_down() is the wp-phpunit polyfill's name and exists only
	 * in the integration suite, so this hook never ran: the fixture files were
	 * left on disk and PHPStan reported the parent call as undefined.
	 */
	protected function tearDown(): void {
		foreach ( $this->fixture_paths as $path ) {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting a unit fixture file; the WP_Filesystem credentials context does not exist here.
				unlink( $path );
			}
		}

		parent::tearDown();
	}

	public function test_core_hierarchy_slugs_are_always_declared(): void {
		$slugs = new ThemeDeclaredSlugs( array( 'version' => 3 ) );

		self::assertTrue( $slugs->declares_template( 'page' ) );
		self::assertTrue( $slugs->declares_template( '404' ) );
	}

	public function test_a_custom_template_needs_a_custom_templates_entry(): void {
		self::assertFalse( ( new ThemeDeclaredSlugs( array( 'version' => 3 ) ) )->declares_template( 'landing' ) );

		$declared = new ThemeDeclaredSlugs(
			array(
				'version'         => 3,
				'customTemplates' => array(
					array(
						'name'  => 'landing',
						'title' => 'Landing',
					),
				),
			)
		);

		self::assertTrue( $declared->declares_template( 'landing' ) );
	}

	public function test_a_template_part_needs_a_template_parts_entry(): void {
		$slugs = new ThemeDeclaredSlugs(
			array(
				'version'       => 3,
				'templateParts' => array(
					array(
						'name' => 'site-header',
						'area' => 'header',
					),
				),
			)
		);

		self::assertTrue( $slugs->declares_template_part( 'site-header' ) );
		self::assertFalse( $slugs->declares_template_part( 'promo-bar' ) );
	}

	public function test_a_template_part_without_an_entry_is_not_declared(): void {
		$slugs = new ThemeDeclaredSlugs( array( 'version' => 3 ) );

		self::assertFalse( $slugs->declares_template_part( 'site-header' ) );
	}

	public function test_from_file_reads_a_theme_json_document(): void {
		$path = $this->fixture_path( 'theme' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a unit fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents(
			$path,
			'{"version":3,"customTemplates":[{"name":"landing","title":"Landing"}],"templateParts":[{"name":"site-header","area":"header"}]}'
		);

		$slugs = ThemeDeclaredSlugs::from_file( $path );

		self::assertTrue( $slugs->declares_template( 'page' ) );
		self::assertTrue( $slugs->declares_template( 'landing' ) );
		self::assertTrue( $slugs->declares_template_part( 'site-header' ) );
		self::assertFalse( $slugs->declares_template_part( 'promo-bar' ) );
	}

	public function test_from_file_refuses_an_unreadable_file_with_exit_code_one(): void {
		$path = $this->fixture_path( 'missing' );

		try {
			ThemeDeclaredSlugs::from_file( $path );

			self::fail( 'from_file() must refuse an unreadable theme.json.' );
		} catch ( PromotionException $exception ) {
			self::assertSame( PromotionExitCode::HARD_ERROR, $exception->exit_code() );
			self::assertStringContainsString( $path, $exception->getMessage() );
		}
	}

	public function test_from_file_refuses_invalid_json_with_exit_code_one(): void {
		$path = $this->fixture_path( 'broken' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a unit fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $path, '{not json' );

		try {
			ThemeDeclaredSlugs::from_file( $path );

			self::fail( 'from_file() must refuse a theme.json that is not a JSON object.' );
		} catch ( PromotionException $exception ) {
			self::assertSame( PromotionExitCode::HARD_ERROR, $exception->exit_code() );
			self::assertStringContainsString( 'JSON', $exception->getMessage() );
		}
	}

	/**
	 * The bundle's theme.json is json_decode(..., true), so a top-level
	 * JSON array is a real possibility the guard must refuse the same way
	 * it refuses broken JSON.
	 */
	public function test_from_file_refuses_a_json_array_with_exit_code_one(): void {
		$path = $this->fixture_path( 'array' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a unit fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $path, '[1,2,3]' );

		try {
			ThemeDeclaredSlugs::from_file( $path );

			self::fail( 'from_file() must refuse a theme.json whose root is not an object.' );
		} catch ( PromotionException $exception ) {
			self::assertSame( PromotionExitCode::HARD_ERROR, $exception->exit_code() );
		}
	}

	private function fixture_path( string $name ): string {
		$path = sys_get_temp_dir() . '/theme-declared-slugs-' . $name . '-' . uniqid( '', true ) . '.json';

		$this->fixture_paths[] = $path;

		return $path;
	}
}
