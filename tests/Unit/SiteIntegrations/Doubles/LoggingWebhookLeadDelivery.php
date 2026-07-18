<?php

declare(strict_types=1);

namespace Tests\Unit\SiteIntegrations\Doubles;

use SiteIntegrations\LeadDelivery\WebhookLeadDelivery;

/**
 * Test double for SiteIntegrations\LeadDelivery\WebhookLeadDelivery:
 * captures write_log() calls into an array instead of writing to the real
 * PHP error log, so a test can assert a failure was logged — and that the
 * logged line never contains the lead payload — without touching the real
 * log sink. See LoggingFakeLeadDelivery for why this lives in its own file.
 */
final class LoggingWebhookLeadDelivery extends WebhookLeadDelivery {

	/** @var list<string> */
	public array $logged = array();

	protected function write_log( string $line ): void {
		$this->logged[] = $line;
	}
}
