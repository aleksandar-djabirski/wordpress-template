<?php
/**
 * BLOCK_THEME_PROPOSAL.md §7.4: ReferenceResolver turns the scanner's raw
 * references into resolvable ones — a targetKey, a targetHash that is
 * byte-identical to the hash the owning provider exports, and a
 * targetIdentity for the human refusal report. The navigation case is the
 * one Task 3's v1 navigation policy is judged on: a bundle that exported
 * templates only must still carry the exported navigation's content hash
 * inside the reference, so finalisation can resolve exactly one matching
 * navigation in the target environment or refuse.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

use AgencyPlatform\State\Providers\NavigationState;
use AgencyPlatform\State\Providers\SyncedPatternsState;
use AgencyPlatform\State\Providers\TemplatePartsState;
use AgencyPlatform\State\Providers\TemplatesState;
use AgencyPlatform\State\ReferenceScanner;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\ReferenceResolver
 */
final class ReferenceResolverTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	public function test_a_navigation_reference_carries_the_target_key_hash_and_identity(): void {
		$navigation_id = $this->make_navigation( 'primary', '<!-- wp:navigation-link {"label":"Home"} /-->' );
		$this->make_template( 'home', sprintf( '<!-- wp:navigation {"ref":%d} /-->', $navigation_id ) );

		$reference = ( new TemplatesState() )->record( 'templates:home' )->references()[0];

		self::assertSame( 'navigation:primary', $reference['targetKey'] );
		self::assertSame(
			( new NavigationState() )->record( 'navigation:primary' )->content_hash(),
			$reference['targetHash'],
			'The reference hash must be byte-identical to the hash the navigation provider exports.'
		);
		self::assertSame( 'primary', $reference['targetIdentity']['slug'] );
		self::assertSame( 'publish', $reference['targetIdentity']['status'] );
	}

	public function test_a_site_logo_reference_gets_its_id_from_the_option(): void {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/08/logo.png',
				'post_mime_type' => 'image/png',
			)
		);
		update_option( 'site_logo', $attachment_id );
		$this->make_part( 'site-header', '<!-- wp:site-logo /-->' );

		$reference = ( new TemplatePartsState() )->record( 'template-parts:site-header' )->references()[0];

		self::assertSame( ReferenceScanner::KIND_SITE_LOGO, $reference['kind'] );
		self::assertSame( $attachment_id, $reference['value'], 'Section 7.4 requires the referenced ID; the site-logo block keeps it in an option.' );
		self::assertSame( 'media-references:attachment-' . $attachment_id, $reference['targetKey'] );
	}

	public function test_a_dangling_reference_resolves_to_null_targets_and_stays_unresolved(): void {
		$this->make_template( 'home', '<!-- wp:navigation {"ref":999999} /-->' );

		$reference = ( new TemplatesState() )->record( 'templates:home' )->references()[0];

		self::assertNull( $reference['targetKey'] );
		self::assertNull( $reference['targetHash'] );
		self::assertTrue( ReferenceScanner::is_unresolved( $reference ) );
	}

	public function test_resolving_a_reference_does_not_recurse_forever(): void {
		// A synced pattern that references itself is legal markup; the
		// with_references=false read inside the resolver is what stops it.
		$pattern_id = $this->make_synced_pattern( 'loop', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		wp_update_post(
			array(
				'ID'           => $pattern_id,
				'post_content' => sprintf( '<!-- wp:block {"ref":%d} /-->', $pattern_id ),
			)
		);

		$references = ( new SyncedPatternsState() )->record( 'synced-patterns:loop' )->references();

		self::assertCount( 1, $references );
		self::assertSame( 'synced-patterns:loop', $references[0]['targetKey'] );
	}
}
