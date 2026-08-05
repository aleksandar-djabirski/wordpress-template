<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The template-parts promotion strategy: wp_template_part rows promoted
 * into the theme's parts/ directory — never nested — gated on the theme
 * declaring the slug.
 */
final class TemplatePartPromotionStrategy extends AbstractBlockTemplateStrategy {

	public function provider_slug(): string {
		return 'template-parts';
	}

	protected function post_type(): string {
		return 'wp_template_part';
	}

	protected function theme_subdirectory(): string {
		return 'parts';
	}

	public function declares( ThemeDeclaredSlugs $declared, string $record_slug ): bool {
		return $declared->declares_template_part( $record_slug );
	}
}
