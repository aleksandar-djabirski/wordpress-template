<?php
/**
 * The Release 4 gate: Global Styles promotion through the Theme JSON
 * adapter (master spec §7.6, plan Task 23). The strategy stages the
 * EXPORTED user origin — read from $record->content(), never from the
 * local database origin — merged into the theme origin by WP_Theme_JSON
 * itself, and the finalizer's deferred-hash branch captures the fully
 * resolved output on the TARGET before anything is destroyed, persists
 * its hash as preResetResolvedHash, resets the user origin, and refuses
 * with resolved-output-drift (restoring the row from the backup) when the
 * resolved output changed. expectedPostResetHash stays null for this
 * record: the expectation is computed on the target host, which is why
 * defers_expected_hash() exists.
 *
 * Equivalence resolution route (CORRECTION D8): the stage() tests operate
 * on a COPY of the shipped theme in a temp directory, while
 * capture_pre_reset_state()/verify_resolved_equivalence() resolve through
 * WP_Theme_JSON_Resolver against the ACTIVE theme — they cannot see a
 * temp copy. The write-over-and-restore route is used: the prepared
 * merged bytes are written over the ACTIVE theme.json, and the original
 * bytes are restored in tear_down() AND from a register_shutdown_function()
 * safety net, with an assertion that the restore is byte-identical.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Promotion;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\Promotion\BundleView;
use AgencyPlatform\State\Promotion\GlobalStylesPromotionStrategy;
use AgencyPlatform\State\Promotion\ManifestStore;
use AgencyPlatform\State\Promotion\PromotionException;
use AgencyPlatform\State\Promotion\PromotionFinalizer;
use AgencyPlatform\State\Promotion\PromotionManifest;
use AgencyPlatform\State\Promotion\PromotionOutcome;
use AgencyPlatform\State\Promotion\PromotionRollback;
use AgencyPlatform\State\Promotion\PromotionSelector;
use AgencyPlatform\State\Promotion\PromotionStrategyRegistrar;
use AgencyPlatform\State\Promotion\StateGateway;
use AgencyPlatform\State\Promotion\StagedPromotionEntry;
use AgencyPlatform\State\Promotion\TemplatePartPromotionStrategy;
use AgencyPlatform\State\Promotion\TemplatePromotionStrategy;
use AgencyPlatform\State\Promotion\ThemeDeclaredSlugs;
use AgencyPlatform\State\Promotion\ThemeJsonAdapter;
use AgencyPlatform\State\PromotionStrategies;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\StateRecord;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\GlobalStylesPromotionStrategy
 * @covers \AgencyPlatform\State\Promotion\PromotionFinalizer
 * @covers \AgencyPlatform\State\Promotion\PromotionRollback
 */
