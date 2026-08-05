<?php
/**
 * The concrete proof of spec §7.3's exclusion list: user email addresses,
 * session tokens, application passwords, and post passwords are customer
 * secrets that must never reach a bundle — even a full --include-content
 * export. The record itself must still travel, with the sensitive fields
 * excluded, so promotion can reason about the content without exfiltrating
 * the secrets.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\StateExporter;
use AgencyPlatform\State\StateRegistry;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\StateExporter
 * @covers \AgencyPlatform\State\Providers\ContentState
 */
final class ExportSensitiveDataTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	private function signer(): HmacSigner {
		return new HmacSigner( array( '2026-01' => str_repeat( 'k', 40 ) ), '2026-01' );
	}

	public function test_no_user_email_session_token_or_application_password_reaches_the_bundle(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'client_editor',
				'user_email' => 'private.person@real-example.com',
			)
		);
		update_user_meta( $user_id, 'session_tokens', array( 'token-abc123' => array( 'expiration' => time() + 3600 ) ) );
		update_user_meta(
			$user_id,
			'_application_passwords',
			array(
				array(
					'name'     => 'agent',
					'password' => 'app-pass-xyz789',
				),
			)
		);

		$page_id = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_author'   => $user_id,
				'post_password' => 'page-secret-987',
			)
		);

		$json = wp_json_encode( ( new StateExporter( $this->signer() ) )->export( StateRegistry::resolve( null, true ) ) );

		self::assertStringNotContainsString( 'private.person@real-example.com', $json );
		self::assertStringNotContainsString( 'token-abc123', $json );
		self::assertStringNotContainsString( 'app-pass-xyz789', $json );
		self::assertStringNotContainsString( 'page-secret-987', $json );
		self::assertStringContainsString( 'content:page-' . $page_id, $json, 'The record itself must still be exported — only the sensitive fields are excluded.' );
	}
}
