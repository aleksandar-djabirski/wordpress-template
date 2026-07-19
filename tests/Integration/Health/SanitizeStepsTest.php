<?php
/**
 * The first runtime exercise of AgencyPlatform\Health\SanitizeSteps' users and
 * comments steps. The unit suite only covers the pure synthetic-value helpers;
 * this suite seeds a real non-administrator user and a real comment carrying
 * PII, runs the steps directly, and asserts every scrubbed field — reading the
 * raw comments table for the comment assertions so they prove exactly what the
 * SQL wrote. It also pins the two deliberate non-scrubs: `user_login` (identity
 * needed to log into the sanitized copy) and `comment_content` (a per-project
 * editorial decision), and that administrators are spared by default.
 *
 * @package Tests\Integration
 */

declare(strict_types=1);

namespace Tests\Integration\Health;

use AgencyPlatform\Health\SanitizeSteps;
use Tests\Integration\IntegrationTestCase;

/**
 * @covers \AgencyPlatform\Health\SanitizeSteps
 */
final class SanitizeStepsTest extends IntegrationTestCase {

	public function test_users_step_anonymizes_profile_pii_but_preserves_user_login(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'          => 'subscriber',
				'user_email'    => 'real.person@real-example.com',
				'user_url'      => 'https://real-person.example.com',
				'display_name'  => 'Real Person',
				'user_nicename' => 'real-person',
			)
		);

		update_user_meta( $user_id, 'first_name', 'Real' );
		update_user_meta( $user_id, 'last_name', 'Person' );
		update_user_meta( $user_id, 'nickname', 'realp' );
		update_user_meta( $user_id, 'description', 'A real bio that names a real person.' );

		$original_login = get_userdata( $user_id )->user_login;

		// An administrator whose identity must survive the default run.
		$admin_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'staff@agency.example',
			)
		);

		SanitizeSteps::users( array() );

		$user = get_userdata( $user_id );

		self::assertSame( SanitizeSteps::sanitized_user_email( $user_id ), $user->user_email, 'user_email must become the synthetic per-user address.' );
		self::assertSame( '', $user->user_url, 'user_url must be cleared.' );
		self::assertSame( SanitizeSteps::sanitized_display_name( $user_id ), $user->display_name, 'display_name must be anonymized.' );
		self::assertSame( SanitizeSteps::sanitized_user_nicename( $user_id ), $user->user_nicename, 'user_nicename (author slug) must be anonymized.' );

		self::assertSame( '', get_user_meta( $user_id, 'first_name', true ), 'first_name meta must be blanked.' );
		self::assertSame( '', get_user_meta( $user_id, 'last_name', true ), 'last_name meta must be blanked.' );
		self::assertSame( '', get_user_meta( $user_id, 'nickname', true ), 'nickname meta must be blanked (not reset to user_login).' );
		self::assertSame( '', get_user_meta( $user_id, 'description', true ), 'description meta must be blanked.' );

		self::assertSame( $original_login, $user->user_login, 'user_login must be preserved — it is the identity needed to log into the sanitized copy.' );

		self::assertSame( 'staff@agency.example', get_userdata( $admin_id )->user_email, 'Administrator email must be preserved by default (no --include-admins).' );
	}

	public function test_users_step_is_idempotent(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'second.person@real-example.com',
			)
		);
		update_user_meta( $user_id, 'first_name', 'Second' );

		SanitizeSteps::users( array() );
		$first_pass_email = get_userdata( $user_id )->user_email;

		SanitizeSteps::users( array() );

		self::assertSame( $first_pass_email, get_userdata( $user_id )->user_email, 'A second run must leave the already-sanitized email unchanged.' );
		self::assertSame( '', get_user_meta( $user_id, 'first_name', true ), 'A second run must leave the already-blanked meta blanked.' );
	}

	public function test_comments_step_anonymizes_commenter_pii_but_preserves_content(): void {
		$comment_id = self::factory()->comment->create(
			array(
				'comment_author'       => 'Real Commenter',
				'comment_author_email' => 'commenter@real-example.com',
				'comment_author_url'   => 'https://commenter.example.com',
				'comment_author_IP'    => '198.51.100.9',
				'comment_agent'        => 'Mozilla/5.0 (RealDevice) RealBrowser/1.0',
				'comment_content'      => 'A genuine comment body that must survive the scrub.',
			)
		);

		SanitizeSteps::comments( array() );

		$row = $this->comment_row( $comment_id );

		self::assertSame( SanitizeSteps::sanitized_comment_author( $comment_id ), $row['comment_author'], 'comment_author must be anonymized.' );
		self::assertSame( SanitizeSteps::sanitized_comment_email( $comment_id ), $row['comment_author_email'], 'comment_author_email must become the synthetic per-comment address.' );
		self::assertSame( '', $row['comment_author_url'], 'comment_author_url must be cleared.' );
		self::assertSame( '', $row['comment_author_IP'], 'comment_author_IP must be cleared.' );
		self::assertSame( '', $row['comment_agent'], 'comment_agent must be cleared.' );

		self::assertSame(
			'A genuine comment body that must survive the scrub.',
			$row['comment_content'],
			'comment_content must be preserved — scrubbing it is a per-project editorial decision, not a baseline default.'
		);
	}

	public function test_comments_step_is_idempotent(): void {
		$comment_id = self::factory()->comment->create(
			array(
				'comment_author'    => 'Another Commenter',
				'comment_author_IP' => '203.0.113.55',
			)
		);

		SanitizeSteps::comments( array() );
		$first_pass = $this->comment_row( $comment_id );

		SanitizeSteps::comments( array() );
		$second_pass = $this->comment_row( $comment_id );

		self::assertSame( $first_pass['comment_author'], $second_pass['comment_author'], 'A second run must leave the already-anonymized author unchanged.' );
		self::assertSame( '', $second_pass['comment_author_IP'], 'A second run must leave the already-cleared IP cleared.' );
	}

	/**
	 * The comment's PII columns read raw (the step writes via $wpdb->query,
	 * bypassing WordPress's comment cache), so the assertions prove exactly
	 * what the sanitize SQL wrote.
	 *
	 * @return array<string, string>
	 */
	private function comment_row( int $comment_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only read; table name is $wpdb->comments, comment id is bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT comment_author, comment_author_email, comment_author_url, comment_author_IP, comment_agent, comment_content FROM {$wpdb->comments} WHERE comment_ID = %d", $comment_id ), ARRAY_A );

		return is_array( $row ) ? array_map( 'strval', $row ) : array();
	}
}
