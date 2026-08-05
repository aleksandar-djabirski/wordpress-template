<?php
/**
 * The determinism contract behind stateHash (BLOCK_THEME_PROPOSAL.md §7.2):
 * repeated exports of unchanged state must produce byte-identical canonical
 * JSON, and therefore identical hashes, no matter what order the source
 * arrays were built in.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\StateException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\Normalizer
 */
final class NormalizerTest extends TestCase {

	public function test_canonical_json_sorts_map_keys_at_every_depth(): void {
		$json = Normalizer::canonical_json(
			array(
				'zebra' => array(
					'gamma' => 1,
					'alpha' => 2,
				),
				'apple' => 3,
			)
		);

		self::assertSame( '{"apple":3,"zebra":{"alpha":2,"gamma":1}}', $json );
	}

	public function test_canonical_json_preserves_list_order(): void {
		$json = Normalizer::canonical_json( array( 'items' => array( 'c', 'a', 'b' ) ) );

		self::assertSame( '{"items":["c","a","b"]}', $json );
	}

	public function test_canonical_json_does_not_escape_slashes_or_unicode(): void {
		$json = Normalizer::canonical_json(
			array(
				'url'  => 'https://example.test/a/b',
				'text' => 'café',
			)
		);

		self::assertStringContainsString( 'https://example.test/a/b', $json );
		self::assertStringContainsString( 'café', $json );
	}

	public function test_an_integral_float_and_the_same_integer_canonicalise_identically(): void {
		self::assertSame(
			Normalizer::canonical_json( array( 'lineHeight' => 1 ) ),
			Normalizer::canonical_json( array( 'lineHeight' => 1.0 ) ),
			'1 and 1.0 are the same value to WordPress; they must not produce two different hashes.'
		);
		self::assertSame( '{"lineHeight":1}', Normalizer::canonical_json( array( 'lineHeight' => 1.0 ) ) );
	}

	public function test_negative_zero_canonicalises_to_zero(): void {
		self::assertSame( '{"offset":0}', Normalizer::canonical_json( array( 'offset' => -0.0 ) ) );
	}

	public function test_a_genuine_fraction_survives(): void {
		self::assertSame( '{"lineHeight":1.5}', Normalizer::canonical_json( array( 'lineHeight' => 1.5 ) ) );
	}

	public function test_a_non_finite_float_is_rejected(): void {
		$this->expectException( StateException::class );

		Normalizer::canonical_json( array( 'ratio' => INF ) );
	}

	public function test_nan_is_rejected(): void {
		$this->expectException( StateException::class );

		Normalizer::canonical_json( array( 'ratio' => NAN ) );
	}

	public function test_float_encoding_does_not_depend_on_serialize_precision(): void {
		$previous = ini_get( 'serialize_precision' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- pinning a deliberately low precision proves canonical_json's own -1 pin is what makes float encoding deterministic; the previous value is restored in the finally below.
		ini_set( 'serialize_precision', '3' );

		try {
			self::assertSame( '{"size":0.1234567}', Normalizer::canonical_json( array( 'size' => 0.1234567 ) ) );
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- the finally guarantees the process-wide serialize_precision is never left changed by the low-precision pin above.
			ini_set( 'serialize_precision', (string) $previous );
		}
	}

	public function test_normalize_content_normalises_line_endings_in_every_string_leaf(): void {
		$normalized = Normalizer::normalize_content(
			array(
				'title'  => "Home\r\n",
				'css'    => "body {\r\n  color: red;\r\n}",
				'nested' => array( 'label' => "One\rTwo" ),
			)
		);

		self::assertSame( 'Home', $normalized['title'] );
		self::assertSame( "body {\n  color: red;\n}", $normalized['css'] );
		self::assertSame( "One\nTwo", $normalized['nested']['label'] );
	}

	public function test_canonical_json_document_ends_with_exactly_one_lf(): void {
		$document = Normalizer::canonical_json_document( array( 'a' => 1 ) );

		self::assertSame( "{\"a\":1}\n", $document );
	}

	public function test_hash_is_independent_of_input_key_order(): void {
		$first  = Normalizer::hash(
			array(
				'b' => 2,
				'a' => 1,
			)
		);
		$second = Normalizer::hash(
			array(
				'a' => 1,
				'b' => 2,
			)
		);

		self::assertSame( $first, $second );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first );
	}

	public function test_hash_string_hashes_an_already_normalised_string_directly(): void {
		$markup = '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->';

		self::assertSame( hash( 'sha256', $markup ), Normalizer::hash_string( $markup ) );
	}

	public function test_hash_changes_when_a_value_changes(): void {
		self::assertNotSame(
			Normalizer::hash( array( 'markup' => '<!-- wp:paragraph /-->' ) ),
			Normalizer::hash( array( 'markup' => '<!-- wp:heading /-->' ) )
		);
	}

	public function test_normalize_line_endings_collapses_crlf_and_trims(): void {
		self::assertSame(
			"one\ntwo",
			Normalizer::normalize_line_endings( "one\r\ntwo\r\n  \n" )
		);
	}

	public function test_prune_empty_drops_empty_leaves_and_empty_branches(): void {
		$pruned = Normalizer::prune_empty(
			array(
				'keep'       => 'value',
				'blank'      => '',
				'nothing'    => null,
				'emptyList'  => array(),
				'emptyTree'  => array( 'typography' => array( 'fontSize' => '' ) ),
				'mixedTree'  => array(
					'typography' => array(
						'fontSize'   => '2rem',
						'lineHeight' => '',
					),
				),
				'zeroStays'  => 0,
				'falseStays' => false,
			)
		);

		self::assertSame(
			array(
				'keep'       => 'value',
				'mixedTree'  => array( 'typography' => array( 'fontSize' => '2rem' ) ),
				'zeroStays'  => 0,
				'falseStays' => false,
			),
			$pruned
		);
	}
}
