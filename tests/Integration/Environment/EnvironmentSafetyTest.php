<?php
/**
 * Proves the environment-safety guarantees the naming contract promises for
 * a `development` WordPress install: lead delivery resolves to the safe
 * in-process fake (never a real webhook), delivering a lead makes no
 * outbound HTTP call, FileModGuard's production-only guard stays inactive,
 * and DatabaseOverrideCheck reports a clean baseline on a fresh install.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Environment;

use AgencyPlatform\Health\DatabaseOverrideCheck;
use AgencyPlatform\Security\FileModGuard;
use AgencyPlatform\Security\MailGuard;
use SiteCore\Leads\LeadSubmissionHandler;
use SiteIntegrations\LeadDelivery\FakeLeadDelivery;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\Security\FileModGuard
 * @covers \AgencyPlatform\Security\MailGuard
 * @covers \AgencyPlatform\Health\DatabaseOverrideCheck
 * @covers \SiteCore\Leads\LeadSubmissionHandler
 * @covers \SiteIntegrations\LeadDelivery\LeadDeliveryResolver
 */
final class EnvironmentSafetyTest extends IntegrationTestCase {

	/**
	 * URLs `pre_http_request` was invoked with during the current test —
	 * populated by record_and_block_http_request() below. Empty means no
	 * code path attempted an outbound HTTP request.
	 *
	 * @var list<string>
	 */
	private static array $recorded_http_requests = array();

