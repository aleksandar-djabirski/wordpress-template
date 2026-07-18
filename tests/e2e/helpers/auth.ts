import { expect, type Page } from '@playwright/test';

/**
 * Local-only credentials created by `scripts/setup`:
 *  - admin/admin — `wp core install --admin_password=admin` (LOCAL ONLY).
 *  - client-editor/client-editor — `wp user create client-editor ...
 *    --user_pass=client-editor` (LOCAL ONLY, see scripts/setup step 8).
 * Never valid outside a freshly bootstrapped local/CI DDEV install.
 */
export const CREDS = {
	admin: { u: 'admin', p: 'admin' },
	clientEditor: { u: 'client-editor', p: 'client-editor' },
} as const;

/**
 * Logs into wp-admin (Bedrock serves core, including wp-login.php, under
 * `/wp/`) and asserts the login actually succeeded by checking the browser
 * left wp-login.php behind, rather than asserting on any particular
 * post-login destination.
 */
export async function loginAs( page: Page, username: string, password: string ): Promise<void> {
	await page.goto( '/wp/wp-login.php' );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();

	await expect( page ).not.toHaveURL( /wp-login\.php/ );
}
