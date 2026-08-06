<?php
/**
 * Master spec §9.5: the vertical-slice proof of the promotion workflow. One
 * end-to-end run — prepare → seal → finalize → rollback → re-finalize →
 * refuse → confirm — through the REAL collaborators (PromotionPreparer,
 * PromotionSealer, PromotionFinalizer, PromotionRollback, PromotionConfirmer,
 * ManifestStore, StateGateway), with no fakes. Every step asserts observable
 * state, never just an exit code: prepared files and a signed-but-unsealed
 * manifest after prepare, the deploy commit after seal, the deleted override
 * plus a backup and semantic equality after finalize, the byte-identical
 * restored row and the re-finalisable record after rollback, and the released
 * locks plus recorded settlement after confirm.
 *
 * The fixture drives the SHIPPED theme's own markup shapes — multi-attribute
 * core/template-part blocks and a ref-less core/navigation block — through
 * the real export pipeline. The two release-blocking defects this unit found
 * were both invisible to synthetic fixtures, and this test exists so nothing
 * before the vertical slice can hide again. The database overrides carry
 * client edits; a throwaway git repository holds a copy of the theme and the
 * prepared files; AGENCY_* settings and the HMAC keyring are driven through
 * putenv(), the same environment plumbing the other promotion tests use.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Promotion\GitRepository;
use AgencyPlatform\State\Promotion\ManifestStore;
use AgencyPlatform\State\Promotion\PreparablePromotionStrategy;
use AgencyPlatform\State\Promotion\PrepareLock;
use AgencyPlatform\State\Promotion\PromotionBackup;
use AgencyPlatform\State\Promotion\PromotionConfirmer;
use AgencyPlatform\State\Promotion\PromotionFinalizer;
use AgencyPlatform\State\Promotion\PromotionPreparer;
use AgencyPlatform\State\Promotion\PromotionRollback;
use AgencyPlatform\State\Promotion\PromotionSealer;
use AgencyPlatform\State\Promotion\RecordLockManager;
use AgencyPlatform\State\Promotion\StateGateway;
use AgencyPlatform\State\Promotion\TemplatePartPromotionStrategy;
use AgencyPlatform\State\Promotion\TemplatePromotionStrategy;
use AgencyPlatform\State\PromotionStrategies;
use AgencyPlatform\State\PromotionStrategy;
use AgencyPlatform\State\StateExporter;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\State\SeedsStateFixtures;

/**
 * @covers \AgencyPlatform\State\Promotion\PromotionPreparer
 * @covers \AgencyPlatform\State\Promotion\PromotionSealer
 * @covers \AgencyPlatform\State\Promotion\PromotionFinalizer
 * @covers \AgencyPlatform\State\Promotion\PromotionRollback
 * @covers \AgencyPlatform\State\Promotion\PromotionConfirmer
 */
