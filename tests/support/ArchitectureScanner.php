<?php
/**
 * Shared, WordPress-free static scanner used by every tests/Architecture/*
 * test. It runs in the `architecture` PHPUnit suite (no live WordPress), so
 * it must never call a WordPress function — only PHP's tokenizer and the
 * filesystem.
 *
 * This file lives in the explicitly-required tests/support/ directory (like
 * wp-stubs.php / woocommerce-stub.php) rather than being PSR-4 autoloaded:
 * the naming contract maps `Tests\` -> `tests/`, so an autoloaded
 * `Tests\Support\` class would have to live in `tests/Support/` (capital S),
 * which collides case-insensitively with this existing lowercase directory
 * on Windows. Callers `require_once` it explicitly instead.
 *
 * @package Tests\Support
 */

declare(strict_types=1);

namespace Tests\Support;

/**
 * Token-based architecture scanner. All methods are pure and static.
 */
final class ArchitectureScanner {

	private function __construct() {
		// Static-only API; never instantiated.
	}

	/**
	 * Recursively lists every *.php file under $dir, skipping dependency and
	 * build output directories (vendor/, node_modules/, build/) that this
	 * project never authors and must never enforce architecture rules on.
	 *
	 * @return list<string> Absolute paths, sorted for deterministic output.
	 */
	public static function php_files( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$skip = array( 'vendor', 'node_modules', 'build' );

		$directory_iterator = new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS );
		$filter             = new \RecursiveCallbackFilterIterator(
			$directory_iterator,
			static function ( \SplFileInfo $current ) use ( $skip ): bool {
				if ( $current->isDir() ) {
					return ! in_array( $current->getFilename(), $skip, true );
				}

				return true;
			}
		);

		$files = array();

		foreach ( new \RecursiveIteratorIterator( $filter ) as $file_info ) {
			if ( $file_info instanceof \SplFileInfo
				&& $file_info->isFile()
				&& 'php' === strtolower( $file_info->getExtension() )
			) {
				$files[] = $file_info->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Finds closures passed as the callback (second) argument of an
	 * add_action()/add_filter() call. Uses the PHP tokenizer (never a regex)
	 * so commented-out or string-literal "add_action(function" text never
	 * false-positives, and tracks parenthesis depth + argument commas so a
	 * closure inside the FIRST argument (or nested calls) is not mistaken for
	 * the callback.
	 *
	 * @return list<array{line: int, hook: string}>
	 */
	public static function tokens_contain_closure_hook( string $file ): array {
		$tokens = self::tokens( $file );
		$count  = count( $tokens );

		$violations = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) ) {
				continue;
			}

			$name = self::callable_name( $token );

			if ( null === $name || ( 'add_action' !== $name && 'add_filter' !== $name ) ) {
				continue;
			}

			// Skip method calls/definitions that merely share the name
			// (e.g. $obj->add_action(...), self::add_action(...),
			// function add_action(...), or a namespaced Ns\add_action()).
			$previous = self::previous_significant( $tokens, $i );

			if ( is_array( $previous ) && in_array(
				$previous[0],
				array( \T_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NULLSAFE_OBJECT_OPERATOR ),
				true
			) ) {
				continue;
			}

			$open_index = self::next_significant_index( $tokens, $i );

			if ( null === $open_index || '(' !== $tokens[ $open_index ] ) {
				continue;
			}

			if ( self::second_argument_is_closure( $tokens, $open_index ) ) {
				$violations[] = array(
					'line' => (int) $token[2],
					'hook' => $name,
				);
			}
		}

