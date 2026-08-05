<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

use Swaggest\JsonSchema\Exception as JsonSchemaException;
use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

/**
 * Validates a state bundle (and, from the promotion track, a promotion
 * manifest) against the JSON Schema shipped in resources/schemas/.
 *
 * swaggest/json-schema is a runtime `require` dependency, not require-dev:
 * validation also runs on the production WordPress host during promotion
 * finalisation (BLOCK_THEME_PROPOSAL.md §6), where dev dependencies are not
 * installed.
 *
 * Documents arrive as PHP arrays and are converted to the stdClass shape the
 * validator expects by round-tripping through Normalizer::canonical_json():
 * that also guarantees the validated bytes are exactly the canonical bytes
 * everything else in this subsystem hashes and signs.
 */
final class SchemaValidator {

	public const SCHEMA_STATE_BUNDLE = 'state-bundle-v1';

	/**
	 * Declared here so the promotion track never passes a magic string. The
	 * schema FILE is that track's to ship; until it exists, validate() fails
	 * with a clear "schema not found" message.
	 */
	public const SCHEMA_PROMOTION_MANIFEST = 'promotion-manifest-v1';

	private string $schema_dir;

	public function __construct( ?string $schema_dir = null ) {
		$this->schema_dir = $schema_dir ?? self::default_schema_dir();
	}

	/**
	 * The default schema directory of the plugin. This static helper always
	 * returns the default location and ignores any directory configured on an
	 * instance; resolve paths through the instance method schema_path() so
	 * path lookups and validate() always agree.
	 */
	public static function default_schema_dir(): string {
		return dirname( __DIR__, 2 ) . '/resources/schemas';
	}

	/**
	 * The absolute path of the given schema file inside THIS instance's
	 * configured directory.
	 */
	public function schema_path( string $schema_name ): string {
		return $this->schema_dir . '/' . $schema_name . '.json';
	}

	/**
	 * @param array<string, mixed> $document
	 * @throws StateException Exit 1 when the schema is missing or the document is invalid; an invalid-document message names the first violation with its JSON pointer path and states that validation stopped at the first violation.
	 */
	public function validate( array $document, string $schema_name ): void {
		$path = $this->schema_path( $schema_name );

		if ( ! is_file( $path ) ) {
			throw StateException::hard_error( sprintf( 'JSON schema "%s" was not found at %s.', $schema_name, $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a schema file shipped inside this mu-plugin; WP_Filesystem needs a credentials-bearing admin request context that CLI validation does not have.
		$raw = file_get_contents( $path );

		if ( false === $raw ) {
			throw StateException::hard_error( sprintf( 'JSON schema "%s" could not be read from %s.', $schema_name, $path ) );
		}

		try {
			$schema = Schema::import( json_decode( $raw, false, 512, JSON_THROW_ON_ERROR ) );
			$schema->in( json_decode( Normalizer::canonical_json( $document ), false, 512, JSON_THROW_ON_ERROR ) );
		} catch ( InvalidValue $invalid ) {
			throw StateException::hard_error(
				sprintf( 'The document does not match schema "%s": %s; validation stopped at the first violation.', $schema_name, $invalid->getMessage() ),
				$invalid
			);
		} catch ( JsonSchemaException | \JsonException $error ) {
			throw StateException::hard_error(
				sprintf( 'Schema "%s" could not be applied: %s', $schema_name, $error->getMessage() ),
				$error
			);
		}
	}
}
