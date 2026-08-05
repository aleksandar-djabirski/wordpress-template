<?php
/**
 * The templates and template-parts promotion strategies against a real
 * WordPress install: theme-relative path building with the escape guard,
 * the fail-closed prepare() refusal (the lifecycle stages with stage(),
 * never a single-record commit), backup capture with the wp_theme term and
 * every meta key, reset() that removes the row and refuses a second call,
 * and resolve_current_hash() over file-backed templates.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\Promotion\BundleView;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionExitCode;
use AgencyPlatform\State\Promotion\TemplatePartPromotionStrategy;
use AgencyPlatform\State\Promotion\TemplatePromotionStrategy;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\StateBundle;
use AgencyPlatform\State\StateExporter;
use AgencyPlatform\State\StateRecord;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\AbstractBlockTemplateStrategy
 * @covers \AgencyPlatform\State\Promotion\TemplatePromotionStrategy
 * @covers \AgencyPlatform\State\Promotion\TemplatePartPromotionStrategy
 */
final class BlockTemplateStrategyTest extends IntegrationTestCase {

	private TemplatePromotionStrategy $templates;
	private TemplatePartPromotionStrategy $template_parts;

	public function set_up(): void {
		parent::set_up();

		$this->templates      = new TemplatePromotionStrategy();
		$this->template_parts = new TemplatePartPromotionStrategy();
	}

	public function test_theme_relative_paths_stay_inside_their_theme_subdirectories(): void {
		self::assertSame( 'templates/page.html', $this->templates->theme_relative_path( 'page' ) );
		self::assertSame( 'parts/site-header.html', $this->template_parts->theme_relative_path( 'site-header' ) );
	}

	public function test_a_slug_that_could_escape_the_theme_directory_is_refused(): void {
		$this->assert_exit_code( 1, fn() => $this->template_parts->theme_relative_path( '../evil' ) );
	}

	public function test_prepare_refuses_because_stage_is_the_promotion_entry_point(): void {
		$record    = $this->make_record( 'templates', 'page', null );
		$exception = $this->assert_exit_code( PromotionExitCode::HARD_ERROR, fn() => $this->templates->prepare( $record, '/tmp/theme' ) );

		self::assertStringContainsString( 'stage()', $exception->getMessage() );
	}

	public function test_capture_backup_includes_the_post_row_the_theme_term_and_every_meta_key(): void {
		$post_id = $this->make_template_row( 'custom-template' );
		update_post_meta( $post_id, 'agency_test_key', 'agency_test_value' );

		$backup = $this->templates->capture_backup( $this->make_record( 'templates', 'custom-template', $post_id ) );

		self::assertIsArray( $backup['post'] );
		self::assertSame( (string) $post_id, (string) $backup['post']['ID'] );
		self::assertSame( array( get_stylesheet() ), $backup['terms'], 'The wp_theme term is not optional: a row without it is invisible to the resolver.' );
		self::assertArrayHasKey( 'agency_test_key', $backup['meta'] );
		self::assertSame( array( 'agency_test_value' ), $backup['meta']['agency_test_key'] );
	}

	public function test_reset_removes_the_database_override(): void {
		$post_id = $this->make_template_row( 'custom-template' );
		$record  = $this->make_record( 'templates', 'custom-template', $post_id );

		$this->templates->reset( $record );

		self::assertNull( get_post( $post_id ), 'reset() must remove the database row.' );
		self::assertSame( array(), wp_get_object_terms( $post_id, 'wp_theme', array( 'fields' => 'slugs' ) ), 'reset() must remove the row\'s wp_theme term link.' );
	}

	public function test_a_second_reset_on_the_same_record_refuses(): void {
		$post_id = $this->make_template_row( 'custom-template' );
		$record  = $this->make_record( 'templates', 'custom-template', $post_id );

		$this->templates->reset( $record );

		$exception = $this->assert_exit_code( 1, fn() => $this->templates->reset( $record ) );

		self::assertStringContainsString( $record->key(), $exception->getMessage() );
	}

	public function test_resolve_current_hash_returns_null_for_an_unknown_slug(): void {
		self::assertNull( $this->templates->resolve_current_hash( 'no-such-template' ) );
		self::assertNull( $this->template_parts->resolve_current_hash( 'no-such-part' ) );
	}

	public function test_resolve_current_hash_returns_a_semantic_hash_for_a_file_backed_template(): void {
		$hash = $this->templates->resolve_current_hash( 'page' );

		self::assertIsString( $hash );
		self::assertSame( 64, strlen( $hash ) );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
	}

