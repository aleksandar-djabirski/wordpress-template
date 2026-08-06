<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The §7.4 count-and-order cross-check (BLOCK_THEME_PROPOSAL.md §7.4): every
 * core/navigation block in a record's markup, in document order, as the
 * minimal (ref) tuple. Task 2's corrected exporter emits one navigation
 * reference per Navigation block, and this scan is the independent
 * count-and-order check the policy runs against it, so a malformed export
 * cannot silently omit a block. scan_parsed() is pure — it walks already
 * parsed block arrays, the shape parse_blocks() returns — and scan() is the
 * WordPress-coupled entry point that parses markup first.
 */
final class NavigationBlockScanner {

	private function __construct() {
		// Static-only scanner; never instantiated.
	}

	/**
	 * Every core/navigation block in the block tree, in document order.
	 *
	 * @param list<array<string, mixed>> $blocks parse_blocks() output
	 * @return list<array{ref:int|null}>
	 */
	public static function scan_parsed( array $blocks ): array {
		$found = array();

		foreach ( $blocks as $block ) {
			self::walk( $block, $found );
		}

		return $found;
	}

	/**
	 * WordPress-coupled: parse_blocks(), then scan_parsed().
	 *
	 * @return list<array{ref:int|null}>
	 */
	public static function scan( string $markup ): array {
		return self::scan_parsed( parse_blocks( $markup ) );
	}

	/**
	 * Recurse into a block tree: a navigation block appends its ref — an
	 * explicit integer, or null when absent or malformed — and inner blocks
	 * are walked in document order.
	 *
	 * @param array<string, mixed>      $block
	 * @param list<array{ref:int|null}> $found
	 */
	private static function walk( array $block, array &$found ): void {
		$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : null;

		if ( 'core/navigation' === $name ) {
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

			$found[] = array( 'ref' => is_int( $attrs['ref'] ?? null ) ? $attrs['ref'] : null );
		}

		if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $inner_block ) {
				if ( is_array( $inner_block ) ) {
					self::walk( $inner_block, $found );
				}
			}
		}
	}
}
