<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Placeholder so `composer test:architecture` is green from the first
 * commit. A later task replaces this with real architecture tests
 * (namespace boundaries, deptrac-backed checks, environment-safety
 * assertions, hook-registration conventions, etc).
 *
 * @group placeholder
 */
final class PlaceholderTest extends TestCase {

	public function test_repo_root_contains_composer_json(): void {
		$repo_root = dirname( __DIR__, 2 );

		self::assertFileExists( $repo_root . '/composer.json' );
	}
}
