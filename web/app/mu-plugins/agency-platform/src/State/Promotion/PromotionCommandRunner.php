<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

use AgencyPlatform\State\Normalizer;
use AgencyPlatform\State\StateCommandResult;

/**
 * The CLI-facing surface of the promotion lifecycle (BLOCK_THEME_PROPOSAL.md
 * §6 and plan Task 17): every command body lives here, free of WP_CLI.
 * PromotionCommands is a thin adapter that runs a method and emits its
 * captured result through CliOutput — the ONLY writer to either stream.
 * $stdin_reader is injectable so `--manifest=-` and `--source=-` input is
 * testable without a real pipe.
 *
 * STDOUT carries exactly ONE JSON document per run — the outcome report, or
 * the signed manifest itself for prepare/seal with `--manifest=-` — and
 * nothing else. Every diagnostic goes to STDERR. The runner writes to NO
 * stream; it only returns the strings inside a StateCommandResult.
 *
 * No public method throws: a PromotionException is caught and turned into a
 * StateCommandResult carrying its exit_code() verbatim (never a code
 * re-derived from a message), an `ok => false` error payload on STDOUT and
 * the message on STDERR; an unexpected Throwable becomes a hard-error result
 * the same way. That is what keeps exit codes 1, 2, 3 and 4 distinguishable
 * at the command surface.
 */
final class PromotionCommandRunner {

	/** @var callable():string|null */
	private $stdin_reader;

	/**
	 * @param callable():string|null $stdin_reader null reads php://stdin.
	 */
	public function __construct( ?callable $stdin_reader = null ) {
		$this->stdin_reader = $stdin_reader;
	}

	/**
	 * The `wp agency promote-overrides` command body: exactly one mode flag
	 * (--prepare/--seal/--finalize/--confirm/--rollback/--heartbeat), the
	 * per-mode required options, and the mode dispatch. Zero or more than
	 * one mode is a hard error; a missing option is a hard error naming it.
	 *
	 * @param array<string, mixed> $assoc_args
	 */
	public function promote_overrides( array $assoc_args ): StateCommandResult {
		try {
			$mode = $this->mode( $assoc_args );

			switch ( $mode ) {
				case 'prepare':
					return $this->prepare_result( $this->required_options( $assoc_args, array( 'source', 'select', 'manifest' ), '--prepare' ) );

				case 'seal':
					return $this->seal_result( $this->required_options( $assoc_args, array( 'manifest', 'deploy-commit' ), '--seal' ) );

				case 'finalize':
					return $this->finalize_result( $this->required_options( $assoc_args, array( 'manifest' ), '--finalize' ) );

				case 'confirm':
					return $this->confirm_result( $this->required_options( $assoc_args, array( 'manifest' ), '--confirm' ) );

				case 'rollback':
					return $this->rollback_result( $this->required_options( $assoc_args, array( 'manifest' ), '--rollback' ) );

				case 'heartbeat':
					return $this->heartbeat_result( $this->required_options( $assoc_args, array( 'manifest' ), '--heartbeat' ) );
			}
		} catch ( PromotionException $exception ) {
			return $this->failure( $exception );
		} catch ( \Throwable $error ) {
			return $this->failure( PromotionException::hard( $error->getMessage(), $error ) );
		}

		// Unreachable: mode() returns only the six handled modes.
		throw PromotionException::hard( sprintf( 'Unknown promotion mode "%s".', $mode ) );
	}

