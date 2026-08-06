<?php

declare(strict_types=1);

namespace AgencyPlatform\State\Promotion;

/**
 * The PreparedFileWriter's staged entry. commit() renames the temp file into
 * place; discard() removes it; rollback_committed() restores the previous
 * bytes captured at stage time, or removes a newly-created file. commit() and
 * discard() are idempotent — a run that fails after partial commits calls
 * both.
 *
 * @internal The private implementation of StagedPromotionEntry for prepared
 *           theme files; constructed only by PreparedFileWriter::stage().
 */
final class StagedPreparedFile implements StagedPromotionEntry {

	private bool $committed = false;

	public function __construct(
		private string $theme_relative_path,
		private string $absolute_path,
		private string $prepared_file_hash,
		private ?string $original_file_hash,
		private string $expected_post_reset_hash,
		private string $temp_path,
		private ?string $previous_bytes
	) {}

	/** @return array<string, mixed> */
	public function manifest_fields(): array {
		return array(
			'themeRelativePath'     => $this->theme_relative_path,
			'absolutePath'          => $this->absolute_path,
			'preparedFileHash'      => $this->prepared_file_hash,
			'originalFileHash'      => $this->original_file_hash,
			'expectedPostResetHash' => $this->expected_post_reset_hash,
		);
	}

	public function commit(): void {
		if ( $this->committed ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
		if ( ! rename( $this->temp_path, $this->absolute_path ) ) {
			throw PromotionException::hard( sprintf( 'The prepared file "%s" could not be moved into place.', $this->absolute_path ) );
		}

		$this->committed = true;
	}

	public function discard(): void {
		if ( ! is_file( $this->temp_path ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
		unlink( $this->temp_path );
	}

	public function rollback_committed(): void {
		if ( ! $this->committed ) {
			return;
		}

		if ( null === $this->previous_bytes ) {
			if ( is_file( $this->absolute_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
				unlink( $this->absolute_path );
			}
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.
			file_put_contents( $this->absolute_path, $this->previous_bytes );
		}

		$this->committed = false;
	}
}
