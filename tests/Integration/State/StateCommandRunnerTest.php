<?php
/**
 * The real command-contract test (BLOCK_THEME_PROPOSAL.md §6): the runner
 * captures the exit code plus both streams of a command run, so the §6
 * contract — machine-readable STDOUT only, every warning on STDERR, exit
 * 0/1/2/4 semantics, and no partial payload on failure — is assertable in
 * PHPUnit where WP_CLI is not loaded at all. The pure helpers are covered by
 * the unit suite; this suite exercises the WordPress-coupled gathers the
 * runner drives.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

use AgencyPlatform\State\BaseStateProvider;
use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\StateCommandRunner;
use AgencyPlatform\State\StateDirectory;
use AgencyPlatform\State\StateRecord;
use AgencyPlatform\State\StateRegistry;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\StateCommandRunner
 */
final class StateCommandRunnerTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	private function runner( string $stdin = '' ): StateCommandRunner {
		return new StateCommandRunner(
			static fn (): string => $stdin,
			null,
			new HmacSigner( array( '2026-01' => str_repeat( 'k', 40 ) ), '2026-01' )
		);
	}

	public function tear_down(): void {
		parent::tear_down();

		remove_filter( StateRegistry::FILTER, array( self::class, 'append_warning_provider' ) );
		StateRegistry::reset();
	}

	public function test_export_to_stdout_writes_only_json_and_exits_zero(): void {
		$result = $this->runner()->state_export(
			array(
				'output'    => '-',
				'providers' => 'templates',
			)
		);

		self::assertSame( 0, $result->exit_code );
		self::assertIsArray( json_decode( $result->stdout, true ), 'STDOUT must be parseable JSON and nothing else.' );
		self::assertStringEndsWith( "\n", $result->stdout );
	}

	public function test_the_sensitivity_warning_goes_to_stderr_never_stdout(): void {
		$result = $this->runner()->state_export( array( 'output' => '-' ) );

		self::assertStringContainsString( 'customer content', $result->stderr );
		self::assertStringNotContainsString( 'customer content', $result->stdout );
	}

	public function test_provider_validation_warnings_go_only_to_stderr(): void {
		add_filter( StateRegistry::FILTER, array( self::class, 'append_warning_provider' ) );
		StateRegistry::reset();

		$result = $this->runner()->state_export(
			array(
				'output'    => '-',
				'providers' => 'warning-provider',
			)
		);

		self::assertSame( 0, $result->exit_code );
		self::assertStringContainsString( 'warning-provider:sample: Deliberate validation warning.', $result->stderr );
		self::assertStringNotContainsString( 'Deliberate validation warning.', $result->stdout );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only assertion over the decoded stdout payload; the assertion must never depend on WordPress being loaded.
		self::assertStringNotContainsString( 'Deliberate validation warning.', json_encode( json_decode( $result->stdout, true ) ) );
	}

	public function test_export_to_a_file_writes_canonical_bytes_and_prints_an_envelope(): void {
		$path   = StateDirectory::ensure() . '/test-bundle.json';
		$result = $this->runner()->state_export(
			array(
				'output'    => $path,
				'providers' => 'templates',
			)
		);

		self::assertSame( 0, $result->exit_code );
		self::assertFileExists( $path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the CLI artifact in an integration assertion; no WP_Filesystem credentials context is available.
		$written = file_get_contents( $path );

		self::assertStringNotContainsString( "\r", $written );
		self::assertSame( $written, Normalizer::canonical_json_document( json_decode( $written, true ) ) );

		$envelope = json_decode( $result->stdout, true );

		self::assertSame( $path, $envelope['output'] );
		self::assertArrayHasKey( 'stateHash', $envelope );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting the CLI artifact in an integration assertion; no WP_Filesystem credentials context is available.
		unlink( $path );
	}

	public function test_an_unknown_provider_exits_one_with_the_message_on_stderr(): void {
		$result = $this->runner()->state_export(
			array(
				'output'    => '-',
				'providers' => 'nonsense',
			)
		);

		self::assertSame( 1, $result->exit_code );
		self::assertStringContainsString( 'nonsense', $result->stderr );
		self::assertSame( '', $result->stdout, 'A failed run must not emit a partial payload.' );
	}

	public function test_missing_hmac_configuration_exits_one(): void {
		$result = ( new StateCommandRunner( null, null, new HmacSigner( array(), '2026-01' ) ) )->state_export( array( 'output' => '-' ) );

		self::assertSame( 1, $result->exit_code );
		self::assertStringContainsString( 'AGENCY_PROMOTION_HMAC_KEYS', $result->stderr );
	}

	public function test_a_missing_output_is_a_hard_error_with_no_json_stdout(): void {
		$result = $this->runner()->state_export( array( 'providers' => 'templates' ) );

		self::assertSame( 1, $result->exit_code );
		self::assertStringContainsString( '--output', $result->stderr );
		self::assertSame( '', $result->stdout );
	}

	public function test_diff_exits_zero_with_no_drift_and_two_with_drift(): void {
		self::assertSame( 0, $this->runner()->state_diff( array( 'format' => 'json' ) )->exit_code );

		$this->make_template( 'page', '<!-- wp:paragraph --><p>Client edit</p><!-- /wp:paragraph -->' );

		$result = $this->runner()->state_diff( array( 'format' => 'json' ) );

		self::assertSame( 2, $result->exit_code );
		self::assertTrue( json_decode( $result->stdout, true )['summary']['drift'] > 0 );
	}

	public function test_diff_json_format_writes_only_json(): void {
		$result = $this->runner()->state_diff( array( 'format' => 'json' ) );

		self::assertIsArray( json_decode( $result->stdout, true ) );
	}

	public function test_diff_table_format_writes_a_human_table(): void {
		$this->make_template( 'page', '<!-- wp:paragraph --><p>Edit</p><!-- /wp:paragraph -->' );

		$result = $this->runner()->state_diff( array( 'format' => 'table' ) );

		self::assertStringContainsString( 'templates:page', $result->stdout );
		self::assertNull( json_decode( $result->stdout, true ) );
	}

	public function test_an_invalid_format_exits_one(): void {
		$result = $this->runner()->state_diff( array( 'format' => 'yaml' ) );

		self::assertSame( 1, $result->exit_code );
		self::assertStringContainsString( 'json', $result->stderr );
	}

	public function test_source_dash_reads_a_bundle_from_stdin(): void {
		$bundle = $this->runner()->state_export(
			array(
				'output'    => '-',
				'providers' => 'templates',
			)
		)->stdout;

		$result = $this->runner( $bundle )->state_diff(
			array(
				'source'    => '-',
				'providers' => 'templates',
				'format'    => 'json',
			)
		);

		self::assertSame( 0, $result->exit_code );
		self::assertSame( 'bundle', json_decode( $result->stdout, true )['mode'] );
	}

	public function test_a_tampered_bundle_on_stdin_exits_four(): void {
		$bundle            = json_decode(
			$this->runner()->state_export(
				array(
					'output'    => '-',
					'providers' => 'templates',
				)
			)->stdout,
			true
		);
		$bundle['siteUrl'] = 'https://attacker.invalid';

		$result = $this->runner( Normalizer::canonical_json_document( $bundle ) )->state_diff(
			array(
				'source' => '-',
				'format' => 'json',
			)
		);

		self::assertSame( 4, $result->exit_code );
		self::assertSame( '', $result->stdout );
	}

	public function test_provider_scoping_reaches_the_report(): void {
		$result = $this->runner()->state_diff(
			array(
				'providers' => 'templates',
				'format'    => 'json',
			)
		);

		self::assertSame( array( 'templates' ), array_values( array_unique( array_column( json_decode( $result->stdout, true )['entries'], 'provider' ) ) ) );
	}

	public function test_include_content_reaches_the_report(): void {
		self::factory()->post->create( array( 'post_type' => 'page' ) );

		$entries = json_decode(
			$this->runner()->state_diff(
				array(
					'include-content' => true,
					'format'          => 'json',
				)
			)->stdout,
			true
		)['entries'];

		self::assertContains( 'content', array_column( $entries, 'provider' ) );
	}

	/**
	 * @param array<string, mixed> $providers
	 * @return array<string, mixed>
	 */
	public static function append_warning_provider( array $providers ): array {
		$providers['warning-provider'] = new WarningStateProvider();

		return $providers;
	}
}

