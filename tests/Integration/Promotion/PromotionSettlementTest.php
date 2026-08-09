<?php
/**
 * The settlement stage of the promotion lifecycle (BLOCK_THEME_PROPOSAL.md
 * §7.9): --confirm releases the record locks and marks the promotion
 * settled (the backups survive, only retention prune removes them), and
 * --rollback recreates the database rows from the backups — leaves-first
 * (template-parts before templates), refusing rather than overwriting when
 * a client recreated a record, a newer promotion claimed it, or the backup
 * was already pruned. A rollback returns its records to a RE-FINALISABLE
 * state: objectId and originalModifiedGmt are rewritten from the restored
 * row, so a second --finalize on the same manifest passes its concurrency
 * check.
 *
 * The fixture mirrors PromotionFinalizerTest: a throwaway state directory,
 * a throwaway theme directory that both stylesheet_directory AND
 * template_directory are filtered to, live wp_template / wp_template_part
 * rows, and a signed, sealed manifest built from the LIVE rows. Every test
 * that needs the finalized state finalizes it first through the REAL
 * finalizer, so settlement is proven against the true post-finalize state.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Promotion\BundleView;
use AgencyPlatform\State\Promotion\GlobalStylesPromotionStrategy;
use AgencyPlatform\State\Promotion\ManifestStore;
use AgencyPlatform\State\Promotion\PreparablePromotionStrategy;
use AgencyPlatform\State\Promotion\PromotionBackup;
use AgencyPlatform\State\Promotion\PromotionConfirmer;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionFinalizer;
use AgencyPlatform\State\Promotion\PromotionManifest;
use AgencyPlatform\State\Promotion\PromotionOutcome;
use AgencyPlatform\State\Promotion\PromotionRollback;
use AgencyPlatform\State\Promotion\RecordLockManager;
use AgencyPlatform\State\Promotion\StagedPromotionEntry;
use AgencyPlatform\State\Promotion\StateGateway;
use AgencyPlatform\State\Promotion\TemplatePartPromotionStrategy;
use AgencyPlatform\State\Promotion\TemplatePromotionStrategy;
use AgencyPlatform\State\Promotion\ThemeDeclaredSlugs;
use AgencyPlatform\State\Promotion\ThemeJsonAdapter;
use AgencyPlatform\State\PromotionStrategies;
use AgencyPlatform\State\PromotionStrategy;
use AgencyPlatform\State\StateRecord;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\State\SeedsStateFixtures;

/**
 * @covers \AgencyPlatform\State\Promotion\PromotionConfirmer
 * @covers \AgencyPlatform\State\Promotion\PromotionRollback
 */
