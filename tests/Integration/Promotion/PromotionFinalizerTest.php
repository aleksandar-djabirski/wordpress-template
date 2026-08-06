<?php
/**
 * The finalise stage of the promotion lifecycle (BLOCK_THEME_PROPOSAL.md
 * §7.8): the run-level guards first — sealed manifest, deploy commit match,
 * theme stylesheet AND version match, site uuid / URL / environment match,
 * and every deployed file still matching its signed hash — then the
 * per-record promotion loop with its concurrency re-read, backup, reset and
 * post-reset semantic verification.
 *
 * The fixture is a throwaway state directory plus a throwaway theme
 * directory that get_stylesheet_directory() is filtered to point at, so the
 * "deployed" prepared files live in the fixture and the REAL shipped theme
 * is never touched. Live wp_template / wp_template_part rows seed the
 * database side; the manifest is built from the LIVE records (real
 * contentHash / modifiedGmt), so a finalize that does not actually re-read
 * the row cannot pass. AGENCY_* settings and the HMAC keyring are driven
 * through putenv(), the same environment plumbing the other promotion tests
 * use.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Promotion\ManifestStore;
use AgencyPlatform\State\Promotion\PreparablePromotionStrategy;
use AgencyPlatform\State\Promotion\PromotionBackup;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionFinalizer;
use AgencyPlatform\State\Promotion\PromotionManifest;
use AgencyPlatform\State\Promotion\RecordLockManager;
use AgencyPlatform\State\Promotion\StateGateway;
use AgencyPlatform\State\Promotion\TemplatePartPromotionStrategy;
use AgencyPlatform\State\Promotion\TemplatePromotionStrategy;
use AgencyPlatform\State\PromotionStrategies;
use AgencyPlatform\State\PromotionStrategy;
use AgencyPlatform\State\StateRecord;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\State\SeedsStateFixtures;

/**
 * @covers \AgencyPlatform\State\Promotion\PromotionFinalizer
 */