	public function set_up(): void {
		parent::set_up();

		self::$recorded_http_requests = array();

		add_filter( 'pre_http_request', array( self::class, 'record_and_block_http_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( self::class, 'record_and_block_http_request' ), 10 );

		parent::tear_down();
	}

	/**
	 * Named `pre_http_request` callback (never a closure — see the naming
	 * contract): records the requested URL and short-circuits the request
	 * with a WP_Error, so that even if some future regression made a code
	 * path attempt a real outbound call, this test would still never hit
	 * the network — it would just also fail the "no calls recorded"
	 * assertion below.
	 *
	 * @param mixed                 $preempt     The short-circuit value WordPress passed in (unused; required to match the filter signature).
	 * @param array<string, mixed>  $parsed_args HTTP request arguments (unused; required to match the filter signature).
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $preempt and $parsed_args are required by WordPress's `pre_http_request` filter signature (apply_filters() binds positionally, so $url can't be read without declaring both); only $url is used.
	public static function record_and_block_http_request( $preempt, array $parsed_args, string $url ) {
		self::$recorded_http_requests[] = $url;

		return new \WP_Error( 'test_http_blocked', 'Outbound HTTP is blocked during EnvironmentSafetyTest.' );
	}

	public function test_the_test_environment_reports_as_development(): void {
		self::assertSame( 'development', wp_get_environment_type() );
	}

	public function test_lead_delivery_resolves_to_the_fake_implementation(): void {
		self::assertInstanceOf( FakeLeadDelivery::class, apply_filters( 'site_core_lead_delivery', null ) );
	}

	public function test_delivering_a_valid_lead_makes_no_outbound_http_call(): void {
		$result = ( new LeadSubmissionHandler() )->process(
			array(
				'name'    => 'Ada Lovelace',
				'email'   => 'ada@example.com',
				'message' => 'Hello there.',
			)
		);

		self::assertSame( array(), self::$recorded_http_requests );
		self::assertSame( array( 'ok' => true ), $result );
	}

	/**
	 * FileModGuard::register() (see that class) only ever calls add_filter()
	 * when wp_get_environment_type() reports 'production' — Plugin::boot()
	 * already ran once for this whole test process, in 'development' (see
	 * test_the_test_environment_reports_as_development() above), so the
	 * filter was never wired up. An administrator keeping edit_themes here
	 * is the live proof that the guard is provably inactive, not merely
	 * that its condition looks correct on paper.
	 */
	public function test_file_mod_guard_is_inactive_so_administrators_keep_edit_themes(): void {
		$admin = $this->make_admin();
		wp_set_current_user( $admin->ID );

		self::assertTrue( current_user_can( 'edit_themes' ) );
	}

	/**
	 * FileModGuard::disallow_file_editing() has no environment check of its
	 * own — only register() decides whether it ever gets wired up (see the
	 * previous test for proof it is not, here). This exercises the mapping
	 * method directly/in isolation: the same logic production wires up via
	 * `map_meta_cap` once wp_get_environment_type() reports 'production'.
	 * Documented explicitly so it's clear this test does NOT prove the
	 * guard is active anywhere — only that its own capability-mapping logic
	 * is correct once something does call it.
	 */
	public function test_file_mod_guards_mapping_logic_forces_do_not_allow_for_guarded_capabilities(): void {
		$guard = new FileModGuard();

		foreach ( array( 'edit_files', 'edit_plugins', 'edit_themes' ) as $capability ) {
			self::assertSame(
				array( 'do_not_allow' ),
				$guard->disallow_file_editing( array( $capability ), $capability ),
				"disallow_file_editing() must force '{$capability}' to do_not_allow."
			);
		}

		self::assertSame(
			array( 'manage_options' ),
			$guard->disallow_file_editing( array( 'manage_options' ), 'manage_options' ),
			'disallow_file_editing() must leave unrelated capabilities untouched.'
		);
	}

	public function test_database_override_check_reports_a_clean_baseline_on_a_fresh_install(): void {
		$result = ( new DatabaseOverrideCheck() )->run();

		self::assertSame( array(), $result['overrides'] );
	}

	/**
	 * MailGuard::register() (booted with the rest of the mu-plugin for this
	 * whole test process, in 'development') hooks `pre_wp_mail` and returns
	 * `false` outside production, so wp_mail() reports a failed send and never
	 * reaches PHPMailer. Asserted against wp-phpunit's MockPHPMailer via the
	 * canonical reset_phpmailer_instance()/tests_retrieve_phpmailer_instance()
	 * helpers: mock_sent stays empty because the short-circuit fired before
	 * any send.
	 */
	public function test_wp_mail_is_suppressed_in_a_non_production_environment(): void {
		reset_phpmailer_instance();

		$sent = wp_mail( 'someone@example.com', 'Subject line', 'Body text' );

		self::assertFalse( $sent, 'MailGuard must make wp_mail() return false outside production.' );

		$mailer = tests_retrieve_phpmailer_instance();

		self::assertNotFalse( $mailer, 'wp-phpunit should provide a MockPHPMailer instance in the test environment.' );
		self::assertSame(
			array(),
			$mailer->mock_sent,
			'No message should reach PHPMailer when MailGuard suppresses the send.'
		);
	}

	/**
	 * MailGuard::should_suppress() is pure and has no environment check of its
	 * own beyond its arguments; this pins the exact contract the live guard
	 * above relies on — production never suppressed, this ('development')
	 * environment suppressed when the override is absent.
	 */
	public function test_mail_guard_suppression_contract(): void {
		self::assertFalse( MailGuard::should_suppress( 'production', false ) );
		self::assertTrue( MailGuard::should_suppress( wp_get_environment_type(), false ) );
	}

	/**
	 * MailGuard registers `pre_wp_mail` at PHP_INT_MAX so it always runs last
	 * and has the final say. This proves the fail-closed ordering: a hostile or
	 * buggy filter registered at a lower priority (PHP_INT_MAX - 1) that returns
	 * `null` to re-enable delivery cannot outrank the guard — wp_mail() must
	 * still return false and nothing may reach PHPMailer.
	 */
	public function test_mail_guard_wins_over_a_later_filter_trying_to_re_enable_delivery(): void {
		reset_phpmailer_instance();

		add_filter( 'pre_wp_mail', array( self::class, 'force_allow_mail' ), PHP_INT_MAX - 1, 2 );

		$sent = wp_mail( 'someone@example.com', 'Subject line', 'Body text' );

		// Removed before the assertions so a failure can't leak the hostile
		// filter into later tests in this process.
		remove_filter( 'pre_wp_mail', array( self::class, 'force_allow_mail' ), PHP_INT_MAX - 1 );

		self::assertFalse( $sent, 'MailGuard at PHP_INT_MAX must have the final say — a lower-priority filter returning null must not re-enable delivery.' );

		$mailer = tests_retrieve_phpmailer_instance();

		self::assertNotFalse( $mailer, 'wp-phpunit should provide a MockPHPMailer instance in the test environment.' );
		self::assertSame(
			array(),
			$mailer->mock_sent,
			'No message may reach PHPMailer even when an earlier filter tries to allow the send.'
		);
	}

	/**
	 * Named `pre_wp_mail` callback (never a closure — see the naming contract):
	 * unconditionally returns `null` to simulate a plugin or filter trying to
	 * keep mail flowing. MailGuard, pinned at PHP_INT_MAX, must still override
	 * this to `false`.
	 *
	 * @param mixed                $short_circuit The value an earlier filter set (unused; required by the filter signature).
	 * @param array<string, mixed> $atts          wp_mail() arguments (unused; required by the filter signature).
	 * @return null
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- both parameters are required to match WordPress's `pre_wp_mail` filter signature; this fixture deliberately ignores them and always returns null.
	public static function force_allow_mail( $short_circuit, array $atts ) {
		return null;
	}
}
