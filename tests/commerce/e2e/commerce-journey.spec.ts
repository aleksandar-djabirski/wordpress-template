import { test } from '@playwright/test';

/**
 * Commerce-profile e2e harness. See tests/commerce/README.md for how a
 * project enables the commerce profile (installs WooCommerce, activates
 * site-commerce). These specs only run when a project has actually done
 * that AND opts in via `COMMERCE=1` — the base starter ships the harness,
 * not fake tests that would either always skip silently or fail against a
 * site with no WooCommerce installed.
 *
 * Run with: COMMERCE=1 npx playwright test tests/commerce/e2e
 */
test.describe( 'commerce journeys', () => {
	test.skip( process.env.COMMERCE !== '1', 'commerce profile only — set COMMERCE=1 to run' );

	/**
	 * The full customer journey a commerce-enabled project needs covered
	 * once WooCommerce is installed and site-commerce is activated:
	 *
	 *  1. Product archive (shop page) lists purchasable products.
	 *  2. Single product page (PDP) renders price, add-to-cart form.
	 *  3. Add to cart updates the cart count/total.
	 *  4. Checkout form validation rejects incomplete/invalid input.
	 *  5. Placing an order reaches an order-confirmation state.
	 *
	 * `test.fixme()` marks this as known-incomplete work rather than a
	 * passing no-op, so it stays visible in `--list`/reporters as something
	 * to implement, without ever going green on a base install that has no
	 * WooCommerce to test against.
	 */
	test.fixme(
		'product archive -> PDP -> add to cart -> checkout validation -> order',
		async () => {
			// Intentionally unimplemented — see the journey list above.
			// Implement against a real WooCommerce install once a project
			// enables the commerce profile.
		}
	);
} );