// putenv() is how these tests drive AGENCY_* settings and the HMAC keyring
// through EnvironmentConfig's process-environment fallback without
// WordPress or real .env files loaded; WordPress's discouraged-function
// sniff would otherwise flag every call.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class PromotionSettlementTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	/** The key id every fixture in this file signs with. */
	private const KEY_ID = '2026-01';

	private const PROMOTION_ID  = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
	private const SITE_UUID     = '11111111-2222-4333-8444-555555555555';
	private const DEPLOY_COMMIT = 'ffffffffffffffffffffffffffffffffffffffff';

	private string $tmp_dir;
	private string $repo_root;
	private string $theme_dir;
	private string $state_dir;
	private string $manifest_path;
	private string $original_content_hash;
	private string $real_theme_json_path;
	private string $original_real_theme_json_bytes;
	private int $page_override_id;

	private StateGateway $gateway;
	private ManifestStore $store;
	private PromotionFinalizer $finalizer;
	private PromotionConfirmer $confirmer;
	private PromotionRollback $rollback;

	/** @var array<string, PreparablePromotionStrategy> the strategies the filter registers */
	private array $strategies = array();

	/** @var array<string, PreparablePromotionStrategy> the real strategies, kept for wrapping */
	private array $real_strategies = array();

	/** @var array<string, string|null> expected post-reset semantic hash per record key (null when the strategy defers it) */
	private array $expected_hashes = array();

	public function set_up(): void {
		parent::set_up();

		$tmp = sys_get_temp_dir() . '/promotion-settlement-' . uniqid( '', true );

		$this->tmp_dir   = $tmp;
		$this->repo_root = $tmp . '/repo';
		$this->theme_dir = $tmp . '/theme';
		$this->state_dir = $tmp . '/state';

		$this->manifest_path = $this->state_dir . '/manifest.json';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->repo_root, 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->theme_dir . '/templates', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->theme_dir . '/parts', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->state_dir, 0700, true );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing integration fixture files; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->theme_dir . '/templates/page.html', "<!-- wp:paragraph --><p>Page body</p><!-- /wp:paragraph -->\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing integration fixture files; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->theme_dir . '/parts/site-header.html', "<!-- wp:paragraph --><p>Header body</p><!-- /wp:paragraph -->\n" );

		// The REAL theme directory, captured BEFORE the directory filters
		// point get_stylesheet_directory() at the fixture. The mixed
		// lifecycle test writes the prepared theme.json bytes over the real
		// active theme.json (CORRECTION D8: the equivalence resolution reads
		// it — WP_Theme_JSON_Resolver ignores the directory filter) and
		// tear_down restores them.
		$this->real_theme_json_path           = get_stylesheet_directory() . '/theme.json';
		$this->original_real_theme_json_bytes = (string) file_get_contents( $this->real_theme_json_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the shipped on-disk artefact under test; the WP_Filesystem credentials context does not exist here.

		register_shutdown_function(
			function (): void {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- restoring the real theme.json after a fatal test failure; the WP_Filesystem credentials context does not exist here.
				file_put_contents( $this->real_theme_json_path, $this->original_real_theme_json_bytes );
			}
		);

		add_filter( 'stylesheet_directory', array( $this, 'fixture_stylesheet_directory' ) );
		// _get_block_template_file() maps BOTH get_stylesheet_directory() and
		// get_template_directory() — with no child theme the two array keys
		// collide and the template entry wins — so the resolver only ever sees
		// the fixture when both filters point at it.
		add_filter( 'template_directory', array( $this, 'fixture_stylesheet_directory' ) );

		// The fixture theme.json is the SHIPPED artefact's bytes: the
		// global-styles record's stage() merges into it and the deployed
		// file the manifest signs is its canonical, merged form.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- copying the shipped theme.json into the fixture theme directory; the WP_Filesystem credentials context does not exist here.
		copy( $this->real_theme_json_path, $this->theme_dir . '/theme.json' );

		$this->gateway = new StateGateway();
		$this->store   = new ManifestStore( $this->gateway );

		$this->real_strategies = array(
			'templates'      => new TemplatePromotionStrategy(),
			'template-parts' => new TemplatePartPromotionStrategy(),
			'global-styles'  => new GlobalStylesPromotionStrategy( new ThemeJsonAdapter(), $this->gateway ),
		);

		$this->strategies = $this->real_strategies;

		add_filter( PromotionStrategies::FILTER, array( $this, 'provide_strategies' ) );
		PromotionStrategies::reset();

		// The semantic hash the RESOLVED state must equal after reset(): read
		// through the very method finalize compares against, while the fixture
		// theme files are the only resolution source (no database rows yet).
		$this->expected_hashes['templates:page']             = (string) $this->real_strategies['templates']->resolve_current_hash( 'page' );
		$this->expected_hashes['template-parts:site-header'] = (string) $this->real_strategies['template-parts']->resolve_current_hash( 'site-header' );
		$this->expected_hashes['global-styles:active']       = null;

		$this->seed_database_rows();

		$this->original_content_hash = $this->live_record( 'templates', 'page' )->content_hash();

		putenv( 'AGENCY_REPO_ROOT=' . $this->repo_root );
		putenv( 'AGENCY_STATE_DIR=' . $this->state_dir );
		putenv( 'AGENCY_DEPLOY_COMMIT=' . self::DEPLOY_COMMIT );
		putenv( 'AGENCY_DEPLOYMENT_ID=' . self::PROMOTION_ID );

		update_option( 'agency_platform_site_uuid', self::SITE_UUID );

		$this->set_keyring();

		$this->store->write( $this->sealed_page_manifest(), $this->manifest_path );

		$this->finalizer = new PromotionFinalizer( $this->gateway, $this->store );
		$this->confirmer = new PromotionConfirmer( $this->store );
		$this->rollback  = new PromotionRollback( $this->gateway, $this->store );
	}

	public function tear_down(): void {
		// The mixed-lifecycle test writes the prepared bytes over the REAL
		// theme.json (the equivalence resolution cannot see the fixture
		// directory — CORRECTION D8); it must be back byte-for-byte. The
		// write is harmless for tests that never touched the file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- restoring the real theme.json after an integration test; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->real_theme_json_path, $this->original_real_theme_json_bytes );

		self::assertSame(
			$this->original_real_theme_json_bytes,
			(string) file_get_contents( $this->real_theme_json_path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back the restored on-disk artefact; the WP_Filesystem credentials context does not exist here.
			'The real theme.json must be restored byte-identically after every test.'
		);

		remove_filter( 'stylesheet_directory', array( $this, 'fixture_stylesheet_directory' ) );
		remove_filter( 'template_directory', array( $this, 'fixture_stylesheet_directory' ) );
		remove_filter( PromotionStrategies::FILTER, array( $this, 'provide_strategies' ) );

		PromotionStrategies::reset();

		putenv( 'AGENCY_REPO_ROOT' );
		putenv( 'AGENCY_STATE_DIR' );
		putenv( 'AGENCY_DEPLOY_COMMIT' );
		putenv( 'AGENCY_DEPLOYMENT_ID' );

		$this->clear_keyring();

		$this->remove_tree( $this->tmp_dir );

		parent::tear_down();
	}

	public function test_confirm_marks_the_promotion_successful_and_keeps_the_backup(): void {
		$this->finalize_fixture();

		$outcome = $this->confirmer->confirm( $this->manifest_path );

		self::assertSame( 'confirmed', $outcome['manifest']->settlement_status() );
		self::assertTrue( ( new PromotionBackup( $this->promotion_id() ) )->exists( 'templates:page' ) );
	}

	public function test_confirm_releases_every_record_lock(): void {
		$this->finalize_fixture();

		$this->confirmer->confirm( $this->manifest_path );

		self::assertNull( ( new RecordLockManager( $this->promotion_id(), 'x' ) )->inspect( 'templates:page' ) );
	}

	public function test_confirm_is_idempotent(): void {
		$this->finalize_fixture();

		$this->confirmer->confirm( $this->manifest_path );

		$settled_before = $this->store->load_canonical( $this->promotion_id() )->settled_at_utc();

		$second = $this->confirmer->confirm( $this->manifest_path );

		self::assertSame( 0, $second['outcome']->exit_code() );
		self::assertSame(
			array( 'templates:page' => 'skipped' ),
			$second['outcome']->outcomes(),
			'A re-confirm must report the skipped record — an empty outcome map would pass an exit-code-only test.'
		);
		self::assertSame( $settled_before, $second['manifest']->settled_at_utc(), 'A re-confirm must not refresh the settlement timestamp.' );
	}

	public function test_confirm_refuses_a_promotion_that_was_never_finalized(): void {
		$this->store->write( $this->sealed_page_manifest(), $this->store->canonical_path( self::PROMOTION_ID ) );

		$exception = $this->assert_exit_code( 1, fn() => $this->confirmer->confirm( $this->manifest_path ) );

		self::assertStringContainsString( 'never finalized', $exception->getMessage() );
		self::assertStringContainsString( self::PROMOTION_ID, $exception->getMessage() );
	}

	/**
	 * The canonical-manifest authority (bounded-review gap): the SUPPLIED
	 * manifest is used ONLY to authenticate the promotion id. A forged
	 * supplied document that claims a DIFFERENT promotion id must not be
	 * confirmed against some other promotion's canonical copy — there is no
	 * canonical copy for the forged id, so it is a hard error.
	 */
	public function test_confirm_authenticates_the_promotion_id_against_the_canonical_store(): void {
		// The forged document is built BEFORE finalize (build_manifest reads
		// the live row, which finalize deletes) but only placed at the
		// supplied path AFTER finalize, so the canonical copy belongs to the
		// real promotion.
		$forged = $this->build_manifest( array( 'templates:page' ) )
			->with_field( 'promotionId', 'cccccccc-dddd-4eee-8fff-000000000001' )
			->with_deploy_commit( self::DEPLOY_COMMIT, '2026-08-01T10:00:00Z' );

		$this->finalize_fixture();

		$this->store->write( $forged, $this->manifest_path );

		$exception = $this->assert_exit_code( 1, fn() => $this->confirmer->confirm( $this->manifest_path ) );

		self::assertStringContainsString( 'No finalized promotion found for', $exception->getMessage() );
		self::assertStringContainsString( 'cccccccc-dddd-4eee-8fff-000000000001', $exception->getMessage() );
	}

	/**
	 * The canonical-manifest authority, second direction: a forged SUPPLIED
	 * document that claims the promotion was already confirmed in 2020 must
	 * be ignored — confirm reads the CANONICAL host copy, which is still
	 * pending, so it proceeds and stamps fresh settlement metadata.
	 */
	public function test_confirm_ignores_a_forged_supplied_manifest_and_uses_the_canonical_copy(): void {
		// The forged document is built BEFORE finalize (sealed_page_manifest
		// reads the live row, which finalize deletes) but only placed at the
		// supplied path AFTER finalize.
		$forged = $this->sealed_page_manifest()
			->with_field( 'settlementStatus', 'confirmed' )
			->with_field( 'settledAtUtc', '2020-01-01T00:00:00Z' )
			->with_field( 'retentionUntilUtc', '2020-02-01T00:00:00Z' );

		$this->finalize_fixture();

		$this->store->write( $forged, $this->manifest_path );

		$before  = time();
		$outcome = $this->confirmer->confirm( $this->manifest_path );
		$after   = time();

		self::assertSame( 'confirmed', $outcome['manifest']->settlement_status() );

		$canonical = $this->store->load_canonical( $this->promotion_id() );

		self::assertSame( 'confirmed', $canonical->settlement_status() );

		$settled = $canonical->settled_at_utc();

		self::assertNotNull( $settled );
		self::assertNotSame( '2020-01-01T00:00:00Z', $settled, 'The forged supplied settlement timestamp must never reach the canonical copy.' );
		self::assertGreaterThanOrEqual( $before, strtotime( $settled ) );
		self::assertLessThanOrEqual( $after, strtotime( $settled ) );

		// Retention is exactly settledAt + the 30-day window (master spec
		// §7.9; the safer end of the 14–30 range is chosen).
		self::assertSame(
			gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $settled ) + 30 * 86400 ),
			$canonical->retention_until_utc()
		);

		// The backup index carries the same settlement metadata.
		$rows = PromotionBackup::list_all();

		self::assertSame( 'confirmed', $rows[0]['settlementStatus'] );
		self::assertSame( $settled, $rows[0]['settledAtUtc'] );
	}

	public function test_rollback_restores_the_original_database_record(): void {
		$this->finalize_fixture();

		$outcome = $this->rollback->rollback( $this->manifest_path );

		self::assertSame( 0, $outcome['outcome']->exit_code() );
		self::assertSame( $this->original_content_hash, $this->gateway->read_live_record( 'templates', 'page' )['contentHash'] );
	}

	public function test_rollback_restores_multi_value_meta_as_separate_rows(): void {
		// update_post_meta() would collapse three values into one serialized array.
		$this->finalize_fixture();

		$outcome = $this->rollback->rollback( $this->manifest_path );

		self::assertSame( array( 'a', 'b', 'c' ), get_post_meta( $outcome['manifest']->record( 'templates:page' )['restoredObjectId'], 'multi', false ) );
	}

	public function test_rollback_records_the_restored_object_id_and_modification_marker(): void {
		// WordPress derives post_modified from post_date on insert, so the restored
		// row's marker differs from the original. Recording it is what lets a later
		// finalize pass its concurrency check.
		$this->finalize_fixture();

		$record = $this->rollback->rollback( $this->manifest_path )['manifest']->record( 'templates:page' );

		self::assertNotNull( $record['restoredObjectId'] );
		self::assertSame(
			get_post( $record['restoredObjectId'] )->post_modified_gmt,
			$record['originalModifiedGmt']
		);
		self::assertSame( 'pending', $record['finalizeStatus'] );
	}

	/**
	 * Orchestrator ruling (bounded-review finding 1): confirm is the point
	 * of no return — retention may prune the backups at any moment after it
	 * — so a confirmed manifest must be refused with exit 1 BEFORE any lock
	 * is acquired.
	 */
	public function test_rollback_refuses_a_confirmed_manifest(): void {
		$this->finalize_fixture();

		$this->confirmer->confirm( $this->manifest_path );

		$exception = $this->assert_exit_code( 1, fn() => $this->rollback->rollback( $this->manifest_path ) );

		self::assertStringContainsString( 'confirm', $exception->getMessage() );
	}

	/**
	 * Bounded-review finding 3: a manifest that was never finalized must be
	 * refused with the same guard and exit code confirm uses — a pending
	 * manifest must never be marked partially-rolled-back by a
	 * missing-backup refusal.
	 */
	public function test_rollback_refuses_a_manifest_that_was_never_finalized(): void {
		$this->store->write( $this->sealed_page_manifest(), $this->store->canonical_path( self::PROMOTION_ID ) );

		$exception = $this->assert_exit_code( 1, fn() => $this->rollback->rollback( $this->manifest_path ) );

		self::assertStringContainsString( 'never finalized', $exception->getMessage() );
		self::assertStringContainsString( self::PROMOTION_ID, $exception->getMessage() );
	}

	/**
	 * Bounded-review finding 2: a failure after the insert (terms or meta)
	 * must DELETE the newly created row — otherwise a retry sees the
	 * half-restored row as a client recreation and refuses, and the content
	 * is unrecoverable by the normal path.
	 */
	public function test_a_failed_meta_restore_deletes_the_partially_inserted_row(): void {
		$this->finalize_fixture();

		// add_post_metadata returning false makes every add_post_meta() call
		// fail AFTER wp_insert_post() has already created the row.
		add_filter( 'add_post_metadata', array( $this, 'refuse_meta_write' ), 10, 5 );

		try {
			$exception = $this->assert_exit_code( 1, fn() => $this->rollback->rollback( $this->manifest_path ) );

			self::assertStringContainsString( 'meta', $exception->getMessage() );

			$created_id = $this->real_strategies['templates']->last_restored_object_id();

			self::assertNotNull( $created_id, 'The restore must have inserted the row before the meta write failed.' );
			self::assertNull( get_post( $created_id ), 'A failed restore must delete the partially inserted row.' );
			self::assertCount( 0, $this->overrides_named( 'page' ) );
		} finally {
			remove_filter( 'add_post_metadata', array( $this, 'refuse_meta_write' ), 10 );
		}
	}

	public function test_rollback_is_idempotent(): void {
		$this->finalize_fixture();

		$this->rollback->rollback( $this->manifest_path );

		// The second run must SKIP the already-restored record, not re-check it
		// and report record-recreated against its own restoration.
		$second = $this->rollback->rollback( $this->manifest_path );

		self::assertSame( 0, $second['outcome']->exit_code() );
		self::assertSame( 'skipped', $second['outcome']->outcomes()['templates:page'] );
		self::assertCount( 1, $this->overrides_named( 'page' ) );
	}

	public function test_rollback_refuses_a_record_a_client_recreated_after_finalize(): void {
		$this->finalize_fixture();

		$this->create_override( 'page', '<!-- wp:paragraph --><p>newer client work</p><!-- /wp:paragraph -->' );

		$outcome = $this->rollback->rollback( $this->manifest_path );

		self::assertSame( 1, $outcome['outcome']->exit_code() );
		self::assertStringContainsString( 'newer client work', $this->read_override( 'page' )->post_content );
		self::assertSame( 'record-recreated', $outcome['manifest']->record( 'templates:page' )['rollbackRefusalReason'] );
	}

	public function test_rollback_refuses_a_record_a_newer_promotion_has_claimed(): void {
		$this->finalize_fixture();

		$this->register_later_promotion_for( 'templates:page' );

		self::assertSame(
			'claimed-by-newer-promotion',
			$this->rollback->rollback( $this->manifest_path )['manifest']->record( 'templates:page' )['rollbackRefusalReason']
		);
	}

	public function test_rollback_refuses_when_the_backup_was_already_pruned(): void {
		$this->finalize_fixture();

		$this->delete_backup( 'templates:page' );

		$outcome = $this->rollback->rollback( $this->manifest_path );

		self::assertSame( 1, $outcome['outcome']->exit_code() );
		self::assertSame( 'backup-missing', $outcome['manifest']->record( 'templates:page' )['rollbackRefusalReason'] );
	}

	/**
	 * Bounded-review gap: the present-state branch (a record finalize left
	 * in place, not deleted) must detect that the live row changed after
	 * finalisation and refuse with changed-since-finalize — never treat the
	 * changed row as a client recreation and never overwrite it.
	 */
	public function test_rollback_detects_a_changed_present_state_record(): void {
		$this->finalize_fixture();

		// The client recreated the record after finalisation...
		$this->create_override( 'page', '<!-- wp:paragraph --><p>client recreated</p><!-- /wp:paragraph -->' );

		// ...and the canonical manifest records the record as present-state
		// with a semantic hash that does not match the live row.
		$canonical = $this->store->load_canonical( $this->promotion_id() );

		$this->store->write_canonical(
			$canonical->with_record_changes(
				'templates:page',
				array(
					'postFinalizeRecordState'  => 'present',
					'postFinalizeSemanticHash' => str_repeat( 'f', 64 ),
					'postFinalizeModifiedGmt'  => null,
				)
			)
		);

		$outcome = $this->rollback->rollback( $this->manifest_path );

		self::assertSame( 'changed-since-finalize', $outcome['manifest']->record( 'templates:page' )['rollbackRefusalReason'] );
		self::assertSame( 'refused', $outcome['manifest']->record( 'templates:page' )['rollbackStatus'] );
		self::assertStringContainsString( 'client recreated', $this->read_override( 'page' )->post_content, 'A refused present-state record must survive rollback untouched.' );
	}

	/**
	 * A corrupt rollback payload must abort the run loudly: the backup is
	 * the only remaining copy of the customer's row, so a partial restore
	 * would destroy the last good copy.
	 */
	public function test_a_corrupt_rollback_payload_aborts_the_run(): void {
		$this->finalize_fixture();

		$this->corrupt_backup_chunk( 'templates:page' );

		$exception = $this->assert_exit_code( 1, fn() => $this->rollback->rollback( $this->manifest_path ) );

		self::assertStringContainsString( 'corrupt', $exception->getMessage() );
		self::assertSame(
			'pending',
			$this->store->load_canonical( $this->promotion_id() )->settlement_status(),
			'An aborted rollback must not settle anything.'
		);
	}

	/**
	 * A restore that itself throws must abort the rollback — reporting the
	 * record restored would lie about a row that was never recreated.
	 */
	public function test_a_restore_that_throws_aborts_the_rollback(): void {
		$this->finalize_fixture();

		// Replace the backup with a self-consistent payload that has no post
		// row, so the strategy's restore refuses AFTER retrieval succeeded.
		$this->replace_backup_without_post_row( 'templates:page' );

		$exception = $this->assert_exit_code( 1, fn() => $this->rollback->rollback( $this->manifest_path ) );

		self::assertStringContainsString( 'post row', $exception->getMessage() );
		self::assertNull(
			( new RecordLockManager( $this->promotion_id(), 'x' ) )->inspect( 'templates:page' ),
			'An aborted rollback must release every lock it acquired.'
		);
	}

	/**
	 * Bounded-review gap: a restored row whose content does NOT match the
	 * recorded original must be flagged restored-hash-mismatch and refused —
	 * never reported as a clean restore.
	 */
	public function test_a_restored_row_that_does_not_match_the_original_is_flagged(): void {
		$this->finalize_fixture();

		// Corrupt the recorded original hash in the canonical manifest: the
		// restore itself reproduces the real row byte-exactly, so only the
		// post-restore verification can catch the discrepancy.
		$canonical = $this->store->load_canonical( $this->promotion_id() );

		$this->store->write_canonical(
			$canonical->with_record_changes( 'templates:page', array( 'originalContentHash' => str_repeat( '0', 64 ) ) )
		);

		$outcome = $this->rollback->rollback( $this->manifest_path );

		self::assertSame( 1, $outcome['outcome']->exit_code() );
		self::assertSame( 'restored-hash-mismatch', $outcome['manifest']->record( 'templates:page' )['rollbackStatus'] );

		$reported = array();

		foreach ( $outcome['outcome']->refusals() as $refusal ) {
			$reported[] = $refusal->reason_code;
		}

		self::assertContains( 'restored-hash-mismatch', $reported, 'The refusal report must carry the mismatch reason.' );
		self::assertNotNull( $this->gateway->live_state_record( 'templates', 'page' ), 'The row must still be restored — only its verification failed.' );
	}

	/**
	 * Hardening gap: the hash-mismatch refusal must be as durable as every
	 * other refusal. refuse_record() writes BOTH rollbackStatus and
	 * rollbackRefusalReason; the restored-hash-mismatch path only wrote the
	 * status, so the manifest record an operator inspects afterwards — and
	 * the one a later run reads — carried a null reason while the outcome
	 * and the report said refused with restored-hash-mismatch.
	 */
	public function test_a_restored_row_with_a_hash_mismatch_records_the_refusal_reason(): void {
		$this->finalize_fixture();

		// Corrupt the recorded original hash in the canonical manifest: the
		// restore itself reproduces the real row byte-exactly, so only the
		// post-restore verification can catch the discrepancy.
		$canonical = $this->store->load_canonical( $this->promotion_id() );

		$this->store->write_canonical(
			$canonical->with_record_changes( 'templates:page', array( 'originalContentHash' => str_repeat( '0', 64 ) ) )
		);

		$record = $this->rollback->rollback( $this->manifest_path )['manifest']->record( 'templates:page' );

		self::assertSame( 'restored-hash-mismatch', $record['rollbackStatus'] );
		self::assertSame(
			'restored-hash-mismatch',
			$record['rollbackRefusalReason'],
			'The manifest record must carry the refusal reason on the hash-mismatch path.'
		);
	}

	/**
	 * Bounded-review gap: a PARTIAL rollback — at least one restored record
	 * and at least one refused — must settle as partially-rolled-back, never
	 * as a clean rolled-back.
	 */
	public function test_a_partial_rollback_reports_partially_rolled_back(): void {
		$path = $this->finalize_two_record_state();

		$this->create_override( 'page', '<!-- wp:paragraph --><p>newer client work</p><!-- /wp:paragraph -->' );

		$outcome = $this->rollback->rollback( $path );

		self::assertSame( 2, $outcome['outcome']->exit_code() );
		self::assertSame( 'partially-rolled-back', $outcome['manifest']->settlement_status() );
		self::assertSame( 'restored', $outcome['manifest']->record( 'template-parts:site-header' )['rollbackStatus'] );
		self::assertSame( 'refused', $outcome['manifest']->record( 'templates:page' )['rollbackStatus'] );
	}

	/**
	 * Bounded-review gap: the ROOT manifest finalizeStatus must return to
	 * pending after a rollback restores every record, so the same manifest
	 * is re-finalisable — the record-level check alone would miss a manifest
	 * left claiming 'complete'.
	 */
	public function test_a_rollback_resets_the_root_manifest_finalize_status(): void {
		$this->finalize_fixture();

		$outcome = $this->rollback->rollback( $this->manifest_path );

		self::assertSame( 0, $outcome['outcome']->exit_code() );
		self::assertSame( 'pending', $outcome['manifest']->finalize_status(), 'A full rollback must return the whole manifest to a re-finalisable state.' );
		self::assertSame( 'rolled-back', $outcome['manifest']->settlement_status() );
	}

	public function test_rollback_restores_parts_before_templates(): void {
		$order = $this->recorded_restore_order();

		self::assertSame( array( 'template-parts:site-header', 'templates:page' ), $order );
	}

	public function test_rollback_releases_every_lock_acquired_by_its_attempt_including_refusals(): void {
		$path = $this->finalize_two_record_state();

		$this->create_override( 'page', '<!-- wp:paragraph --><p>newer client work</p><!-- /wp:paragraph -->' );

		$this->rollback->rollback( $path );

		foreach ( array( 'template-parts:site-header', 'templates:page' ) as $key ) {
			self::assertNull( ( new RecordLockManager( $this->promotion_id(), 'x' ) )->inspect( $key ) );
		}
	}

	/**
	 * Orchestrator note 2: a rollback returns its records to a RE-FINALISABLE
	 * state. The restored row's objectId and originalModifiedGmt are rewritten
	 * into the manifest, so the next --finalize passes its concurrency check
	 * instead of refusing on a stale objectId.
	 */
	public function test_a_rollback_returns_the_record_to_a_re_finalisable_state(): void {
		$this->finalize_fixture();

		$first = $this->rollback->rollback( $this->manifest_path );

		self::assertSame( 0, $first['outcome']->exit_code() );

		$second = $this->finalizer->finalize( $this->manifest_path );

		self::assertSame( 0, $second['outcome']->exit_code() );
		self::assertSame( 'promoted', $second['manifest']->record( 'templates:page' )['finalizeStatus'] );
		self::assertSame( 'complete', $second['manifest']->finalize_status() );
	}

	/**
	 * Fix 3 (fix-diff review): the mixed-lifecycle gate. No test drove
	 * global-styles and template-parts through ONE manifest, yet that is
	 * where interaction defects live: Global Styles is the only strategy
	 * whose post_finalize_record_state() is 'present' and the only one that
	 * defers its post-reset hash, and both differ from templates inside the
	 * SHARED finalize, rollback and confirm paths. The walk is
	 * finalize → rollback → re-finalize → confirm; every step asserts, for
	 * BOTH records, the exit code, the per-record outcome, the manifest
	 * record state, and where the customer's content is.
	 */
	public function test_the_mixed_global_styles_and_template_parts_lifecycle(): void {
		$part_key   = 'template-parts:site-header';
		$styles_key = 'global-styles:active';

		$this->save_user_global_style( array( 'styles' => array( 'color' => array( 'background' => '#101010' ) ) ) );

		$part_content_before = $this->live_record( 'template-parts', 'site-header' )->content()['markup'];

		self::assertIsString( $part_content_before );
		self::assertStringContainsString( '<p>DB header body</p>', $part_content_before );

		$styles_object_id_before = $this->live_record( 'global-styles', 'active' )->object_id();
		$styles_content_before   = get_post( $styles_object_id_before, ARRAY_A )['post_content'];

		self::assertIsString( $styles_content_before );
		self::assertStringContainsString( '#101010', $styles_content_before );

		// The merged document must be BOTH the deployed fixture file (the
		// manifest's tamper check and the post-reset resolution read
		// get_stylesheet_directory()/theme.json — the fixture) AND the real
		// active theme.json (the equivalence resolution reads it — CORRECTION
		// D8, WP_Theme_JSON_Resolver ignores the directory filter).
		$merged = $this->stage_merged_global_styles();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the prepared bytes over the real active theme.json for the equivalence resolution; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->real_theme_json_path, $merged );

		$path = $this->state_dir . '/mixed.json';

		$this->store->write(
			$this->build_manifest( array( $part_key, $styles_key ) )->with_deploy_commit( self::DEPLOY_COMMIT, '2026-08-01T10:00:00Z' ),
			$path
		);

		// ---- finalize: both records promoted; the part row is gone, the
		// global-styles row is reset to the empty document.
		$finalized = $this->finalizer->finalize( $path );

		self::assertSame( 0, $finalized['outcome']->exit_code() );
		self::assertSame(
			array(
				$styles_key => PromotionOutcome::OUTCOME_PROMOTED,
				$part_key   => PromotionOutcome::OUTCOME_PROMOTED,
			),
			$finalized['outcome']->outcomes()
		);
		self::assertSame( 'promoted', $finalized['manifest']->record( $part_key )['finalizeStatus'] );
		self::assertSame( 'absent', $finalized['manifest']->record( $part_key )['postFinalizeRecordState'] );
		self::assertSame( 'promoted', $finalized['manifest']->record( $styles_key )['finalizeStatus'] );
		self::assertSame( 'present', $finalized['manifest']->record( $styles_key )['postFinalizeRecordState'] );
		self::assertSame( 'complete', $finalized['manifest']->finalize_status() );
		self::assertNull(
			$this->gateway->live_state_record( 'template-parts', 'site-header' ),
			'The finalized part row must be gone.'
		);
		self::assertNotSame(
			$styles_content_before,
			get_post( $styles_object_id_before, ARRAY_A )['post_content'],
			'The finalized global-styles row must be reset.'
		);

		// ---- rollback: both records restored; the customer's content is
		// back exactly where it was, on the same rows.
		$rolled_back = $this->rollback->rollback( $path );

		self::assertSame( 0, $rolled_back['outcome']->exit_code() );
		self::assertSame(
			array(
				$part_key   => PromotionOutcome::OUTCOME_RESTORED,
				$styles_key => PromotionOutcome::OUTCOME_RESTORED,
			),
			$rolled_back['outcome']->outcomes()
		);
		self::assertSame( 'restored', $rolled_back['manifest']->record( $part_key )['rollbackStatus'] );
		self::assertSame( 'restored', $rolled_back['manifest']->record( $styles_key )['rollbackStatus'] );
		self::assertSame( 'pending', $rolled_back['manifest']->record( $part_key )['finalizeStatus'] );
		self::assertSame( 'pending', $rolled_back['manifest']->record( $styles_key )['finalizeStatus'] );
		self::assertSame( 'pending', $rolled_back['manifest']->finalize_status() );
		self::assertSame( 'rolled-back', $rolled_back['manifest']->settlement_status() );

		self::assertSame(
			$part_content_before,
			$this->live_record( 'template-parts', 'site-header' )->content()['markup'],
			'The rolled-back part row must carry the original markup.'
		);
		self::assertSame(
			$styles_content_before,
			get_post( $styles_object_id_before, ARRAY_A )['post_content'],
			'The rolled-back global-styles row must be byte-identical to the original.'
		);
		self::assertSame(
			$styles_object_id_before,
			$this->live_record( 'global-styles', 'active' )->object_id(),
			'The rollback must restore the same global-styles row.'
		);

		// ---- re-finalize: the restored rows pass the rewritten concurrency
		// identity, so the SAME manifest finalizes again.
		$re_finalized = $this->finalizer->finalize( $path );

		self::assertSame( 0, $re_finalized['outcome']->exit_code() );
		self::assertSame(
			array(
				$styles_key => PromotionOutcome::OUTCOME_PROMOTED,
				$part_key   => PromotionOutcome::OUTCOME_PROMOTED,
			),
			$re_finalized['outcome']->outcomes()
		);
		self::assertSame( 'promoted', $re_finalized['manifest']->record( $part_key )['finalizeStatus'] );
		self::assertSame( 'promoted', $re_finalized['manifest']->record( $styles_key )['finalizeStatus'] );
		self::assertSame( 'complete', $re_finalized['manifest']->finalize_status() );
		self::assertNull(
			$this->gateway->live_state_record( 'template-parts', 'site-header' ),
			'The re-finalize must remove the part row again.'
		);
		self::assertNotSame(
			$styles_content_before,
			get_post( $styles_object_id_before, ARRAY_A )['post_content'],
			'The re-finalize must reset the global-styles row again.'
		);

		// ---- confirm: the point of no return, reached after the full
		// rollback → re-finalize round trip.
		$confirmed = $this->confirmer->confirm( $path );

		self::assertSame( 0, $confirmed['outcome']->exit_code() );
		self::assertSame( 'confirmed', $confirmed['manifest']->settlement_status() );
		self::assertNull(
			$this->gateway->live_state_record( 'template-parts', 'site-header' ),
			'Confirm must not touch the finalized part row.'
		);
		self::assertNotSame(
			$styles_content_before,
			get_post( $styles_object_id_before, ARRAY_A )['post_content'],
			'Confirm must not touch the finalized global-styles row.'
		);
	}

	/**
	 * Named filter callback — never a closure (master spec §4). Points
	 * get_stylesheet_directory() at the fixture theme directory, so the
	 * deployed prepared files and the file-backed template resolution both
	 * live in the fixture and the real theme is never touched.
	 */
	public function fixture_stylesheet_directory( string $stylesheet_dir ): string {
		return $this->theme_dir;
	}

	/**
	 * Named filter callback — never a closure (master spec §4). Returning
	 * false from add_post_metadata makes every add_post_meta() call fail, so
	 * the restore's post-insert meta step fails deterministically.
	 */
	public function refuse_meta_write( $check, $object_id, $meta_key, $meta_value, $unique ): bool {
		return false;
	}

	/**
	 * Named filter callback — never a closure (master spec §4).
	 *
	 * @param array<string, PromotionStrategy> $strategies
	 * @return array<string, PromotionStrategy>
	 */
	public function provide_strategies( array $strategies ): array {
		return $this->strategies;
	}

	/**
	 * Finalizes the single-record fixture through the REAL finalizer and
	 * requires a clean run — settlement tests must operate on the true
	 * post-finalize state.
	 */
	private function finalize_fixture(): void {
		$outcome = $this->finalizer->finalize( $this->manifest_path );

		self::assertSame( 0, $outcome['outcome']->exit_code() );
	}

	/**
	 * Writes and finalizes the two-record manifest (page + site-header) and
	 * returns its path.
	 */
	private function finalize_two_record_state(): string {
		$path = $this->state_dir . '/two-records.json';

		$this->store->write( $this->sealed_two_record_manifest(), $path );

		$outcome = $this->finalizer->finalize( $path );

		self::assertSame( 0, $outcome['outcome']->exit_code() );

		return $path;
	}

	/**
	 * Stages the LIVE global-styles origin into the fixture theme directory
	 * (whose theme.json starts as the shipped artefact's bytes) and returns
	 * the committed merged bytes — the deployed file the mixed manifest
	 * signs, and the bytes written over the REAL theme.json so the
	 * equivalence resolution sees them.
	 */
	private function stage_merged_global_styles(): string {
		$strategy = $this->real_strategies['global-styles'];
		$entry    = $strategy->stage( $this->live_record( 'global-styles', 'active' ), $this->theme_dir );
		$entry->commit();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back the staged fixture file; the WP_Filesystem credentials context does not exist here.
		return (string) file_get_contents( $entry->manifest_fields()['absolutePath'] );
	}

	/**
	 * Writes a user origin through the REAL adapter and attaches the theme
	 * term — the same path the production strategy uses. wp-phpunit runs
	 * with no current user, so core's on-demand post creation cannot attach
	 * the wp_theme term itself (see GlobalStylesPromotionTest for the
	 * rationale).
	 *
	 * @param array<string, mixed> $content
	 */
	private function save_user_global_style( array $content ): void {
		$adapter = new ThemeJsonAdapter();
		$adapter->refresh_caches();
		$adapter->write_user_origin( $content );

		$post = $adapter->user_origin_post();

		self::assertNotNull( $post, 'The fixture needs a Global Styles post to attach the theme term to.' );

		wp_set_object_terms( (int) $post['ID'], get_stylesheet(), 'wp_theme' );

		$adapter->refresh_caches();
	}

	/**
	 * The two-record restore order, recorded by wrapping strategies — an
	 * ORDER assertion needs both records, so this builds and finalizes the
	 * two-record state first.
	 *
	 * @return list<string> record keys in restore order
	 */
	private function recorded_restore_order(): array {
		$path = $this->finalize_two_record_state();

		$order = array();

		$this->strategies = array(
			'templates'      => new RecordingRestoreStrategy( $this->real_strategies['templates'], $order ),
			'template-parts' => new RecordingRestoreStrategy( $this->real_strategies['template-parts'], $order ),
		);

		PromotionStrategies::reset();

		$outcome = $this->rollback->rollback( $path );

		self::assertSame( 0, $outcome['outcome']->exit_code() );

		return $order;
	}

	/**
	 * The live wp_template rows the export saw, plus the part row the
	 * two-record manifest needs. The page row carries a multi-value meta
	 * key, so the restore's add_post_meta-per-value behaviour is proven
	 * against the real row.
	 */
	private function seed_database_rows(): void {
		$this->page_override_id = $this->make_template( 'page', '<!-- wp:paragraph --><p>DB page body</p><!-- /wp:paragraph -->' );
		$this->make_part( 'site-header', '<!-- wp:paragraph --><p>DB header body</p><!-- /wp:paragraph -->' );

		add_post_meta( $this->page_override_id, 'multi', 'a' );
		add_post_meta( $this->page_override_id, 'multi', 'b' );
		add_post_meta( $this->page_override_id, 'multi', 'c' );
	}

	private function create_override( string $slug, string $markup ): int {
		return $this->make_template( $slug, $markup );
	}

	/**
	 * Registers a LATER finalization of the same record key in the backup
	 * index: a new promotion stored a backup for the key and finalized it
	 * one hour from now, so the rollback's later-claims check must refuse.
	 */
	private function register_later_promotion_for( string $record_key ): void {
		$later_id = wp_generate_uuid4();

		( new PromotionBackup( $later_id ) )->store( $record_key, array( 'post' => array( 'ID' => 1 ) ) );
		( new PromotionBackup( $later_id ) )->mark_finalized( gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ) );
	}

	/**
	 * Removes every option row of one record key's backup — the state
	 * after retention pruning.
	 */
	private function delete_backup( string $record_key ): void {
		$prefix = PromotionBackup::OPTION_PREFIX . self::PROMOTION_ID . '_' . hash( 'sha256', $record_key );
		$meta   = get_option( $prefix . '_meta' );

		if ( is_array( $meta ) && is_int( $meta['chunks'] ?? null ) ) {
			for ( $index = 0; $index < $meta['chunks']; $index++ ) {
				delete_option( $prefix . '_c' . str_pad( (string) $index, 4, '0', STR_PAD_LEFT ) );
			}
		}

		delete_option( $prefix . '_meta' );
	}

	/**
	 * Corrupts chunk 0 of one record key's backup with VALID JSON, so
	 * retrieve() fails its sha256 verification and refuses loudly — a byte
	 * mismatch that still parses is exactly what only the sha256 check can
	 * catch.
	 */
	private function corrupt_backup_chunk( string $record_key ): void {
		$prefix = PromotionBackup::OPTION_PREFIX . self::PROMOTION_ID . '_' . hash( 'sha256', $record_key );

		update_option( $prefix . '_c0000', '{"post":[]}', false );
	}

	/**
	 * Overwrites one record key's backup options with a self-consistent
	 * payload that has NO post row, so the strategy's restore() refuses with
	 * its own hard error after retrieve() succeeded.
	 */
	private function replace_backup_without_post_row( string $record_key ): void {
		$prefix  = PromotionBackup::OPTION_PREFIX . self::PROMOTION_ID . '_' . hash( 'sha256', $record_key );
		$payload = wp_json_encode(
			array(
				'terms' => array(),
				'meta'  => array(),
			)
		);

		update_option(
			$prefix . '_meta',
			array(
				'chunks'    => 1,
				'bytes'     => strlen( (string) $payload ),
				'sha256'    => hash( 'sha256', (string) $payload ),
				'recordKey' => $record_key,
			),
			false
		);
		update_option( $prefix . '_c0000', $payload, false );
	}

	private function promotion_id(): string {
		return self::PROMOTION_ID;
	}

	/**
	 * The live wp_template row for a slug, or null when it no longer exists.
	 */
	private function read_override( string $slug ): ?\WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => 'wp_template',
				'post_status'    => array( 'publish', 'draft', 'private', 'trash' ),
				'name'           => $slug,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);

		return $posts[0] ?? null;
	}

	/**
	 * @return list<\WP_Post>
	 */
	private function overrides_named( string $slug ): array {
		return get_posts(
			array(
				'post_type'      => 'wp_template',
				'post_status'    => 'any',
				'name'           => $slug,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
	}

	private function live_record( string $provider, string $slug ): StateRecord {
		$live = $this->gateway->live_state_record( $provider, $slug );

		self::assertNotNull( $live, sprintf( 'Expected a live %s:%s record.', $provider, $slug ) );

		return $live;
	}

	/**
	 * The signed, sealed, single-record manifest (templates:page only), so
	 * the zero-success refusal tests get their exit 1.
	 */
	private function sealed_page_manifest(): PromotionManifest {
		return $this->build_manifest( array( 'templates:page' ) )->with_deploy_commit( self::DEPLOY_COMMIT, '2026-08-01T10:00:00Z' );
	}

	/**
	 * The signed, sealed two-record manifest (page + site-header).
	 */
	private function sealed_two_record_manifest(): PromotionManifest {
		return $this->build_manifest( array( 'template-parts:site-header', 'templates:page' ) )->with_deploy_commit( self::DEPLOY_COMMIT, '2026-08-01T10:00:00Z' );
	}

	/**
	 * A manifest whose header matches this host (stylesheet, version, uuid,
	 * URL, environment all read from the live environment) with the given
	 * records built from the LIVE database rows.
	 *
	 * @param list<string> $keys
	 */
	private function build_manifest( array $keys ): PromotionManifest {
		$theme = array(
			'stylesheet' => get_stylesheet(),
			'version'    => (string) wp_get_theme()->get( 'Version' ),
			'gitCommit'  => null,
		);

		$manifest = PromotionManifest::create(
			self::PROMOTION_ID,
			'2026-08-01T10:00:00Z',
			array(
				'exportId'      => '11111111-2222-4333-8444-555555555555',
				'exportedAtUtc' => '2026-08-01T09:00:00Z',
				'siteUrl'       => home_url(),
				'environment'   => wp_get_environment_type(),
				'activeTheme'   => $theme,
			),
			self::SITE_UUID,
			str_repeat( 'b', 40 ),
			array( 'npm run test:e2e' )
		);

		foreach ( $keys as $key ) {
			$manifest = $manifest->with_record( $key, $this->record_for( $key ) );
		}

		return $manifest;
	}

	/**
	 * One manifest record built from the LIVE row and the deployed file, so
	 * every hash is the value production actually compares against.
	 *
	 * @return array<string, mixed>
	 */
	private function record_for( string $key ): array {
		$parts = explode( ':', $key, 2 );

		self::assertCount( 2, $parts );

		$provider = $parts[0];
		$slug     = $parts[1];
		$live     = $this->live_record( $provider, $slug );
		$relative = $this->relative_prepared_path( $provider, $slug );

		return array(
			'key'                      => $key,
			'provider'                 => $provider,
			'slug'                     => $slug,
			'objectId'                 => $live->object_id(),
			'originalContentHash'      => $live->content_hash(),
			'originalModifiedGmt'      => $live->modified_gmt(),
			'preparedFilePath'         => $relative,
			'themeRelativePath'        => $relative,
			'preparedFileHash'         => $this->deployed_file_sha256( $provider, $slug ),
			'originalFileHash'         => null,
			'referenceScan'            => array(),
			'navigationExpectation'    => array(),
			'expectedPostResetHash'    => $this->expected_hashes[ $key ],
			'preResetResolvedHash'     => null,
			'postFinalizeRecordState'  => null,
			'postFinalizeSemanticHash' => null,
			'postFinalizeModifiedGmt'  => null,
			'finalizeStatus'           => 'pending',
			'finalizeRefusalReason'    => null,
			'rollbackStatus'           => 'not-attempted',
			'rollbackRefusalReason'    => null,
			'restoredObjectId'         => null,
		);
	}

	private function relative_prepared_path( string $provider, string $slug ): string {
		if ( 'global-styles' === $provider ) {
			return 'theme.json';
		}

		return ( 'templates' === $provider ? 'templates/' : 'parts/' ) . $slug . '.html';
	}

	private function deployed_file_sha256( string $provider, string $slug ): string {
		$path = $this->theme_dir . '/' . $this->relative_prepared_path( $provider, $slug );

		$hash = is_file( $path ) ? hash_file( 'sha256', $path ) : false;

		return false === $hash ? '' : $hash;
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

	/**
	 * Removes an integration fixture directory and everything inside it.
	 */
	private function remove_tree( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		foreach ( scandir( $directory ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $directory . '/' . $entry;

			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_tree( $path );

				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
			unlink( $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- deleting an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		rmdir( $directory );
	}
}

/**
 * A PreparablePromotionStrategy that records every restore() call in a
 * shared, by-reference order list — the only way to assert the rollback's
 * leaves-first restore ORDER rather than its outcome.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- the plan pins this recording strategy to the settlement test file so the restore order is pinned without a shared fixtures file.
final class RecordingRestoreStrategy implements PreparablePromotionStrategy {

	/**
	 * @param array<int, string> $order
	 */
	public function __construct( private PreparablePromotionStrategy $inner, private array &$order ) {}

	public function provider_slug(): string {
		return $this->inner->provider_slug();
	}

	/**
	 * @return array{preparedPath: string, preparedHash: string, originalHash: string|null}
	 */
	public function prepare( StateRecord $record, string $target_path ): array {
		return $this->inner->prepare( $record, $target_path );
	}

	public function reset( StateRecord $record ): void {
		$this->inner->reset( $record );
	}

	/**
	 * @param array<string, mixed> $backup
	 */
	public function restore( StateRecord $record, array $backup ): void {
		$this->order[] = $record->key();

		$this->inner->restore( $record, $backup );
	}

	public function expected_post_reset_hash( StateRecord $record ): string {
		return $this->inner->expected_post_reset_hash( $record );
	}

	public function stage( StateRecord $record, string $theme_root ): StagedPromotionEntry {
		return $this->inner->stage( $record, $theme_root );
	}

	public function theme_relative_path( string $record_slug ): string {
		return $this->inner->theme_relative_path( $record_slug );
	}

	public function declares( ThemeDeclaredSlugs $declared, string $record_slug ): bool {
		return $this->inner->declares( $declared, $record_slug );
	}

	public function post_finalize_record_state(): string {
		return $this->inner->post_finalize_record_state();
	}

	public function defers_expected_hash(): bool {
		return $this->inner->defers_expected_hash();
	}

	public function resolve_current_hash( string $record_slug ): ?string {
		return $this->inner->resolve_current_hash( $record_slug );
	}

	/**
	 * @param array<string, mixed> $bundle_record
	 * @param list<string>         $selected_keys
	 * @return list<RecordRefusal>
	 */
	public function validate_for_promotion( array $bundle_record, BundleView $bundle, array $selected_keys ): array {
		return $this->inner->validate_for_promotion( $bundle_record, $bundle, $selected_keys );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function capture_backup( StateRecord $record ): array {
		return $this->inner->capture_backup( $record );
	}
}
