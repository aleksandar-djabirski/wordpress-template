<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The fail-closed seam onto WordPress's Theme JSON internals (master spec
 * §7.6, plan Task 22). This class is the ONLY file in the project allowed to
 * name any Theme JSON internal — including its cache-clearing calls — so the
 * promotion strategy and the finalizer can do their job through this adapter
 * and never touch WP_Theme_JSON, WP_Theme_JSON_Data or
 * WP_Theme_JSON_Resolver themselves.
 *
 * The adapter is fail-closed: every operation requires the locked WordPress
 * version to expose every symbol this class depends on. The probe accepts
 * the four symbol forms (class, Class::method, function(), Class::CONST)
 * through an injectable callable so tests can prove the fail-closed path,
 * and the default probe answers with class_exists()/method_exists()/
 * function_exists()/defined(). Because this repository targets exactly one
 * WordPress version, there is deliberately no version matrix: the locked
 * version's support is asserted by an integration test that runs on every
 * dependency update.
 *
 * The two views this adapter exposes are chosen so that a promotion never
 * reports drift by construction:
 *
 * - merge_user_into_theme() builds its result from WP_Theme_JSON::get_data(),
 *   the flattened, input-shaped view, NEVER from get_raw_data(): the raw
 *   view keys every preset node by origin ({"theme":[...],"custom":[...]}),
 *   which is not valid theme.json input and mis-registers every preset when
 *   written back.
 * - resolved() and resolved_without_user_origin() both return
 *   WP_Theme_JSON_Resolver::get_merged_data()->get_data() split into
 *   settings and styles. That canonical view flattens origins, so a user
 *   preset that promotion legitimately MOVES from the custom origin to the
 *   theme origin resolves identically on both sides of the reset.
 *
 * refresh_caches() is the ONLY cache entry point: wp_clean_theme_json_cache()
 * and WP_Theme_JSON_Resolver::clean_cached_data() do NOT clear
 * WP_Theme_JSON_Resolver::$theme_json_file_cache, so a process that resolved
 * before theme.json changed would keep resolving the old file and produce a
 * false drift verdict. The static property is therefore cleared by
 * reflection, guarded with property_exists() so a future WordPress that
 * removes it degrades to a no-op instead of fataling.
 */
final class ThemeJsonAdapter {

	/** Every internal Core symbol this adapter depends on. */
	public const REQUIRED_CLASSES = array( 'WP_Theme_JSON', 'WP_Theme_JSON_Data', 'WP_Theme_JSON_Resolver' );

	/** @var array<string, list<string>> */
	public const REQUIRED_METHODS = array(
		'WP_Theme_JSON_Resolver' => array(
			'get_theme_data',
			'get_user_data',
			'get_merged_data',
			'clean_cached_data',
			'get_user_global_styles_post_id',
		),
		'WP_Theme_JSON'          => array( 'get_raw_data', 'get_data', 'merge' ),
		'WP_Theme_JSON_Data'     => array( 'get_theme_json', 'update_with' ),
	);

	/** @var list<string> */
	public const REQUIRED_FUNCTIONS = array( 'wp_get_global_settings', 'wp_get_global_styles' );

	/** @var list<string> */
	public const REQUIRED_CONSTANTS = array( 'WP_Theme_JSON::LATEST_SCHEMA' );

	/**
	 * @var callable(string):bool symbol name -> the symbol exists
	 */
	private $probe;

	/**
	 * @param callable(string):bool|null $probe test seam: symbol name -> exists.
	 */
	public function __construct( ?callable $probe = null ) {
		$this->probe = $probe ?? static function ( string $symbol ): bool {
			if ( str_ends_with( $symbol, '()' ) ) {
				return function_exists( substr( $symbol, 0, -2 ) );
			}

			if ( str_contains( $symbol, '::' ) ) {
				if ( defined( $symbol ) ) {
					return true;
				}

				$parts = explode( '::', $symbol, 2 );

				return class_exists( $parts[0] ) && method_exists( $parts[0], $parts[1] );
			}

			return class_exists( $symbol );
		};
	}

