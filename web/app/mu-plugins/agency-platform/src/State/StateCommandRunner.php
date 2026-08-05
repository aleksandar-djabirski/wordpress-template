<?php

declare(strict_types=1);

namespace AgencyPlatform\State;

/**
 * The CLI-facing surface of the state subsystem (BLOCK_THEME_PROPOSAL.md §6):
 * every command body lives here, free of WP_CLI. StateCommands (and the
 * deprecated check-overrides alias) are thin adapters that run a method and
 * emit its result. $stdin_reader is injectable so `--source=-` input is
 * testable without a real pipe.
 *
 * STDOUT carries machine-readable output ONLY: the signed bundle document or
 * the export envelope for state-export, the JSON report or the human table
 * for state-diff, and the informational report lines for the deprecated
 * check-overrides alias. Every warning, deprecation notice, and diagnostic
 * goes to STDERR, so a piped STDOUT never corrupts a consumer.
 *
 * No public method throws: a StateException is caught and turned into a
 * StateCommandResult carrying its exit_code() and its message on STDERR with
 * an EMPTY STDOUT — a failed run never emits a partial payload — and an
 * unexpected Throwable becomes a hard-error result. That is what makes exit
 * codes 1, 2, and 4 assertable.
 */
final class StateCommandRunner {

	/** @var callable():string|null */
	private $stdin_reader;

	private ?GitBaseline $git;
	private ?HmacSigner $signer;

	/**
	 * @param callable():string|null $stdin_reader null reads php://stdin.
	 */
	public function __construct( ?callable $stdin_reader = null, ?GitBaseline $git = null, ?HmacSigner $signer = null ) {
		$this->stdin_reader = $stdin_reader;
		$this->git          = $git;
		$this->signer       = $signer;
	}

	/**
	 * The `wp agency state-export` command body. A missing or blank --output
	 * is a hard error before anything else; the sensitivity warning goes to
	 * STDERR first, then every provider validation warning; the bundle is
	 * either written atomically (canonical bytes to a same-directory temp
	 * file, then rename) with the result envelope on STDOUT, or the bundle
	 * document itself goes to STDOUT for --output=-. Every real --output
	 * path is resolved and validated through StateDirectory's shared
	 * web-root guard BEFORE the bundle is built or written: a path that
	 * would land inside <repo root>/web — directly, through `..`, or
	 * through a symlink — is a hard error, because everything under web/ is
	 * served over HTTP and the bundle carries customer content. Exit 0 on
	 * success.
	 *
	 * @param array<string, mixed> $assoc_args
	 */
	public function state_export( array $assoc_args ): StateCommandResult {
		try {
			return $this->export( $assoc_args );
		} catch ( StateException $error ) {
			return $this->failure( $error );
		} catch ( \Throwable $error ) {
			return $this->crash( $error );
		}
	}

	/**
	 * The `wp agency state-diff` command body. --format accepts only table
	 * and json (hard error naming both otherwise); with --source the bundle
	 * is loaded — StateBundle::load() verifies the signature before anything
	 * is read — and diffed in bundle mode, otherwise the live database is
	 * diffed against the Git baseline. The table or the canonical report
	 * JSON goes to STDOUT, every skippedProviders entry to STDERR, and the
	 * exit code is 0 for no drift, 2 for drift.
	 *
	 * @param array<string, mixed> $assoc_args
	 */
	public function state_diff( array $assoc_args ): StateCommandResult {
		try {
			return $this->diff( $assoc_args );
		} catch ( StateException $error ) {
			return $this->failure( $error );
		} catch ( \Throwable $error ) {
			return $this->crash( $error );
		}
	}

	/**
	 * The deprecated `wp agency check-overrides` alias body. Overrides are
	 * expected under the block-theme editing model, so the default run never
	 * fails because one exists; only --fail-on-drift turns the same run into
	 * exit 1. The report includes content by default — content is
	 * database-owned and can never be Git drift, so including it costs only
	 * report lines; large sites should use `wp agency state-diff
	 * --providers=...` for a narrower run. This command is deliberately NOT
	 * part of the machine-readable STDOUT contract: its report is
	 * human-facing, and the deprecation notice goes to STDERR.
	 *
	 * @param array<string, mixed> $assoc_args
	 */
	public function check_overrides( array $assoc_args ): StateCommandResult {
		try {
			return $this->overrides( $assoc_args );
		} catch ( StateException $error ) {
			return $this->failure( $error );
		} catch ( \Throwable $error ) {
			return $this->crash( $error );
		}
	}

