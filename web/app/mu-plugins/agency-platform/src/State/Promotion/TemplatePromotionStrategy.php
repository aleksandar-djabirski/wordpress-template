<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The templates promotion strategy: wp_template rows promoted into the
 * theme's templates/ directory, gated on the theme declaring the slug.
 */
final class TemplatePromotionStrategy extends AbstractBlockTemplateStrategy {

	public function provider_slug(): string {
		return 'templates';
	}

	protected function post_type(): string {
		return 'wp_template';
	}

	protected function theme_subdirectory(): string {
		return 'templates';
	}

	public function declares( ThemeDeclaredSlugs $declared, string $record_slug ): bool {
		return $declared->declares_template( $record_slug );
	}
}