	/**
	 * The `wp agency promotion-backups` command body: `list` renders every
	 * protected backup as JSON (or a table with --format=table); `prune`
	 * deletes the backups past the retention window, with --dry-run
	 * previewing and --older-than setting the cutoff.
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function promotion_backups( array $args, array $assoc_args ): StateCommandResult {
		try {
			$subcommand = $args[0] ?? '';

			if ( 'list' === $subcommand ) {
				return $this->backup_list( $assoc_args );
			}

			if ( 'prune' === $subcommand ) {
				return $this->backup_prune( $assoc_args );
			}

			throw PromotionException::hard(
				sprintf( 'The first positional argument must be "list" or "prune"; got "%s".', $subcommand )
			);
		} catch ( PromotionException $exception ) {
			return $this->failure( $exception );
		} catch ( \Throwable $error ) {
			return $this->failure( PromotionException::hard( $error->getMessage(), $error ) );
		}
	}

	/**
	 * The single mode flag among the six lifecycle modes, or a hard error.
	 * Zero modes and two modes are both invalid operator input: the
	 * lifecycle steps are mutually exclusive and the wrapper must never
	 * guess which one the operator meant.
	 *
	 * @param array<string, mixed> $assoc_args
	 * @throws PromotionException Exit 1.
	 */
	private function mode( array $assoc_args ): string {
		$modes = array( 'prepare', 'seal', 'finalize', 'confirm', 'rollback', 'heartbeat' );
		$found = array();

		foreach ( $modes as $candidate ) {
			if ( ! empty( $assoc_args[ $candidate ] ) ) {
				$found[] = $candidate;
			}
		}

		if ( array() === $found ) {
			throw PromotionException::hard(
				'No mode given: pass exactly one of --prepare, --seal, --finalize, --confirm, --rollback, --heartbeat.'
			);
		}

		if ( 1 !== count( $found ) ) {
			throw PromotionException::hard(
				sprintf( 'Exactly one mode may be given; got --%s and --%s.', $found[0], $found[1] )
			);
		}

		return $found[0];
	}

	/**
	 * Every option of a mode must be present and a non-empty string; the
	 * first missing one is named in the hard error.
	 *
	 * @param array<string, mixed> $assoc_args
	 * @param list<string>         $required
	 * @return array<string, string>
	 * @throws PromotionException Exit 1.
	 */
	private function required_options( array $assoc_args, array $required, string $mode_label ): array {
		$options = array();

		foreach ( $required as $name ) {
			$value = $assoc_args[ $name ] ?? null;

			if ( ! is_string( $value ) || '' === $value ) {
				throw PromotionException::hard( sprintf( 'The --%s option is required for %s.', $name, $mode_label ) );
			}

			$options[ $name ] = $value;
		}

		return $options;
	}

