<?php

declare(strict_types=1);

namespace AgencyPlatform\Security;

/**
 * Belt-and-braces on top of `DISALLOW_FILE_MODS` (set in
 * config/environments/production.php): in production, maps the
 * file-editing meta capabilities to `do_not_allow` for every user,
 * including administrators, so the plugin/theme file editors and file-based
 * install/update flows stay unreachable even if a future change ever
 * accidentally weakens the `DISALLOW_FILE_MODS` constant.
 */
final class FileModGuard {

	/**
	 * @var string[]
	 */
	private const GUARDED_CAPABILITIES = array( 'edit_files', 'edit_plugins', 'edit_themes' );

	public function register(): void {
		if ( 'production' !== wp_get_environment_type() ) {
			return;
		}

		add_filter( 'map_meta_cap', array( $this, 'disallow_file_editing' ), 10, 2 );
	}

	/**
	 * @param string[] $required_capabilities
	 * @return string[]
	 */
	public function disallow_file_editing( array $required_capabilities, string $capability ): array {
		if ( ! in_array( $capability, self::GUARDED_CAPABILITIES, true ) ) {
			return $required_capabilities;
		}

		return array( 'do_not_allow' );
	}
}
