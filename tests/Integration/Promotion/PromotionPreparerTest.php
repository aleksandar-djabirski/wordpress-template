<?php
/**
 * The prepare stage of the promotion lifecycle (BLOCK_THEME_PROPOSAL.md
 * §7.5). The refusal taxonomy is the whole point: run-level refusals abort
 * the run with exit 1 (or 4 for a tampered bundle, 3 for a lock conflict)
 * and write NOTHING, while per-record refusals continue the run, land in
 * the manifest's refusal report and surface through PromotionOutcome's exit
 * code (2 with mixed success, 1 with zero successes). Every run-level test
 * therefore proves the absence of a write — the manifest path does not
 * exist, or its bytes are unchanged — never merely that an exception was
 * thrown.
 *
 * The fixture is self-contained: a throwaway git repository holding a copy
 * of the shipped theme (templates/, parts/, theme.json), live wp_template /
 * wp_template_part rows whose markup comes from those shipped files (so the
 * ref-less site-header navigation exercises the real Task 9 policy), a
 * published wp_navigation post so that ref-less block resolves at export, a
 * signed state bundle built by StateExporter, and AGENCY_* settings pointed
 * at the fixture through putenv() — the same environment plumbing the other
 * promotion tests use. Git is unavailable in this worktree from inside the
 * container (the .git file points at a Windows path), so the fixture
 * repository is the only git the preparer ever sees, and
 * GIT_CONFIG_GLOBAL/GIT_CONFIG_SYSTEM are pinned to /dev/null so a host
 * gitconfig cannot change the fixture's behaviour.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Promotion\BundleView;
use AgencyPlatform\State\Promotion\GitRepository;
use AgencyPlatform\State\Promotion\ManifestStore;
use AgencyPlatform\State\Promotion\PreparablePromotionStrategy;
use AgencyPlatform\State\Promotion\PrepareLock;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionPreparer;
use AgencyPlatform\State\Promotion\PromotionSelector;
use AgencyPlatform\State\Promotion\StagedPromotionEntry;
use AgencyPlatform\State\Promotion\StateGateway;
use AgencyPlatform\State\Promotion\TemplatePartPromotionStrategy;
use AgencyPlatform\State\Promotion\TemplatePromotionStrategy;
use AgencyPlatform\State\Promotion\ThemeDeclaredSlugs;
use AgencyPlatform\State\PromotionStrategies;
use AgencyPlatform\State\PromotionStrategy;
use AgencyPlatform\State\StateExporter;
use AgencyPlatform\State\StateRecord;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\State\SeedsStateFixtures;

/**
 * @covers \AgencyPlatform\State\Promotion\PromotionPreparer
 */