	/**
	 * @return list<string> the symbols that are missing
	 */
	public function missing_symbols(): array {
		$missing = array();

		foreach ( self::REQUIRED_CLASSES as $symbol ) {
			if ( ! $this->probe_exists( $symbol ) ) {
				$missing[] = $symbol;
			}
		}

		foreach ( self::REQUIRED_METHODS as $class => $methods ) {
			foreach ( $methods as $method ) {
				$symbol = $class . '::' . $method;

				if ( ! $this->probe_exists( $symbol ) ) {
					$missing[] = $symbol;
				}
			}
		}

		foreach ( self::REQUIRED_FUNCTIONS as $function ) {
			$symbol = $function . '()';

			if ( ! $this->probe_exists( $symbol ) ) {
				$missing[] = $symbol;
			}
		}

		foreach ( self::REQUIRED_CONSTANTS as $symbol ) {
			if ( ! $this->probe_exists( $symbol ) ) {
				$missing[] = $symbol;
			}
		}

		return $missing;
	}

	public function supported(): bool {
		return array() === $this->missing_symbols();
	}

	/**
	 * @throws PromotionException Exit 1 when supported() is false.
	 */
	public function require_support(): void {
		$missing = $this->missing_symbols();

		if ( array() !== $missing ) {
			throw PromotionException::hard(
				'This WordPress version does not expose the Theme JSON API shape this adapter needs: '
				. implode( ', ', $missing )
				. '. Global Styles promotion is refused.'
			);
		}
	}

	/**
	 * The active theme's own global-styles data, flattened to input shape.
	 *
	 * @return array<string, mixed>
	 */
	public function theme_origin(): array {
		$this->require_support();

		$data = \WP_Theme_JSON_Resolver::get_theme_data()->get_data();

		return is_array( $data ) ? $data : array();
	}

	/**
	 * The user's global-styles data, flattened to input shape.
	 *
	 * @return array<string, mixed>
	 */
	public function user_origin(): array {
		$this->require_support();

		$data = \WP_Theme_JSON_Resolver::get_user_data()->get_data();

		return is_array( $data ) ? $data : array();
	}

	/**
	 * The fully resolved settings and styles of the site right now, in the
	 * canonical flattened view — the SAME view resolved_without_user_origin()
	 * returns, so the two are directly comparable.
	 *
	 * @return array{settings: array<string, mixed>, styles: array<string, mixed>}
	 */
	public function resolved(): array {
		$this->require_support();

		return $this->split_resolved( \WP_Theme_JSON_Resolver::get_merged_data()->get_data() );
	}

	/**
	 * WordPress-aware merge — never array_merge_recursive. Both origins are
	 * parsed and merged by WP_Theme_JSON itself (origin 'theme' for the
	 * theme, 'custom' for the user), so preset lists merge by slug exactly
	 * as the editor would, and the flattened get_data() output is valid
	 * theme.json input again. Every top-level key other than settings and
	 * styles — $schema, version, templateParts, customTemplates, patterns,
	 * blockTypes — is copied through verbatim: losing templateParts would
	 * un-register the theme's parts and break the Site Editor.
	 *
	 * @param array<string, mixed> $theme_json
	 * @param array<string, mixed> $user_origin
	 * @return array<string, mixed>
	 */
	public function merge_user_into_theme( array $theme_json, array $user_origin ): array {
		$this->require_support();

		$merged = new \WP_Theme_JSON(
			array(
				'version'  => isset( $theme_json['version'] ) && is_int( $theme_json['version'] ) ? $theme_json['version'] : $this->latest_schema(),
				'settings' => isset( $theme_json['settings'] ) && is_array( $theme_json['settings'] ) ? $theme_json['settings'] : array(),
				'styles'   => isset( $theme_json['styles'] ) && is_array( $theme_json['styles'] ) ? $theme_json['styles'] : array(),
			),
			'theme'
		);

		$merged->merge(
			new \WP_Theme_JSON(
				array(
					'version'  => isset( $user_origin['version'] ) && is_int( $user_origin['version'] ) ? $user_origin['version'] : $this->latest_schema(),
					'settings' => isset( $user_origin['settings'] ) && is_array( $user_origin['settings'] ) ? $user_origin['settings'] : array(),
					'styles'   => isset( $user_origin['styles'] ) && is_array( $user_origin['styles'] ) ? $user_origin['styles'] : array(),
				),
				'custom'
			)
		);

		// get_data() — NEVER get_raw_data(). The raw view keys every preset
		// node by origin and is not valid theme.json input.
		$flattened = $merged->get_data();

		$result = $theme_json;

		$result['version']  = isset( $flattened['version'] ) && is_int( $flattened['version'] ) ? $flattened['version'] : $this->latest_schema();
		$result['settings'] = isset( $flattened['settings'] ) && is_array( $flattened['settings'] ) ? $flattened['settings'] : array();
		$result['styles']   = isset( $flattened['styles'] ) && is_array( $flattened['styles'] ) ? $flattened['styles'] : array();

		return $result;
	}