	/**
	 * @param array<string, mixed> $assoc_args
	 */
	private function export( array $assoc_args ): StateCommandResult {
		$output = isset( $assoc_args['output'] ) && is_string( $assoc_args['output'] ) ? $assoc_args['output'] : '';

		if ( '' === $output ) {
			throw StateException::hard_error( 'The --output option is required: pass a bundle file path, or "-" to write the bundle JSON to STDOUT.' );
		}

		// The web-root guard runs BEFORE the bundle is built: a refused path
		// must never spend the export work, and nothing of a refused run
		// reaches the filesystem. '-' streams to STDOUT and skips the guard.
		$path = '-' === $output ? null : StateDirectory::resolve_output( $output, '--output' );

		$stderr = StateExporter::sensitivity_warning() . "\n";

		$exporter = new StateExporter( $this->signer, null, $this->git );
		$bundle   = $exporter->export( StateRegistry::resolve( $assoc_args['providers'] ?? null, ! empty( $assoc_args['include-content'] ) ) );

		foreach ( $exporter->validation_warnings() as $warning ) {
			$stderr .= $warning . "\n";
		}

		if ( null === $path ) {
			return new StateCommandResult( 0, Normalizer::canonical_json_document( $bundle ), $stderr );
		}

		$this->write_bundle_atomically( $path, $bundle );

		return new StateCommandResult( 0, Normalizer::canonical_json_document( $this->envelope( $path, $bundle ) ), $stderr );
	}

	/**
	 * @param array<string, mixed> $assoc_args
	 */
	private function diff( array $assoc_args ): StateCommandResult {
		$format = isset( $assoc_args['format'] ) && is_string( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			throw StateException::hard_error( 'The --format option accepts only "table" or "json"; got "' . $format . '".' );
		}

		$differ = new StateDiffer( $this->git );

		$source = $assoc_args['source'] ?? null;

		if ( null === $source ) {
			$report = $differ->diff_against_git( StateRegistry::resolve( $assoc_args['providers'] ?? null, ! empty( $assoc_args['include-content'] ) ) );
		} else {
			if ( ! is_string( $source ) || '' === $source ) {
				throw StateException::hard_error( 'The --source option must be a bundle file path, or "-" to read the bundle JSON from STDIN.' );
			}

			$bundle = '-' === $source ? $this->load_bundle_from_json( $this->read_stdin() ) : StateBundle::load( $source, $this->signer );

			$report = $differ->diff_against_bundle( StateRegistry::resolve( $assoc_args['providers'] ?? null, ! empty( $assoc_args['include-content'] ) ), $bundle );
		}

		$stderr = '';

		foreach ( $report['skippedProviders'] as $skipped ) {
			$stderr .= 'Skipped provider "' . (string) $skipped . '": it is not present in the bundle.' . "\n";
		}

		$stdout = 'json' === $format ? Normalizer::canonical_json_document( $report ) : \AgencyPlatform\Cli\StateCommands::format_table( $report );

		return new StateCommandResult( \AgencyPlatform\Cli\StateCommands::diff_exit_code( $report ), $stdout, $stderr );
	}

	/**
	 * @param array<string, mixed> $assoc_args
	 */
	private function overrides( array $assoc_args ): StateCommandResult {
		$fail_on_drift = ! empty( $assoc_args['fail-on-drift'] );

		$report = ( new StateDiffer( $this->git ) )->diff_against_git( StateRegistry::resolve( null, true ) );

		$stderr = 'check-overrides is deprecated. Use `wp agency state-diff` for the machine-readable report.' . "\n";

		$lines = array( $this->summary_line( $report ) );

		foreach ( $report['entries'] as $entry ) {
			if ( ! empty( $entry['countsAsDrift'] ) || ! empty( $entry['hasDatabaseOverride'] ) ) {
				$lines[] = sprintf(
					'%s (%s, %s)',
					(string) $entry['key'],
					(string) $entry['status'],
					(string) $entry['classification']
				);
			}
		}

		$drift_count = (int) $report['summary']['drift'];

		if ( 0 === $drift_count ) {
			$lines[] = 'No drift against the Git baseline.';
		} elseif ( $fail_on_drift ) {
			$stderr .= sprintf( '%d record(s) differ from the Git baseline (--fail-on-drift).', $drift_count ) . "\n";

			return new StateCommandResult( StateException::EXIT_HARD_ERROR, implode( "\n", $lines ) . "\n", $stderr );
		} else {
			$lines[] = sprintf( '%d record(s) differ from the Git baseline. Database overrides are expected under the block-theme editing model; this report is informational.', $drift_count );
		}

		return new StateCommandResult( 0, implode( "\n", $lines ) . "\n", $stderr );
	}

