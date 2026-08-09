<?php
/**
 * The recursion-guard half of the record() contract (BLOCK_THEME_PROPOSAL.md
 * §7.1, ReferenceResolver): record( $key, false ) must return the record
 * with an EMPTY reference list, so resolving a reference can never trigger
 * another scan. The base implementation rebuilds the record from its own
 * fields — same content, therefore same content hash, only the references
 * dropped — and this suite pins that on a real provider object with no
 * database and no WordPress at all.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\BaseStateProvider;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\StateRecord;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\BaseStateProvider::record
 */
final class BaseStateProviderRecordTest extends TestCase {

	public function test_record_without_the_flag_returns_the_scanned_reference_list(): void {
		$record = ( new ReferencingStateProvider() )->record( 'referencing:sample' );

		self::assertNotNull( $record );
		self::assertCount( 1, $record->references(), 'record( $key ) must return the references the provider recorded.' );
	}

	public function test_record_with_the_flag_false_returns_an_empty_reference_list(): void {
		$record = ( new ReferencingStateProvider() )->record( 'referencing:sample', false );

		self::assertNotNull( $record );
		self::assertSame( array(), $record->references(), 'record( $key, false ) must return the record with an EMPTY reference list — the recursion guard.' );
	}

	public function test_dropping_the_references_never_changes_the_content_hash(): void {
		$provider = new ReferencingStateProvider();

		self::assertSame(
			$provider->record( 'referencing:sample' )->content_hash(),
			$provider->record( 'referencing:sample', false )->content_hash(),
			'The content hash is computed from the content alone; dropping references must not change it.'
		);
		self::assertSame(
			$provider->record( 'referencing:sample' )->to_array()['content'],
			$provider->record( 'referencing:sample', false )->to_array()['content']
		);
	}

	public function test_record_with_the_flag_false_still_returns_null_for_an_unknown_key(): void {
		self::assertNull( ( new ReferencingStateProvider() )->record( 'referencing:missing', false ) );
	}
}

/**
 * A test-local provider whose records() yields one record that already
 * carries a scanned reference, so the base record() has something to strip.
 * No WordPress needed: the record is built directly through
 * StateRecord::create() with a pre-scanned reference array.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- the plan fixes the test provider to this file so the base record() guard is pinned without a database or a production class.
final class ReferencingStateProvider extends BaseStateProvider {

	public function slug(): string {
		return 'referencing';
	}

	public function ownership(): string {
		return Ownership::DATABASE;
	}

	public function promotion(): string {
		return PromotionPolicy::NEVER_PROMOTE;
	}

	public function includes_content(): bool {
		return false;
	}

	public function has_git_baseline(): bool {
		return false;
	}

	/**
	 * @return list<StateRecord>
	 */
	public function records(): array {
		return array(
			StateRecord::create(
				$this->slug(),
				'sample',
				null,
				'publish',
				null,
				Normalizer::normalize_content( array( 'markup' => '<!-- wp:navigation {"ref":31} /-->' ) ),
				array(
					array(
						'kind'           => 'navigation',
						'value'          => 31,
						'resolution'     => 'environment',
						'targetKey'      => null,
						'targetHash'     => null,
						'targetIdentity' => null,
					),
				),
				$this->ownership(),
				$this->promotion()
			),
		);
	}

	/**
	 * @return list<StateRecord>
	 */
	public function baseline_records(): array {
		return array();
	}
}
