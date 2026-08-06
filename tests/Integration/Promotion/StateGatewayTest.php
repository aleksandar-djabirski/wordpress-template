<?php
/**
 * The promotion seam onto Task 2 (plan §Task 3): StateGateway is the ONLY
 * class the promotion lifecycle may use to read Task 2 state, and it must
 * translate every StateException into a PromotionException carrying the SAME
 * exit code — the whole reason exit 1 and exit 4 are asserted separately is
 * that an earlier message-based mapping turned a missing keyring into a
 * tamper code. The valid signed bundle is built exactly the way the state
 * suite builds its own fixtures — StateExporter with an injected signer —
 * and the keyring it signs with is put into the environment, because the
 * gateway's signer resolves lazily from the environment.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Normalizer;
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
// putenv() is how these tests drive AGENCY_PROMOTION_HMAC_KEYS and
// AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID through EnvironmentConfig's
// process-environment fallback without WordPress or real .env files loaded;
// WordPress's discouraged-function sniff would otherwise flag every call.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class StateGatewayTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	/** The key id every fixture in this file signs with. */
	private const KEY_ID = '2026-01';

	public function tear_down(): void {
		$this->clear_keyring();
		parent::tear_down();
	}

	public function test_provider_exists_reflects_the_registry(): void {
		$gateway = new StateGateway();

		self::assertTrue( $gateway->provider_exists( 'templates' ) );
		self::assertFalse( $gateway->provider_exists( 'nope' ) );
	}

	public function test_read_live_record_is_null_before_an_override_exists(): void {
		self::assertNull( ( new StateGateway() )->read_live_record( 'templates', 'page' ) );
	}

	public function test_read_live_record_returns_an_inserted_template_with_its_content_hash(): void {
		$markup  = '<!-- wp:paragraph --><p>Promoted</p><!-- /wp:paragraph -->';
		$gateway = new StateGateway();

		$this->make_template( 'page', $markup );

		$record = $gateway->read_live_record( 'templates', 'page' );

		self::assertNotNull( $record, 'A wp_template row carrying the active theme\'s wp_theme term must be visible to the templates provider.' );
		self::assertSame( 'templates', $record['provider'], 'The derived provider key must identify the owning provider.' );
		self::assertSame( 'templates:page', $record['key'] );
		self::assertSame( 'page', $record['slug'] );
		self::assertSame( serialize_blocks( parse_blocks( $markup ) ), $record['content']['markup'] );
		self::assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $record['contentHash'] ), 'The content hash must be the lowercase 64-char SHA-256 hex of the canonical content.' );
	}

	/**
	 * The load-bearing assertion of the whole seam: a manifest hash computed
	 * with hash_markup() over the SAME markup the database row holds must
	 * equal the bundle contentHash of that row. Finalize compares a prepared
	 * file's hash with the bundle record's hash — if this convention ever
	 * drifted, every finalize would refuse or self-restore.
	 */
	public function test_hash_markup_matches_the_live_records_content_hash(): void {
		$markup  = '<!-- wp:paragraph --><p>Promoted</p><!-- /wp:paragraph -->';
		$gateway = new StateGateway();

		$this->make_template( 'page', $markup );

		$record = $gateway->read_live_record( 'templates', 'page' );

		self::assertNotNull( $record );
		self::assertSame(
			$record['contentHash'],
			$gateway->hash_markup( $gateway->normalize_block_markup( $markup ) )
		);
	}

	public function test_load_bundle_returns_a_view_over_a_valid_signed_bundle(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Promoted</p><!-- /wp:paragraph -->' );
		$this->set_keyring();

		$path = $this->write_bundle( $this->signed_bundle_document() );

		try {
			$view = ( new StateGateway() )->load_bundle( $path );

			self::assertSame( $view->export_id(), $view->header()['exportId'] );
			self::assertSame( $view->exported_at_utc(), $view->header()['exportedAtUtc'] );
			self::assertSame( $view->site_uuid(), $view->header()['siteUuid'] );
			self::assertSame( $view->site_url(), $view->header()['siteUrl'] );
			self::assertSame( $view->environment(), $view->header()['environment'] );
			self::assertSame( array( 'stylesheet', 'version', 'gitCommit' ), array_keys( $view->active_theme() ), 'StateBundle::active_theme() carries THREE keys; the view must pass all of them through.' );
			self::assertSame( $view->active_theme(), $view->header()['activeTheme'] );
			self::assertTrue( $view->has_provider( 'templates' ) );
			self::assertFalse( $view->has_provider( 'nope' ) );
			self::assertSame( 'templates', $view->record( 'templates:page' )['provider'] );
			self::assertSame( 'templates:page', $view->state_record( 'templates:page' )->key() );
			self::assertSame( array( 'templates:page' ), array_map( static fn ( array $row ): string => $row['key'], $view->records( 'templates' ) ) );
			self::assertNull( $view->record( 'templates:missing' ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $path );
			$this->clear_keyring();
		}
	}

	public function test_load_bundle_with_an_altered_hmac_is_a_tamper_exception(): void {
		$this->set_keyring();

		$document         = $this->signed_bundle_document();
		$document['hmac'] = str_repeat( '0', 64 );
		$path             = $this->write_bundle( $document );

		try {
			try {
				( new StateGateway() )->load_bundle( $path );

				self::fail( 'An altered hmac must refuse the bundle.' );
			} catch ( PromotionException $exception ) {
				self::assertSame( PromotionExitCode::TAMPER, $exception->exit_code(), 'A signature failure is tamper, exit 4 — never a hard error.' );
				self::assertStringContainsString( 'HMAC', $exception->getMessage() );
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $path );
			$this->clear_keyring();
		}
	}

	/**
	 * The regression this pair of tests exists to prevent: an earlier
	 * message-based mapping turned a missing keyring (a hard error, exit 1)
	 * into a tamper code, and a test that only asserted "an exception was
	 * thrown" could not tell the two cases apart.
	 */
	public function test_load_bundle_without_a_keyring_is_a_hard_error_not_tamper(): void {
		$this->clear_keyring();

		$path = $this->write_bundle( $this->signed_bundle_document() );

		try {
			try {
				( new StateGateway() )->load_bundle( $path );

				self::fail( 'A bundle load without any HMAC keyring must refuse.' );
			} catch ( PromotionException $exception ) {
				self::assertSame( PromotionExitCode::HARD_ERROR, $exception->exit_code(), 'A missing keyring is a hard error, exit 1 — never the tamper code 4.' );
				self::assertStringContainsString( HmacSigner::SETTING_KEYS, $exception->getMessage() );
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $path );
		}
	}

	/**
	 * This test FLIPPED DIRECTION when the subsystem was wired into Plugin.php.
	 *
	 * It used to assert `strategy_for( 'templates' )` was NULL, which was
	 * correct while the registry shipped empty and nothing registered it. The
	 * final commit of this release adds the one sequenced
	 * `PromotionSubsystem::register()` line, so the strategies are now
	 * registered on every request — and the old assertion became a test that
	 * the wiring does NOT work.
	 *
	 * The replacement is strictly stronger. It is the only test that proves the
	 * `Plugin.php` registration line is actually REACHED at runtime: remove that
	 * one line and this fails. It also still pins the Release 3/Release 4
	 * boundary, because `global-styles` must stay unregistered until Unit 3B
	 * adds its strategy.
	 */
	public function test_the_plugin_registers_the_release_three_strategies_and_no_more(): void {
		$gateway = new StateGateway();

		self::assertNotNull(
			$gateway->strategy_for( 'templates' ),
			'Plugin.php must register PromotionSubsystem; without it the promotion CLI has no strategies.'
		);
		self::assertNotNull(
			$gateway->strategy_for( 'template-parts' ),
			'Plugin.php must register PromotionSubsystem; without it the promotion CLI has no strategies.'
		);
		self::assertNull(
			$gateway->strategy_for( 'global-styles' ),
			'Global Styles stays unregistered until Unit 3B; this is the Release 3/Release 4 boundary.'
		);
	}

	/**
	 * The signed-bundle fixture, built the way the state suite builds its
	 * own (StateExportTest, StateDiffBundleModeTest): StateExporter with an
	 * injected signer whose keyring the environment below mirrors.
	 *
	 * @return array<string, mixed>
	 */
	private function signed_bundle_document(): array {
		return ( new StateExporter( new HmacSigner( array( self::KEY_ID => str_repeat( 'k', 40 ) ), self::KEY_ID ) ) )->export( array( 'templates' ) );
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function write_bundle( array $document ): string {
		$path = tempnam( sys_get_temp_dir(), 'bundle' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- integration fixture file for StateGateway::load_bundle(); the WP_Filesystem credentials context does not exist here.
		file_put_contents( $path, Normalizer::canonical_json_document( $document ) );

		return $path;
	}

	/**
	 * The keyring the gateway's from_environment() signer must find: valid
	 * JSON, one key id, a 40-char key — the same key the fixtures sign with.
	 */
	private function keyring_json(): string {
		return '{"' . self::KEY_ID . '":"' . str_repeat( 'k', 40 ) . '"}';
	}

	private function set_keyring(): void {
		putenv( HmacSigner::SETTING_KEYS . '=' . $this->keyring_json() );
		putenv( HmacSigner::SETTING_SIGNING_KEY_ID . '=' . self::KEY_ID );
	}

	private function clear_keyring(): void {
		putenv( HmacSigner::SETTING_KEYS );
		putenv( HmacSigner::SETTING_SIGNING_KEY_ID );
	}
}
