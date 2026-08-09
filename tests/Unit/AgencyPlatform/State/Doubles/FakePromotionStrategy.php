<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State\Doubles;

use AgencyPlatform\State\PromotionStrategy;
use AgencyPlatform\State\StateRecord;

/**
 * PromotionStrategy for the delegation test: serves the 'templates'
 * provider slug and never lets its behaviour methods run — every one of
 * them throws, because nothing in this task may call prepare()/reset()/
 * restore(). The promotion track replaces this with the real strategies.
 */
final class FakePromotionStrategy implements PromotionStrategy {

	public function provider_slug(): string {
		return 'templates';
	}

	/**
	 * @return array{preparedPath: string, preparedHash: string, originalHash: string|null}
	 */
	public function prepare( StateRecord $record, string $target_path ): array {
		throw new \LogicException( 'FakePromotionStrategy::prepare() must never be called by this task.' );
	}

	public function reset( StateRecord $record ): void {
		throw new \LogicException( 'FakePromotionStrategy::reset() must never be called by this task.' );
	}

	/**
	 * @param array<string, mixed> $backup
	 */
	public function restore( StateRecord $record, array $backup ): void {
		throw new \LogicException( 'FakePromotionStrategy::restore() must never be called by this task.' );
	}

	public function expected_post_reset_hash( StateRecord $record ): string {
		throw new \LogicException( 'FakePromotionStrategy::expected_post_reset_hash() must never be called by this task.' );
	}
}
