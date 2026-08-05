<?php
/**
 * StateDirectory holds the two most security-relevant guards in the state
 * subsystem — the repository-root boundary and the web-root boundary — and
 * until this file existed it had no tests at all, so a guard proven only by
 * an ad-hoc probe could silently disappear on the next edit.
 *
 * The web-root guard is evaluated on the lexically canonicalised path (dots
 * and `..` collapsed, no filesystem access), so an absolute override like
 * <root>/var/../web/leak is rejected even though its raw string form does
 * not start with the web root, while <root>/../../etc/leak is accepted as
 * the canonical /var/etc/leak. An absolute override outside the repository
 * is legitimate and stays accepted.
 *
 * path() reads AGENCY_REPO_ROOT and AGENCY_STATE_DIR through
 * EnvironmentConfig (a PHP constant first, then the process environment).
 * The unit suite has no WordPress, so ABSPATH is undefined; driving
 * AGENCY_REPO_ROOT through putenv() keeps every test on the real path()
 * code path instead of testing a copy of the decision. No stubs needed.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State;

use AgencyPlatform\State\StateDirectory;
use AgencyPlatform\State\StateException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\StateDirectory
 */
// putenv() is how this test drives AGENCY_REPO_ROOT and AGENCY_STATE_DIR
// through EnvironmentConfig's process-environment fallback without
// WordPress or real .env files loaded; WordPress's discouraged-function
// sniff would otherwise flag every call below.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
final class StateDirectoryTest extends TestCase {

	private const REPO_ROOT = '/var/www/html';

	private ?string $saved_repo_root;
	private ?string $saved_state_dir;

	protected function setUp(): void {
		$this->saved_repo_root = self::env( 'AGENCY_REPO_ROOT' );
		$this->saved_state_dir = self::env( 'AGENCY_STATE_DIR' );

		putenv( 'AGENCY_REPO_ROOT' );
		putenv( 'AGENCY_STATE_DIR' );

		putenv( 'AGENCY_REPO_ROOT=' . self::REPO_ROOT );
	}

	protected function tearDown(): void {
		self::restore( 'AGENCY_REPO_ROOT', $this->saved_repo_root );
		self::restore( 'AGENCY_STATE_DIR', $this->saved_state_dir );

		parent::tearDown();
	}

	public function test_path_defaults_inside_the_repository_when_the_setting_is_unset(): void {
		self::assertSame( self::REPO_ROOT . '/var/agency-state', StateDirectory::path() );
	}

	public function test_a_relative_override_resolves_against_the_repository_root(): void {
		putenv( 'AGENCY_STATE_DIR=var/custom' );

		self::assertSame( self::REPO_ROOT . '/var/custom', StateDirectory::path() );
	}

	public function test_a_relative_override_with_traversal_is_rejected(): void {
		putenv( 'AGENCY_STATE_DIR=../escape' );

		$this->assert_rejected();
	}

	public function test_a_relative_override_into_the_web_root_is_rejected(): void {
		putenv( 'AGENCY_STATE_DIR=web/stuff' );

		$this->assert_rejected();
	}

	public function test_an_absolute_override_into_the_web_root_is_rejected(): void {
		putenv( 'AGENCY_STATE_DIR=' . self::REPO_ROOT . '/web/leak' );

		$this->assert_rejected();
	}

	public function test_an_absolute_override_with_traversal_into_the_web_root_is_rejected(): void {
		putenv( 'AGENCY_STATE_DIR=' . self::REPO_ROOT . '/var/../web/leak' );

		$this->assert_rejected();
	}

	public function test_an_absolute_override_escaping_the_repository_is_canonicalised_and_accepted(): void {
		putenv( 'AGENCY_STATE_DIR=' . self::REPO_ROOT . '/../../etc/leak' );

		$path = StateDirectory::path();

		self::assertSame( '/var/etc/leak', $path );
		self::assertStringNotContainsString( '..', $path );
	}

	public function test_an_absolute_override_outside_the_repository_is_accepted(): void {
		putenv( 'AGENCY_STATE_DIR=/srv/agency-state' );

		self::assertSame( '/srv/agency-state', StateDirectory::path() );
	}

	public function test_an_absolute_override_on_a_windows_drive_is_canonicalised_and_accepted(): void {
		putenv( 'AGENCY_STATE_DIR=C:/srv/agency-state/../state' );

		self::assertSame( 'C:/srv/state', StateDirectory::path() );
	}

	public function test_canonicalise_collapses_dot_and_dotdot_segments_lexically(): void {
		self::assertSame( '/var/www/html/var/agency-state', StateDirectory::canonicalise( '/var/www/html/var/./../var/agency-state' ) );
		self::assertSame( 'var/agency-state', StateDirectory::canonicalise( './var/agency-state/.' ) );
	}

	/**
	 * Asserts that path() throws a hard-error StateException for the
	 * currently configured AGENCY_STATE_DIR — class AND exit code, because
	 * the exit code is the WP-CLI command contract.
	 */
	private function assert_rejected(): void {
		$this->expectException( StateException::class );

		try {
			StateDirectory::path();
		} catch ( StateException $exception ) {
			self::assertSame( StateException::EXIT_HARD_ERROR, $exception->exit_code() );
			throw $exception;
		}
	}

	private static function env( string $name ): ?string {
		$value = getenv( $name );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	private static function restore( string $name, ?string $value ): void {
		if ( null === $value ) {
			putenv( $name );
		} else {
			putenv( $name . '=' . $value );
		}
	}
}
