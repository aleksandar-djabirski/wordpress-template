<?php
/**
 * The CLI surface of the promotion lifecycle (BLOCK_THEME_PROPOSAL.md §6 and
 * plan Task 17): PromotionCommandRunner captures every command body free of
 * WP_CLI, and Cli\PromotionCommands is the ONLY writer to either stream.
 * STDOUT carries exactly ONE JSON document per run and nothing else — a
 * stray diagnostic on STDOUT would corrupt the document a deployment wrapper
 * is about to parse — and every mode's exit code (0, 1, 2, 3 and 4) reaches
 * the result intact from a real run, never re-derived from a message.
 *
 * The fixture is the full vertical-slice setup the other workflow tests
 * use: a throwaway git repository holding a copy of the shipped theme, live
 * wp_template / wp_template_part overrides, a published wp_navigation post,
 * a signed bundle, and AGENCY_* settings driven through putenv(). The
 * prepare and seal stages run THROUGH the runner in set_up(), so the
 * manifests the dash tests feed back through STDIN are the runner's own
 * output.
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
use AgencyPlatform\State\Promotion\PromotionCommandRunner;
use AgencyPlatform\State\Promotion\PromotionExitCode;
use AgencyPlatform\State\Promotion\PromotionPreparer;
use AgencyPlatform\State\Promotion\StateGateway;
use AgencyPlatform\State\Promotion\TemplatePartPromotionStrategy;
use AgencyPlatform\State\Promotion\TemplatePromotionStrategy;
use AgencyPlatform\State\PromotionStrategies;
use AgencyPlatform\State\PromotionStrategy;
use AgencyPlatform\State\StateCommandResult;
use AgencyPlatform\State\StateExporter;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\State\SeedsStateFixtures;

/**
 * @covers \AgencyPlatform\State\Promotion\PromotionCommandRunner
 */
