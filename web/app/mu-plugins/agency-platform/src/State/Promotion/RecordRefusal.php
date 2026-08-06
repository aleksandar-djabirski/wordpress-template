<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * One per-record refusal during a promotion run (BLOCK_THEME_PROPOSAL.md
 * §7.5): the record key, the reason code and the detail an operator needs to
 * decide whether to fix the record or exclude it. The optional block fields
 * carry the exact position a reference check failed at, so the report can
 * point at the offending block rather than the whole record.
 */
final class RecordRefusal {

	public function __construct(
		public readonly string $record_key,
		public readonly string $provider,
		public readonly string $slug,
		public readonly string $reason_code,
		public readonly string $detail,
		public readonly ?string $block_name = null,
		public readonly ?string $attribute = null,
		public readonly ?string $referenced_value = null,
		public readonly ?string $suggested_policy = null
	) {}

	/**
	 * @return array<string, mixed> camelCase keys for the manifest/report.
	 */
	public function to_array(): array {
		return array(
			'recordKey'       => $this->record_key,
			'provider'        => $this->provider,
			'slug'            => $this->slug,
			'reasonCode'      => $this->reason_code,
			'detail'          => $this->detail,
			'blockName'       => $this->block_name,
			'attribute'       => $this->attribute,
			'referencedValue' => $this->referenced_value,
			'suggestedPolicy' => $this->suggested_policy,
		);
	}
}
