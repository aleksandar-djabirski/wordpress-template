<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\State\Doubles;

use AgencyPlatform\State\BaseStateProvider;
use AgencyPlatform\State\Ownership;
use AgencyPlatform\State\PromotionPolicy;
use AgencyPlatform\State\StateRecord;

/**
 * Minimal BaseStateProvider implementation for tests that need a real
 * provider object without touching the database: slug 'fake', no records,
 * no Git baseline, and the neutral database ownership / never-promote
 * policy. Mirrors the existing tests/Unit/SiteIntegrations/Doubles/
 * convention.
 */
final class FakeStateProvider extends BaseStateProvider {

	public function slug(): string {
		return 'fake';
	}

	public function ownership(): string {
		return Ownership::DATABASE;
	}

	public function promotion(): string {
		return PromotionPolicy::NEVER_PROMOTE;
	}

	public function includes_content(): bool {
		return false;
	}

	public function has_git_baseline(): bool {
		return false;
	}

	/**
	 * @return list<StateRecord>
	 */
	public function records(): array {
		return array();
	}

	/**
	 * @return list<StateRecord>
	 */
	public function baseline_records(): array {
		return array();
	}
}