// putenv() is how these tests drive AGENCY_* settings and the HMAC keyring
// through EnvironmentConfig's process-environment fallback without
// WordPress or real .env files loaded; WordPress's discouraged-function
// sniff would otherwise flag every call.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class PromotionCommandRunnerTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	/** The key id every fixture in this file signs with. */
	private const KEY_ID = '2026-01';

	private string $tmp_dir;
	private string $repo_root;
	private string $theme_dir;
	private string $state_dir;
	private string $bundle_path;
	private string $rendered_manifest;
	private string $sealed_manifest;
	private string $promotion_id;
	private string $sha;

	private StateGateway $gateway;
	private ManifestStore $store;

	/** @var array<string, PreparablePromotionStrategy> */
	private array $strategies = array();

	public function set_up(): void {
		parent::set_up();

		$tmp = sys_get_temp_dir() . '/promotion-runner-' . uniqid( '', true );

		$this->tmp_dir     = $tmp;
		$this->repo_root   = $tmp . '/repo';
		$this->theme_dir   = $this->repo_root;
		$this->state_dir   = $tmp . '/state';
		$this->bundle_path = $this->state_dir . '/bundle.json';

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

		$this->seed_database_rows();

		$this->export_bundle();

		// Prepare THROUGH the runner with --manifest=-: STDOUT is the signed
		// manifest itself. The prepared files are then committed, so a later
		// re-prepare inside a test still sees a clean tree.
		$prepared = $this->runner()->promote_overrides( $this->prepare_args_with_dash_manifest() );

		self::assertSame( 0, $prepared->exit_code, $prepared->stderr );

		$this->rendered_manifest = $prepared->stdout;
		$this->promotion_id      = (string) ( json_decode( $this->rendered_manifest, true )['promotionId'] ?? '' );

		$this->sha = $this->commit_prepared_files();

		putenv( 'AGENCY_DEPLOY_COMMIT=' . $this->sha );

		$sealed = ( new PromotionCommandRunner( fn(): string => $this->rendered_manifest ) )->promote_overrides(
			array(
				'seal'          => true,
				'manifest'      => '-',
				'deploy-commit' => $this->sha,
			)
		);

		self::assertSame( 0, $sealed->exit_code, $sealed->stderr );

		$this->sealed_manifest = $sealed->stdout;
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
		putenv( 'AGENCY_DEPLOY_COMMIT' );
		putenv( 'GIT_CONFIG_GLOBAL' );
		putenv( 'GIT_CONFIG_SYSTEM' );

		$this->clear_keyring();

		$this->remove_tree( $this->tmp_dir );

		parent::tear_down();
	}

	public function test_stdout_carries_exactly_one_json_document(): void {
		$result = $this->runner()->promote_overrides( $this->prepare_args_with_dash_manifest() );

		self::assertSame( 0, $result->exit_code );
		self::assertStringEndsWith( "\n", $result->stdout );
		$document = json_decode( $result->stdout, true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $document );
		// A second JSON value or diagnostics after the first makes JSON_THROW_ON_ERROR
		// fail. Exact canonical bytes also reject an extra leading or trailing line.
		self::assertSame( Normalizer::canonical_json_document( $document ), $result->stdout );
	}

	public function test_prepare_with_a_dash_manifest_emits_the_manifest_itself(): void {
		$document = json_decode( $this->runner()->promote_overrides( $this->prepare_args_with_dash_manifest() )->stdout, true );

		self::assertArrayHasKey( 'promotionId', $document );
		self::assertArrayHasKey( 'hmac', $document );
	}

	public function test_prepare_with_a_dash_source_reads_the_bundle_from_stdin(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.
		$bundle_json = (string) file_get_contents( $this->bundle_path );

		$runner = new PromotionCommandRunner( static fn(): string => $bundle_json );
		$result = $runner->promote_overrides(
			array(
				'prepare'  => true,
				'source'   => '-',
				'select'   => 'templates:page',
				'manifest' => '-',
			)
		);

		self::assertSame( 0, $result->exit_code );

		$document = json_decode( $result->stdout, true );

		self::assertArrayHasKey( 'hmac', $document );
		self::assertSame(
			array(),
			glob( $this->state_dir . '/bundle-from-stdin-*.json' ),
			'The stdin materialization must leave no transient bundle file behind.'
		);
	}

	public function test_seal_with_a_dash_manifest_reads_stdin_and_emits_the_sealed_manifest(): void {
		$runner   = new PromotionCommandRunner( fn(): string => $this->rendered_manifest );
		$document = json_decode(
			$runner->promote_overrides(
				array(
					'seal'          => true,
					'manifest'      => '-',
					'deploy-commit' => $this->sha,
				)
			)->stdout,
			true
		);

		self::assertSame( $this->sha, $document['deployCommit'] );
	}

	public function test_finalize_with_a_dash_manifest_emits_one_result_document_not_a_manifest(): void {
		$result = $this->finalize_through_runner();

		self::assertSame( 0, $result->exit_code );

		$document = json_decode( $result->stdout, true );

		self::assertArrayHasKey( 'outcomes', $document );
		self::assertArrayNotHasKey( 'hmac', $document );
	}

	public function test_heartbeat_with_a_dash_manifest_reads_stdin(): void {
		$this->finalize_through_runner();

		$runner = new PromotionCommandRunner( fn(): string => $this->sealed_manifest );
		$result = $runner->promote_overrides(
			array(
				'heartbeat' => true,
				'manifest'  => '-',
			)
		);

		self::assertSame( 0, $result->exit_code );

		$document = json_decode( $result->stdout, true );

		self::assertArrayHasKey( 'outcomes', $document );
		self::assertSame( 'skipped', $document['outcomes']['templates:page'] );
		self::assertSame( 'skipped', $document['outcomes']['template-parts:site-header'] );
	}

	public function test_diagnostics_never_reach_stdout(): void {
		$result = $this->runner()->promote_overrides( array( 'prepare' => true ) );   // missing options

		self::assertSame( 1, $result->exit_code );
		self::assertJson( trim( $result->stdout ) );
		self::assertStringContainsString( '--source', $result->stderr );
	}

	public function test_two_mode_flags_are_a_hard_error(): void {
		self::assertSame(
			1,
			$this->runner()->promote_overrides(
				array(
					'prepare' => true,
					'seal'    => true,
				)
			)->exit_code
		);
	}

	public function test_no_mode_flag_is_a_hard_error(): void {
		$result = $this->runner()->promote_overrides( array( 'manifest' => '-' ) );

		self::assertSame( 1, $result->exit_code );

		$document = json_decode( $result->stdout, true );

		self::assertFalse( $document['ok'] );
		self::assertSame( 1, $document['exitCode'] );
	}

	/**
	 * A mixed prepare — one success, one refusal — surfaces exit 2 through
	 * the command layer with the refusal report inside the emitted manifest.
	 */
	public function test_a_partial_prepare_exits_two_with_the_manifest_on_stdout(): void {
		$result = $this->runner()->promote_overrides(
			array(
				'prepare'  => true,
				'source'   => $this->bundle_path,
				'select'   => 'templates:gallery,templates:page',
				'manifest' => '-',
			)
		);

		self::assertSame( 2, $result->exit_code );

		$document = json_decode( $result->stdout, true );

		self::assertArrayHasKey( 'promotionId', $document );
		self::assertSame(
			array( 'templates:gallery' ),
			array_map( static fn( array $refusal ): string => $refusal['recordKey'], $document['refusals'] )
		);
	}

	public function test_a_lock_conflict_exits_three(): void {
		$lock = new PrepareLock( $this->state_dir . '/prepare.lock' );
		$lock->acquire();

		try {
			$result = $this->runner()->promote_overrides( $this->prepare_args_with_dash_manifest() );
		} finally {
			$lock->release();
		}

		self::assertSame( PromotionExitCode::LOCK_CONFLICT, $result->exit_code );

		$document = json_decode( $result->stdout, true );

		self::assertFalse( $document['ok'] );
		self::assertSame( PromotionExitCode::LOCK_CONFLICT, $document['exitCode'] );
		self::assertStringContainsString( 'prepare.lock', $result->stderr );
	}

	public function test_a_tampered_manifest_exits_four(): void {
		$document             = json_decode( $this->rendered_manifest, true );
		$document['siteUuid'] = '00000000-0000-4000-8000-000000000000';
		$tampered             = wp_json_encode( $document );

		$runner = new PromotionCommandRunner( fn(): string => $tampered );
		$result = $runner->promote_overrides(
			array(
				'seal'          => true,
				'manifest'      => '-',
				'deploy-commit' => $this->sha,
			)
		);

		self::assertSame( PromotionExitCode::TAMPER, $result->exit_code );

		$payload = json_decode( $result->stdout, true );

		self::assertFalse( $payload['ok'] );
		self::assertSame( PromotionExitCode::TAMPER, $payload['exitCode'] );
		self::assertStringContainsString( 'HMAC does not match', $result->stderr );
	}

	public function test_backup_list_defaults_to_json(): void {
		$this->finalize_through_runner();

		$result = $this->runner()->promotion_backups( array( 'list' ), array() );

		self::assertJson( trim( $result->stdout ) );

		$rows = json_decode( $result->stdout, true );

		self::assertIsArray( $rows );
		self::assertSame( $this->promotion_id, $rows[0]['promotionId'], 'The list must name the finalized promotion.' );
		self::assertSame( 2, $rows[0]['records'], 'The list must count the two promoted records.' );
	}

	public function test_backup_list_can_render_a_table(): void {
		$this->finalize_through_runner();

		$result = $this->runner()->promotion_backups( array( 'list' ), array( 'format' => 'table' ) );

		self::assertStringContainsString( 'promotionId', $result->stdout );
		self::assertStringContainsString( $this->promotion_id, $result->stdout );
	}

	public function test_backup_prune_dry_run_deletes_nothing(): void {
		$this->finalize_through_runner();

		// Age the index entry so the backup outlives both its retention
		// window and the --older-than cutoff.
		$index = get_option( PromotionBackup::INDEX_OPTION );

		self::assertIsArray( $index );

		$index[ $this->promotion_id ]['finalizedAtUtc'] = gmdate( 'Y-m-d\TH:i:s\Z', time() - 40 * 86400 );
		update_option( PromotionBackup::INDEX_OPTION, $index, false );

		$result = $this->runner()->promotion_backups(
			array( 'prune' ),
			array(
				'older-than' => '1d',
				'dry-run'    => true,
			)
		);

		self::assertSame( 0, $result->exit_code );

		$document = json_decode( $result->stdout, true );

		self::assertSame( array( $this->promotion_id ), $document['pruned'] );

		self::assertStringContainsString( 'DRY RUN', $result->stderr );

		self::assertTrue(
			( new PromotionBackup( $this->promotion_id ) )->exists( 'templates:page' ),
			'A dry run must not delete the backup chunks.'
		);
		self::assertTrue( $this->store->canonical_exists( $this->promotion_id ), 'A dry run must not delete the canonical manifest.' );
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
	 * @return array<string, mixed>
	 */
	private function prepare_args_with_dash_manifest(): array {
		return array(
			'prepare'  => true,
			'source'   => $this->bundle_path,
			'select'   => 'templates:page,template-parts:site-header',
			'manifest' => '-',
		);
	}

	private function runner(): PromotionCommandRunner {
		return new PromotionCommandRunner();
	}

	/**
	 * Finalizes the sealed manifest through the runner with --manifest=-,
	 * which reads the sealed manifest from STDIN and emits the outcome
	 * document.
	 */
	private function finalize_through_runner(): StateCommandResult {
		return ( new PromotionCommandRunner( fn(): string => $this->sealed_manifest ) )->promote_overrides(
			array(
				'finalize' => true,
				'manifest' => '-',
			)
		);
	}

	/**
	 * The live wp_template / wp_template_part rows the export sees, plus a
	 * published wp_navigation post so the shipped ref-less site-header
	 * navigation resolves WordPress core's deterministic fallback at export
	 * time. The gallery row carries an attachment id that can never resolve,
	 * which is what the per-record refusal path drives.
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
	 * The signed, schema-validated state bundle over the live template and
	 * template-part rows, written to the fixture state directory. The target
	 * site uuid is pinned from the export so the prepare and finalize
	 * identity guards agree with the bundle.
	 */
	private function export_bundle(): void {
		$document = ( new StateExporter( new HmacSigner( array( self::KEY_ID => str_repeat( 'k', 40 ) ), self::KEY_ID ) ) )->export( array( 'templates', 'template-parts' ) );

		putenv( 'AGENCY_TARGET_SITE_UUID=' . (string) $document['siteUuid'] );

		$this->write_json_document( $this->bundle_path, $document );
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
	 * Copies the shipped theme's templates/, parts/ and theme.json into the
	 * fixture repository root, which is also the stylesheet directory every
	 * lifecycle stage reads through the directory filters. The copied
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
	 * copy, and the branch pinned to a known name.
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
