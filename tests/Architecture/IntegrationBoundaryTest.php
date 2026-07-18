<?php
/**
 * Keeps outbound HTTP inside its two approved homes. Network egress
 * (wp_remote_*, cURL, fsockopen, Guzzle, or file_get_contents on an http
 * URL) is only allowed under the site-integrations plugin and
 * site-commerce/src/Integrations/. Anywhere else it signals that a layer is
 * reaching out to the network directly instead of delegating to an
 * integration.
 *
 * @package Tests\Architecture
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\ArchitectureScanner;
use Tests\Support\FormatsArchitectureFailures;

require_once dirname( __DIR__ ) . '/support/ArchitectureScanner.php';
require_once dirname( __DIR__ ) . '/support/FormatsArchitectureFailures.php';

final class IntegrationBoundaryTest extends TestCase {

	use FormatsArchitectureFailures;

	private const ALLOWED_FRAGMENTS = array(
		'/web/app/plugins/site-integrations/',
		'/web/app/plugins/site-commerce/src/Integrations/',
	);

	public function test_outbound_http_only_lives_in_integration_layers(): void {
		$violations = array();

		foreach ( $this->production_php_files() as $file ) {
			if ( $this->is_allowed( $file ) ) {
				continue;
			}

			foreach ( ArchitectureScanner::outbound_http_calls( $file ) as $call ) {
				$violations[] = $this->to_relative( $file ) . ':' . $call['line'] . ' (' . $call['call'] . ')';
			}
		}

		self::assertSame(
			array(),
			$violations,
			$this->architecture_failure(
				'Outbound HTTP call outside an integration layer',
				implode( "\n                          ", $violations ),
				'Direct network egress from the theme, site-core, or the platform makes side effects unpredictable and untestable; egress belongs behind an integration.',
				'Move the call into web/app/plugins/site-integrations/ (base profile) or web/app/plugins/site-commerce/src/Integrations/ (commerce), behind a SiteCore\\Contracts\\* interface.'
			)
		);
	}

	private function is_allowed( string $file ): bool {
		$normalized = str_replace( '\\', '/', $file );

		foreach ( self::ALLOWED_FRAGMENTS as $fragment ) {
			if ( str_contains( $normalized, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<string>
	 */
	private function production_php_files(): array {
		$root = $this->repo_root();

		return array_merge(
			ArchitectureScanner::php_files( $root . '/web/app/mu-plugins/agency-platform' ),
			ArchitectureScanner::php_files( $root . '/web/app/plugins/site-core' ),
			ArchitectureScanner::php_files( $root . '/web/app/plugins/site-integrations' ),
			ArchitectureScanner::php_files( $root . '/web/app/plugins/site-commerce' ),
			ArchitectureScanner::php_files( $root . '/web/app/themes/site-theme' )
		);
	}
}
