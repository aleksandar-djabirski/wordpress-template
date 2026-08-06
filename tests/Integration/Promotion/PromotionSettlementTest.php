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
use AgencyPlatform\State\Promotion\ManifestStore;
use AgencyPlatform\State\Promotion\PreparablePromotionStrategy;
use AgencyPlatform\State\Promotion\PromotionBackup;
use AgencyPlatform\State\Promotion\PromotionConfirmer;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionFinalizer;
use AgencyPlatform\State\Promotion\PromotionManifest;
use AgencyPlatform\State\Promotion\PromotionRollback;
use AgencyPlatform\State\Promotion\RecordLockManager;
use AgencyPlatform\State\Promotion\StagedPromotionEntry;
use AgencyPlatform\State\Promotion\StateGateway;
use AgencyPlatform\State\Promotion\TemplatePartPromotionStrategy;
use AgencyPlatform\State\Promotion\TemplatePromotionStrategy;
use AgencyPlatform\State\Promotion\ThemeDeclaredSlugs;
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

	/** @var array<string, string> expected post-reset semantic hash per record key */
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

		add_filter( 'stylesheet_directory', array( $this, 'fixture_stylesheet_directory' ) );
		// _get_block_template_file() maps BOTH get_stylesheet_directory() and
		// get_template_directory() — with no child theme the two array keys
		// collide and the template entry wins — so the resolver only ever sees
		// the fixture when both filters point at it.
		add_filter( 'template_directory', array( $this, 'fixture_stylesheet_directory' ) );

		$this->gateway = new StateGateway();
		$this->store   = new ManifestStore( $this->gateway );

		$this->real_strategies = array(
			'templates'      => new TemplatePromotionStrategy(),
			'template-parts' => new TemplatePartPromotionStrategy(),
		);

		$this->strategies = $this->real_strategies;

		add_filter( PromotionStrategies::FILTER, array( $this, 'provide_strategies' ) );
		PromotionStrategies::reset();

		// The semantic hash the RESOLVED state must equal after reset(): read
		// through the very method finalize compares against, while the fixture
		// theme files are the only resolution source (no database rows yet).
		$this->expected_hashes['templates:page']             = (string) $this->real_strategies['templates']->resolve_current_hash( 'page' );
		$this->expected_hashes['template-parts:site-header'] = (string) $this->real_strategies['template-parts']->resolve_current_hash( 'site-header' );

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

		self::assertSame( 0, $this->confirmer->confirm( $this->manifest_path )['outcome']->exit_code() );
	}

	public function test_confirm_refuses_a_promotion_that_was_never_finalized(): void {
		$this->store->write( $this->sealed_page_manifest(), $this->store->canonical_path( self::PROMOTION_ID ) );

		$exception = $this->assert_exit_code( 1, fn() => $this->confirmer->confirm( $this->manifest_path ) );

		self::assertStringContainsString( 'never finalized', $exception->getMessage() );
		self::assertStringContainsString( self::PROMOTION_ID, $exception->getMessage() );
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
