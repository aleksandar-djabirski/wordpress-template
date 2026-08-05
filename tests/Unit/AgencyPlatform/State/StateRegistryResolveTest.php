<?php
/**
 * The provider registry (BLOCK_THEME_PROPOSAL.md §7.1): the built-in
 * provider map is extended through the agency_platform_state_providers
 * filter, and resolve() turns the --providers CLI option (or null) into the
 * deterministic, sorted, de-duplicated slug list the exporter runs.
 *
 * This test must not touch the database, so it exercises resolve() only —
 * the three providers' constructors are side-effect free by contract.
 *
 * Task 8 ships only the three Git-backed providers. The default-set test
 * therefore asserts them in canonical ascending order —
 * array( 'global-styles', 'template-parts', 'templates' ) — and Task 9
 * restores the full StateRegistry::STRUCTURAL_SLUGS assertion after it
 * registers the other five structural providers. Every `content` assertion
 * also lives in Task 9, not here: the `content` provider is ContentState,
 * which Task 9 creates, and resolve() validates a named slug against the
 * REGISTERED providers, so resolve( 'content', … ) is a hard error in Task
 * 8 by design. The default set itself is derived from the REGISTERED
 * providers, never from STRUCTURAL_SLUGS — that constant names all eight
 * slugs while this task registers three, so reading the constant here would
 * make the test pass by accident and the registry wrong.
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
		self::assertSame( array( 'global-styles', 'template-parts', 'templates' ), StateRegistry::resolve( null, false ) );
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
