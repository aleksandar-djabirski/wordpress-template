<?php

declare(strict_types=1);

namespace Tests\Unit\SiteIntegrations\Doubles;

use SiteIntegrations\LeadDelivery\FakeLeadDelivery;

/**
 * Test double for SiteIntegrations\LeadDelivery\FakeLeadDelivery: captures
 * write_log() calls into an array instead of writing to the real PHP error
 * log, so a test can assert on the logged line's exact contents (e.g. that
 * it never contains a full email address or message body). Simpler than
 * juggling ini_set('error_log', ...) plus a temp file — see write_log()'s
 * docblock on the parent class for why that protected seam exists.
 *
 * Lives in its own file (rather than inline as an anonymous class) only
 * because it's reused across multiple test methods; phpcs's
 * Generic.Files.OneObjectStructurePerFile rule keeps it out of the test
 * case file itself.
 */
final class LoggingFakeLeadDelivery extends FakeLeadDelivery {

	/** @var list<string> */
	public array $logged = array();

	protected function write_log( string $line ): void {
		$this->logged[] = $line;
	}
}
