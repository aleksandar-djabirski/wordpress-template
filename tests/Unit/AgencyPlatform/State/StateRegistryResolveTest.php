<?php
/**
 * The provider registry (BLOCK_THEME_PROPOSAL.md §7.1): the built-in
 * provider map is extended through the agency_platform_state_providers
 * filter, and resolve() turns the --providers CLI option (or null) into the
 * deterministic, sorted, de-duplicated slug list the exporter runs.
 *
 * This test must not touch the database, so it exercises resolve() only —
 * every provider constructor is side-effect free by contract.
 *
 * With all eight structural providers registered, the default set IS
 * StateRegistry::STRUCTURAL_SLUGS. The constant never drives resolution,
 * though — the set is derived from the REGISTERED providers, so the two can
 * only coincide while the registry is complete. `content` is the one
 * provider resolve() excludes from the default set: it is gated behind
 * --include-content unless named explicitly.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\StateException;
use AgencyPlatform\State\StateRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AgencyPlatform\State\Doubles\FakeStateProvider;

/**
 * @covers \AgencyPlatform\State\StateRegistry
 */
final class StateRegistryResolveTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		StateRegistry::reset();
		$GLOBALS['_test_filters'] = array();
	}

	public function test_the_default_set_is_the_structural_providers(): void {
		self::assertSame( StateRegistry::STRUCTURAL_SLUGS, StateRegistry::resolve( null, false ) );
	}

	public function test_include_content_adds_the_content_provider(): void {
		self::assertContains( 'content', StateRegistry::resolve( null, true ) );
	}

	public function test_an_explicitly_named_content_provider_is_honoured_without_the_flag(): void {
		self::assertSame( array( 'content' ), StateRegistry::resolve( 'content', false ) );
	}

	public function test_the_default_set_never_contains_content(): void {
		self::assertNotContains( 'content', StateRegistry::resolve( null, false ) );
	}

	public function test_an_explicit_list_narrows_the_export(): void {
		self::assertSame( array( 'global-styles', 'templates' ), StateRegistry::resolve( 'templates, global-styles', false ) );
	}

	public function test_an_unknown_slug_is_a_hard_error_listing_the_valid_slugs(): void {
		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/nonsense.*templates/s' );

		StateRegistry::resolve( 'nonsense', false );
	}

	public function test_an_empty_selection_is_a_hard_error(): void {
		$this->expectException( StateException::class );

		StateRegistry::resolve( '  ,  ', false );
	}

	public function test_a_filtered_provider_joins_the_registry(): void {
		add_filter( StateRegistry::FILTER, array( self::class, 'append_fake_provider' ) );
		StateRegistry::reset();

		self::assertContains( 'fake', StateRegistry::slugs() );
		self::assertContains( 'fake', StateRegistry::resolve( null, false ) );
	}

	public function test_a_non_provider_value_from_the_filter_is_rejected(): void {
		add_filter( StateRegistry::FILTER, array( self::class, 'append_garbage' ) );
		StateRegistry::reset();

		$this->expectException( StateException::class );

		StateRegistry::providers();
	}

	public function test_structural_slugs_is_declared_in_canonical_ascending_order(): void {
		$sorted = StateRegistry::STRUCTURAL_SLUGS;
		sort( $sorted, SORT_STRING );

		self::assertSame( $sorted, StateRegistry::STRUCTURAL_SLUGS, 'The constant must already be in the order resolve() returns.' );
	}

	/**
	 * @param array<string, mixed> $providers
	 * @return array<string, mixed>
	 */
	public static function append_fake_provider( array $providers ): array {
		$providers['fake'] = new FakeStateProvider();

		return $providers;
	}

	/**
	 * @param array<string, mixed> $providers
	 * @return array<string, mixed>
	 */
	public static function append_garbage( array $providers ): array {
		$providers['broken'] = 'not-a-provider';

		return $providers;
	}
}