	/**
	 * The three-part validity check of a merged theme.json. It deliberately
	 * does NOT assert that every input key survives: core legitimately
	 * expands settings.appearanceTools into the settings it enables and
	 * drops settings.spacing.custom (not a valid v3 property), so that
	 * assertion would refuse the shipped theme outright. What IS checked:
	 * an integer version, a document WP_Theme_JSON accepts, and STABILITY —
	 * get_data() of the reparsed document equals get_data() of the
	 * document, so a document WP_Theme_JSON cannot represent losslessly is
	 * refused. The property the plan actually wanted — no user intent
	 * lost — is enforced by the strategy's resolved-output-equivalence
	 * gate, which is a far stronger check.
	 *
	 * @param array<string, mixed> $data
	 * @throws PromotionException Exit 1 for any refusal.
	 */
	public function validate_theme_json( array $data ): void {
		$this->require_support();

		if ( ! isset( $data['version'] ) || ! is_int( $data['version'] ) ) {
			throw PromotionException::hard( 'The theme.json document carries no integer version; refusing it.' );
		}

		try {
			$document  = new \WP_Theme_JSON( $data, 'theme' );
			$flattened = $document->get_data();

			if ( ( new \WP_Theme_JSON( $flattened, 'theme' ) )->get_data() !== $flattened ) {
				throw PromotionException::hard(
					'The theme.json document cannot be represented losslessly by WP_Theme_JSON; refusing it.'
				);
			}
		} catch ( PromotionException $exception ) {
			throw $exception;
		} catch ( \Throwable $previous ) {
			throw PromotionException::hard(
				'The theme.json document cannot be parsed by WP_Theme_JSON; refusing it.',
				$previous
			);
		}
	}

	/**
	 * Clears every Theme JSON cache this adapter owns: wp_clean_theme_json_cache()
	 * when it exists, the resolver's clean_cached_data(), the resolver's
	 * theme_json_file_cache static (which neither of the former clears —
	 * a stale entry makes the resolver keep resolving the OLD theme file,
	 * producing a false drift verdict), and wp_cache_flush_runtime() when
	 * it exists. The reflection clear is guarded with property_exists() so
	 * a future WordPress that removes the property degrades to a no-op
	 * rather than fataling. The ONLY cache entry point; nothing outside
	 * this class may call any of these.
	 */
	public function refresh_caches(): void {
		$this->require_support();

		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}

		\WP_Theme_JSON_Resolver::clean_cached_data();

		if ( property_exists( \WP_Theme_JSON_Resolver::class, 'theme_json_file_cache' ) ) {
			$property = new \ReflectionProperty( \WP_Theme_JSON_Resolver::class, 'theme_json_file_cache' );
			$property->setValue( null, array() );
		}

		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * The resolved settings and styles with the user origin simulated away:
	 * the empty-origin simulation the promotion gate compares against. The
	 * filter is installed and the caches refreshed BEFORE the resolution
	 * so get_user_data() re-runs and picks the empty data up, and both are
	 * undone in a finally so a thrown exception can never leave the filter
	 * installed.
	 *
	 * @return array{settings: array<string, mixed>, styles: array<string, mixed>}
	 */
	public function resolved_without_user_origin(): array {
		$this->require_support();

		add_filter( 'wp_theme_json_data_user', array( $this, 'filter_empty_user_data' ) );

		try {
			$this->refresh_caches();

			return $this->split_resolved( \WP_Theme_JSON_Resolver::get_merged_data()->get_data() );
		} finally {
			remove_filter( 'wp_theme_json_data_user', array( $this, 'filter_empty_user_data' ) );

			$this->refresh_caches();
		}
	}

