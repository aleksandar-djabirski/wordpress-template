<?php
/**
 * The concrete proof of spec §7.3's exclusion list: user email addresses,
 * session tokens, application passwords, and post passwords are customer
 * secrets that must never reach a bundle — even a full --include-content
 * export. Multiple distinct secrets are seeded — two users, two sessions,
 * two application passwords, two password-protected posts — and the
 * exported shape is asserted against the ALLOWLIST of fields the schema
 * defines, never a denylist of hard-coded literals: a leak of any other
 * secret would appear as a new field and fail the allowlist. The records
 * themselves must still travel, with the sensitive fields excluded, so
 * promotion can reason about the content without exfiltrating the secrets.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\State;

use AgencyPlatform\State\HmacSigner;
use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\StateExporter;
use AgencyPlatform\State\StateRegistry;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\State\StateExporter
 * @covers \AgencyPlatform\State\Providers\ContentState
 */
final class ExportSensitiveDataTest extends IntegrationTestCase {

	use SeedsStateFixtures;

	/**
	 * The schema's provider node fields (resources/schemas/state-bundle-v1.json):
	 * a provider carries its metadata and its record list, nothing else.
	 *
	 * @var list<string>
	 */
	private const PROVIDER_FIELDS = array( 'slug', 'ownership', 'promotion', 'hasGitBaseline', 'records' );

	/**
	 * The schema's record fields: every record in every bundle must be
	 * exactly these ten. This is the exported-field allowlist the §7.3 rule
	 * is asserted against — a secret leaked as any new record field fails
	 * here even when the test never hard-coded the secret.
	 *
	 * @var list<string>
	 */
	private const RECORD_FIELDS = array( 'key', 'objectId', 'slug', 'status', 'modifiedGmt', 'content', 'contentHash', 'references', 'ownership', 'promotion' );

	/**
	 * The schema's content-node fields, the only shape ContentState may
	 * export: identifying data is reduced to authorId and hasPassword.
	 *
	 * @var list<string>
	 */
	private const CONTENT_FIELDS = array( 'postType', 'title', 'status', 'slug', 'parent', 'menuOrder', 'pageTemplate', 'authorId', 'hasPassword', 'markup' );

	/**
	 * The schema's reference fields.
	 *
	 * @var list<string>
	 */
	private const REFERENCE_FIELDS = array( 'record', 'provider', 'blockName', 'attribute', 'value', 'kind', 'resolution', 'policy', 'targetKey', 'targetHash', 'targetIdentity' );

	private function signer(): HmacSigner {
		return new HmacSigner( array( '2026-01' => str_repeat( 'k', 40 ) ), '2026-01' );
	}

	public function test_no_user_email_session_token_or_application_password_reaches_the_bundle(): void {
		$first_user  = self::factory()->user->create(
			array(
				'role'       => 'client_editor',
				'user_email' => 'private.person@real-example.com',
			)
		);
		$second_user = self::factory()->user->create(
			array(
				'role'       => 'client_editor',
				'user_email' => 'another.private@example.org',
			)
		);

		update_user_meta( $first_user, 'session_tokens', array( 'token-abc123' => array( 'expiration' => time() + 3600 ) ) );
		update_user_meta(
			$first_user,
			'_application_passwords',
			array(
				array(
					'name'     => 'agent',
					'password' => 'app-pass-xyz789',
				),
			)
		);
		update_user_meta( $second_user, 'session_tokens', array( 'token-def456' => array( 'expiration' => time() + 7200 ) ) );
		update_user_meta(
			$second_user,
			'_application_passwords',
			array(
				array(
					'name'     => 'agent-two',
					'password' => 'app-pass-qwerty42',
				),
			)
		);

		$page_id  = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_author'   => $first_user,
				'post_password' => 'page-secret-987',
			)
		);
		$draft_id = self::factory()->post->create(
			array(
				'post_type'     => 'post',
				'post_author'   => $second_user,
				'post_password' => 'draft-secret-123',
				'post_status'   => 'draft',
			)
		);

		$document = ( new StateExporter( $this->signer() ) )->export( StateRegistry::resolve( null, true ) );
		$json     = Normalizer::canonical_json_document( $document );

		$this->assert_exported_shape_is_allowlisted( $document );

		self::assertStringContainsString( 'content:page-' . $page_id, $json, 'The page record itself must still be exported — only the sensitive fields are excluded.' );
		self::assertStringContainsString( 'content:post-' . $draft_id, $json, 'The draft record itself must still be exported — only the sensitive fields are excluded.' );

		foreach ( array( 'private.person@real-example.com', 'another.private@example.org', 'token-abc123', 'token-def456', 'app-pass-xyz789', 'app-pass-qwerty42', 'page-secret-987', 'draft-secret-123' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $json, 'The seeded secret "' . $secret . '" must not appear anywhere in the exported document.' );
		}
	}

	/**
	 * The fail-closed shape check: every key of every exported provider
	 * node, content record, content field, and reference must be a field
	 * the schema allowlists. A leak of any secret — seeded or not — that
	 * added a field would fail here.
	 *
	 * @param array<string, mixed> $document
	 */
	private function assert_exported_shape_is_allowlisted( array $document ): void {
		foreach ( $document['providers'] as $provider_slug => $node ) {
			foreach ( array_keys( $node ) as $field ) {
				self::assertContains( $field, self::PROVIDER_FIELDS, sprintf( 'Provider node "%s" carries the non-allowlisted field "%s"; a leak would hide here.', $provider_slug, $field ) );
			}
		}

		foreach ( $document['providers']['content']['records'] as $record ) {
			foreach ( array_keys( $record ) as $field ) {
				self::assertContains( $field, self::RECORD_FIELDS, sprintf( 'Content record "%s" carries the non-allowlisted field "%s"; a leak would hide here.', $record['key'], $field ) );
			}

			foreach ( array_keys( $record['content'] ) as $field ) {
				self::assertContains( $field, self::CONTENT_FIELDS, sprintf( 'Content record "%s" carries the non-allowlisted content field "%s"; a leak would hide here.', $record['key'], $field ) );
			}

			foreach ( $record['references'] as $reference ) {
				foreach ( array_keys( $reference ) as $field ) {
					self::assertContains( $field, self::REFERENCE_FIELDS, sprintf( 'A reference of content record "%s" carries the non-allowlisted field "%s"; a leak would hide here.', $record['key'], $field ) );
				}
			}
		}
	}
}
