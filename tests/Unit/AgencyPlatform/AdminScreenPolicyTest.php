<?php
/**
 * Proves AgencyPlatform\Security\AdminScreenPolicy's deny/allow boundary is
 * exactly the one BLOCK_THEME_PROPOSAL.md Â§9.2 specifies: the Site Editor is
 * reachable, every other theme/customizer/file-editor screen is not.
 *
 * @package Tests\Unit
 */

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform;

use AgencyPlatform\Security\AdminScreenPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\Security\AdminScreenPolicy
 */
final class AdminScreenPolicyTest extends TestCase {

	/**
	 * @return list<array{0: string}>
	 */
	public static function denied_screens(): array {
		return array(
			array( 'themes.php' ),
			array( 'theme-install.php' ),
			array( 'theme-editor.php' ),
			array( 'plugin-install.php' ),
			array( 'plugin-editor.php' ),
			array( 'customize.php' ),
			array( 'widgets.php' ),
			array( 'nav-menus.php' ),
			array( 'options-general.php' ),
			array( 'options-writing.php' ),
			array( 'options-reading.php' ),
			array( 'options-discussion.php' ),
			array( 'options-media.php' ),
			array( 'options-permalink.php' ),
			array( 'options.php' ),
		);
	}

	/**
	 * @dataProvider denied_screens
	 */
	public function test_client_roles_are_denied_every_forbidden_screen( string $pagenow ): void {
		self::assertTrue( AdminScreenPolicy::is_denied( $pagenow, false ) );
	}

	/**
	 * @dataProvider denied_screens
	 */
	public function test_privileged_users_are_denied_nothing( string $pagenow ): void {
		self::assertFalse( AdminScreenPolicy::is_denied( $pagenow, true ) );
	}

	public function test_the_site_editor_is_allowed_for_client_roles(): void {
		self::assertFalse( AdminScreenPolicy::is_denied( 'site-editor.php', false ) );
	}

	public function test_the_font_library_is_allowed_for_client_roles(): void {
		// Font Library access is part of the Site Editor capability model
		// (BLOCK_THEME_PROPOSAL.md Â§7.6 keeps font records database-owned), so
		// it is deliberately NOT on the deny list.
		self::assertFalse( AdminScreenPolicy::is_denied( 'font-library.php', false ) );
	}

	public function test_ordinary_content_screens_are_allowed_for_client_roles(): void {
		foreach ( array( 'index.php', 'edit.php', 'post.php', 'post-new.php', 'upload.php', 'profile.php' ) as $pagenow ) {
			self::assertFalse( AdminScreenPolicy::is_denied( $pagenow, false ), $pagenow . ' must stay reachable.' );
		}
	}

	public function test_denied_screens_constant_covers_the_whole_spec_deny_list(): void {
		foreach ( array( 'themes.php', 'theme-install.php', 'theme-editor.php', 'plugin-install.php', 'plugin-editor.php', 'customize.php', 'widgets.php', 'nav-menus.php' ) as $screen ) {
			self::assertContains( $screen, AdminScreenPolicy::DENIED_SCREENS );
		}

		self::assertNotContains( AdminScreenPolicy::SITE_EDITOR_SCREEN, AdminScreenPolicy::DENIED_SCREENS );
	}
}