	/**
	 * Named filter callback — NEVER a closure (master spec §4). Replaces the
	 * user origin with an empty one, so the resolution below sees exactly
	 * what the site would resolve without any user customisation.
	 *
	 * @param mixed $theme_json
	 * @return \WP_Theme_JSON_Data
	 */
	public function filter_empty_user_data( $theme_json ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $theme_json is the filter payload, deliberately ignored: the callback REPLACES the user origin with an empty one, so the incoming value is never read.
		$this->require_support();

		return new \WP_Theme_JSON_Data( array( 'version' => $this->latest_schema() ), 'custom' );
	}

	/**
	 * Replaces the user origin post's content with the given document.
	 * WordPress requires the isGlobalStylesUserThemeJSON marker on every
	 * row — content without it is rejected by the resolver — so it and the
	 * schema version are always written.
	 *
	 * @param array<string, mixed> $user_origin
	 * @throws PromotionException Exit 1 when the write cannot complete.
	 */
	public function write_user_origin( array $user_origin ): void {
		$this->require_support();

		$post = $this->user_origin_post();

		if ( null === $post ) {
			throw PromotionException::hard(
				'The Global Styles user origin could not be written: the theme\'s Global Styles post no longer exists.'
			);
		}

		$document = array_merge(
			array(
				'version'                     => $this->latest_schema(),
				'isGlobalStylesUserThemeJSON' => true,
			),
			$user_origin
		);

		$encoded = wp_json_encode( $document );

		if ( false === $encoded ) {
			throw PromotionException::hard( 'The Global Styles user origin could not be encoded as JSON.' );
		}

		$result = wp_update_post(
			array(
				'ID'           => (int) $post['ID'],
				'post_content' => $encoded,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			throw PromotionException::hard(
				'The Global Styles user origin could not be written: ' . $result->get_error_message()
			);
		}
	}

	/**
	 * The §7.8 reset: write the empty user origin back. WordPress always
	 * keeps exactly one wp_global_styles post per theme, so the reset is an
	 * update, never a delete — which is why the Global Styles record's
	 * postFinalizeRecordState is 'present'.
	 *
	 * @throws PromotionException Exit 1 when the write cannot complete.
	 */
	public function reset_user_origin(): void {
		$this->require_support();

		$this->write_user_origin( array() );
	}

	/**
	 * The user-origin post row, or null when no post exists. The post id is
	 * resolved through the resolver, which CREATES the post on demand — a
	 * falsy id after that call is a real failure of core, so it is a hard
	 * error, never a silent null.
	 *
	 * @return array<string, mixed>|null the user-origin post row, or null when no post exists
	 * @throws PromotionException Exit 1 when WordPress cannot create the post.
	 */
	public function user_origin_post(): ?array {
		$this->require_support();

		$post_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();

		if ( null === $post_id || 0 === $post_id ) {
			throw PromotionException::hard( 'WordPress could not create the Global Styles post for this theme.' );
		}

		$post = get_post( $post_id, ARRAY_A );

		return is_array( $post ) ? $post : null;
	}

	public function latest_schema(): int {
		$this->require_support();

		return (int) constant( 'WP_Theme_JSON::LATEST_SCHEMA' );
	}

	/**
	 * @param array<string, mixed> $merged
	 * @return array{settings: array<string, mixed>, styles: array<string, mixed>}
	 */
	private function split_resolved( array $merged ): array {
		return array(
			'settings' => isset( $merged['settings'] ) && is_array( $merged['settings'] ) ? $merged['settings'] : array(),
			'styles'   => isset( $merged['styles'] ) && is_array( $merged['styles'] ) ? $merged['styles'] : array(),
		);
	}

	private function probe_exists( string $symbol ): bool {
		$probe = $this->probe;

		return $probe( $symbol );
	}
}