	/**
	 * The --prepare dispatch: with --manifest=- the signed manifest itself
	 * goes to STDOUT (the store renders it, the command layer never streams
	 * a document the store did not sign), otherwise the outcome report.
	 *
	 * @param array<string, string> $options
	 */
	private function prepare_result( array $options ): StateCommandResult {
		$gateway      = new StateGateway();
		$store        = new ManifestStore( $gateway, $this->stdin_reader );
		$materialized = null;

		try {
			$source = $options['source'];

			if ( '-' === $source ) {
				$source       = $this->materialize_stdin_bundle();
				$materialized = $source;
			}

			$prepared = ( new PromotionPreparer(
				$gateway,
				$store,
				GitRepository::discover(),
				PrepareLock::for_state_dir()
			) )->prepare(
				array(
					'source'   => $source,
					'select'   => $options['select'],
					'manifest' => $options['manifest'],
				)
			);

			$stdout = '-' === $options['manifest']
				? $store->render( $prepared['manifest'] )
				: Normalizer::canonical_json_document( $prepared['outcome']->to_array() );

			return new StateCommandResult( $prepared['outcome']->exit_code(), $stdout, '' );
		} finally {
			if ( null !== $materialized && is_file( $materialized ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- the stdin-materialized bundle is a transient CLI artifact; WP_Filesystem exposes no single-file delete primitive in a CLI context.
				unlink( $materialized );
			}
		}
	}

	/**
	 * The --seal dispatch: with --manifest=- the sealed manifest goes to
	 * STDOUT; with a path, the outcome report carries exactly ONE entry —
	 * the seal step has no per-record loop of its own, so the single entry
	 * is the promotion id with a success outcome.
	 *
	 * @param array<string, string> $options
	 */
	private function seal_result( array $options ): StateCommandResult {
		$store  = new ManifestStore( new StateGateway(), $this->stdin_reader );
		$sealed = ( new PromotionSealer( $store, GitRepository::discover() ) )->seal( $options['manifest'], $options['deploy-commit'] );

		if ( '-' === $options['manifest'] ) {
			return new StateCommandResult( 0, $store->render( $sealed ), '' );
		}

		$outcome = new PromotionOutcome(
			array( $sealed->promotion_id() => PromotionOutcome::OUTCOME_PREPARED ),
			array()
		);

		return new StateCommandResult( $outcome->exit_code(), Normalizer::canonical_json_document( $outcome->to_array() ), '' );
	}

	/**
	 * @param array<string, string> $options
	 */
	private function finalize_result( array $options ): StateCommandResult {
		$gateway = new StateGateway();
		$store   = new ManifestStore( $gateway, $this->stdin_reader );

		return $this->lifecycle_result( ( new PromotionFinalizer( $gateway, $store ) )->finalize( $options['manifest'] ) );
	}

	/**
	 * @param array<string, string> $options
	 */
	private function confirm_result( array $options ): StateCommandResult {
		$store = new ManifestStore( new StateGateway(), $this->stdin_reader );

		return $this->lifecycle_result( ( new PromotionConfirmer( $store ) )->confirm( $options['manifest'] ) );
	}

	/**
	 * @param array<string, string> $options
	 */
	private function rollback_result( array $options ): StateCommandResult {
		$gateway = new StateGateway();
		$store   = new ManifestStore( $gateway, $this->stdin_reader );

		return $this->lifecycle_result( ( new PromotionRollback( $gateway, $store ) )->rollback( $options['manifest'] ) );
	}

	/**
	 * finalize, confirm and rollback share one payload shape: the outcome
	 * report document, regardless of whether the manifest came from a path
	 * or from STDIN.
	 *
	 * @param array{manifest: PromotionManifest, outcome: PromotionOutcome} $result
	 */
	private function lifecycle_result( array $result ): StateCommandResult {
		return new StateCommandResult(
			$result['outcome']->exit_code(),
			Normalizer::canonical_json_document( $result['outcome']->to_array() ),
			''
		);
	}

	/**
	 * @param array<string, string> $options
	 */
	private function heartbeat_result( array $options ): StateCommandResult {
		$outcome = $this->heartbeat( $options['manifest'] );

		return new StateCommandResult( $outcome->exit_code(), Normalizer::canonical_json_document( $outcome->to_array() ), '' );
	}

	/**
	 * The --heartbeat mode — the only mode with no collaborator class of its
	 * own: authenticate the supplied manifest to read the promotion id, then
	 * load the CANONICAL host copy and refresh ONLY the records whose
	 * promotion mutation is still active. A refused or self-restored record
	 * released its lock at finalize.
	 *
	 * @throws PromotionException Exit 1 when the promotion was never
	 *                            finalized, exit 3 when a lock was reclaimed
	 *                            or released.
	 */
	private function heartbeat( string $manifest_path ): PromotionOutcome {
		$store    = new ManifestStore( new StateGateway(), $this->stdin_reader );
		$manifest = $store->load_canonical( $store->load( $manifest_path )->promotion_id() );

		$keys = array();

		foreach ( $manifest->records() as $record ) {
			if ( 'promoted' === $record['finalizeStatus'] ) {
				$keys[] = (string) $record['key'];
			}
		}

		( new RecordLockManager(
			$manifest->promotion_id(),
			PromotionSettings::text( PromotionSettings::DEPLOYMENT_ID, $manifest->promotion_id() )
		) )->heartbeat( $keys );

		return new PromotionOutcome( array_fill_keys( $keys, PromotionOutcome::OUTCOME_SKIPPED ), array() );
	}

	/**
	 * The --format=json branch of promotion-backups list.
	 *
	 * @param array<string, mixed> $assoc_args
	 */
	private function backup_list( array $assoc_args ): StateCommandResult {
		$format = isset( $assoc_args['format'] ) && is_string( $assoc_args['format'] ) ? $assoc_args['format'] : 'json';

		if ( ! in_array( $format, array( 'json', 'table' ), true ) ) {
			throw PromotionException::hard( 'The --format option accepts only "table" or "json"; got "' . $format . '".' );
		}

		$rows = PromotionBackup::list_all();

		$stdout = 'table' === $format
			? \AgencyPlatform\Cli\PromotionCommands::format_backups_table( $rows )
			: Normalizer::canonical_json_document( $rows );

		return new StateCommandResult( 0, $stdout, '' );
	}

	/**
	 * The prune branch of promotion-backups: the pruned promotion ids go to
	 * STDOUT, the human summary to STDERR — prefixed `DRY RUN` so an
	 * operator piping the JSON can never mistake a preview for a deletion.
	 *
	 * @param array<string, mixed> $assoc_args
	 */
	private function backup_prune( array $assoc_args ): StateCommandResult {
		$older_than = isset( $assoc_args['older-than'] ) && is_string( $assoc_args['older-than'] ) ? $assoc_args['older-than'] : '30d';
		$dry_run    = ! empty( $assoc_args['dry-run'] );

		$pruned = PromotionBackup::prune( $older_than, $dry_run );

		$summary = $dry_run
			? sprintf( 'DRY RUN: %d backup(s) would be pruned: %s', count( $pruned ), implode( ', ', $pruned ) )
			: sprintf( 'Pruned %d backup(s): %s', count( $pruned ), implode( ', ', $pruned ) );

		return new StateCommandResult( 0, Normalizer::canonical_json_document( array( 'pruned' => $pruned ) ), $summary . "\n" );
	}

	/**
	 * Writes the bundle JSON from the injectable stdin reader to a transient
	 * file in the state directory and returns its path. StateBundle::load()
	 * needs a real path — its '-' branch would read php://stdin directly,
	 * bypassing the injected reader this command surface exists to test —
	 * and the state directory is the one place guaranteed outside the web
	 * root. The caller unlinks the file when the run finishes.
	 *
	 * @throws PromotionException Exit 1 when the reader produced nothing or
	 *                            the write failed.
	 */
	private function materialize_stdin_bundle(): string {
		$json = $this->read_stdin();

		if ( '' === trim( $json ) ) {
			throw PromotionException::hard( 'No bundle JSON was read from STDIN.' );
		}

		$path = ( new StateGateway() )->state_dir() . '/bundle-from-stdin-' . wp_generate_uuid4() . '.json';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a transient CLI artifact for StateBundle::load(); WP_Filesystem needs credentials and cannot own a CLI pipe.
		$written = file_put_contents( $path, $json );

		if ( false === $written ) {
			throw PromotionException::hard( sprintf( 'The bundle read from STDIN could not be written to "%s".', $path ) );
		}

		return $path;
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
	 * A PromotionException becomes a result carrying its own exit code, its
	 * message on STDERR, and the `ok => false` error payload on STDOUT — the
	 * machine-readable contract keeps exactly one JSON document on STDOUT
	 * even when the run fails, so a wrapper can always parse it.
	 */
	private function failure( PromotionException $exception ): StateCommandResult {
		return new StateCommandResult(
			$exception->exit_code(),
			Normalizer::canonical_json_document(
				array(
					'ok'       => false,
					'error'    => $exception->getMessage(),
					'exitCode' => $exception->exit_code(),
				)
			),
			$exception->getMessage() . "\n"
		);
	}
}
