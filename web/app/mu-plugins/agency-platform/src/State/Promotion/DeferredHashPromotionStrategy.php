<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The target-side equivalence contract of the Release 4 gate (master spec
 * §7.6, plan Task 23). A strategy that defers its expected post-reset hash
 * cannot compare against expectedPostResetHash — the expectation is
 * computed ON the target host, right before the reset — so the finalizer
 * needs two capabilities the base PreparablePromotionStrategy does not
 * declare: capture the fully resolved output before anything changes, and
 * verify after the reset that the resolved output did not change.
 *
 * The finalizer guards with `$strategy instanceof
 * DeferredHashPromotionStrategy` and refuses the record (never fatal) when
 * a strategy defers its hash without implementing this interface.
 */
interface DeferredHashPromotionStrategy extends PreparablePromotionStrategy {

	/**
	 * Step 3 of §7.6, on the target: the fully resolved settings and styles
	 * of the site right now, in the canonical flattened view the adapter
	 * exposes. Held in memory across the reset and hashed into
	 * preResetResolvedHash for audit and rollback.
	 *
	 * @return array{settings: array<string, mixed>, styles: array<string, mixed>}
	 */
	public function capture_pre_reset_state(): array;

	/**
	 * Step 7 of §7.6: verify that the post-reset resolved output still
	 * equals the pre-reset snapshot. A difference is refused by the
	 * finalizer as resolved-output-drift, which restores the row from the
	 * backup.
	 *
	 * @param array{settings: array<string, mixed>, styles: array<string, mixed>} $expected_resolved
	 * @return array{equivalent: bool, difference: string|null, hash: string}
	 */
	public function verify_resolved_equivalence( array $expected_resolved ): array;

	/**
	 * The canonical hash of a resolved settings/styles view. Declared here
	 * so the finalizer records preResetResolvedHash through the strategy's
	 * OWN hashing convention instead of reaching for the gateway's
	 * hash_content(): if that canonicalisation ever changes, the audit
	 * record and the equivalence gate must change together — one source of
	 * truth, never two.
	 *
	 * @param array{settings: array<string, mixed>, styles: array<string, mixed>} $resolved
	 */
	public function resolved_hash( array $resolved ): string;
}