// putenv() is how these tests drive AGENCY_* settings and the HMAC keyring
// through EnvironmentConfig's process-environment fallback without
// WordPress or real .env files loaded; WordPress's discouraged-function
// sniff would otherwise flag every call.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class PromotionFinalizerTest extends IntegrationTestCase {

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
	private string $unsealed_manifest_path;
	private string $deployed_file;
	private string $deployed_part_file;
	private string $original_content_hash;

	private StateGateway $gateway;
	private ManifestStore $store;
	private PromotionFinalizer $finalizer;

	/** @var array<string, PreparablePromotionStrategy> */
	private array $strategies = array();

	/** @var array<string, string> expected post-reset semantic hash per record key */
	private array $expected_hashes = array();

	public function set_up(): void {
		parent::set_up();

		$tmp = sys_get_temp_dir() . '/promotion-finalizer-' . uniqid( '', true );

		$this->tmp_dir   = $tmp;
		$this->repo_root = $tmp . '/repo';
		$this->theme_dir = $tmp . '/theme';
		$this->state_dir = $tmp . '/state';

		$this->manifest_path          = $this->state_dir . '/manifest.json';
		$this->unsealed_manifest_path = $this->state_dir . '/unsealed.json';

		$this->deployed_file      = $this->theme_dir . '/templates/page.html';
		$this->deployed_part_file = $this->theme_dir . '/parts/site-header.html';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->repo_root, 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->theme_dir . '/templates', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->theme_dir . '/parts', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->state_dir, 0700, true );

		// The "deployed" prepared files: the bytes finalize hashes against the
		// recorded preparedFileHash.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing integration fixture files; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->deployed_file, "<!-- wp:paragraph --><p>Page body</p><!-- /wp:paragraph -->\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing integration fixture files; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->deployed_part_file, "<!-- wp:paragraph --><p>Header body</p><!-- /wp:paragraph -->\n" );

		add_filter( 'stylesheet_directory', array( $this, 'fixture_stylesheet_directory' ) );
		// _get_block_template_file() maps BOTH get_stylesheet_directory() and
		// get_template_directory() — with no child theme the two array keys
		// collide and the template entry wins — so the resolver only ever sees
		// the fixture when both filters point at it.
		add_filter( 'template_directory', array( $this, 'fixture_stylesheet_directory' ) );

		$this->gateway = new StateGateway();
		$this->store   = new ManifestStore( $this->gateway );

		$this->strategies = array(
			'templates'      => new TemplatePromotionStrategy(),
			'template-parts' => new TemplatePartPromotionStrategy(),
		);

		add_filter( PromotionStrategies::FILTER, array( $this, 'provide_strategies' ) );
		PromotionStrategies::reset();

		// The semantic hash the RESOLVED state must equal after reset(): read
		// through the very method finalize compares against, while the fixture
		// theme files are the only resolution source (no database rows yet).
		$this->expected_hashes['templates:page']             = (string) $this->strategies['templates']->resolve_current_hash( 'page' );
		$this->expected_hashes['template-parts:site-header'] = (string) $this->strategies['template-parts']->resolve_current_hash( 'site-header' );

		$this->seed_database_rows();

		$this->original_content_hash = $this->live_record( 'templates', 'page' )->content_hash();

		putenv( 'AGENCY_REPO_ROOT=' . $this->repo_root );
		putenv( 'AGENCY_STATE_DIR=' . $this->state_dir );
		putenv( 'AGENCY_DEPLOY_COMMIT=' . self::DEPLOY_COMMIT );
		putenv( 'AGENCY_DEPLOYMENT_ID=' . self::PROMOTION_ID );

		update_option( 'agency_platform_site_uuid', self::SITE_UUID );

		$this->set_keyring();

		$this->store->write( $this->sealed_page_manifest(), $this->manifest_path );
		$this->store->write( $this->build_manifest( array( 'templates:page' ) ), $this->unsealed_manifest_path );

		$this->finalizer = new PromotionFinalizer( $this->gateway, $this->store );
	}

	public function tear_down(): void {
		remove_filter( 'stylesheet_directory', array( $this, 'fixture_stylesheet_directory' ) );
		remove_filter( 'template_directory', array( $this, 'fixture_stylesheet_directory' ) );
		remove_filter( PromotionStrategies::FILTER, array( $this, 'provide_strategies' ) );
		remove_filter( 'pre_delete_post', array( $this, 'refuse_post_delete' ) );

		PromotionStrategies::reset();

		putenv( 'AGENCY_REPO_ROOT' );
		putenv( 'AGENCY_STATE_DIR' );
		putenv( 'AGENCY_DEPLOY_COMMIT' );
		putenv( 'AGENCY_DEPLOYMENT_ID' );

		$this->clear_keyring();

		$this->remove_tree( $this->tmp_dir );

		parent::tear_down();
	}

	public function test_finalize_refuses_an_unsealed_manifest(): void {
		$this->assert_exit_code( 1, fn() => $this->finalizer->finalize( $this->unsealed_manifest_path ) );
	}

	public function test_finalize_refuses_a_deploy_commit_mismatch(): void {
		putenv( 'AGENCY_DEPLOY_COMMIT=' . str_repeat( 'b', 40 ) );

		$this->assert_exit_code( 1, fn() => $this->finalizer->finalize( $this->manifest_path ) );
	}

	public function test_finalize_refuses_a_theme_version_mismatch(): void {
		// The stylesheet still matches; only the version moved. The deployed files
		// are therefore not the ones the manifest was prepared against.
		$this->set_manifest_theme_version( '9.9.9' );

		$this->assert_exit_code( 1, fn() => $this->finalizer->finalize( $this->manifest_path ) );
	}

	/**
	 * Master spec §7.7 checks all four identities together — stylesheet, uuid,
	 * URL and environment — never the uuid alone. Each mismatch must refuse
	 * the whole run with exit 1 and write nothing.
	 */
	public function test_finalize_refuses_a_stylesheet_uuid_url_or_environment_mismatch(): void {
		$theme = $this->build_manifest( array( 'templates:page' ) )->active_theme();

		$this->rewrite_manifest_field(
			'activeTheme',
			array(
				'stylesheet' => 'other-theme',
				'version'    => $theme['version'],
				'gitCommit'  => $theme['gitCommit'],
			)
		);
		$this->assert_exit_code( 1, fn() => $this->finalizer->finalize( $this->manifest_path ) );

		$this->rewrite_manifest_field( 'siteUuid', '00000000-0000-4000-8000-000000000000' );
		$this->assert_exit_code( 1, fn() => $this->finalizer->finalize( $this->manifest_path ) );

		$this->rewrite_manifest_field( 'siteUrl', 'https://other.example.com' );
		$this->assert_exit_code( 1, fn() => $this->finalizer->finalize( $this->manifest_path ) );

		$this->rewrite_manifest_field( 'environment', 'staging' );
		$this->assert_exit_code( 1, fn() => $this->finalizer->finalize( $this->manifest_path ) );

		self::assertFalse(
			$this->store->canonical_exists( self::PROMOTION_ID ),
			'A run-level identity refusal must write no canonical manifest.'
		);
	}

	public function test_finalize_refuses_a_missing_or_altered_deployed_file(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- rewriting an integration fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->deployed_file, "<!-- wp:paragraph --><p>swapped</p><!-- /wp:paragraph -->\n" );

		$this->assert_exit_code( 4, fn() => $this->finalizer->finalize( $this->manifest_path ) );
	}

	public function test_finalize_exits_three_when_another_promotion_holds_a_record_lock(): void {
		( new RecordLockManager( wp_generate_uuid4(), 'other' ) )->acquire( array( 'templates:page' ) );

		$this->assert_exit_code( 3, fn() => $this->finalizer->finalize( $this->manifest_path ) );
	}

	public function test_a_client_edit_after_export_refuses_that_record(): void {
		$this->edit_template_override_after_export();

		$outcome = $this->finalizer->finalize( $this->manifest_path );

		self::assertSame( 1, $outcome['outcome']->exit_code() );
		self::assertNotNull( $this->read_override( 'page' ) );
		self::assertSame( 'concurrent-edit', $outcome['manifest']->record( 'templates:page' )['finalizeRefusalReason'] );
		self::assertSame( 'refused', $outcome['manifest']->record( 'templates:page' )['finalizeStatus'] );
	}

	public function test_a_record_deleted_after_export_is_refused_not_silently_accepted(): void {
		// A missing pending record means somebody deleted the override between
		// export and finalize. That is a concurrent change, not an idempotent
		// re-run, and it must never be reported as a success.
		$this->delete_override( 'page' );

		$outcome = $this->finalizer->finalize( $this->manifest_path );

		self::assertSame( 1, $outcome['outcome']->exit_code() );
		self::assertSame( 'concurrent-delete', $outcome['manifest']->record( 'templates:page' )['finalizeRefusalReason'] );
	}

	/**
	 * Bounded-review finding 4: the concurrency check must ALSO compare
	 * objectId. A row deleted and recreated with identical content and an
	 * identical modification marker slips past the content-hash and
	 * modified-gmt comparisons alone — the recreated row must be refused as
	 * a concurrent edit, never promoted.
	 */
	public function test_a_recreated_row_with_identical_content_and_marker_is_refused(): void {
		$original = $this->read_override( 'page' );

		self::assertNotNull( $original );

		$original_id = (int) $original->ID;

		$this->delete_override( 'page' );

		// Recreate the row with the SAME content and the ORIGINAL row's
		// dates: wp_insert_post() derives post_modified/post_modified_gmt
		// from post_date/post_date_gmt on insert (wp-includes/post.php), so
		// the recreated row carries the same content hash AND the same
		// modification marker — only its objectId differs from what the
		// manifest recorded.
		$recreated_id = wp_insert_post(
			array(
				'post_type'     => 'wp_template',
				'post_name'     => 'page',
				'post_status'   => 'publish',
				'post_content'  => '<!-- wp:paragraph --><p>DB page body</p><!-- /wp:paragraph -->',
				'post_date'     => $original->post_modified,
				'post_date_gmt' => $original->post_modified_gmt,
			),
			true
		);

		self::assertNotWPError( $recreated_id );

		wp_set_object_terms( (int) $recreated_id, get_stylesheet(), 'wp_theme' );

		$recreated = $this->read_override( 'page' );

		self::assertNotNull( $recreated );
		self::assertNotSame( $original_id, (int) $recreated->ID, 'The recreated row must be a NEW row for this test to mean anything.' );
		self::assertSame( $original->post_modified_gmt, $recreated->post_modified_gmt, 'The recreated row must carry the same modification marker, so only the objectId comparison can catch the swap.' );
		self::assertSame( $this->original_content_hash, $this->live_record( 'templates', 'page' )->content_hash(), 'The recreated row must carry the same content hash.' );

		$outcome = $this->finalizer->finalize( $this->manifest_path );

		self::assertSame( 1, $outcome['outcome']->exit_code() );
		self::assertSame( 'concurrent-edit', $outcome['manifest']->record( 'templates:page' )['finalizeRefusalReason'] );
		self::assertNotNull( $this->read_override( 'page' ), 'A refused record must survive finalize untouched.' );
	}

	public function test_locks_are_released_for_every_record_that_was_not_promoted(): void {
		$this->edit_template_override_after_export();

		$this->finalizer->finalize( $this->manifest_path );

		self::assertNull( ( new RecordLockManager( $this->promotion_id(), 'x' ) )->inspect( 'templates:page' ) );
	}

	public function test_a_failed_reset_refuses_without_restoring(): void {
		$this->make_delete_post_fail();

		$outcome = $this->finalizer->finalize( $this->manifest_path );

		self::assertSame( 'reset-failed', $outcome['manifest']->record( 'templates:page' )['finalizeRefusalReason'] );
		self::assertCount( 1, $this->overrides_named( 'page' ) );   // no duplicate restore
	}

	public function test_an_unresolvable_template_after_reset_restores_the_record(): void {
		$this->delete_the_theme_template_file();

		$outcome = $this->finalizer->finalize( $this->manifest_path );

		self::assertSame( 'post-reset-unresolved', $outcome['manifest']->record( 'templates:page' )['finalizeRefusalReason'] );
		self::assertNotNull( $this->read_override( 'page' ) );
		self::assertSame(
			$this->gateway->live_state_record( 'templates', 'page' )?->content_hash(),
			$this->original_content_hash,
			'The restored row must carry the original content, not a half-restored one.'
		);
	}

	/**
	 * The clean run: the record is promoted, the database override is
	 * deleted, the template resolves from the deployed file, the backup
	 * exists and the canonical host manifest is written.
	 */
	public function test_a_clean_run_promotes_the_record_and_leaves_the_template_resolving_from_the_file(): void {
		$outcome = $this->finalizer->finalize( $this->manifest_path );

		self::assertSame( 0, $outcome['outcome']->exit_code() );
		self::assertSame( 'complete', $outcome['manifest']->finalize_status() );
		self::assertNull( $this->read_override( 'page' ) );

		$record = $outcome['manifest']->record( 'templates:page' );

		self::assertSame( 'promoted', $record['finalizeStatus'] );
		self::assertSame( 'absent', $record['postFinalizeRecordState'] );
		self::assertSame( $this->expected_hashes['templates:page'], $record['postFinalizeSemanticHash'] );
		self::assertNull( $record['postFinalizeModifiedGmt'] );
		self::assertTrue( ( new PromotionBackup( self::PROMOTION_ID ) )->exists( 'templates:page' ) );
		self::assertTrue( $this->store->canonical_exists( self::PROMOTION_ID ) );

		// The template now resolves from the deployed file with the expected hash.
		self::assertSame( $this->expected_hashes['templates:page'], $this->strategies['templates']->resolve_current_hash( 'page' ) );
	}

	public function test_a_second_finalize_is_idempotent_and_changes_nothing(): void {
		$first  = $this->finalizer->finalize( $this->manifest_path );
		$before = hash_file( 'sha256', $this->manifest_path );

		$second = $this->finalizer->finalize( $this->manifest_path );

		self::assertSame( 0, $second['outcome']->exit_code() );
		self::assertSame( 'skipped', $second['outcome']->outcomes()['templates:page'] );
		self::assertSame( $before, hash_file( 'sha256', $this->manifest_path ), 'A re-finalize must not rewrite the manifest.' );
		self::assertSame( 'complete', $first['manifest']->finalize_status() );
		self::assertNotNull(
			( new RecordLockManager( $this->promotion_id(), 'x' ) )->inspect( 'templates:page' ),
			'The promoted record keeps its lock until --confirm or --rollback.'
		);
	}

	/**
	 * A two-record run where one record was edited after export: the clean
	 * record promotes, the edited one refuses, the exit code is 2, and only
	 * the refused record's lock is released — the promoted one stays held
	 * for settlement.
	 */
	public function test_a_two_record_run_with_one_refusal_exits_two_and_releases_only_the_refused_lock(): void {
		$two_record_path = $this->state_dir . '/two-records.json';

		$this->store->write( $this->sealed_two_record_manifest(), $two_record_path );

		$this->edit_template_override_after_export();

		$outcome = $this->finalizer->finalize( $two_record_path );

		self::assertSame( 2, $outcome['outcome']->exit_code() );
		self::assertSame( 'partial', $outcome['manifest']->finalize_status() );
		self::assertSame( 'concurrent-edit', $outcome['manifest']->record( 'templates:page' )['finalizeRefusalReason'] );
		self::assertSame( 'promoted', $outcome['manifest']->record( 'template-parts:site-header' )['finalizeStatus'] );
		self::assertNull( ( new RecordLockManager( $this->promotion_id(), 'x' ) )->inspect( 'templates:page' ) );
		self::assertNotNull(
			( new RecordLockManager( $this->promotion_id(), 'x' ) )->inspect( 'template-parts:site-header' ),
			'The promoted record keeps its lock until --confirm or --rollback.'
		);
	}

	/**
	 * The finalize-side navigation wiring: a record whose expectation cannot
	 * be reproduced on the target (no published wp_navigation post resolves
	 * the exported hash) must refuse before anything is destroyed.
	 */
	public function test_a_record_whose_navigation_expectation_cannot_be_reproduced_is_refused(): void {
		$this->store->write(
			$this->build_manifest( array( 'templates:page' ) )->with_record_changes(
				'templates:page',
				array(
					'navigationExpectation' => array(
						array(
							'originalRef'                => null,
							'exportedNavigationHash'     => str_repeat( 'e', 64 ),
							'exportedNavigationIdentity' => null,
						),
					),
				)
			)->with_deploy_commit( self::DEPLOY_COMMIT, '2026-08-01T10:00:00Z' ),
			$this->manifest_path
		);

		$outcome = $this->finalizer->finalize( $this->manifest_path );

		self::assertSame( 1, $outcome['outcome']->exit_code() );
		self::assertSame( 'missing-navigation', $outcome['manifest']->record( 'templates:page' )['finalizeRefusalReason'] );
		self::assertNotNull( $this->read_override( 'page' ), 'A navigation refusal must leave the database row untouched.' );
	}

	/**
	 * The restore-on-mismatch path: a corrupted expectedPostResetHash — the
	 * deployed file is intact, so only the post-reset comparison can catch
	 * the drift — must restore the database row from the backup, refuse the
	 * record and KEEP the backup.
	 */
	public function test_a_post_reset_hash_mismatch_restores_the_record_and_refuses(): void {
		$this->store->write(
			$this->sealed_page_manifest()->with_record_changes( 'templates:page', array( 'expectedPostResetHash' => str_repeat( 'd', 64 ) ) ),
			$this->manifest_path
		);

		$outcome = $this->finalizer->finalize( $this->manifest_path );

		self::assertSame( 'post-reset-mismatch', $outcome['manifest']->record( 'templates:page' )['finalizeRefusalReason'] );
		self::assertSame( 'restored', $outcome['manifest']->record( 'templates:page' )['finalizeStatus'] );
		self::assertNotNull( $this->read_override( 'page' ) );
		self::assertSame(
			$this->original_content_hash,
			$this->gateway->live_state_record( 'templates', 'page' )?->content_hash(),
			'The restored row must carry the original content, not a half-restored one.'
		);
		self::assertTrue( ( new PromotionBackup( self::PROMOTION_ID ) )->exists( 'templates:page' ), 'A post-reset mismatch must NOT delete the backup.' );
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
	 * Named filter callback — never a closure (master spec §4).
	 *
	 * @param array<string, PromotionStrategy> $strategies
	 * @return array<string, PromotionStrategy>
	 */
	public function provide_strategies( array $strategies ): array {
		return $this->strategies;
	}

	/**
	 * Named filter callback that makes every wp_delete_post() call return
	 * false: pre_delete_post short-circuits the delete with the returned
	 * value, so the strategy's reset() refusal is reached deterministically.
	 */
	public function refuse_post_delete( $delete, $post ): bool {
		return false;
	}

	/**
	 * The live wp_template row the export saw, plus the part row the
	 * two-record manifest needs. The page row deliberately uses markup that
	 * differs from the deployed file, so the reset actually changes the
	 * resolved state and the post-reset comparison is not a tautology.
	 */
	private function seed_database_rows(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>DB page body</p><!-- /wp:paragraph -->' );
		$this->make_part( 'site-header', '<!-- wp:paragraph --><p>DB header body</p><!-- /wp:paragraph -->' );
	}

	/**
	 * Changes the page override's content AFTER the manifest was built, so
	 * both the content hash and the modification marker differ from what the
	 * manifest records — the concurrency check must refuse instead of
	 * silently overwriting the customer's edit.
	 */
	private function edit_template_override_after_export(): void {
		wp_update_post(
			array(
				'ID'           => $this->override_id( 'page' ),
				'post_content' => '<!-- wp:paragraph --><p>edited after export</p><!-- /wp:paragraph -->',
			)
		);
	}

	private function delete_override( string $slug ): void {
		wp_delete_post( $this->override_id( $slug ), true );
	}

	private function delete_the_theme_template_file(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting an integration fixture file; the WP_Filesystem credentials context does not exist here.
		unlink( $this->deployed_file );
	}

	private function make_delete_post_fail(): void {
		add_filter( 'pre_delete_post', array( $this, 'refuse_post_delete' ), 10, 2 );
	}

	private function promotion_id(): string {
		return self::PROMOTION_ID;
	}

	/**
	 * The one template override row for a slug, or null.
	 */
	private function override_id( string $slug ): int {
		$override = $this->read_override( $slug );

		self::assertNotNull( $override, sprintf( 'Expected a live %s override.', $slug ) );

		return (int) $override->ID;
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

	/**
	 * The live record behind one manifest record, read through the gateway.
	 */
	private function live_record( string $provider, string $slug ): StateRecord {
		$live = $this->gateway->live_state_record( $provider, $slug );

		self::assertNotNull( $live, sprintf( 'Expected a live %s:%s record.', $provider, $slug ) );

		return $live;
	}

	/**
	 * The signed, sealed, single-record manifest every refusal test drives.
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
		$hash = hash_file( 'sha256', $this->theme_dir . '/' . $this->relative_prepared_path( $provider, $slug ) );

		return false === $hash ? '' : $hash;
	}

	/**
	 * Rewrites the single-record manifest with one root field changed and
	 * re-signs it, keeping the deploy commit intact.
	 *
	 * @param mixed $value
	 */
	private function rewrite_manifest_field( string $field, $value ): void {
		$this->store->write( $this->sealed_page_manifest()->with_field( $field, $value ), $this->manifest_path );
	}

	private function set_manifest_theme_version( string $version ): void {
		$theme = $this->sealed_page_manifest()->active_theme();

		$this->store->write(
			$this->sealed_page_manifest()->with_field(
				'activeTheme',
				array(
					'stylesheet' => $theme['stylesheet'],
					'version'    => $version,
					'gitCommit'  => $theme['gitCommit'],
				)
			),
			$this->manifest_path
		);
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
