<?php
/**
 * Every bundle record carries the fields BLOCK_THEME_PROPOSAL.md §7.2
 * requires, and its content hash must depend only on the content values —
 * never on the order the content array happened to be built in — because
 * that hash is what the two-mode diff and every promotion concurrency check
 * compare.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\StateException;
use AgencyPlatform\State\StateRecord;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\StateRecord
 */
final class StateRecordTest extends TestCase {

	private function record(): StateRecord {
		return StateRecord::create(
			'templates',
			'page',
			12,
			'publish',
			'2026-08-01 10:00:00',
			array( 'markup' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ),
			array(),
			Ownership::GIT_BASELINE_PLUS_DB,
			PromotionPolicy::PROMOTABLE
		);
	}

	public function test_the_key_is_provider_slug_colon_record_slug(): void {
		self::assertSame( 'templates:page', $this->record()->key() );
		self::assertSame( 'templates', $this->record()->provider_slug() );
	}

	public function test_the_content_hash_is_the_canonical_hash_of_the_content(): void {
		$content = array( 'markup' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' );

		self::assertSame( Normalizer::hash( $content ), $this->record()->content_hash() );
	}

	public function test_the_content_hash_ignores_content_key_order(): void {
		$forward = StateRecord::create(
			'content',
			'page-1',
			1,
			'publish',
			null,
			array(
				'a' => 1,
				'b' => 2,
			),
			array(),
			Ownership::DATABASE,
			PromotionPolicy::NEVER_PROMOTE
		);
		$reverse = StateRecord::create(
			'content',
			'page-1',
			1,
			'publish',
			null,
			array(
				'b' => 2,
				'a' => 1,
			),
			array(),
			Ownership::DATABASE,
			PromotionPolicy::NEVER_PROMOTE
		);

		self::assertSame( $forward->content_hash(), $reverse->content_hash() );
	}

	public function test_to_array_uses_the_bundle_field_names(): void {
		self::assertSame(
			array( 'key', 'objectId', 'slug', 'status', 'modifiedGmt', 'content', 'contentHash', 'references', 'ownership', 'promotion' ),
			array_keys( $this->record()->to_array() )
		);
	}

	public function test_from_array_round_trips_to_array(): void {
		$restored = StateRecord::from_array( $this->record()->to_array() );

		self::assertSame( $this->record()->to_array(), $restored->to_array() );
	}

	public function test_from_array_rejects_a_record_whose_hash_does_not_match_its_content(): void {
		$record                = $this->record()->to_array();
		$record['contentHash'] = str_repeat( 'f', 64 );

		$this->expectException( StateException::class );
		$this->expectExceptionMessageMatches( '/templates:page/' );

		StateRecord::from_array( $record );
	}

	public function test_create_rejects_an_unknown_ownership_value(): void {
		$this->expectException( StateException::class );

		StateRecord::create( 'templates', 'page', 12, 'publish', null, array(), array(), 'not-a-real-ownership', PromotionPolicy::PROMOTABLE );
	}

	public function test_create_rejects_a_slug_containing_the_key_separator(): void {
		$this->expectException( StateException::class );

		StateRecord::create( 'templates', 'pa:ge', 12, 'publish', null, array(), array(), Ownership::GIT_BASELINE_PLUS_DB, PromotionPolicy::PROMOTABLE );
	}
}