		return $violations;
	}

	/**
	 * Scans a file's CODE (comments and docblocks stripped, string literals
	 * and inline HTML kept) for each regex in $patterns, returning every
	 * match with its 1-indexed line number. String literals count: e.g.
	 * 'WooCommerce' as a class_exists() argument is a real reference.
	 *
	 * @param list<string> $patterns PCRE patterns, each with delimiters.
	 * @return list<array{line: int, match: string, pattern: string}>
	 */
	public static function find_symbol_references( string $file, array $patterns ): array {
		$code  = self::code_only( $file );
		$lines = explode( "\n", $code );

		$matches = array();

		foreach ( $lines as $index => $line ) {
			foreach ( $patterns as $pattern ) {
				if ( 0 === preg_match_all( $pattern, $line, $found ) ) {
					continue;
				}

				foreach ( $found[0] as $hit ) {
					$matches[] = array(
						'line'    => $index + 1,
						'match'   => (string) $hit,
						'pattern' => $pattern,
					);
				}
			}
		}

		return $matches;
	}

	/**
	 * Detects outbound-HTTP call sites: the wp_remote_* helpers, cURL,
	 * fsockopen(), a Guzzle reference, or file_get_contents() whose first
	 * argument is a literal URL beginning with "http". Comments/docblocks are
	 * ignored (a wp_remote_post reference in prose is not a call site).
	 *
	 * @return list<array{line: int, call: string}>
	 */
	public static function outbound_http_calls( string $file ): array {
		$tokens = self::tokens( $file );
		$count  = count( $tokens );

		$http_functions = array(
			'wp_remote_get',
			'wp_remote_post',
			'wp_remote_request',
			'wp_remote_head',
			'wp_safe_remote_get',
			'wp_safe_remote_post',
			'wp_safe_remote_request',
			'wp_safe_remote_head',
			'curl_init',
			'curl_exec',
			'curl_setopt',
			'fsockopen',
		);

		$calls = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) ) {
				continue;
			}

			if ( in_array(
				$token[0],
				array( \T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_CONSTANT_ENCAPSED_STRING ),
				true
			) && str_contains( $token[1], 'GuzzleHttp' ) ) {
				$calls[] = array(
					'line' => (int) $token[2],
					'call' => 'GuzzleHttp',
				);
				continue;
			}

			$name = self::callable_name( $token );

			if ( null === $name ) {
				continue;
			}

			$previous = self::previous_significant( $tokens, $i );

			if ( is_array( $previous ) && in_array(
				$previous[0],
				array( \T_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NULLSAFE_OBJECT_OPERATOR ),
				true
			) ) {
				continue;
			}

			if ( in_array( $name, $http_functions, true ) ) {
				$calls[] = array(
					'line' => (int) $token[2],
					'call' => $name,
				);
				continue;
			}

			if ( 'file_get_contents' === $name && self::first_argument_is_http_url( $tokens, $i ) ) {
				$calls[] = array(
					'line' => (int) $token[2],
					'call' => 'file_get_contents(http)',
				);
			}
		}

		return $calls;
	}

	/**
	 * Reads a file and tokenizes it, returning an empty list if the file is
	 * unreadable so callers never have to guard against a false return.
	 *
	 * @return array<int, array{0: int, 1: string, 2: int}|string>
	 */
	private static function tokens( string $file ): array {
		return token_get_all( self::read( $file ) );
	}

	/**
	 * Returns the file's source with every comment/docblock replaced by the
	 * same number of newlines it spanned, so line numbers are preserved while
	 * comment text can never match a code-level pattern.
	 */
	private static function code_only( string $file ): string {
		$output = '';

		foreach ( self::tokens( $file ) as $token ) {
			if ( is_array( $token ) ) {
				if ( \T_COMMENT === $token[0] || \T_DOC_COMMENT === $token[0] ) {
					$output .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
				} else {
					$output .= $token[1];
				}
			} else {
				$output .= $token;
			}
		}

		return $output;
	}

	/**
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function second_argument_is_closure( array $tokens, int $open_index ): bool {
		$count     = count( $tokens );
		$depth     = 0;
		$arg_index = 0;

		for ( $k = $open_index; $k < $count; $k++ ) {
			$token = $tokens[ $k ];

			if ( '(' === $token ) {
				++$depth;
				continue;
			}

			if ( ')' === $token ) {
				--$depth;

				if ( 0 === $depth ) {
					return false;
				}

				continue;
			}

			if ( 1 === $depth && ',' === $token ) {
				++$arg_index;
				continue;
			}

			if ( 1 !== $depth || 1 !== $arg_index ) {
				continue;
			}

			if ( self::is_skippable( $token ) ) {
				continue;
			}

			// First significant token of the callback argument.
			if ( is_array( $token ) && ( \T_FUNCTION === $token[0] || \T_FN === $token[0] ) ) {
				return true;
			}

			if ( is_array( $token ) && \T_STATIC === $token[0] ) {
				$next = self::next_significant( $tokens, $k );

				return is_array( $next ) && ( \T_FUNCTION === $next[0] || \T_FN === $next[0] );
			}

			return false;
		}

		return false;
	}

	/**
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function first_argument_is_http_url( array $tokens, int $function_index ): bool {
		$open_index = self::next_significant_index( $tokens, $function_index );

		if ( null === $open_index || '(' !== $tokens[ $open_index ] ) {
			return false;
		}

		$argument = self::next_significant( $tokens, $open_index );

		if ( ! is_array( $argument ) || \T_CONSTANT_ENCAPSED_STRING !== $argument[0] ) {
			return false;
		}

		return 0 === stripos( self::unquote( $argument[1] ), 'http' );
	}

	/**
	 * Returns the callable's trailing name segment for an identifier token,
	 * or null when the token is not an identifier. This collapses the three
	 * ways PHP tokenizes a function name so an unqualified `add_action`, a
	 * fully-qualified `\add_action` (T_NAME_FULLY_QUALIFIED), and a qualified
	 * `Ns\add_action` (T_NAME_QUALIFIED) all resolve to `add_action` — a hook
	 * or HTTP call must not be able to slip past the scanner by being written
	 * with a leading or namespace-qualified backslash.
	 *
	 * @param array{0: int, 1: string, 2: int}|string $token
	 */
	private static function callable_name( array|string $token ): ?string {
		if ( ! is_array( $token ) || ! in_array(
			$token[0],
			array( \T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED ),
			true
		) ) {
			return null;
		}

		$name     = ltrim( $token[1], '\\' );
		$last_sep = strrpos( $name, '\\' );

		return false === $last_sep ? $name : substr( $name, $last_sep + 1 );
	}

	private static function unquote( string $literal ): string {
		if ( strlen( $literal ) < 2 ) {
			return $literal;
		}

		$quote = $literal[0];

		if ( ( '"' === $quote || "'" === $quote ) && $literal[ strlen( $literal ) - 1 ] === $quote ) {
			return substr( $literal, 1, -1 );
		}

		return $literal;
	}

	/**
	 * @param array{0: int, 1: string, 2: int}|string $token
	 */
	private static function is_skippable( array|string $token ): bool {
		return is_array( $token ) && in_array(
			$token[0],
			array( \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT ),
			true
		);
	}

	/**
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
	 * @return array{0: int, 1: string, 2: int}|string|null
	 */
	private static function previous_significant( array $tokens, int $index ) {
		for ( $k = $index - 1; $k >= 0; $k-- ) {
			if ( ! self::is_skippable( $tokens[ $k ] ) ) {
				return $tokens[ $k ];
			}
		}

		return null;
	}

	/**
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function next_significant_index( array $tokens, int $index ): ?int {
		$count = count( $tokens );

		for ( $k = $index + 1; $k < $count; $k++ ) {
			if ( ! self::is_skippable( $tokens[ $k ] ) ) {
				return $k;
			}
		}

		return null;
	}

	/**
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
	 * @return array{0: int, 1: string, 2: int}|string|null
	 */
	private static function next_significant( array $tokens, int $index ) {
		$next_index = self::next_significant_index( $tokens, $index );

		return null === $next_index ? null : $tokens[ $next_index ];
	}

	private static function read( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- pure static analysis of local source files; wp_remote_get() is for remote URLs, not on-disk reads, and this class must stay WordPress-free.
		$source = file_get_contents( $file );

		return false === $source ? '' : $source;
	}
}