	/**
	 * The machine-readable STDOUT envelope for --output=<path>: the written
	 * path, the export identity, and the per-provider record counts — never
	 * the bundle itself, which is already on disk.
	 *
	 * @param array<string, mixed> $bundle
	 * @return array<string, mixed>
	 */
	private function envelope( string $path, array $bundle ): array {
		$providers = array();

		foreach ( $bundle['providers'] as $slug => $node ) {
			$providers[ (string) $slug ] = count( $node['records'] );
		}

		return array(
			'output'        => $path,
			'exportId'      => (string) $bundle['exportId'],
			'exportedAtUtc' => (string) $bundle['exportedAtUtc'],
			'stateHash'     => (string) $bundle['stateHash'],
			'providers'     => $providers,
		);
	}

	/**
	 * Writes the bundle as canonical bytes to a temp file in the same
	 * directory, then renames it into place, so a consumer never observes a
	 * half-written artifact.
	 *
	 * @param array<string, mixed> $bundle
	 */
	private function write_bundle_atomically( string $path, array $bundle ): void {
		$directory = dirname( $path );

		if ( ! is_dir( $directory ) ) {
			throw StateException::hard_error( 'The output directory "' . $directory . '" does not exist; create it before exporting.' );
		}

		$temporary = $path . '.tmp-' . uniqid();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- atomic CLI artifact write; WP_Filesystem needs credentials and cannot preserve this rename contract.
		$written = file_put_contents( $temporary, Normalizer::canonical_json_document( $bundle ) );

		if ( false === $written ) {
			throw StateException::hard_error( 'The bundle could not be written to "' . $path . '".' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic CLI artifact write; WP_Filesystem needs credentials and cannot preserve this rename contract.
		if ( ! rename( $temporary, $path ) ) {
			throw StateException::hard_error( 'The bundle could not be moved into place at "' . $path . '".' );
		}
	}

	/**
	 * The per-classification counts line of the deprecated alias report.
	 *
	 * @param array<string, mixed> $report
	 */
	private function summary_line( array $report ): string {
		$summary = $report['summary'];

		return sprintf(
			'promotable: %d, db-owned: %d, forbidden: %d, unresolved: %d, unchanged: %d',
			(int) $summary['promotable'],
			(int) $summary['dbOwned'],
			(int) $summary['forbidden'],
			(int) $summary['unresolved'],
			(int) $summary['unchanged']
		);
	}

	/**
	 * The injected stdin reader, or php://stdin when none was injected.
	 */
	private function read_stdin(): string {
		if ( null !== $this->stdin_reader ) {
			return ( $this->stdin_reader )();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI STDIN has no WP_Filesystem equivalent.
		return (string) file_get_contents( 'php://stdin' );
	}

	/**
	 * The full bundle-load path for `--source=-`: read the JSON through the
	 * injectable stdin reader, schema-validate it, verify the signature, and
	 * confirm the stateHash — the same order StateBundle::load() applies to
	 * a file path, replicated here because load('-') would read php://stdin
	 * directly and bypass the reader tests inject. Nothing is readable until
	 * every step has succeeded.
	 */
	private function load_bundle_from_json( string $json ): StateBundle {
		$bundle = StateBundle::from_json( $json );

		$bundle->verify_signature( $this->signer ?? HmacSigner::from_environment() );

		( new SchemaValidator() )->validate( $bundle->to_array(), SchemaValidator::SCHEMA_STATE_BUNDLE );

		$recomputed = Normalizer::hash( StateExporter::canonical_provider_records( $bundle->to_array()['providers'] ) );

		if ( ! hash_equals( $bundle->state_hash(), $recomputed ) ) {
			throw StateException::tamper( 'The bundle stateHash does not match its providers: the content was altered after signing.' );
		}

		return $bundle;
	}

	/**
	 * A StateException becomes a result carrying its own exit code, its
	 * message on STDERR, and an empty STDOUT — a failed run never emits a
	 * partial payload.
	 */
	private function failure( StateException $error ): StateCommandResult {
		return new StateCommandResult( $error->exit_code(), '', $error->getMessage() . "\n" );
	}

	/**
	 * Any unexpected Throwable becomes exit 1 with its message on STDERR and
	 * an empty STDOUT.
	 */
	private function crash( \Throwable $error ): StateCommandResult {
		return new StateCommandResult( StateException::EXIT_HARD_ERROR, '', $error->getMessage() . "\n" );
	}
}