/**
 * A test-local provider that always emits one validation warning, so the
 * warning channel of state-export can be pinned without using a production
 * closure and without adding any text to the JSON bundle.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- the plan fixes the warning-channel test provider to this file so the warning channel is pinned without a production closure or a shared fixtures file.
final class WarningStateProvider extends BaseStateProvider {

	public function slug(): string {
		return 'warning-provider';
	}

	public function ownership(): string {
		return Ownership::DATABASE;
	}

	public function promotion(): string {
		return PromotionPolicy::NEVER_PROMOTE;
	}

	public function includes_content(): bool {
		return false;
	}

	public function has_git_baseline(): bool {
		return false;
	}

	/**
	 * @return list<StateRecord>
	 */
	public function records(): array {
		return array(
			StateRecord::create(
				$this->slug(),
				'sample',
				null,
				'publish',
				null,
				Normalizer::normalize_content( array( 'markup' => '<!-- wp:paragraph --><p>Sample</p><!-- /wp:paragraph -->' ) ),
				array(),
				$this->ownership(),
				$this->promotion()
			),
		);
	}

	/**
	 * @return list<StateRecord>
	 */
	public function baseline_records(): array {
		return array();
	}

	/**
	 * @return list<string>
	 */
	public function validate( StateRecord $record ): array {
		return array( 'Deliberate validation warning.' );
	}
}