	/**
	 * The shipped theme's own templates must pass validate_for_promotion().
	 *
	 * The parse check originally compared `normalize_block_markup( $markup )`
	 * to the RAW markup. Normalisation is a TRANSFORM — it strips the injected
	 * theme attribute and ksorts the rest — so that comparison refused every
	 * record whose attributes were not already sorted. That is all three
	 * shipped files: templates/page.html and templates/index.html carry
	 * four-attribute template-part blocks, and parts/site-header.html carries a
	 * multi-attribute navigation block. The entire theme was unpromotable and
	 * nothing before the Task 16 vertical slice would have noticed.
	 *
	 * This drives the REAL shipped files rather than a fixture, for the same
	 * reason the navigation regression test does: a fixture written to suit the
	 * validator cannot detect that the validator rejects the real thing.
	 *
	 * @dataProvider shipped_theme_records
	 */
	public function test_the_shipped_theme_templates_pass_the_promotion_validator( string $provider, string $slug, string $relative_path ): void {
		$markup = (string) file_get_contents( get_stylesheet_directory() . '/' . $relative_path );

		self::assertNotSame( '', $markup, 'The shipped theme file must exist and be readable.' );

		$strategy = 'templates' === $provider ? $this->templates : $this->template_parts;

		$refusals = $strategy->validate_for_promotion(
			array(
				'key'      => $provider . ':' . $slug,
				'provider' => $provider,
				'slug'     => $slug,
				'content'  => array( 'markup' => $markup ),
			),
			$this->verified_bundle_view(),
			// Every template part the shipped templates reference is selected,
			// so the missing-template-part rule is satisfied and this test is
			// about the PARSE check only.
			array( 'templates:page', 'templates:index', 'template-parts:site-header', 'template-parts:site-footer' )
		);

		self::assertSame(
			array(),
			array_map( static fn( $refusal ): string => $refusal->reason_code, $refusals ),
			sprintf( 'The shipped %s must be promotable; a refusal here blocks the Task 16 vertical slice.', $relative_path )
		);
	}

	/**
	 * A real, signature-verified BundleView. The validator consults the bundle
	 * before it consults the selected keys, and an unverified bundle throws, so
	 * this cannot be faked.
	 */
	private function verified_bundle_view(): BundleView {
		$key_id = 'test-key';
		$signer = new HmacSigner( array( $key_id => str_repeat( 'k', 40 ) ), $key_id );

		$bundle = StateBundle::from_array(
			( new StateExporter( $signer ) )->export( array( 'templates', 'template-parts' ) )
		);

		$bundle->verify_signature( $signer );

		return new BundleView( $bundle );
	}

	/** @return array<string, array{string, string, string}> */
	public static function shipped_theme_records(): array {
		return array(
			'page template'  => array( 'templates', 'page', 'templates/page.html' ),
			'index template' => array( 'templates', 'index', 'templates/index.html' ),
			'site header'    => array( 'template-parts', 'site-header', 'parts/site-header.html' ),
		);
	}

	/**
	 * A wp_template database row of the active theme: publish status, the
	 * wp_theme term of the stylesheet (a row without it is invisible to the
	 * resolver) and a distinct post_name so it never shadows a file-backed
	 * template.
	 */
	private function make_template_row( string $post_name ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => $post_name,
				'post_title'   => 'Custom Template',
				'post_content' => '<!-- wp:paragraph --><p>hello</p><!-- /wp:paragraph -->',
			)
		);

		$term = get_term_by( 'slug', get_stylesheet(), 'wp_theme' );

		if ( false === $term ) {
			$inserted = wp_insert_term( get_stylesheet(), 'wp_theme' );

			if ( is_wp_error( $inserted ) ) {
				self::fail( 'Could not create the wp_theme term: ' . $inserted->get_error_message() );
			}
		}

		wp_set_object_terms( $post_id, get_stylesheet(), 'wp_theme' );

		return $post_id;
	}

	private function make_record( string $provider_slug, string $slug, ?int $object_id ): StateRecord {
		return StateRecord::create(
			$provider_slug,
			$slug,
			$object_id,
			'publish',
			null,
			array( 'markup' => '<!-- wp:paragraph --><p>hello</p><!-- /wp:paragraph -->' ),
			array(),
			Ownership::GIT_BASELINE_PLUS_DB,
			PromotionPolicy::PROMOTABLE
		);
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
}