// putenv() is how these tests drive AGENCY_* settings and the HMAC keyring
// through EnvironmentConfig's process-environment fallback without
// WordPress or real .env files loaded; WordPress's discouraged-function
// sniff would otherwise flag every call.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class GlobalStylesPromotionTest extends IntegrationTestCase {

	/** The key id every fixture in this file signs with. */
	private const KEY_ID = '2026-01';

	private const PROMOTION_ID  = 'cccccccc-dddd-4eee-8fff-000000000001';
	private const SITE_UUID     = '11111111-2222-4333-8444-555555555555';
	private const DEPLOY_COMMIT = 'ffffffffffffffffffffffffffffffffffffffff';

	private string $tmp_dir;
	private string $state_dir;
	private string $theme_copy_dir;
	private string $active_theme_json_path;
	private string $original_theme_json_bytes;
	private string $global_styles_manifest_path;

	private StateGateway $gateway;
	private ManifestStore $store;
	private ThemeJsonAdapter $adapter;
	private GlobalStylesPromotionStrategy $strategy;
	private PromotionFinalizer $finalizer;
	private PromotionRollback $rollback;
	private PromotionStrategyRegistrar $registrar;

	private string $original_user_origin_hash = '';

	/** @var array<string, \AgencyPlatform\State\PromotionStrategy> */
	private array $strategies = array();

	public function set_up(): void {
		parent::set_up();

		$tmp = sys_get_temp_dir() . '/global-styles-promotion-' . uniqid( '', true );

		$this->tmp_dir        = $tmp;
		$this->state_dir      = $tmp . '/state';
		$this->theme_copy_dir = $tmp . '/theme-copy';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->state_dir, 0755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- creating integration fixture directories; the WP_Filesystem credentials context does not exist here.
		mkdir( $this->theme_copy_dir, 0755, true );

		// The stage() tests write into a COPY of the shipped theme; the
		// equivalence tests write the prepared bytes over the ACTIVE
		// theme.json (CORRECTION D8) and restore them in tear_down() plus a
		// shutdown-function safety net.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- copying the shipped theme.json into an integration fixture directory; the WP_Filesystem credentials context does not exist here.
		copy( get_stylesheet_directory() . '/theme.json', $this->theme_copy_dir . '/theme.json' );

		$this->active_theme_json_path    = get_stylesheet_directory() . '/theme.json';
		$this->original_theme_json_bytes = (string) file_get_contents( $this->active_theme_json_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the shipped on-disk artefact under test; the WP_Filesystem credentials context does not exist here.

		register_shutdown_function(
			function (): void {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- restoring the active theme.json after a fatal test failure; the WP_Filesystem credentials context does not exist here.
				file_put_contents( $this->active_theme_json_path, $this->original_theme_json_bytes );
			}
		);

		$this->gateway   = new StateGateway();
		$this->store     = new ManifestStore( $this->gateway );
		$this->adapter   = new ThemeJsonAdapter();
		$this->strategy  = new GlobalStylesPromotionStrategy( $this->adapter, $this->gateway );
		$this->finalizer = new PromotionFinalizer( $this->gateway, $this->store );
		$this->rollback  = new PromotionRollback( $this->gateway, $this->store );

		$this->registrar = new PromotionStrategyRegistrar();
		$this->registrar->register();
		PromotionStrategies::reset();

		$this->global_styles_manifest_path = $this->state_dir . '/global-styles.json';

		putenv( 'AGENCY_REPO_ROOT=' . $tmp . '/repo' );
		putenv( 'AGENCY_STATE_DIR=' . $this->state_dir );
		putenv( 'AGENCY_DEPLOY_COMMIT=' . self::DEPLOY_COMMIT );
		putenv( 'AGENCY_DEPLOYMENT_ID=' . self::PROMOTION_ID );

		update_option( 'agency_platform_site_uuid', self::SITE_UUID );

		$this->set_keyring();
	}

	public function tear_down(): void {
		// The write-over-and-restore route (CORRECTION D8): the ACTIVE
		// theme.json must be back byte-for-byte after every test.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- restoring the active theme.json after an integration test; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->active_theme_json_path, $this->original_theme_json_bytes );

		self::assertSame(
			$this->original_theme_json_bytes,
			(string) file_get_contents( $this->active_theme_json_path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back the restored on-disk artefact; the WP_Filesystem credentials context does not exist here.
			'The active theme.json must be restored byte-identically after every test.'
		);

		remove_filter( PromotionStrategies::FILTER, array( $this->registrar, 'add_strategies' ) );
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

	/**
	 * The bundle carries production's user origin. A different local origin
	 * must not leak into the promoted theme.json: stage() reads
	 * $record->content() only, never the adapter's user_origin().
	 */
	public function test_prepare_uses_the_exported_user_origin_not_the_local_one(): void {
		$this->save_user_global_style( array( 'styles' => array( 'color' => array( 'background' => '#local' ) ) ) );

		$entry = $this->strategy->stage( $this->exported_record_with_background( '#exported' ), $this->theme_copy_dir );
		$entry->commit();

		$body = (string) file_get_contents( $entry->manifest_fields()['absolutePath'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.

		self::assertStringContainsString( '#exported', $body );
		self::assertStringNotContainsString( '#local', $body );
	}

	public function test_a_user_style_round_trips_without_changing_resolved_output(): void {
		$this->save_user_global_style( array( 'styles' => array( 'color' => array( 'background' => '#101010' ) ) ) );

		$this->deploy_merged_theme_json( $this->live_origin_record() );

		$before = $this->strategy->capture_pre_reset_state();
		$this->strategy->reset( $this->live_origin_record() );
		$outcome = $this->strategy->verify_resolved_equivalence( $before );

		self::assertTrue( $outcome['equivalent'], (string) $outcome['difference'] );
	}

	/**
	 * The negative control, through the real finalizer: the deployed
	 * theme.json does NOT carry the exported user origin (nothing was
	 * promoted into it), so after the reset the resolved output loses the
	 * customisation. The finalizer must restore the user origin row from
	 * the backup and refuse with resolved-output-drift — a false EQUIVALENT
	 * verdict here would silently destroy the customer's content.
	 */
	public function test_promotion_is_refused_when_resolved_output_changes(): void {
		$this->save_user_global_style( $this->style_the_merge_cannot_express() );

		$live_before = $this->live_origin_record();

		$this->original_user_origin_hash = (string) $this->gateway->read_live_record( 'global-styles', 'active' )['contentHash'];

		$object_id_before = $live_before->object_id();

		self::assertNotNull( $object_id_before, 'The refusal test needs a live user origin object id to pin.' );

		$post_before = get_post( $object_id_before, ARRAY_A );

		self::assertIsArray( $post_before, 'The live user origin must have a post row to pin its content bytes.' );
		self::assertIsString( $post_before['post_content'] ?? null );
		self::assertStringContainsString( '#101010', $post_before['post_content'] );

		$this->build_global_styles_manifest( $this->global_styles_manifest_path );

		$outcome = $this->finalizer->finalize( $this->global_styles_manifest_path );

		self::assertSame( 1, $outcome['outcome']->exit_code() );
		self::assertSame( 'resolved-output-drift', $outcome['manifest']->record( 'global-styles:active' )['finalizeRefusalReason'] );
		self::assertSame( 'restored', $outcome['manifest']->record( 'global-styles:active' )['finalizeStatus'] );
		// The user origin must be back exactly as it was.
		self::assertSame(
			$this->original_user_origin_hash,
			$this->gateway->read_live_record( 'global-styles', 'active' )['contentHash']
		);
		// A content hash cannot distinguish a row that was deleted and
		// recreated with identical content — the engagement's own concurrency
		// check exists precisely because of that. The refusal must restore
		// the SAME row: same object id, byte-identical post content.
		self::assertSame(
			$object_id_before,
			$this->live_origin_record()->object_id(),
			'The refusal must restore the same row; a recreated row with identical content must never pass.'
		);
		$post_after = get_post( $object_id_before, ARRAY_A );

		self::assertIsArray( $post_after );
		self::assertSame(
			$post_before['post_content'],
			$post_after['post_content'] ?? null,
			'The restored user origin post content must be byte-identical to the original.'
		);
	}

	public function test_the_pre_reset_hash_is_recorded_in_the_manifest(): void {
		$this->save_user_global_style( array( 'styles' => array( 'color' => array( 'background' => '#101010' ) ) ) );

		$this->deploy_merged_theme_json( $this->live_origin_record() );

		$this->build_global_styles_manifest( $this->global_styles_manifest_path );

		$record = $this->finalizer->finalize( $this->global_styles_manifest_path )['manifest']->record( 'global-styles:active' );

		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $record['preResetResolvedHash'] );
		self::assertSame( 'present', $record['postFinalizeRecordState'] );
		self::assertSame( 'promoted', $record['finalizeStatus'] );
	}

	/**
	 * CRITICAL 1 (whole-unit review): a successful Global Styles promotion
	 * must be rollback-able. For a present-state record the rollback
	 * compares the live record's content hash against the manifest's
	 * postFinalizeSemanticHash — so that manifest value must BE the live
	 * record's content hash. When resolve_current_hash() returned the
	 * resolved-output hash instead, the two quantities could never be equal
	 * and every rollback refused with changed-since-finalize, leaving the
	 * customer's user origin reset.
	 */
	public function test_a_promoted_global_styles_record_rolls_back_byte_identically(): void {
		$this->save_user_global_style( $this->style_the_merge_cannot_express() );

		$live_before = $this->live_origin_record();

		$object_id_before = $live_before->object_id();

		self::assertNotNull( $object_id_before, 'The round trip needs a live user origin object id to pin.' );

		$original_user_origin_hash = (string) $this->gateway->read_live_record( 'global-styles', 'active' )['contentHash'];

		$post_before = get_post( $object_id_before, ARRAY_A );

		self::assertIsArray( $post_before );
		self::assertIsString( $post_before['post_content'] ?? null );
		$original_post_content = $post_before['post_content'];

		$this->deploy_merged_theme_json( $this->live_origin_record() );
		$this->build_global_styles_manifest( $this->global_styles_manifest_path );

		$finalized = $this->finalizer->finalize( $this->global_styles_manifest_path );

		self::assertSame( 0, $finalized['outcome']->exit_code() );
		self::assertSame( 'promoted', $finalized['manifest']->record( 'global-styles:active' )['finalizeStatus'] );

		// The finalize must have reset the user origin to the empty document.
		self::assertNotSame(
			$original_user_origin_hash,
			$this->gateway->read_live_record( 'global-styles', 'active' )['contentHash'],
			'The finalize must reset the user origin before the round trip can be meaningful.'
		);

		$rolled_back = $this->rollback->rollback( $this->global_styles_manifest_path );

		self::assertSame(
			0,
			$rolled_back['outcome']->exit_code(),
			'The rollback must not refuse: a just-finalized record has not changed since finalization.'
		);
		self::assertSame(
			PromotionOutcome::OUTCOME_RESTORED,
			$rolled_back['outcome']->outcomes()['global-styles:active'],
			'The rollback must restore the user origin, never refuse it.'
		);
		self::assertSame( 'restored', $rolled_back['manifest']->record( 'global-styles:active' )['rollbackStatus'] );
		self::assertSame( 'pending', $rolled_back['manifest']->record( 'global-styles:active' )['finalizeStatus'], 'A restored record must be re-finalisable.' );

		// The customer's ORIGINAL user origin must be back: same row, same
		// content hash, byte-identical post content.
		self::assertSame(
			$original_user_origin_hash,
			$this->gateway->read_live_record( 'global-styles', 'active' )['contentHash']
		);
		self::assertSame(
			$object_id_before,
			$this->live_origin_record()->object_id(),
			'The rollback must restore the same row; a recreated row with identical content must never pass.'
		);

		$post_after = get_post( $object_id_before, ARRAY_A );

		self::assertIsArray( $post_after );
		self::assertSame(
			$original_post_content,
			$post_after['post_content'] ?? null,
			'The restored user origin post content must be byte-identical to the original.'
		);
	}

	/**
	 * CRITICAL 2 (whole-unit review): a missing deployed theme.json must
	 * refuse the record as post-reset-unresolved. assert_deployed_file_matches()
	 * deliberately skips a missing file — the safety net is the post-reset
	 * resolution returning null, which only fires when resolve_current_hash()
	 * actually looks at theme.json. A resolve that never touches the file
	 * promotes nothing, resets the customer's user origin, and reports
	 * success.
	 */
	public function test_a_missing_deployed_theme_json_is_refused_post_reset_unresolved(): void {
		$this->save_user_global_style( $this->style_the_merge_cannot_express() );

		$live_before = $this->live_origin_record();

		$object_id_before = $live_before->object_id();

		self::assertNotNull( $object_id_before, 'The missing-file test needs a live user origin object id to pin.' );

		$this->original_user_origin_hash = (string) $this->gateway->read_live_record( 'global-styles', 'active' )['contentHash'];

		$post_before = get_post( $object_id_before, ARRAY_A );

		self::assertIsArray( $post_before );
		self::assertIsString( $post_before['post_content'] ?? null );
		$original_post_content = $post_before['post_content'];

		$this->deploy_merged_theme_json( $this->live_origin_record() );
		$this->build_global_styles_manifest( $this->global_styles_manifest_path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting the deployed theme.json to simulate a deploy that lacks it; the WP_Filesystem credentials context does not exist here.
		unlink( $this->active_theme_json_path );

		self::assertFileDoesNotExist( $this->active_theme_json_path, 'The missing-file scenario needs the deployed theme.json absent.' );

		$outcome = $this->finalizer->finalize( $this->global_styles_manifest_path );

		self::assertSame( 1, $outcome['outcome']->exit_code() );
		self::assertSame(
			'post-reset-unresolved',
			$outcome['manifest']->record( 'global-styles:active' )['finalizeRefusalReason'],
			'A missing deployed theme.json must refuse the record as post-reset-unresolved, never promote it.'
		);
		self::assertSame( 'restored', $outcome['manifest']->record( 'global-styles:active' )['finalizeStatus'] );

		// The user origin must be restored from the backup: same row, same
		// content hash, byte-identical post content.
		self::assertSame(
			$this->original_user_origin_hash,
			$this->gateway->read_live_record( 'global-styles', 'active' )['contentHash']
		);
		self::assertSame(
			$object_id_before,
			$this->live_origin_record()->object_id(),
			'The refusal must restore the same row; a recreated row with identical content must never pass.'
		);

		$post_after = get_post( $object_id_before, ARRAY_A );

		self::assertIsArray( $post_after );
		self::assertSame(
			$original_post_content,
			$post_after['post_content'] ?? null,
			'The restored user origin post content must be byte-identical to the original.'
		);
	}

	public function test_theme_json_keeps_its_template_parts_after_promotion(): void {
		$entry = $this->strategy->stage( $this->exported_record(), $this->theme_copy_dir );
		$entry->commit();

		$decoded = json_decode( (string) file_get_contents( $entry->manifest_fields()['absolutePath'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back an integration fixture file; the WP_Filesystem credentials context does not exist here.

		self::assertIsArray( $decoded );
		self::assertArrayHasKey( 'templateParts', $decoded );
	}

	/**
	 * The Release 3 boundary, from the other side: with the strategy
	 * registered, the selector accepts what it used to refuse.
	 */
	public function test_the_strategy_makes_global_styles_selectable_only_now(): void {
		PromotionStrategies::reset();
		( new PromotionStrategyRegistrar() )->register();

		self::assertNotNull( PromotionStrategies::for_provider( 'global-styles' ) );

		$entries = PromotionSelector::parse(
			'global-styles:active',
			static fn( string $provider ): bool => null !== PromotionStrategies::for_provider( $provider ),
			static fn( string $record_key ): bool => 'global-styles:active' === $record_key
		);

		self::assertCount( 1, $entries );
		self::assertSame( 'global-styles:active', $entries[0]['key'] );
	}

	/**
	 * The shipped-artefact guard: the REAL shipped theme.json, driven
	 * through the REAL adapter and the REAL strategy, must merge the
	 * exported origin, keep EVERY top-level key other than settings and
	 * styles (derived from the shipped file at runtime, never hard-coded,
	 * so keys added later stay covered), and pass validate_theme_json() —
	 * which stage() runs internally, so a shipped file the guards refuse
	 * fails this test.
	 */
	public function test_the_shipped_theme_json_promotes_through_the_real_strategy(): void {
		$raw = file_get_contents( get_stylesheet_directory() . '/theme.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the shipped on-disk artefact under test; the WP_Filesystem credentials context does not exist here.

		self::assertIsString( $raw, 'The shipped theme.json must be readable by the shipped-artefact test.' );

		$shipped = json_decode( $raw, true );

		self::assertIsArray( $shipped );

		$this->save_user_global_style( array( 'styles' => array( 'color' => array( 'background' => '#101010' ) ) ) );

		$entry = $this->strategy->stage( $this->live_origin_record(), $this->theme_copy_dir );
		$entry->commit();

		$decoded = json_decode( (string) file_get_contents( $entry->manifest_fields()['absolutePath'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back the staged artefact; the WP_Filesystem credentials context does not exist here.

		self::assertIsArray( $decoded );

		// Exhaustive, runtime-derived: every top-level key of the shipped
		// theme.json other than settings and styles must survive, identical
		// to the shipped value. $schema and version are as load-bearing as
		// templateParts — losing them is quiet and real. Array values are
		// compared against their canonical form because the staged writer
		// recursively key-sorts object maps (never list order), so the
		// decoded entry keys differ from the shipped file's key order even
		// though every value is identical.
		foreach ( array_diff( array_keys( $shipped ), array( 'settings', 'styles' ) ) as $key ) {
			self::assertArrayHasKey(
				$key,
				$decoded,
				sprintf( 'The shipped top-level theme.json key "%s" must be present in the staged document.', $key )
			);
			self::assertSame(
				is_array( $shipped[ $key ] ) ? Normalizer::sort_recursive( $shipped[ $key ] ) : $shipped[ $key ],
				$decoded[ $key ],
				sprintf( 'The shipped top-level theme.json key "%s" must be unchanged by promotion.', $key )
			);
		}

		self::assertSame( '#101010', $decoded['styles']['color']['background'] );
		self::assertArrayHasKey( 'settings', $decoded );
		self::assertArrayHasKey( 'styles', $decoded );
	}

	public function test_prepare_refuses_because_stage_is_the_promotion_entry_point(): void {
		$exception = $this->assert_exit_code( 1, fn() => $this->strategy->prepare( $this->exported_record(), $this->theme_copy_dir ) );

		self::assertStringContainsString( 'stage()', $exception->getMessage() );
	}

	public function test_expected_post_reset_hash_throws_for_a_deferred_strategy(): void {
		$exception = $this->assert_exit_code( 1, fn() => $this->strategy->expected_post_reset_hash( $this->exported_record() ) );

		self::assertStringContainsString( 'capture_pre_reset_state', $exception->getMessage() );
	}

	/**
	 * CORRECTION D7: the canonical resolved view is already order-insensitive
	 * through the recursive key sort, so resolved_hash() must not depend on
	 * how the resolved document was assembled.
	 */
	public function test_resolved_hash_is_order_insensitive(): void {
		self::assertSame(
			$this->strategy->resolved_hash(
				array(
					'settings' => array(
						'a' => 1,
						'b' => array( 'x' => 'y' ),
					),
					'styles'   => array( 'color' => array( 'background' => '#101010' ) ),
				)
			),
			$this->strategy->resolved_hash(
				array(
					'styles'   => array( 'color' => array( 'background' => '#101010' ) ),
					'settings' => array(
						'b' => array( 'x' => 'y' ),
						'a' => 1,
					),
				)
			)
		);
	}

	/**
	 * The counterpart of the object-map test above: preset lists
	 * (settings.color.palette, settings.spacing.spacingSizes,
	 * settings.typography.fontSizes) carry PRECEDENCE order — the first
	 * matching slug wins — so their order is meaningful and must change the
	 * hash. A refactor that recursively sorted lists too would make this
	 * test fail, exactly as it must.
	 */
	public function test_resolved_hash_is_order_sensitive_for_preset_lists(): void {
		$palette = array(
			array(
				'slug'  => 'base',
				'color' => '#ffffff',
			),
			array(
				'slug'  => 'accent',
				'color' => '#d97b29',
			),
		);

		self::assertNotSame(
			$this->strategy->resolved_hash(
				array(
					'settings' => array( 'color' => array( 'palette' => $palette ) ),
					'styles'   => array(),
				)
			),
			$this->strategy->resolved_hash(
				array(
					'settings' => array( 'color' => array( 'palette' => array_reverse( $palette ) ) ),
					'styles'   => array(),
				)
			),
			'A preset list is a precedence list: reversing it must change the resolved hash.'
		);
	}

	/**
	 * CORRECTION D3: a strategy that defers its expected hash WITHOUT the
	 * target-side equivalence interface must be refused per record, never
	 * fatal, and never called — nothing may be captured, backed up or reset.
	 */
	public function test_a_strategy_that_defers_without_the_interface_is_refused(): void {
		$this->save_user_global_style( $this->style_the_merge_cannot_express() );

		$origin_hash_before = (string) $this->gateway->read_live_record( 'global-styles', 'active' )['contentHash'];

		$this->build_global_styles_manifest( $this->global_styles_manifest_path );

		remove_filter( PromotionStrategies::FILTER, array( $this->registrar, 'add_strategies' ) );
		$this->strategies = array( 'global-styles' => new DefersWithoutInterfaceStrategy() );
		add_filter( PromotionStrategies::FILTER, array( $this, 'provide_strategies' ) );
		PromotionStrategies::reset();

		$outcome = $this->finalizer->finalize( $this->global_styles_manifest_path );

		self::assertSame( 1, $outcome['outcome']->exit_code() );
		self::assertSame( 'deferred-hash-unsupported', $outcome['manifest']->record( 'global-styles:active' )['finalizeRefusalReason'] );
		self::assertSame( 'refused', $outcome['manifest']->record( 'global-styles:active' )['finalizeStatus'] );
		self::assertSame(
			$origin_hash_before,
			$this->gateway->read_live_record( 'global-styles', 'active' )['contentHash'],
			'An unsupported deferred-hash strategy must be refused before anything touches the row.'
		);
	}

	/**
	 * The durable-recovery guarantee of the deferred-hash branch: the
	 * canonical manifest IS the recovery record, so the pre-reset resolved
	 * hash must be persisted to it BEFORE the backup and reset — a value
	 * computed before a destructive step must never wait for a write that
	 * happens after it. This test's strategy throws AFTER the reset (the
	 * equivalence check never runs), and the canonical manifest the run
	 * leaves behind must still carry the hash computed while the record was
	 * pending.
	 */
	public function test_a_failure_after_the_reset_leaves_the_pre_reset_hash_in_the_canonical(): void {
		$this->save_user_global_style( array( 'styles' => array( 'color' => array( 'background' => '#101010' ) ) ) );

		$this->build_global_styles_manifest( $this->global_styles_manifest_path );

		remove_filter( PromotionStrategies::FILTER, array( $this->registrar, 'add_strategies' ) );
		$this->strategies = array( 'global-styles' => new ThrowsAfterResetStrategy() );
		add_filter( PromotionStrategies::FILTER, array( $this, 'provide_strategies' ) );
		PromotionStrategies::reset();

		$exception = $this->assert_exit_code( 1, fn() => $this->finalizer->finalize( $this->global_styles_manifest_path ) );

		self::assertStringContainsString( 'probe failure after reset', $exception->getMessage() );

		$canonical_record = $this->store->load_canonical( self::PROMOTION_ID )->record( 'global-styles:active' );

		self::assertIsArray( $canonical_record );
		self::assertMatchesRegularExpression(
			'/^[0-9a-f]{64}$/',
			(string) ( $canonical_record['preResetResolvedHash'] ?? '' ),
			'The canonical manifest must carry a sha256 preResetResolvedHash even when the finalize throws after the reset.'
		);
		self::assertSame(
			'pending',
			$canonical_record['finalizeStatus'],
			'The failed record must still read pending in the canonical manifest.'
		);
	}

	/**
	 * Named filter callback — never a closure (master spec §4).
	 *
	 * @param array<string, \AgencyPlatform\State\PromotionStrategy> $strategies
	 * @return array<string, \AgencyPlatform\State\PromotionStrategy>
	 */
	public function provide_strategies( array $strategies ): array {
		return $this->strategies;
	}

	/**
	 * The current user origin with the #101010 customisation, as the
	 * exported record would carry it.
	 */
	private function exported_record(): StateRecord {
		return $this->exported_record_with_background( '#101010' );
	}

	private function exported_record_with_background( string $background ): StateRecord {
		return StateRecord::create(
			'global-styles',
			'active',
			null,
			'publish',
			null,
			array( 'styles' => array( 'color' => array( 'background' => $background ) ) ),
			array(),
			Ownership::GIT_BASELINE_PLUS_DB_USER_ORIGIN,
			PromotionPolicy::PROMOTABLE
		);
	}

	private function live_origin_record(): StateRecord {
		$live = $this->gateway->live_state_record( 'global-styles', 'active' );

		self::assertNotNull( $live, 'Expected a live global-styles:active record.' );

		return $live;
	}

	/**
	 * Stages the exported origin into the temp theme copy, commits it, and
	 * writes the prepared bytes over the ACTIVE theme.json — the deployed
	 * file a finalize would verify and resolve against. The original bytes
	 * are restored in tear_down().
	 */
	private function deploy_merged_theme_json( StateRecord $record ): void {
		$entry = $this->strategy->stage( $record, $this->theme_copy_dir );
		$entry->commit();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading back the staged fixture file; the WP_Filesystem credentials context does not exist here.
		$merged_bytes = (string) file_get_contents( $entry->manifest_fields()['absolutePath'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the prepared bytes over the active theme.json for the equivalence tests; the WP_Filesystem credentials context does not exist here.
		file_put_contents( $this->active_theme_json_path, $merged_bytes );
	}

	/**
	 * The signed, sealed, single-record manifest every finalize test drives:
	 * built from the LIVE row (contentHash / modifiedGmt / objectId) and the
	 * CURRENT deployed theme.json bytes, so a finalize that does not re-read
	 * the row cannot pass.
	 */
	private function build_global_styles_manifest( string $path ): void {
		$live = $this->live_origin_record();

		$manifest = PromotionManifest::create(
			self::PROMOTION_ID,
			'2026-08-01T10:00:00Z',
			array(
				'exportId'      => '11111111-2222-4333-8444-555555555555',
				'exportedAtUtc' => '2026-08-01T09:00:00Z',
				'siteUrl'       => home_url(),
				'environment'   => wp_get_environment_type(),
				'activeTheme'   => array(
					'stylesheet' => get_stylesheet(),
					'version'    => (string) wp_get_theme()->get( 'Version' ),
					'gitCommit'  => null,
				),
			),
			self::SITE_UUID,
			str_repeat( 'b', 40 ),
			array( 'npm run test:e2e' )
		);

		$manifest = $manifest->with_record(
			'global-styles:active',
			array(
				'key'                      => 'global-styles:active',
				'provider'                 => 'global-styles',
				'slug'                     => 'active',
				'objectId'                 => $live->object_id(),
				'originalContentHash'      => $live->content_hash(),
				'originalModifiedGmt'      => $live->modified_gmt(),
				'preparedFilePath'         => 'theme.json',
				'themeRelativePath'        => 'theme.json',
				'preparedFileHash'         => $this->active_theme_json_sha256(),
				'originalFileHash'         => null,
				'referenceScan'            => array(),
				'navigationExpectation'    => array(),
				'expectedPostResetHash'    => null,
				'preResetResolvedHash'     => null,
				'postFinalizeRecordState'  => null,
				'postFinalizeSemanticHash' => null,
				'postFinalizeModifiedGmt'  => null,
				'finalizeStatus'           => 'pending',
				'finalizeRefusalReason'    => null,
				'rollbackStatus'           => 'not-attempted',
				'rollbackRefusalReason'    => null,
				'restoredObjectId'         => null,
			)
		);

		$this->store->write( $manifest->with_deploy_commit( self::DEPLOY_COMMIT, '2026-08-01T10:00:00Z' ), $path );
	}

	private function active_theme_json_sha256(): string {
		$hash = hash_file( 'sha256', $this->active_theme_json_path );

		return false === $hash ? '' : $hash;
	}

	/**
	 * The user origin the deployed file does NOT carry: a plain style
	 * override that is only in the database. The equivalence gate's job is
	 * to notice that promoting it (without the file ever gaining it) would
	 * change the resolved output, so the negative control is exactly "the
	 * reset changes the resolved output".
	 *
	 * @return array<string, mixed>
	 */
	private function style_the_merge_cannot_express(): array {
		return array( 'styles' => array( 'color' => array( 'background' => '#101010' ) ) );
	}

	/**
	 * Writes a user origin through the REAL adapter — the same path the
	 * strategy and the finalizer use — so the fixtures and the production
	 * write path cannot drift apart.
	 *
	 * wp-phpunit runs with no current user, so core's on-demand post
	 * creation cannot attach the wp_theme term: wp_insert_post() applies
	 * tax_input only when current_user_can( $taxonomy_obj->cap->assign_terms ),
	 * which is false for user 0. Without the term the resolver's name-field
	 * tax_query cannot find the row. SeedsStateFixtures attaches the term
	 * explicitly for the same reason.
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
 * A DeferredHashPromotionStrategy whose post-reset equivalence check
 * throws — the "failure after the reset" whose durable record must carry
 * the pre-reset resolved hash. capture_pre_reset_state() and
 * resolved_hash() work normally, so the finalizer reaches the backup, the
 * reset and the equivalence check before the exception escapes.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- the plan pins this stub to the Global Styles test file so the post-reset failure path is deterministic.
final class ThrowsAfterResetStrategy extends \AgencyPlatform\State\Promotion\AbstractBlockTemplateStrategy implements \AgencyPlatform\State\Promotion\DeferredHashPromotionStrategy {

	public function provider_slug(): string {
		return 'global-styles';
	}

	public function defers_expected_hash(): bool {
		return true;
	}

	public function post_finalize_record_state(): string {
		return 'present';
	}

	public function declares( ThemeDeclaredSlugs $declared, string $record_slug ): bool {
		return 'active' === $record_slug;
	}

	/**
	 * The deployed theme.json always resolves; a 64-hex placeholder is the
	 * non-null result the finalizer needs to reach the equivalence branch.
	 */
	public function resolve_current_hash( string $record_slug ): ?string {
		return str_repeat( 'a', 64 );
	}

	public function capture_pre_reset_state(): array {
		return array(
			'settings' => array(),
			'styles'   => array( 'color' => array( 'background' => '#101010' ) ),
		);
	}

	public function resolved_hash( array $resolved ): string {
		return ( new StateGateway() )->hash_content( $resolved );
	}

	public function verify_resolved_equivalence( array $expected_resolved ): array {
		throw PromotionException::hard( 'probe failure after reset' );
	}

	protected function post_type(): string {
		return 'wp_global_styles';
	}

	protected function theme_subdirectory(): string {
		return '.';
	}
}

/**
 * A PreparablePromotionStrategy that defers its expected hash but does NOT
 * implement DeferredHashPromotionStrategy — the shape CORRECTION D3 guards
 * against. The finalizer must refuse the record, never call capture or
 * verify on it.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- the plan pins this stub to the Global Styles test file so the deferred-hash guard is deterministic.
final class DefersWithoutInterfaceStrategy extends \AgencyPlatform\State\Promotion\AbstractBlockTemplateStrategy {

	public function provider_slug(): string {
		return 'global-styles';
	}

	public function defers_expected_hash(): bool {
		return true;
	}

	public function post_finalize_record_state(): string {
		return 'present';
	}

	public function declares( ThemeDeclaredSlugs $declared, string $record_slug ): bool {
		return 'active' === $record_slug;
	}

	/**
	 * The deployed theme.json always resolves; a 64-hex placeholder is the
	 * non-null result the finalizer needs to reach the equivalence branch.
	 */
	public function resolve_current_hash( string $record_slug ): ?string {
		return str_repeat( 'a', 64 );
	}

	protected function post_type(): string {
		return 'wp_global_styles';
	}

	protected function theme_subdirectory(): string {
		return '.';
	}
}