// putenv() is how these tests drive AGENCY_* settings and the HMAC keyring
// through EnvironmentConfig's process-environment fallback without
// WordPress or real .env files loaded; WordPress's discouraged-function
// sniff would otherwise flag every call.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class WorkflowProofTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	/** The key id every fixture in this file signs with. */
	private const KEY_ID = '2026-01';

	private string $tmp_dir;
	private string $repo_root;
	private string $theme_dir;
	private string $state_dir;
	private string $bundle_path;
	private string $manifest_path;
	private string $edited_template_markup;
	private string $edited_part_markup;
	private string $original_content_hash;

	private StateGateway $gateway;
	private ManifestStore $store;
	private PromotionPreparer $preparer;
	private PromotionSealer $sealer;
	private PromotionFinalizer $finalizer;
	private PromotionRollback $rollback;
	private PromotionConfirmer $confirmer;

	/** @var array<string, PreparablePromotionStrategy> */
	private array $strategies = array();

	public function set_up(): void {
		parent::set_up();

		$tmp = sys_get_temp_dir() . '/promotion-workflow-' . uniqid( '', true );

		$this->tmp_dir       = $tmp;
		$this->repo_root     = $tmp . '/repo';
		$this->theme_dir     = $this->repo_root;
		$this->state_dir     = $tmp . '/state';
		$this->bundle_path   = $this->state_dir . '/bundle.json';
		$this->manifest_path = $this->state_dir . '/promotions/prepare.json';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->repo_root, 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->state_dir, 0700, true );

		$this->copy_theme_into( $this->repo_root );

		$this->assert_git_available();

		putenv( 'GIT_CONFIG_GLOBAL=/dev/null' );
		putenv( 'GIT_CONFIG_SYSTEM=/dev/null' );

		$this->build_fixture_repository();

		// The repository root must point at the fixture BEFORE the export
		// runs: GitBaseline reads AGENCY_REPO_ROOT first, and the fixture's
		// real .git gives the bundle a meaningful activeTheme.gitCommit.
		putenv( 'AGENCY_REPO_ROOT=' . $this->repo_root );
		putenv( 'AGENCY_STATE_DIR=' . $this->state_dir );

		$this->set_keyring();

		// _get_block_template_file() maps BOTH get_stylesheet_directory() and
		// get_template_directory() — with no child theme the two array keys
		// collide and the template entry wins — so the resolver only ever sees
		// the fixture when both filters point at it.
		add_filter( 'stylesheet_directory', array( $this, 'fixture_stylesheet_directory' ) );
		add_filter( 'template_directory', array( $this, 'fixture_stylesheet_directory' ) );

		$this->gateway = new StateGateway();
		$this->store   = new ManifestStore( $this->gateway );

		$this->strategies = array(
			'templates'      => new TemplatePromotionStrategy(),
			'template-parts' => new TemplatePartPromotionStrategy(),
		);

		add_filter( PromotionStrategies::FILTER, array( $this, 'provide_strategies' ) );
		PromotionStrategies::reset();

		$this->preparer  = new PromotionPreparer( $this->gateway, $this->store, new GitRepository( $this->repo_root ), new PrepareLock( $this->state_dir . '/prepare.lock' ) );
		$this->sealer    = new PromotionSealer( $this->store, new GitRepository( $this->repo_root ) );
		$this->finalizer = new PromotionFinalizer( $this->gateway, $this->store );
		$this->rollback  = new PromotionRollback( $this->gateway, $this->store );
		$this->confirmer = new PromotionConfirmer( $this->store );

		$this->edited_template_markup = $this->edited_markup( 'templates/page.html', '<!-- wp:template-part {"slug":"site-header","tagName":"header","area":"header","className":"site-header"} /-->', 'client edit' );
		$this->edited_part_markup     = $this->edited_markup( 'parts/site-header.html', '<!-- wp:site-tagline /-->', 'Header client edit' );
	}

	public function tear_down(): void {
		PromotionPreparer::override_environment( null );
		PromotionStrategies::reset();

		remove_filter( 'stylesheet_directory', array( $this, 'fixture_stylesheet_directory' ) );
		remove_filter( 'template_directory', array( $this, 'fixture_stylesheet_directory' ) );
		remove_filter( PromotionStrategies::FILTER, array( $this, 'provide_strategies' ) );

		putenv( 'AGENCY_REPO_ROOT' );
		putenv( 'AGENCY_STATE_DIR' );
		putenv( 'AGENCY_TARGET_SITE_UUID' );
		putenv( 'AGENCY_EXPECTED_BRANCH' );
		putenv( 'AGENCY_ALLOW_DETACHED_HEAD' );
		putenv( 'AGENCY_DEPLOY_COMMIT' );
		putenv( 'AGENCY_DEPLOYMENT_ID' );
		putenv( 'GIT_CONFIG_GLOBAL' );
		putenv( 'GIT_CONFIG_SYSTEM' );

		$this->clear_keyring();

		$this->remove_tree( $this->tmp_dir );

		parent::tear_down();
	}

	public function test_the_full_template_and_part_promotion_loop(): void {
		// 1. A client edit exists in the database for a template and for a part.
		$this->create_override( 'wp_template', 'page', $this->edited_template_markup );
		$this->create_override( 'wp_template_part', 'site-header', $this->edited_part_markup );
		// The shipped page also references site-footer; the referenced-part
		// rule is satisfied from the bundle, so the footer record is part of
		// the export even though it is never selected.
		$this->create_override( 'wp_template_part', 'site-footer', $this->shipped_file( 'parts/site-footer.html' ) );
		// The shipped header's navigation is ref-less; a published
		// wp_navigation post is what WordPress core's deterministic fallback
		// resolves at export time AND at finalization.
		$this->make_navigation( 'primary-menu', '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->' );

		$live = $this->gateway->read_live_record( 'templates', 'page' );

		self::assertNotNull( $live );

		$this->original_content_hash = (string) $live['contentHash'];

		// 2. Export. StateBundle::load() verifies the signature and stateHash
		//    before any promotable content becomes readable.
		$bundle_path = $this->export_bundle();

		// 3. Prepare both records.
		$prepared = $this->preparer->prepare(
			array(
				'source'   => $bundle_path,
				'select'   => 'template-parts:site-header,templates:page',
				'manifest' => $this->manifest_path,
			)
		);
		self::assertSame( 0, $prepared['outcome']->exit_code() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		self::assertStringContainsString( 'client edit', file_get_contents( $this->theme_dir . '/templates/page.html' ) );
		self::assertFileExists( $this->manifest_path, 'The prepared manifest must be written.' );
		self::assertFalse( $this->store->load( $this->manifest_path )->is_sealed(), 'The prepared manifest must not be sealed yet.' );
		self::assertSame(
			$prepared['manifest']->record( 'templates:page' )['preparedFileHash'],
			$this->file_sha256( $this->theme_dir . '/templates/page.html' ),
			'The manifest must record the on-disk prepared file hash.'
		);
		self::assertNotNull( $this->read_override( 'page' ), 'Prepare must leave the database override untouched.' );

		// 4. Commit, then seal against that commit.
		$sha    = $this->commit_prepared_files();
		$sealed = $this->sealer->seal( $this->manifest_path, $sha );
		self::assertSame( $sha, $sealed->deploy_commit() );
		self::assertNotNull( $sealed->to_array()['sealedAtUtc'], 'Sealing must record the sealed-at timestamp.' );
		self::assertTrue( $sealed->is_sealed() );

		// 5. Finalize against the sealed commit.
		putenv( 'AGENCY_DEPLOY_COMMIT=' . $sha );
		$finalized = $this->finalizer->finalize( $this->manifest_path );
		self::assertSame( 0, $finalized['outcome']->exit_code() );

		$promotion_id = $finalized['manifest']->promotion_id();

		self::assertTrue( ( new PromotionBackup( $promotion_id ) )->exists( 'templates:page' ), 'A backup must exist for every promoted record.' );
		self::assertTrue( $this->store->canonical_exists( $promotion_id ), 'The canonical host manifest must be written.' );

		// 6. Semantic equality: the override is gone and the template resolves
		//    from the promoted file with the same content.
		self::assertNull( $this->gateway->read_live_record( 'templates', 'page' ) );
		self::assertSame(
			$finalized['manifest']->record( 'templates:page' )['expectedPostResetHash'],
			$this->resolved_hash( 'page', 'wp_template' )
		);

		// 7. Roll it back.
		$rolled_back = $this->rollback->rollback( $this->manifest_path );
		self::assertSame( 0, $rolled_back['outcome']->exit_code() );
		self::assertSame( $this->original_content_hash, $this->gateway->read_live_record( 'templates', 'page' )['contentHash'] );
		self::assertSame( 'pending', $rolled_back['manifest']->record( 'templates:page' )['finalizeStatus'], 'A restored record must be re-finalisable.' );
		self::assertNotNull( $rolled_back['manifest']->record( 'templates:page' )['restoredObjectId'], 'The restored row identity must be recorded for the next finalize.' );

		// 8. Re-finalize and confirm. This only works because rollback
		//    returned the records to `pending` and rewrote their objectId and
		//    modification marker from the restored rows.
		$refinalized = $this->finalizer->finalize( $this->manifest_path );
		self::assertSame( 0, $refinalized['outcome']->exit_code() );
		self::assertSame( 'complete', $refinalized['manifest']->finalize_status() );

		// 9. A record a client has since re-created must NOT be clobbered.
		//    BOTH records are re-created, so the rollback's zero-success
		//    refusal reports exit 1. (A rollback after --confirm is refused
		//    outright by the point-of-no-return guard, so the client-
		//    recreation check runs before --confirm.)
		$this->create_override( 'wp_template', 'page', '<!-- wp:paragraph --><p>newer client work</p><!-- /wp:paragraph -->' );
		$this->create_override( 'wp_template_part', 'site-header', '<!-- wp:paragraph --><p>newer part work</p><!-- /wp:paragraph -->' );

		$refused = $this->rollback->rollback( $this->manifest_path );
		self::assertSame( 1, $refused['outcome']->exit_code() );
		self::assertSame( 'refused', $refused['outcome']->outcomes()['template-parts:site-header'], 'A re-created part must be refused too, or the run would only be partial.' );
		self::assertSame( 'record-recreated', $refused['manifest']->record( 'templates:page' )['rollbackRefusalReason'] );
		self::assertStringContainsString( 'newer client work', $this->read_override( 'page' )->post_content );

		// 10. Confirm still settles the promotion: settlement recorded, every
		//     record lock released.
		$confirmed = $this->confirmer->confirm( $this->manifest_path );
		self::assertSame( 'confirmed', $confirmed['manifest']->settlement_status() );
		self::assertNull(
			( new RecordLockManager( $confirmed['manifest']->promotion_id(), 'x' ) )->inspect( 'templates:page' ),
			'Confirm must release every record lock.'
		);
	}

	/**
	 * Named filter callback — never a closure (master spec §4). Points
	 * get_stylesheet_directory() at the fixture repository root, so prepare
	 * writes the prepared files into the fixture repo and finalize resolves
	 * the file-backed templates from the same promoted files.
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
	 * One database override of the given post type: wp_template or
	 * wp_template_part, attached to the active theme's wp_theme term.
	 */
	private function create_override( string $post_type, string $slug, string $markup ): int {
		if ( 'wp_template' === $post_type ) {
			return $this->make_template( $slug, $markup );
		}

		if ( 'wp_template_part' === $post_type ) {
			return $this->make_part( $slug, $markup );
		}

		throw new \InvalidArgumentException( 'create_override() supports only wp_template and wp_template_part.' );
	}

	/**
	 * The signed, schema-validated state bundle over the live template and
	 * template-part rows, written to the fixture state directory. Returns the
	 * bundle path; the target site uuid is pinned from the export so the
	 * prepare and finalize identity guards agree with the bundle.
	 */
	private function export_bundle(): string {
		$document = ( new StateExporter( new HmacSigner( array( self::KEY_ID => str_repeat( 'k', 40 ) ), self::KEY_ID ) ) )->export( array( 'templates', 'template-parts' ) );

		putenv( 'AGENCY_TARGET_SITE_UUID=' . (string) $document['siteUuid'] );

		$this->write_json_document( $this->bundle_path, $document );

		return $this->bundle_path;
	}

	/**
	 * Commits every prepared file with the identity passed INLINE (-c), so
	 * the commit works on a host with no git identity configured. Returns the
	 * new HEAD sha.
	 */
	private function commit_prepared_files(): string {
		$this->run_git( array( 'add', '-A' ) );
		$this->run_git(
			array(
				'-c',
				'user.email=promotion-fixture@example.invalid',
				'-c',
				'user.name=Promotion Fixture',
				'-c',
				'commit.gpgsign=false',
				'commit',
				'-m',
				'prepare',
			)
		);

		return trim( $this->run_git( array( 'rev-parse', 'HEAD' ) )['stdout'] );
	}

	/**
	 * The semantic hash the record resolves to right now, read through the
	 * very method the finalizer compares against.
	 */
	private function resolved_hash( string $slug, string $post_type ): string {
		$strategy = 'wp_template' === $post_type ? $this->strategies['templates'] : $this->strategies['template-parts'];
		$hash     = $strategy->resolve_current_hash( $slug );

		self::assertNotNull( $hash, sprintf( 'The %s record "%s" must resolve after finalisation.', $post_type, $slug ) );

		return (string) $hash;
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
	 * The shipped theme file with a client edit spliced in after $after, so
	 * the promoted record carries the shipped theme's exact shape —
	 * multi-attribute template-part blocks, a ref-less navigation block —
	 * plus the edit.
	 */
	private function edited_markup( string $relative_path, string $after, string $edit ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a shipped theme file into an integration fixture; the WP_Filesystem credentials context does not exist here.
		$shipped = (string) file_get_contents( dirname( __DIR__, 3 ) . '/web/app/themes/site-theme/' . $relative_path );

		self::assertStringContainsString( $after, $shipped, sprintf( 'The shipped %s must contain the splice point.', $relative_path ) );

		return str_replace(
			$after,
			$after . "\n<!-- wp:paragraph --><p>" . $edit . '</p><!-- /wp:paragraph -->',
			$shipped
		);
	}

	private function shipped_file( string $relative_path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a shipped theme file into an integration fixture; the WP_Filesystem credentials context does not exist here.
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/web/app/themes/site-theme/' . $relative_path );
	}

	/**
	 * Copies the shipped theme's templates/, parts/ and theme.json into the
	 * fixture repository root, which is also the stylesheet directory every
	 * lifecycle stage reads through the directory filters.
	 */
	private function copy_theme_into( string $target ): void {
		$source = dirname( __DIR__, 3 ) . '/web/app/themes/site-theme';

		foreach ( array( 'templates', 'parts' ) as $subdirectory ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating an integration fixture directory; the WP_Filesystem credentials context does not exist here.
			mkdir( $target . '/' . $subdirectory, 0755 );

			foreach ( glob( $source . '/' . $subdirectory . '/*.html' ) as $file ) {
				copy( $file, $target . '/' . $subdirectory . '/' . basename( $file ) );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a shipped theme file into an integration fixture; the WP_Filesystem credentials context does not exist here.
		$theme_json = file_get_contents( $source . '/theme.json' );

		self::assertNotFalse( $theme_json, 'The shipped theme.json must be readable.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing an integration fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $target . '/theme.json', $theme_json );
	}

	/**
	 * The fixture repository: git init, a committed tree holding the theme
	 * copy, and the branch pinned to a known name.
	 */
	private function build_fixture_repository(): void {
		$this->run_git( array( 'init' ) );
		$this->run_git( array( 'config', 'user.email', 'promotion-fixture@example.invalid' ) );
		$this->run_git( array( 'config', 'user.name', 'Promotion Fixture' ) );
		$this->run_git( array( 'add', '-A' ) );
		$this->run_git( array( '-c', 'commit.gpgsign=false', 'commit', '-m', 'fixture' ) );
		$this->run_git( array( 'branch', '-M', 'main' ) );
	}

	/**
	 * Runs one git command against the fixture repository and returns both
	 * streams plus the exit code. The argv array form never invokes a shell,
	 * so no argument can be injected; the process environment is the test's
	 * own, with GIT_CONFIG_GLOBAL and GIT_CONFIG_SYSTEM already pinned to
	 * /dev/null by set_up().
	 *
	 * @param list<string> $argv
	 * @return array{stdout: string, stderr: string, exit: int}
	 */
	private function run_git( array $argv ): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- the fixture repository needs a real git binary; proc_open with an argv array is the only way to run it without a shell.
		$process = proc_open(
			array_merge( array( 'git' ), $argv ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->repo_root
		);

		if ( false === $process ) {
			self::fail( 'Git is required for the promotion release gate.' );
		}

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the captured fixture git pipes; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the captured fixture git pipes; there is no WP_Filesystem alternative for a process pipe.
		fclose( $pipes[2] );

		return array(
			'stdout' => false === $stdout ? '' : $stdout,
			'stderr' => false === $stderr ? '' : $stderr,
			'exit'   => proc_close( $process ),
		);
	}

	/**
	 * Runs git --version and fails the test when Git is unavailable. It
	 * never skips: a missing environment stays a failed gate.
	 */
	private function assert_git_available(): void {
		$result = $this->run_git( array( '--version' ) );

		if ( 0 !== $result['exit'] ) {
			self::fail( 'Git is required for the promotion release gate.' );
		}
	}

	private function file_sha256( string $path ): string {
		$hash = hash_file( 'sha256', $path );

		return false === $hash ? '' : $hash;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function write_json_document( string $path, array $document ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing an integration fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $path, Normalizer::canonical_json_document( $document ) );
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
