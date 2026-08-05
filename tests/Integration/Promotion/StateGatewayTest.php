<?php
/**
 * The StateGateway seam (BLOCK_THEME_PROPOSAL.md §6): the promotion
 * lifecycle's one surface onto Task 2's state subsystem. A valid signed
 * bundle is built the way the repo already builds them — StateExporter
 * (exactly as StateExportTest does), written as canonical bytes, then read
 * back through StateGateway::load_bundle(), which resolves the signer from
 * the environment. A tampered hmac must be exit 4; a missing keyring must
 * be exit 1 — the two must stay distinguishable, because an earlier
 * message-based mapping turned a missing keyring into a tamper code, and
 * collapsing them is the exact regression these assertions exist to catch.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Promotion\BundleView;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionExitCode;
use AgencyPlatform\State\Promotion\StateGateway;
use AgencyPlatform\State\StateExporter;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\State\SeedsStateFixtures;

/**
 * @covers \AgencyPlatform\State\Promotion\StateGateway
 * @covers \AgencyPlatform\State\Promotion\BundleView
 */
// putenv() drives the AGENCY_PROMOTION_HMAC_* settings through
// EnvironmentConfig's process-environment fallback, exactly as the keyring
// is resolved in production; WordPress's discouraged-function sniff would
// otherwise flag every call below.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class StateGatewayTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	private const KEY_ID = '2026-01';

	public function set_up(): void {
		parent::set_up();

		$this->set_keyring();
	}

	public function tear_down(): void {
		putenv( HmacSigner::SETTING_KEYS );
		putenv( HmacSigner::SETTING_SIGNING_KEY_ID );

		parent::tear_down();
	}

	private function signer(): HmacSigner {
		return new HmacSigner( array( self::KEY_ID => str_repeat( 'k', 40 ) ), self::KEY_ID );
	}

	private function set_keyring(): void {
		putenv( HmacSigner::SETTING_KEYS . '=' . wp_json_encode( array( self::KEY_ID => str_repeat( 'k', 40 ) ) ) );
		putenv( HmacSigner::SETTING_SIGNING_KEY_ID . '=' . self::KEY_ID );
	}

	/**
	 * A signed bundle document for the fixtures of this test, built exactly
	 * the way StateExportTest builds its bundles: the exporter signs with
	 * the same key the environment-driven gateway signer resolves, so the
	 * document verifies on the load path.
	 *
	 * @return array<string, mixed>
	 */
	private function exported_document(): array {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Gateway</p><!-- /wp:paragraph -->' );

		return ( new StateExporter( $this->signer() ) )->export( array( 'templates' ) );
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function write_bundle( array $document ): string {
		$path = tempnam( sys_get_temp_dir(), 'promo-bundle' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- integration fixture file for StateGateway::load_bundle(); the WP_Filesystem credentials context does not exist here.
		file_put_contents( $path, Normalizer::canonical_json_document( $document ) );

		return $path;
	}

	public function test_provider_exists_reflects_the_registry(): void {
		$gateway = new StateGateway();

		self::assertTrue( $gateway->provider_exists( 'templates' ) );
		self::assertFalse( $gateway->provider_exists( 'nope' ) );
	}

	public function test_read_live_record_is_null_without_an_override(): void {
		self::assertNull( ( new StateGateway() )->read_live_record( 'templates', 'page' ) );
	}

	public function test_read_live_record_matches_the_bundle_hash_convention(): void {
		$markup  = '<!-- wp:paragraph --><p>Gateway</p><!-- /wp:paragraph -->';
		$gateway = new StateGateway();

		$this->make_template( 'page', $markup );

		$record = $gateway->read_live_record( 'templates', 'page' );

		self::assertNotNull( $record, 'The template row must be visible to the resolver once its wp_theme term is set; a row without the term stays invisible.' );
		self::assertSame( 'templates', $record['provider'], 'Every live record must carry its derived provider key.' );
		self::assertSame( 'templates:page', $record['key'] );
		self::assertArrayHasKey( 'markup', $record['content'] );
		self::assertSame(
			$gateway->hash_markup( $markup ),
			$record['contentHash'],
			'hash_markup() must use the same content-map convention as StateRecord::content_hash(), or a manifest hash and a bundle contentHash are not comparable at finalize time.'
		);
	}

	public function test_load_bundle_rejects_a_tampered_signature_with_exit_code_four(): void {
		$document         = $this->exported_document();
		$document['hmac'] = str_repeat( '0', 64 );
		$path             = $this->write_bundle( $document );

		try {
			( new StateGateway() )->load_bundle( $path );

			self::fail( 'load_bundle() must throw PromotionException when the bundle signature is tampered.' );
		} catch ( PromotionException $exception ) {
			self::assertSame( PromotionExitCode::TAMPER, $exception->exit_code(), 'A tampered signature is exit 4, not a hard error.' );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $path );
		}
	}

	public function test_load_bundle_with_no_keyring_is_a_hard_error_not_tamper(): void {
		$path = $this->write_bundle( $this->exported_document() );

		putenv( HmacSigner::SETTING_KEYS );
		putenv( HmacSigner::SETTING_SIGNING_KEY_ID );

		try {
			( new StateGateway() )->load_bundle( $path );

			self::fail( 'load_bundle() must throw PromotionException when no HMAC keyring is configured.' );
		} catch ( PromotionException $exception ) {
			self::assertSame(
				PromotionExitCode::HARD_ERROR,
				$exception->exit_code(),
				'A missing keyring is a hard error (exit 1), never a tamper code (exit 4); the two failures must stay distinguishable.'
			);
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $path );
		}
	}

	public function test_load_bundle_returns_a_view_of_the_verified_bundle(): void {
		$path = $this->write_bundle( $this->exported_document() );

		try {
			$view = ( new StateGateway() )->load_bundle( $path );

			self::assertInstanceOf( BundleView::class, $view );

			$header = $view->header();

			self::assertSame( $view->export_id(), $header['exportId'] );
			self::assertSame( $view->exported_at_utc(), $header['exportedAtUtc'] );
			self::assertSame( $view->site_uuid(), $header['siteUuid'] );
			self::assertSame( $view->site_url(), $header['siteUrl'] );
			self::assertSame( 'development', $header['environment'] );
			self::assertSame( $view->active_theme(), $header['activeTheme'] );
			self::assertSame( 'site-theme', $view->active_theme()['stylesheet'] );
			self::assertArrayHasKey( 'gitCommit', $view->active_theme(), 'active_theme() passes the bundle\'s three-key shape through unchanged.' );

			self::assertTrue( $view->has_provider( 'templates' ) );
			self::assertFalse( $view->has_provider( 'navigation' ) );

			$record = $view->record( 'templates:page' );

			self::assertNotNull( $record );
			self::assertSame( 'templates', $record['provider'] );
			self::assertSame( 'templates:page', $record['key'] );
			self::assertNull( $view->record( 'templates:missing' ) );
			self::assertNotNull( $view->state_record( 'templates:page' ) );

			$records = $view->records( 'templates' );

			self::assertCount( 1, $records );
			self::assertSame( 'templates:page', $records[0]['key'] );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $path );
		}
	}

	public function test_strategy_for_is_null_until_a_strategy_is_registered(): void {
		self::assertNull( ( new StateGateway() )->strategy_for( 'templates' ) );
	}
}