// putenv() is how these tests drive AGENCY_* settings and the HMAC keyring
// through EnvironmentConfig's process-environment fallback without
// WordPress or real .env files loaded; WordPress's discouraged-function
// sniff would otherwise flag every call.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class PromotionPreparerTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	/** The key id every fixture in this file signs with. */
	private const KEY_ID = '2026-01';

	private string $repo_root;
	private string $theme_dir;
	private string $state_dir;
	private string $bundle_path;
	private string $manifest_path;
	private string $template_path;
	private string $prepared_file;
	private string $original_template_body;
	private string $site_uuid;

	private StateGateway $gateway;
	private ManifestStore $store;
	private PromotionPreparer $preparer;

	/** @var array<string, PreparablePromotionStrategy> */
	private array $strategies = array();

	public function set_up(): void {
		parent::set_up();

		$tmp = sys_get_temp_dir() . '/promotion-preparer-' . uniqid( '', true );

		$this->repo_root = $tmp . '/repo';
		$this->theme_dir = $this->repo_root;
		$this->state_dir = $tmp . '/state';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->repo_root, 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->state_dir, 0700, true );

		$this->copy_theme_into( $this->repo_root );

		$this->assert_git_available();

		putenv( 'GIT_CONFIG_GLOBAL=/dev/null' );
		putenv( 'GIT_CONFIG_SYSTEM=/dev/null' );

		$this->build_fixture_repository();

		$this->seed_database_rows();

		$this->set_keyring();

		// The repository root must point at the fixture BEFORE the export
		// runs: GitBaseline reads AGENCY_REPO_ROOT first, and the fixture's
		// real .git gives the bundle a meaningful activeTheme.gitCommit.
		putenv( 'AGENCY_REPO_ROOT=' . $this->repo_root );
		putenv( 'AGENCY_STATE_DIR=' . $this->state_dir );

		$document = ( new StateExporter( new HmacSigner( array( self::KEY_ID => str_repeat( 'k', 40 ) ), self::KEY_ID ) ) )->export( array( 'templates', 'template-parts' ) );

		$this->site_uuid = (string) $document['siteUuid'];

		putenv( 'AGENCY_TARGET_SITE_UUID=' . $this->site_uuid );

		$this->bundle_path   = $this->state_dir . '/bundle.json';
		$this->manifest_path = $this->state_dir . '/promotions/prepare.json';
		$this->template_path = $this->theme_dir . '/templates/page.html';
		$this->prepared_file = $this->template_path;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		$this->original_template_body = (string) file_get_contents( $this->template_path );

		$this->write_json_document( $this->bundle_path, $document );

		$this->gateway = new StateGateway();
		$this->store   = new ManifestStore( $this->gateway );

		$this->preparer = new PromotionPreparer(
			$this->gateway,
			$this->store,
			new GitRepository( $this->repo_root ),
			new PrepareLock( $this->state_dir . '/prepare.lock' )
		);

		$this->strategies = array(
			'templates'      => new TemplatePromotionStrategy(),
			'template-parts' => new TemplatePartPromotionStrategy(),
		);

		add_filter( PromotionStrategies::FILTER, array( $this, 'provide_strategies' ) );
		add_filter( 'stylesheet_directory', array( $this, 'fixture_stylesheet_directory' ) );

		PromotionStrategies::reset();
	}

	public function tear_down(): void {
		PromotionPreparer::override_environment( null );
		PromotionStrategies::reset();

		putenv( 'AGENCY_STATE_DIR' );
		putenv( 'AGENCY_REPO_ROOT' );
		putenv( 'AGENCY_TARGET_SITE_UUID' );
		putenv( 'AGENCY_EXPECTED_BRANCH' );
		putenv( 'AGENCY_ALLOW_DETACHED_HEAD' );
		putenv( 'GIT_CONFIG_GLOBAL' );
		putenv( 'GIT_CONFIG_SYSTEM' );

		$this->clear_keyring();

		$this->remove_tree( dirname( $this->repo_root ) );

		parent::tear_down();
	}

	public function test_prepare_refuses_to_run_in_production(): void {
		$this->with_environment(
			'production',
			function (): void {
				$this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );

				self::assertFalse( file_exists( $this->manifest_path ), 'A production refusal must write nothing.' );
			}
		);
	}

	public function test_prepare_refuses_a_target_site_uuid_mismatch(): void {
		putenv( 'AGENCY_TARGET_SITE_UUID=00000000-0000-4000-8000-000000000000' );

		$exception = $this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );

		self::assertStringContainsString( $this->site_uuid, $exception->getMessage(), 'The refusal must name the bundle site uuid.' );
		self::assertStringContainsString( '00000000-0000-4000-8000-000000000000', $exception->getMessage(), 'The refusal must name the configured target uuid.' );
		self::assertFalse( file_exists( $this->manifest_path ), 'A uuid mismatch must write nothing.' );
	}

	public function test_prepare_refuses_a_detached_head_without_the_opt_in(): void {
		$this->detach_head();

		$this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );

		self::assertFalse( file_exists( $this->manifest_path ), 'A detached HEAD refusal must write nothing.' );

		putenv( 'AGENCY_ALLOW_DETACHED_HEAD=1' );

		self::assertSame( 0, $this->preparer->prepare( $this->arguments() )['outcome']->exit_code() );
	}

	public function test_prepare_refuses_an_unexpected_branch(): void {
		putenv( 'AGENCY_EXPECTED_BRANCH=release' );

		$exception = $this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );

		self::assertStringContainsString( 'release', $exception->getMessage(), 'The refusal must name the expected branch.' );
		self::assertStringContainsString( $this->current_branch_name(), $exception->getMessage(), 'The refusal must name the actual branch.' );
		self::assertFalse( file_exists( $this->manifest_path ), 'An unexpected-branch refusal must write nothing.' );
	}

	public function test_prepare_refuses_an_undeclared_custom_template_slug(): void {
		$exception = $this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments( 'templates:landing' ) ) );

		self::assertStringContainsString( 'landing', $exception->getMessage(), 'The refusal must name the undeclared slug.' );
		self::assertStringContainsString( 'not declared in theme.json', $exception->getMessage() );
		self::assertFalse( file_exists( $this->manifest_path ), 'An undeclared-slug refusal must write nothing.' );
	}

	public function test_prepare_refuses_an_unrelated_dirty_file(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- creating an untracked fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->repo_root . '/README.md', 'dirty' );

		$exception = $this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );

		self::assertStringContainsString( 'README.md', $exception->getMessage(), 'The refusal must name the offending path.' );
		self::assertFalse( file_exists( $this->manifest_path ), 'A dirty-tree refusal must write nothing.' );
	}

	public function test_prepare_allows_a_dirty_tree_when_every_change_matches_a_recorded_hash(): void {
		$first = $this->preparer->prepare( $this->arguments() );

		// The prepared file is now a working-tree modification whose bytes match
		// the recorded preparedFileHash, so a re-run must not false-refuse.
		$second = $this->preparer->prepare( $this->arguments() );

		self::assertSame( 0, $second['outcome']->exit_code() );
		self::assertSame(
			$first['manifest']->record( 'templates:page' )['preparedFileHash'],
			$second['manifest']->record( 'templates:page' )['preparedFileHash']
		);
	}

	public function test_prepare_refuses_a_prepared_file_that_was_edited_by_hand(): void {
		$this->preparer->prepare( $this->arguments() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- rewriting an integration fixture file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->prepared_file, "<!-- wp:paragraph --><p>hand edit</p><!-- /wp:paragraph -->\n" );

		$manifest_before = $this->manifest_sha256();

		// The path is on the allow-list, but its bytes match neither the recorded
		// prepared hash nor the recorded original hash.
		$exception = $this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );

		self::assertStringContainsString( 'modified outside the promotion', $exception->getMessage() );
		self::assertStringContainsString( 'hand edit', file_get_contents( $this->prepared_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		self::assertSame( $manifest_before, $this->manifest_sha256(), 'A refused re-run must not rewrite the manifest.' );
	}

	public function test_prepare_refuses_a_sealed_manifest(): void {
		$this->seal_the_manifest();

		$manifest_before = $this->manifest_sha256();

		$exception = $this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );

		self::assertStringContainsString( 'already sealed', $exception->getMessage() );
		self::assertSame( $manifest_before, $this->manifest_sha256(), 'A sealed-manifest refusal must not rewrite the manifest.' );
	}

	public function test_prepare_refuses_a_finalized_manifest(): void {
		$first     = $this->preparer->prepare( $this->arguments() );
		$finalized = $first['manifest']->with_field( 'finalizeStatus', 'complete' );

		$this->store->write( $finalized, $this->manifest_path );

		$manifest_before = $this->manifest_sha256();

		$exception = $this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );

		self::assertStringContainsString( 'already been finalized', $exception->getMessage() );
		self::assertSame( $manifest_before, $this->manifest_sha256(), 'A finalized-manifest refusal must not rewrite the manifest.' );
	}

	public function test_prepare_refuses_a_re_run_against_a_different_bundle(): void {
		$this->preparer->prepare( $this->arguments() );

		$this->rewrite_bundle_with_different_export_id();

		$manifest_before = $this->manifest_sha256();

		$exception = $this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );

		self::assertStringContainsString( 'different export', $exception->getMessage() );
		self::assertSame( $manifest_before, $this->manifest_sha256(), 'A different-export refusal must not rewrite the manifest.' );
	}

	public function test_a_tampered_bundle_exits_four_before_any_file_is_written(): void {
		$this->tamper_with_bundle();

		$this->assert_exit_code( 4, fn() => $this->preparer->prepare( $this->arguments() ) );
		self::assertSame( $this->original_template_body, file_get_contents( $this->template_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		self::assertFalse( file_exists( $this->manifest_path ), 'A tampered bundle must write nothing.' );
	}

	public function test_a_failure_after_staging_leaves_no_partial_file(): void {
		$this->make_parts_directory_read_only();

		$this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments( 'template-parts:site-header,templates:page' ) ) );
		self::assertSame( $this->original_template_body, file_get_contents( $this->template_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		self::assertSame( array(), glob( $this->theme_dir . '/templates/*.tmp' ) );
	}

	public function test_prepare_calls_the_selected_strategy_and_uses_its_staged_entry(): void {
		$strategy = new RecordingPreparableStrategy( $this->real_template_strategy() );
		$this->register_strategy( 'templates', $strategy );

		$this->preparer->prepare( $this->arguments( 'templates:page' ) );

		self::assertSame( array( 'templates:page' ), $strategy->prepared_keys() );
		self::assertTrue( $strategy->staged_entry_was_committed() );
	}

	/**
	 * A two-record selection where one record carries an unresolved
	 * attachment reference: the run continues, the clean record's file is
	 * written, the refused record's file is not, and the exit code is 2 —
	 * the mix of success and refusal PromotionOutcome already maps.
	 */
	public function test_a_two_record_selection_with_one_unresolved_reference_exits_two_and_writes_only_the_clean_file(): void {
		$result = $this->preparer->prepare( $this->arguments( 'templates:gallery,templates:page' ) );

		self::assertSame( 2, $result['outcome']->exit_code() );
		self::assertFileExists( $this->theme_dir . '/templates/page.html', 'The clean record must be committed.' );
		self::assertFileDoesNotExist( $this->theme_dir . '/templates/gallery.html', 'The refused record must leave no file.' );
		self::assertSame( array(), glob( $this->theme_dir . '/templates/*.tmp' ), 'A refused record must leave no temp residue.' );
		self::assertSame( array( 'templates:gallery' ), $this->refused_keys( $result['outcome']->refusals() ) );
	}

	/**
	 * A selection where EVERY record is refused: zero successes plus at
	 * least one refusal is a hard error (exit 1), and the manifest is still
	 * written with the full refusal report.
	 */
	public function test_a_selection_where_every_record_is_refused_exits_one(): void {
		$result = $this->preparer->prepare( $this->arguments( 'templates:gallery' ) );

		self::assertSame( 1, $result['outcome']->exit_code() );
		self::assertSame( array( 'templates:gallery' ), $this->refused_keys( $result['outcome']->refusals() ) );
		self::assertFileExists( $this->manifest_path, 'The manifest with the refusal report must still be written.' );

		$loaded = $this->store->load( $this->manifest_path );

		self::assertSame( array( 'templates:gallery' ), array_map( static fn( array $refusal ): string => $refusal['recordKey'], $loaded->refusals() ) );
	}

	public function test_a_refused_record_leaves_no_file_and_no_tmp_residue(): void {
		$this->preparer->prepare( $this->arguments( 'templates:gallery,templates:page' ) );

		self::assertFileDoesNotExist( $this->theme_dir . '/templates/gallery.html' );
		self::assertSame( array(), glob( $this->theme_dir . '/templates/*.tmp' ) );
		self::assertSame( array(), glob( $this->theme_dir . '/parts/*.tmp' ) );
	}

	/**
	 * The §11.13 Release 3 boundary: every export-only provider — and
	 * global-styles in particular — has no registered promotion strategy, so
	 * selecting any of its records is invalid operator input at selection:
	 * exit 1, never a per-record refusal.
	 *
	 * @dataProvider export_only_providers
	 */
	public function test_export_only_providers_are_refused_at_selection( string $provider, string $record_slug ): void {
		$exception = $this->assert_exit_code(
			1,
			fn() => PromotionSelector::parse(
				$provider . ':' . $record_slug,
				static fn(): bool => false,
				static fn(): bool => true
			)
		);

		self::assertStringContainsString( $provider, $exception->getMessage() );
		self::assertStringContainsString( 'No promotion strategy is registered', $exception->getMessage() );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function export_only_providers(): array {
		return array(
			'navigation'       => array( 'navigation', 'primary-menu' ),
			'synced-patterns'  => array( 'synced-patterns', 'callout' ),
			'fonts'            => array( 'fonts', 'inter' ),
			'media-references' => array( 'media-references', 'header' ),
			'custom-css'       => array( 'custom-css', 'global-styles' ),
			'content'          => array( 'content', 'page-2' ),
			'global-styles'    => array( 'global-styles', 'default' ),
		);
	}

	/**
	 * Additional CSS is never promotable: neither the global-styles row nor
	 * the custom-css post row has a strategy, so both are refused at
	 * selection with exit 1.
	 *
	 * @dataProvider additional_css_records
	 */
	public function test_additional_css_records_are_never_promotable( string $select ): void {
		$exception = $this->assert_exit_code(
			1,
			fn() => PromotionSelector::parse(
				$select,
				static fn(): bool => false,
				static fn(): bool => true
			)
		);

		self::assertStringContainsString( 'custom-css', $exception->getMessage() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function additional_css_records(): array {
		return array(
			'global styles row'   => array( 'custom-css:global-styles' ),
			'custom css post row' => array( 'custom-css:custom-css-post' ),
		);
	}

	/**
	 * The environment the prepare guard sees. The integration suite pins
	 * WP_ENVIRONMENT_TYPE to 'development' via tests/wp-tests-config.php AND
	 * WordPress caches the resolved type in a function static, so the real
	 * function can never report 'production' here; PromotionPreparer exposes
	 * the override seam for exactly this case.
	 */
	private function with_environment( string $environment, callable $callback ): void {
		PromotionPreparer::override_environment( $environment );

		try {
			$callback();
		} finally {
			PromotionPreparer::override_environment( null );
		}
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
	 * Named filter callback — never a closure (master spec §4). Points
	 * get_stylesheet_directory() at the fixture repository root, so every
	 * stage lands inside the fixture repo and the working-tree dirtiness it
	 * creates is exactly the dirtiness the dirty-tree guard sees.
	 */
	public function fixture_stylesheet_directory( string $stylesheet_dir ): string {
		return $this->theme_dir;
	}

	private function register_strategy( string $provider_slug, PreparablePromotionStrategy $strategy ): void {
		$this->strategies[ $provider_slug ] = $strategy;

		PromotionStrategies::reset();
	}

	private function real_template_strategy(): TemplatePromotionStrategy {
		return new TemplatePromotionStrategy();
	}

	/**
	 * @return array{source:string, select:string, manifest:string}
	 */
	private function arguments( string $select = 'templates:page,template-parts:site-header' ): array {
		return array(
			'source'   => $this->bundle_path,
			'select'   => $select,
			'manifest' => $this->manifest_path,
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
	 * The live wp_template / wp_template_part rows the export sees, plus a
	 * published wp_navigation post so the shipped ref-less site-header
	 * navigation resolves WordPress core's deterministic fallback at export
	 * time. The gallery row carries an attachment id that can never resolve,
	 * which is what the per-record refusal tests drive.
	 *
	 * The page row deliberately keeps its template-part blocks to a SINGLE
	 * attribute: AbstractBlockTemplateStrategy::validate_for_promotion()
	 * round-trips the record markup through the gateway's normaliser, which
	 * re-sorts template-part attributes (strip_theme_attribute ksort), so a
	 * multi-attribute template-part comment like the shipped page.html's
	 * cannot round-trip and refuses with unparseable-markup. That is a Task 7
	 * defect, reported in the task report; the fixture markup here stays
	 * within what the shipped validator accepts.
	 */
	private function seed_database_rows(): void {
		$this->make_navigation( 'primary-menu', '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->' );

		$this->make_template(
			'page',
			"<!-- wp:template-part {\"slug\":\"site-header\"} /-->\n\n<!-- wp:paragraph --><p>Page body</p><!-- /wp:paragraph -->\n\n<!-- wp:template-part {\"slug\":\"site-footer\"} /-->"
		);
		$this->make_template( 'landing', '<!-- wp:paragraph --><p>Landing</p><!-- /wp:paragraph -->' );
		$this->make_template( 'gallery', '<!-- wp:image {"id":999999,"sizeSlug":"large"} /-->' );

		$this->make_part( 'site-header', $this->shipped_file( 'parts/site-header.html' ) );
		$this->make_part( 'site-footer', $this->shipped_file( 'parts/site-footer.html' ) );
	}

	/**
	 * Copies the shipped theme's templates/, parts/ and theme.json into the
	 * fixture repository root, which is also the stylesheet directory the
	 * prepare step reads through the stylesheet_directory filter. The copied
	 * theme.json additionally declares the gallery custom template: the
	 * declared-slug guard is run-level, so the per-record refusal tests
	 * need the gallery record to pass it and be refused by the reference
	 * policy instead. landing stays undeclared on purpose.
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
		$theme_json = json_decode( (string) file_get_contents( $source . '/theme.json' ), true );

		self::assertIsArray( $theme_json );

		$theme_json['customTemplates'] = array(
			array(
				'name'  => 'gallery',
				'title' => 'Gallery',
			),
		);

		$this->write_json_document( $target . '/theme.json', $theme_json );
	}

	private function shipped_file( string $relative_path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a shipped theme file into an integration fixture; the WP_Filesystem credentials context does not exist here.
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/web/app/themes/site-theme/' . $relative_path );
	}

	/**
	 * The fixture repository: git init, a committed tree holding the theme
	 * copy and a README, and the branch pinned to a known name so the
	 * unexpected-branch test is deterministic.
	 */
	private function build_fixture_repository(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the fixture tree; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->repo_root . '/README.md', 'fixture' );

		$this->run_git( array( 'init' ) );
		$this->run_git( array( 'config', 'user.email', 'promotion-fixture@example.invalid' ) );
		$this->run_git( array( 'config', 'user.name', 'Promotion Fixture' ) );
		$this->run_git( array( 'add', '-A' ) );
		$this->run_git( array( '-c', 'commit.gpgsign=false', 'commit', '-m', 'fixture' ) );
		$this->run_git( array( 'branch', '-M', 'main' ) );
	}

	/**
	 * Detaches the fixture repository's HEAD, so current_branch() reports
	 * null and the detached-head guard is exercised.
	 */
	private function detach_head(): void {
		$this->run_git( array( 'checkout', '--detach' ) );
	}

	/**
	 * The fixture branch name, read the same way the preparer reads it.
	 */
	private function current_branch_name(): string {
		$result = $this->run_git( array( 'symbolic-ref', '--quiet', '--short', 'HEAD' ) );

		return trim( $result['stdout'] );
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

	/**
	 * Writes a prepared manifest to the manifest path and re-signs it as
	 * sealed: deployCommit set, so is_sealed() reports true.
	 */
	private function seal_the_manifest(): void {
		$first = $this->preparer->prepare( $this->arguments() );

		$sealed = $first['manifest']->with_deploy_commit( str_repeat( 'f', 40 ), '2026-08-01T10:00:00Z' );

		$this->store->write( $sealed, $this->manifest_path );
	}

	/**
	 * Rewrites the bundle file with a DIFFERENT export id, re-signed by the
	 * same keyring: the JSON and the schema stay valid, so the refusal the
	 * next prepare must hit is the export-id mismatch, not a signature or
	 * shape failure.
	 */
	private function rewrite_bundle_with_different_export_id(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		$document = json_decode( (string) file_get_contents( $this->bundle_path ), true );

		self::assertIsArray( $document );

		$document['exportId'] = wp_generate_uuid4();

		$signature = ( new HmacSigner( array( self::KEY_ID => str_repeat( 'k', 40 ) ), self::KEY_ID ) )->sign( $document, HmacSigner::PURPOSE_BUNDLE );

		$document['hmacKeyId']   = $signature['hmacKeyId'];
		$document['hmacVersion'] = $signature['hmacVersion'];
		$document['hmac']        = $signature['hmac'];

		$this->write_json_document( $this->bundle_path, $document );
	}

	/**
	 * Corrupts the bundle in a way that keeps the JSON and the schema valid
	 * but breaks the signature: flipping the last hex digit of the export id
	 * changes a signed byte while the uuid pattern still matches.
	 */
	private function tamper_with_bundle(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		$document = json_decode( (string) file_get_contents( $this->bundle_path ), true );

		self::assertIsArray( $document );

		$export_id = (string) $document['exportId'];
		$last      = substr( $export_id, -1 );

		$document['exportId'] = substr( $export_id, 0, -1 ) . ( '0' === $last ? '1' : '0' );

		$this->write_json_document( $this->bundle_path, $document );
	}

	/**
	 * Replaces the fixture's parts/ directory with a file, so the first
	 * staged write into parts/ fails deterministically even when the suite
	 * runs as root — a read-only chmod would not stop root, and the failure
	 * must be the same on every host.
	 */
	private function make_parts_directory_read_only(): void {
		$this->remove_tree( $this->theme_dir . '/parts' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- replacing the fixture parts directory with a file; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->theme_dir . '/parts', 'not a directory' );
	}

	/**
	 * The record keys behind a refusal report, for asserting the report
	 * CONTENT rather than its length — an empty report would pass a length
	 * assertion just as well as the right one.
	 *
	 * @param list<object> $refusals
	 * @return list<string>
	 */
	private function refused_keys( array $refusals ): array {
		$keys = array();

		foreach ( $refusals as $refusal ) {
			$keys[] = $refusal->record_key;
		}

		sort( $keys, SORT_STRING );

		return $keys;
	}

	/**
	 * The manifest's current bytes as a sha256, to prove a refused re-run
	 * did not rewrite it.
	 */
	private function manifest_sha256(): string {
		$hash = hash_file( 'sha256', $this->manifest_path );

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

/**
 * A PreparablePromotionStrategy that records which records were staged and
 * whether the staged entry was committed, so the preparer's strategy-owned
 * staging and its commit call can be proven without reimplementing the
 * strategy.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- the plan fixes this recording strategy to the preparer test file so the strategy-owned staging contract is pinned without a shared fixtures file.
final class RecordingPreparableStrategy implements PreparablePromotionStrategy {

	/** @var list<string> */
	private array $prepared_keys = array();

	private ?RecordingStagedEntry $last_staged_entry = null;

	public function __construct( private PreparablePromotionStrategy $inner ) {}

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
		$this->inner->restore( $record, $backup );
	}

	public function expected_post_reset_hash( StateRecord $record ): string {
		return $this->inner->expected_post_reset_hash( $record );
	}

	public function stage( StateRecord $record, string $theme_root ): StagedPromotionEntry {
		$this->prepared_keys[] = $record->key();

		$this->last_staged_entry = new RecordingStagedEntry( $this->inner->stage( $record, $theme_root ) );

		return $this->last_staged_entry;
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

	/**
	 * @return list<string>
	 */
	public function prepared_keys(): array {
		return $this->prepared_keys;
	}

	public function staged_entry_was_committed(): bool {
		return null !== $this->last_staged_entry && $this->last_staged_entry->was_committed();
	}
}

/**
 * A StagedPromotionEntry that records whether the preparer committed it.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- same reason as RecordingPreparableStrategy: the plan pins it to this file.
final class RecordingStagedEntry implements StagedPromotionEntry {

	private bool $committed = false;

	public function __construct( private StagedPromotionEntry $inner ) {}

	/**
	 * @return array<string, mixed>
	 */
	public function manifest_fields(): array {
		return $this->inner->manifest_fields();
	}

	public function commit(): void {
		$this->inner->commit();

		$this->committed = true;
	}

	public function discard(): void {
		$this->inner->discard();
	}

	public function rollback_committed(): void {
		$this->inner->rollback_committed();

		$this->committed = false;
	}

	public function was_committed(): bool {
		return $this->committed;
	}
}
