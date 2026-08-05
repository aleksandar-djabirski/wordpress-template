# Promotion Lifecycle & Global Styles Promotion Implementation Plan (Task 3 — Releases 3 + 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the safe, selective promotion lifecycle that turns database template/template-part overrides into Git baseline files (`prepare → seal → finalize → confirm/rollback`) with per-record locking, protected backups, tamper detection and refusal enforcement — then, as a strictly separable final release, Global Styles promotion behind a fail-closed Theme JSON adapter.

**Architecture:** Every class in this task lives in one purpose-named directory, `web/app/mu-plugins/agency-platform/src/State/Promotion/` (namespace `AgencyPlatform\State\Promotion`). Promotion mechanics are NOT branched on provider slug: Task 2 defines the `PromotionStrategy` interface and the `agency_platform_promotion_strategies` filter registry (which ships EMPTY), and this task implements and registers one strategy per promotable provider. That registry is also the Release 3/Release 4 boundary — Release 3 registers only the template and template-part strategies, so `global-styles` stays export-and-diff even though Task 2 classifies it promotable. The lifecycle classes (`PromotionPreparer`, `PromotionSealer`, `PromotionFinalizer`, `PromotionConfirmer`, `PromotionRollback`) drive the strategies and read/write one signed artifact, the promotion manifest, through `ManifestStore`. Nothing except the CLI layer writes to STDOUT.

**Tech Stack:** PHP 8.3, WordPress 7.0 (pinned by `composer.lock` / `roots/wordpress: ^7.0`), WP-CLI, PHPUnit 9.6 (`architecture` / `unit` / `integration` suites), `swaggest/json-schema` (added to `require` by Task 2), Playwright 1.61 (TypeScript), bash (deployment wrapper), DDEV for all PHP/Composer commands.

---

## Global Constraints

These apply to EVERY task below.

**Master spec §4 non-negotiables that touch this task**

- Production hooks use named class methods, never closures (`tests/Architecture/HookOwnershipTest.php` scans the second argument of every `add_action`/`add_filter` in production code). This task registers a filter callback in Task 7 and another inside the Theme JSON adapter in Task 22 — both must be `array( $this, 'method' )`.
- No forbidden catch-all directories in a plugin: `components`, `layouts`, `inc`, `includes`, `helpers`, `misc`, `common`, `lib`, `utils` (`tests/Architecture/DirectoryRulesTest.php`). `src/State/Promotion/` is purpose-named and allowed.
- `agency-platform` has NO project-layer dependencies (`deptrac.yaml`: `AgencyPlatform: []`). Check with `ddev composer deptrac`.
- Outbound HTTP is forbidden in `agency-platform` (`tests/Architecture/IntegrationBoundaryTest.php`). Never call `wp_remote_*`, `curl_*`, `fsockopen`, `GuzzleHttp`, or `file_get_contents('http…')` from any PHP file here. The deployment wrapper is bash and lives outside that scan.
- `ddev composer verify:fast` must pass before every commit; `ddev composer test:integration` (or `verify`) additionally when database-touching code changed.
- No state bundle, manifest, backup payload, secret, or customer data may be committed.

**Repository conventions (verified in the existing code)**

- Every PHP file: `<?php`, blank line, `declare(strict_types=1);`, blank line, `namespace …;`.
- Long array syntax `array( … )` everywhere. Short `[ … ]` appears nowhere in production source.
- Yoda conditions required (`'production' === $environment`) — WPCS `WordPress.PHP.YodaConditions`.
- Tabs for indentation; **snake_case for every method and property** — WPCS `WordPress.NamingConventions.ValidFunctionName` rejects camelCase methods. JSON field names stay camelCase (they are data, not identifiers).
- `final class` for concrete classes; PSR-4 file name = class name.
- PHPStan level 6 over `src` and `tests` (minus the integration suites). Every array parameter/return needs an array-shape or generic docblock.
- phpcs runs `WordPress-Extra` and **fails on warnings** (no `ignore_warnings_on_exit` in `phpcs.xml`). Native filesystem and process functions need an inline `phpcs:ignore` with a real justification. The only ones needed here:
  - `// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_* -- atomic temp-write + rename() is required by the promotion contract (master spec §7.5); WP_Filesystem exposes no atomic-replace primitive.`
  - `// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local on-disk artifact, never a URL.`
  - `// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- git is invoked with an argv array (no shell), which is the only way to read working-tree state.`
  - Precedents already in the repo: `tests/support/ArchitectureScanner.php:448`, `tests/Integration/bootstrap.php:61-84`, `src/Logging/Logger.php:48`.
- Never use direct SQL where a WordPress API exists (master spec §11.11). The one place this bites is atomic locking; see Task 12.

**Fixed shared contract with the other tracks**

- Namespace root `AgencyPlatform\State\*`, autoloaded from `web/app/mu-plugins/agency-platform/src/State/`.
- Task 2 owns `src/State/*.php`, `src/State/Providers/`, `Cli/StateCommands.php`, `resources/schemas/state-bundle-v1.json`, `var/agency-state/` + its `.gitignore` entry, `swaggest/json-schema` in `require`, `.env.example`, and the deletion of `Health/DatabaseOverrideCheck.php`. **This task creates none of those and edits none of them.**
- Task 1 owns `src/Plugin.php`, `src/Cli/AgencyCommands.php`, the theme, the role/editor policy, and the base `.github/workflows/ci.yml` changes.
- Task 4 owns `docs/state-reconciliation.md` and every other documentation file. **This task writes no documentation files.**
- **Ownership granted to this task in the correction round:** the single promotion e2e script line in `package.json`, and the promotion job added to `.github/workflows/ci.yml`. Both land ONLY after the rebase onto the integration branch, in the final two tasks.
- HMAC purposes fixed: `HmacSigner::PURPOSE_BUNDLE = 'state-bundle-v1:'`, `HmacSigner::PURPOSE_MANIFEST = 'promotion-manifest-v1:'`. Keyring from `AGENCY_PROMOTION_HMAC_KEYS` + `AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID`; a missing or empty keyring is a hard failure (exit 1), never a WordPress-salt fallback. Keys shorter than 32 characters are rejected by Task 2's signer.

**Exit codes (master spec §6)**

| Code | Meaning |
|---|---|
| `0` | Success — every selected record succeeded |
| `1` | Hard error — run-level refusal, invalid input, or every selected record refused |
| `2` | Partial success — at least one record succeeded AND at least one was refused |
| `3` | Concurrency/lock conflict |
| `4` | Tamper detection — bundle or manifest signature failure, or a prepared file that no longer matches its signed hash |

Task 2's `StateException` already carries these as `EXIT_HARD_ERROR`, `EXIT_DRIFT`, `EXIT_LOCKED`, `EXIT_TAMPER`. **Never re-derive an exit code from an exception message.** When this task catches a `StateException`, it rethrows a `PromotionException` built with `$exception->exit_code()` verbatim.

**`-` direction table (master spec §6, plus the two directions the spec leaves open)**

```text
prepare   --manifest=-      STDOUT is the signed manifest JSON
seal      --manifest=-      reads manifest JSON from STDIN, STDOUT is the sealed manifest JSON
finalize  --manifest=-      reads manifest JSON from STDIN, STDOUT is one result document
confirm   --manifest=-      reads manifest JSON from STDIN, STDOUT is one result document
rollback  --manifest=-      reads manifest JSON from STDIN, STDOUT is one result document
heartbeat --manifest=-      reads manifest JSON from STDIN, STDOUT is one result document
prepare   --source=-        reads bundle JSON from STDIN
```

STDOUT carries exactly ONE JSON document per run and nothing else. All diagnostics go to STDERR. **Only `Cli/PromotionCommands.php` writes to either stream** — every other class returns data.

**Environment variables**

Read through Task 2's `EnvironmentConfig::get()` (constant first, then `getenv()`), never `getenv()` directly: `AGENCY_TARGET_SITE_UUID`, `AGENCY_REPO_ROOT`, `AGENCY_EXPECTED_BRANCH`, `AGENCY_ALLOW_DETACHED_HEAD`, `AGENCY_STATE_DIR`, `AGENCY_DEPLOY_COMMIT`. The state directory itself comes from Task 2's `StateDirectory::ensure()`.

Introduced by this plan (all optional, with the stated defaults):

| Variable | Default | Meaning |
|---|---|---|
| `AGENCY_PROMOTION_LOCK_TTL` | `900` | per-record lock TTL in seconds; an older lock is reclaimable |
| `AGENCY_PROMOTION_MUTEX_TTL` | `30` | lifetime of the short mutation mutex around lock bookkeeping |
| `AGENCY_PROMOTION_BACKUP_RETENTION_DAYS` | `30` | retention window (master spec §7.9 allows 14–30; the safer end is chosen) |
| `AGENCY_PROMOTION_BACKUP_CHUNK_BYTES` | `500000` | maximum bytes per backup option row before chunking |
| `AGENCY_DEPLOYMENT_ID` | the promotion UUID | human-readable lock owner recorded in the lock record |
| `AGENCY_VERIFICATION_COMMANDS` | `npm run test:e2e,npm run test:visual` | comma-separated advisory list written into the manifest |

Wrapper-only, master-spec-defined:

```text
AGENCY_REMOTE_WP_CLI_COMMAND  # e.g. ssh deploy@example.com 'cd /var/www && wp'
AGENCY_DEPLOY_URL             # the URL Playwright runs against
AGENCY_REMOTE_STATE_DIR       # where the manifest lands on the host
AGENCY_PLAYWRIGHT_PROJECT     # Playwright project name for verification
AGENCY_VERIFICATION_TIMEOUT   # how long to allow verification before rollback
```

Wrapper-only, introduced here: `AGENCY_HEARTBEAT_INTERVAL` (60), `AGENCY_PLAYWRIGHT_COMMAND` (`npx playwright test`), `AGENCY_PLAYWRIGHT_TESTS` (`tests/e2e`), `AGENCY_PROMOTION_LOG_DIR` (`./promotion-logs`).

**Decisions this plan fixes where the master spec is silent** (do not re-litigate)

1. **Run-level vs per-record refusal.** Master spec §7.5's list headed "Prepare must refuse to run when" is run-level and aborts with exit 1 writing nothing: production environment, dirty tree, unexpected branch, `siteUuid` mismatch, undeclared slug, a sealed or finalized manifest, a modified prepared file. Per-record refusals (exit 2 when mixed with successes) are: unparseable markup, an unregistered block, a referenced part that neither exists nor is selected, and any unresolved reference from §7.4.
2. **A selector naming an unknown provider, a provider with no registered promotion strategy, or a record absent from the bundle is invalid operator input → exit 1.** The strategy check is what keeps Global Styles out of Release 3.
3. **Zero successes plus at least one refusal → exit 1.** The manifest is still written with the full refusal report.
4. **`--seal` verifies the prepared content is inside the named commit** (`git show <sha>:<path>` hashed against the recorded hash). A working-tree mismatch is exit 4; "not present in that commit" is exit 1.
5. **The authoritative post-finalisation manifest lives on the WordPress host** at `<AGENCY_STATE_DIR>/promotions/<promotionId>.json`. `--finalize` creates it. `--confirm`, `--rollback` and `--heartbeat` read the supplied manifest only to authenticate the `promotionId`, then load the canonical host copy. A missing canonical copy is exit 1.
6. **Manifests store two paths per record**: `preparedFilePath` (repo-relative, for git and `--seal`) and `themeRelativePath` (e.g. `templates/page.html`, for `get_stylesheet_directory()` at finalize), so production needs neither `.git` nor `AGENCY_REPO_ROOT`.
7. **All semantic record hashes use Task 2's content-map convention**: `Normalizer::hash( array( 'markup' => $normalized_markup ) )`, identical to `StateRecord::content_hash()`. Raw-byte SHA-256 is used ONLY for prepared-file integrity (`preparedFileHash`).
8. **Prepared files are normalised on write**: `Normalizer::normalize_block_markup()`, then `\n` endings and exactly one trailing newline, mode 0644, with `ref` removed from `core/navigation` blocks (the §7.4 v1 policy) and `theme` removed from `core/template-part` blocks (WordPress injects it into file-backed templates, so leaving it in the file would make every post-reset comparison fail).
9. **Post-reset semantic comparison strips the injected `theme` attribute on both sides** before hashing.
10. **Navigation resolution follows WordPress core exactly**: the fallback is the MOST RECENTLY PUBLISHED `wp_navigation` post (`post_status=publish`, `orderby=date`, `order=DESC`, `posts_per_page=1`), not "exactly one must exist". See Task 8.
11. **Rollback restore order is leaves-first**: `template-parts`, then `templates`, then `global-styles`; reverse canonical-key order within a provider.
12. **A rollback returns its records to a re-finalisable state.** Restored records go back to `finalizeStatus = pending`, and the manifest's `finalizeStatus`/`settlementStatus` return to `pending` on the next finalize. Rollback re-reads the restored row and rewrites `objectId` and `originalModifiedGmt` in the manifest, so the next finalize's concurrency check passes.
13. **`WP_Upgrader::create_lock()` is the atomic lock primitive**, guarded by a short per-record mutation mutex; its absence fails closed rather than degrading to a racy `add_option()`.

---

## File Structure

Created by this task, all under `web/app/mu-plugins/agency-platform/`:

```text
src/State/Promotion/
├── PromotionExitCode.php            # the five exit-code constants
├── PromotionException.php           # carries an exit code with the message
├── PromotionSettings.php            # this task's own AGENCY_PROMOTION_* reads
├── RecordRefusal.php                # one refusal report entry (§7.4 fields)
├── PromotionOutcome.php             # per-record outcomes → exit code + payload array
├── StateGateway.php                 # names Task 2 symbols (1 of 4)
├── BundleView.php                   # names Task 2 symbols (2 of 4)
├── ResolvedTemplateNormalizer.php   # strips the injected theme attribute and nav refs
├── PromotionManifest.php            # manifest value object (no I/O)
├── ManifestStore.php                # load/render/write, atomic, HMAC, schema
├── PromotionSelector.php            # `--select` parsing + strategy allow-list gate
├── GitRepository.php                # root/branch/HEAD/dirty paths/commit blobs
├── PrepareLock.php                  # local filesystem lock for `--prepare`
├── ThemeDeclaredSlugs.php           # theme.json customTemplates/templateParts
├── StagedPromotionEntry.php         # one staged file, two-phase commit/discard
├── PreparedFileWriter.php           # normalise + staged atomic write
├── CanonicalJsonFileWriter.php      # staged canonical-JSON write (no block normalisation)
├── PreparablePromotionStrategy.php  # Task 3's extension of Task 2's interface
├── AbstractBlockTemplateStrategy.php# shared template/part strategy behaviour
├── TemplatePromotionStrategy.php    # provider slug `templates`
├── TemplatePartPromotionStrategy.php# provider slug `template-parts`
├── PromotionStrategyRegistrar.php   # named filter callback; the release allow-list
├── PromotionSubsystem.php           # the one Plugin.php entry point
├── NavigationBlockScanner.php       # finds every core/navigation block
├── NavigationPolicy.php             # §7.4 v1 navigation rule, both sides
├── ReferenceRefusalPolicy.php       # §7.4 enforcement for every other kind
├── PromotionPreparer.php            # §7.5
├── PromotionSealer.php              # §7.7 seal
├── RecordLockManager.php            # §7.10 per-record locks + mutex + heartbeat
├── PromotionBackup.php              # §7.9 chunked non-autoloaded backup storage
├── PromotionFinalizer.php           # §7.8
├── PromotionConfirmer.php           # §7.9 confirm
├── PromotionRollback.php            # §7.9 rollback
├── PromotionCommandRunner.php       # every command body, free of WP_CLI
├── ThemeJsonAdapter.php             # §7.6 — RELEASE 4 ONLY
└── GlobalStylesPromotionStrategy.php# §7.6 — RELEASE 4 ONLY
src/Cli/PromotionCommands.php        # names Task 2 symbols (3 of 4); the ONLY stream writer
resources/schemas/promotion-manifest-v1.json
```

Elsewhere:

```text
scripts/promote-overrides                          # deployment-side bash wrapper
tests/Unit/AgencyPlatform/Promotion/*.php          # pure-logic unit tests
tests/Integration/Promotion/*.php                  # WordPress-backed tests
tests/fixtures/promotion/*.php                     # wp eval-file fixtures for the e2e spec
tests/e2e/helpers/promotion.ts
tests/e2e/promotion-lifecycle.spec.ts
package.json                                       # ONE added script (Task 20)
.github/workflows/ci.yml                           # ONE required step in the existing e2e job (Task 21)
web/app/mu-plugins/agency-platform/src/Plugin.php   # ONE added line (Task 21, LAST)
```

Unit tests must not require new entries in `tests/support/wp-stubs.php` — Task 2 made the same commitment, so neither track touches that shared file. Pure classes therefore never call a WordPress function: UUIDs and timestamps are passed in from the command runner.

---

## Consumed Task 2 interfaces (authoritative, post-correction)

Task 2 symbols appear in exactly four places: `StateGateway.php`, `BundleView.php`, `Cli/PromotionCommands.php` (CLI plumbing), and the strategy classes (which implement a Task 2 interface). Everything else sees plain arrays and Task 3 types.

```php
use AgencyPlatform\State\StateException;      // exit_code(); EXIT_HARD_ERROR|EXIT_DRIFT|EXIT_LOCKED|EXIT_TAMPER
use AgencyPlatform\State\HmacSigner;          // INSTANCE: sign(array,string):array{hmacKeyId,hmac}; verify(array,string):void; canonicalize(array):string; from_environment():self
use AgencyPlatform\State\Normalizer;          // STATIC: normalize_block_markup(string):string; hash(array):string; hash_string(string):string; canonical_json_document(array):string
use AgencyPlatform\State\SchemaValidator;     // INSTANCE: validate(array,string):void; const SCHEMA_PROMOTION_MANIFEST
use AgencyPlatform\State\StateRecord;         // key(), provider_slug(), slug(), object_id(), status(), modified_gmt(), content(), content_hash(), references(), to_array()
use AgencyPlatform\State\StateProvider;       // record_key(string):string; record(string $key, bool $with_references = true):?StateRecord; records():array; promotion_strategy():?PromotionStrategy
use AgencyPlatform\State\StateRegistry;       // STATIC provider(string):?StateProvider; providers():array
use AgencyPlatform\State\StateBundle;         // STATIC load(string $path, ?HmacSigner, ?SchemaValidator):self  <-- THE call to use
use AgencyPlatform\State\PromotionStrategy;   // provider_slug(); prepare(StateRecord,string):array{preparedPath:string,preparedHash:string,originalHash:string|null}; reset(StateRecord):void; restore(StateRecord,array):void; expected_post_reset_hash(StateRecord):string
use AgencyPlatform\State\PromotionStrategies; // const FILTER; STATIC all():array; for_provider(string):?PromotionStrategy; reset():void
use AgencyPlatform\State\ReferenceScanner;    // STATIC scan(string $markup, string $record_key):list<array>; KIND_*; RESOLUTION_*; is_unresolved(array):bool
use AgencyPlatform\State\StateDirectory;      // STATIC ensure():string
use AgencyPlatform\State\EnvironmentConfig;   // STATIC get(string):?string
use AgencyPlatform\State\StateCommandResult;  // readonly int $exit_code, string $stdout, string $stderr
use AgencyPlatform\State\CliOutput;           // STATIC emit(StateCommandResult):void
```

Hard invariants this task relies on:

- `StateBundle::load()` reads, schema-validates, verifies the `PURPOSE_BUNDLE` signature AND re-confirms `stateHash`. `records()`, `record()` and `provider_meta()` throw `StateException` (exit 4) while `is_verified()` is false — so no unverified byte can reach promotion logic. **Never `json_decode` a bundle in this task.**
- `StateRecord::to_array()` key order: `key, objectId, slug, status, modifiedGmt, content, contentHash, references, ownership, promotion`. There is no `provider` key — derive it from `provider_slug()`.
- Template and template-part record content is `array( 'markup' => string )`; `contentHash` is `Normalizer::hash()` of that whole content map.
- `modifiedGmt` is nullable. Every comparison must handle `null` on either side.
- A reference entry carries `record, provider, blockName, attribute, value, kind, resolution, policy, targetKey, targetHash, targetIdentity`. `targetHash` is the SHA-256 of the target's normalised content, so the navigation policy is enforceable from a `--providers=templates` bundle.
- **Corrected Task 2 navigation contract.** Task 2 emits one navigation reference for *every* `core/navigation` block. For an explicit integer `ref`, it records that navigation record's normalised `targetHash` and `targetIdentity`. For a ref-less block, it resolves WordPress core's deterministic most-recently-published fallback during export and records that fallback's `targetHash` and `targetIdentity`, with `value`/`originalRef` `null`. If source export cannot resolve one fallback record and hash, it records an unresolved navigation reference. Task 3 removes every `ref` only after it has recorded this source identity/hash, and finalisation must resolve the same deterministic fallback hash on the target. This is the same check for a source block that was ref-less and for explicit-ref removal.
- `PromotionStrategies::all()` ships EMPTY. Nothing is promotable until this task registers a strategy.

---

## Release 3 — Safe template & template-part promotion

### Task 1: Promotion primitives

**Files:**
- Create: `src/State/Promotion/PromotionExitCode.php`
- Create: `src/State/Promotion/PromotionException.php`
- Create: `src/State/Promotion/PromotionSettings.php`
- Create: `src/State/Promotion/RecordRefusal.php`
- Create: `src/State/Promotion/PromotionOutcome.php`
- Test: `tests/Unit/AgencyPlatform/Promotion/PromotionOutcomeTest.php`
- Test: `tests/Unit/AgencyPlatform/Promotion/PromotionExceptionTest.php`

**Interfaces:**
- Consumes: `StateException` (exit-code preservation only), `EnvironmentConfig`.
- Produces:
  ```php
  final class PromotionExitCode {
      public const SUCCESS         = 0;
      public const HARD_ERROR      = 1;
      public const PARTIAL_SUCCESS = 2;
      public const LOCK_CONFLICT   = 3;
      public const TAMPER          = 4;
  }

  final class PromotionException extends \RuntimeException {
      public static function hard( string $message, ?\Throwable $previous = null ): self;
      public static function tamper( string $message, ?\Throwable $previous = null ): self;
      public static function lock_conflict( string $message, ?\Throwable $previous = null ): self;
      /** Rethrows a Task 2 failure PRESERVING its exit code verbatim — never remap by message. */
      public static function from_state_exception( StateException $exception ): self;
      public function exit_code(): int;
  }

  final class PromotionSettings {
      public const LOCK_TTL       = 'AGENCY_PROMOTION_LOCK_TTL';
      public const MUTEX_TTL      = 'AGENCY_PROMOTION_MUTEX_TTL';
      public const RETENTION_DAYS = 'AGENCY_PROMOTION_BACKUP_RETENTION_DAYS';
      public const CHUNK_BYTES    = 'AGENCY_PROMOTION_BACKUP_CHUNK_BYTES';
      public const DEPLOYMENT_ID  = 'AGENCY_DEPLOYMENT_ID';
      public const VERIFICATION   = 'AGENCY_VERIFICATION_COMMANDS';
      public static function integer( string $name, int $default ): int;
      public static function text( string $name, string $default ): string;
      public static function flag( string $name ): bool;
      /** @return list<string> */
      public static function verification_commands(): array;
  }

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
      /** @return array<string,mixed> camelCase keys for the manifest/report. */
      public function to_array(): array;
  }

  final class PromotionOutcome {
      /** @param array<string,string> $outcomes record key => prepared|promoted|restored|skipped|refused
       *  @param list<RecordRefusal> $refusals */
      public function __construct( array $outcomes, array $refusals );
      public function exit_code(): int;
      /** @return array<string,mixed> */
      public function to_array(): array;
      /** @return array<string,string> */
      public function outcomes(): array;
      /** @return list<RecordRefusal> */
      public function refusals(): array;
      public function successes(): int;
  }
  ```

- [ ] **Step 1: Write the failing outcome test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\AgencyPlatform\Promotion;

use AgencyPlatform\State\Promotion\PromotionOutcome;
use AgencyPlatform\State\Promotion\RecordRefusal;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AgencyPlatform\State\Promotion\PromotionOutcome
 */
final class PromotionOutcomeTest extends TestCase {

	public function test_all_successes_exit_zero(): void {
		self::assertSame( 0, ( new PromotionOutcome( array( 'templates:page' => 'promoted' ), array() ) )->exit_code() );
	}

	public function test_mixed_outcomes_exit_two(): void {
		$outcome = new PromotionOutcome(
			array(
				'templates:page'             => 'promoted',
				'template-parts:site-header' => 'refused',
			),
			array( $this->refusal( 'template-parts:site-header' ) )
		);

		self::assertSame( 2, $outcome->exit_code() );
	}

	public function test_every_record_refused_exits_one(): void {
		$outcome = new PromotionOutcome( array( 'templates:page' => 'refused' ), array( $this->refusal( 'templates:page' ) ) );

		self::assertSame( 1, $outcome->exit_code() );
	}

	public function test_skipped_records_count_as_successes(): void {
		self::assertSame( 0, ( new PromotionOutcome( array( 'templates:page' => 'skipped' ), array() ) )->exit_code() );
	}

	public function test_to_array_reports_every_refusal_field(): void {
		$array = ( new PromotionOutcome( array( 'templates:page' => 'refused' ), array( $this->refusal( 'templates:page' ) ) ) )->to_array();

		self::assertSame( 'unresolved-reference', $array['refusals'][0]['reasonCode'] );
		self::assertSame( 'core/image', $array['refusals'][0]['blockName'] );
		self::assertSame( 'id', $array['refusals'][0]['attribute'] );
		self::assertSame( '42', $array['refusals'][0]['referencedValue'] );
	}

	private function refusal( string $key ): RecordRefusal {
		list( $provider, $slug ) = explode( ':', $key, 2 );

		return new RecordRefusal(
			$key,
			$provider,
			$slug,
			'unresolved-reference',
			'Attachment ID 42 is environment-specific.',
			'core/image',
			'id',
			'42',
			'Replace the image with a theme asset, or exclude this record.'
		);
	}
}
```

- [ ] **Step 2: Write the failing exit-code preservation test**

```php
public function test_a_task_two_failure_keeps_its_exit_code(): void {
	self::assertSame( 4, PromotionException::from_state_exception( StateException::tamper( 'bad signature' ) )->exit_code() );
}

public function test_a_task_two_keyring_failure_stays_a_hard_error(): void {
	// A missing keyring is exit 1 in Task 2. Message-based mapping used to
	// turn it into exit 4 because the text contains "hmac".
	self::assertSame(
		1,
		PromotionException::from_state_exception(
			StateException::hard_error( 'AGENCY_PROMOTION_HMAC_KEYS is not set' )
		)->exit_code()
	);
}
```

Run: `ddev composer test:unit -- --filter "PromotionOutcomeTest|PromotionExceptionTest"`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the five classes**

`PromotionException` holds a private promoted `int $promotion_exit_code`; `from_state_exception()` calls `new self( $exception->getMessage(), $exception->exit_code(), $exception )`. **The exit code comes only from `StateException::exit_code()` — never from string inspection.**

`PromotionSettings` reads through `EnvironmentConfig::get()` so constants defined in `config/environments/*.php` work exactly like `.env` values. `integer()` casts only an all-digit value; anything else returns the default. `flag()` is true for `1`, `true`, `yes` case-insensitively. `verification_commands()` splits on `,`, trims, drops empties, and defaults to `array( 'npm run test:e2e', 'npm run test:visual' )`.

`PromotionOutcome::exit_code()` counts `prepared`, `promoted`, `restored`, `skipped` as successes: no refusals → `SUCCESS`; successes > 0 and refusals > 0 → `PARTIAL_SUCCESS`; otherwise `HARD_ERROR`. `to_array()` returns `array( 'outcomes' => …, 'refusals' => array_map( … ) )` with `outcomes` key-sorted.

- [ ] **Step 4: Run the tests**

Run: `ddev composer test:unit -- --filter "PromotionOutcomeTest|PromotionExceptionTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
ddev composer verify:fast
git status --porcelain          # confirm only this task's files are listed
git add web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionExitCode.php web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionException.php web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionSettings.php web/app/mu-plugins/agency-platform/src/State/Promotion/RecordRefusal.php web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionOutcome.php tests/Unit/AgencyPlatform/Promotion/PromotionOutcomeTest.php tests/Unit/AgencyPlatform/Promotion/PromotionExceptionTest.php
git commit -m "feat: add promotion lifecycle primitives"
git status --porcelain          # now empty
```

---

### Task 2: Promotion manifest schema and value object

**Files:**
- Create: `web/app/mu-plugins/agency-platform/resources/schemas/promotion-manifest-v1.json`
- Create: `src/State/Promotion/PromotionManifest.php`
- Test: `tests/Unit/AgencyPlatform/Promotion/PromotionManifestTest.php`

**Interfaces:**
- Consumes: `PromotionException`, `RecordRefusal` (Task 1).
- Produces:
  ```php
  final class PromotionManifest {
      public const SCHEMA_VERSION = 1;

      /** @param array<string,mixed> $data */
      public static function from_array( array $data ): self;
      /** @param array<string,mixed> $bundle_header exportId/exportedAtUtc/siteUrl/environment/activeTheme
       *  @param list<string> $verification_commands */
      public static function create( string $promotion_id, string $prepared_at_utc, array $bundle_header,
          string $target_site_uuid, string $base_commit, array $verification_commands ): self;

      /** @return array<string,mixed> */
      public function to_array(): array;
      public function promotion_id(): string;
      public function export_id(): string;
      public function site_uuid(): string;
      public function site_url(): string;
      public function environment(): string;
      /** @return array{stylesheet:string, version:string} */
      public function active_theme(): array;
      public function base_commit(): string;
      public function deploy_commit(): ?string;
      public function is_sealed(): bool;
      /** @return list<string> canonical record keys, sorted ascending */
      public function record_keys(): array;
      /** @return array<string,mixed>|null */
      public function record( string $record_key ): ?array;
      /** @return list<array<string,mixed>> */
      public function records(): array;
      /** @param array<string,mixed> $record */
      public function with_record( string $record_key, array $record ): self;
      /** @param array<string,mixed> $changes merged into the existing record */
      public function with_record_changes( string $record_key, array $changes ): self;
      public function with_deploy_commit( string $sha, string $sealed_at_utc ): self;
      public function with_field( string $key, mixed $value ): self;
      /** @param list<RecordRefusal> $refusals */
      public function with_refusals( array $refusals ): self;
      /** @return list<array<string,mixed>> */
      public function refusals(): array;
      public function finalize_status(): string;
      public function finalized_at_utc(): ?string;
      public function settlement_status(): string;
      public function settled_at_utc(): ?string;
      public function retention_until_utc(): ?string;
      public function backup_id(): ?string;
      /** @return list<string> */
      public function verification_commands(): array;
  }
  ```

- [ ] **Step 1: Write the JSON Schema**

`resources/schemas/promotion-manifest-v1.json`, Draft-07 (`"$schema": "http://json-schema.org/draft-07/schema#"`), `"additionalProperties": false` at the root AND at the record level, so an injected field is rejected. Validated with `( new SchemaValidator() )->validate( $document, SchemaValidator::SCHEMA_PROMOTION_MANIFEST )` — Task 2 declares that constant, so this task never passes a magic string and never edits `SchemaValidator`.

Root properties (all required):

| Property | Type |
|---|---|
| `schemaVersion` | integer, `const 1` |
| `promotionId` | string, uuid pattern |
| `exportId` | string, uuid pattern |
| `exportedAtUtc` | string, date-time |
| `preparedAtUtc` | string, date-time |
| `siteUuid` | string (the TARGET site UUID) |
| `siteUrl` | string |
| `environment` | string |
| `activeTheme` | object `{stylesheet: string, version: string}` |
| `baseCommit` | string, `^[0-9a-f]{40}$` |
| `deployCommit` | `["string","null"]`, `^[0-9a-f]{40}$` |
| `sealedAtUtc` | `["string","null"]`, date-time |
| `records` | array of record objects |
| `refusals` | array of refusal objects |
| `verificationCommands` | array of string |
| `finalizeStatus` | enum `pending`, `partial`, `complete` |
| `finalizedAtUtc` | `["string","null"]`, date-time |
| `backupId` | `["string","null"]` |
| `settlementStatus` | enum `pending`, `confirmed`, `rolled-back`, `partially-rolled-back` |
| `settledAtUtc` | `["string","null"]`, date-time |
| `retentionUntilUtc` | `["string","null"]`, date-time |
| `hmacKeyId` | string |
| `hmac` | string, `^[0-9a-f]{64}$` |

Record properties (all required; nullable where marked):

`key`, `provider`, `slug`, `objectId` (integer|null), `originalContentHash`, `originalModifiedGmt` (string|null), `preparedFilePath`, `themeRelativePath`, `preparedFileHash` (raw-byte SHA-256 of the written file), `originalFileHash` (string|null), `referenceScan` (array), `navigationExpectation` (array of `{originalRef: integer|null, exportedNavigationHash: string, exportedNavigationIdentity: object|null}`), `expectedPostResetHash` (string|null — null for a strategy whose expectation can only be computed on the target, i.e. Global Styles), `preResetResolvedHash` (string|null — recorded at finalize by such a strategy), `postFinalizeRecordState` (enum `present`, `absent`, or null), `postFinalizeSemanticHash` (string|null), `postFinalizeModifiedGmt` (string|null), `finalizeStatus` (enum `pending`, `promoted`, `refused`, `restored`), `finalizeRefusalReason` (string|null), `rollbackStatus` (enum `not-attempted`, `restored`, `refused`, `restored-hash-mismatch`), `rollbackRefusalReason` (string|null), `restoredObjectId` (integer|null).

Refusal properties mirror `RecordRefusal::to_array()` exactly: `recordKey`, `provider`, `slug`, `reasonCode`, `detail`, `blockName`, `attribute`, `referencedValue`, `suggestedPolicy`.

- [ ] **Step 2: Write the failing manifest test**

Assert: `create()` yields `finalizeStatus === 'pending'`, `settlementStatus === 'pending'`, `deployCommit === null`, `is_sealed() === false`; `site_uuid()` returns the TARGET argument, not the bundle header's value; `with_deploy_commit()` returns a NEW instance and leaves the original untouched; `record_keys()` is sorted ascending regardless of insertion order; `with_record_changes()` merges without dropping untouched fields; `from_array( $m->to_array() )` round-trips identically; `from_array()` on a document whose `schemaVersion` is not `1` throws `PromotionException` with exit code 1.

Run: `ddev composer test:unit -- --filter PromotionManifestTest` → FAIL.

- [ ] **Step 3: Implement `PromotionManifest`**

Hold one `array<string,mixed> $data`. Every `with_*` method clones, mutates the clone and returns it. `with_record()`/`with_record_changes()` replace or append, then re-sort `records` by `key` so the signed payload is order-stable. `to_array()` re-indexes `records` and `refusals` with `array_values()`.

- [ ] **Step 4: Run the test**

Run: `ddev composer test:unit -- --filter PromotionManifestTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/resources/schemas/promotion-manifest-v1.json web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionManifest.php tests/Unit/AgencyPlatform/Promotion/PromotionManifestTest.php
git commit -m "feat: add promotion manifest schema and value object"
```

---

### Task 3: State gateway and bundle view — the seam onto Task 2

**Files:**
- Create: `src/State/Promotion/StateGateway.php`
- Create: `src/State/Promotion/BundleView.php`
- Create: `src/State/Promotion/ResolvedTemplateNormalizer.php`
- Test: `tests/Unit/AgencyPlatform/Promotion/ResolvedTemplateNormalizerTest.php`
- Test: `tests/Integration/Promotion/StateGatewayTest.php`

**Interfaces:**
- Consumes: the authoritative Task 2 list above.
- Produces:
  ```php
  final class ResolvedTemplateNormalizer {
      /** Removes the `theme` attribute WordPress injects into file-backed templates. */
      public static function strip_theme_attribute( string $markup ): string;
      /** Removes `ref` from every core/navigation block (the §7.4 v1 policy). */
      public static function strip_navigation_refs( string $markup ): string;
  }

  final class BundleView {
      public function __construct( private StateBundle $bundle ) {}
      public function export_id(): string;
      public function exported_at_utc(): string;
      public function site_uuid(): string;
      public function site_url(): string;
      public function environment(): string;
      /** @return array{stylesheet:string, version:string, gitCommit:string|null} */
      public function active_theme(): array;
      /** @return array<string,mixed>|null StateRecord::to_array() plus a derived `provider` key. */
      public function record( string $record_key ): ?array;
      /** @return list<array<string,mixed>> */
      public function records( string $provider_slug ): array;
      public function has_provider( string $provider_slug ): bool;
      /** The underlying verified record, for handing to a PromotionStrategy. */
      public function state_record( string $record_key ): ?StateRecord;
      /** @return array{exportId:string,exportedAtUtc:string,siteUuid:string,siteUrl:string,environment:string,activeTheme:array<string,mixed>} */
      public function header(): array;
  }

  final class StateGateway {
      /** Uses StateBundle::load() — schema, PURPOSE_BUNDLE signature and stateHash are all verified. */
      public function load_bundle( string $path_or_dash ): BundleView;
      public function provider_exists( string $provider_slug ): bool;
      public function record_key( string $provider_slug, string $record_slug ): string;
      /** @return array<string,mixed>|null live record as StateRecord::to_array() plus `provider`. */
      public function read_live_record( string $provider_slug, string $record_slug ): ?array;
      public function live_state_record( string $provider_slug, string $record_slug ): ?StateRecord;
      /** @return list<array<string,mixed>> every live record of a provider */
      public function live_records( string $provider_slug ): array;
      public function normalize_block_markup( string $markup ): string;
      /** Task 2's content-map convention: Normalizer::hash( array( 'markup' => $markup ) ). */
      public function hash_markup( string $normalized_markup ): string;
      /** @param array<string,mixed> $content */
      public function hash_content( array $content ): string;
      public function hash_string( string $value ): string;
      /** @return list<array<string,mixed>> */
      public function scan_references( string $markup, string $record_key ): array;
      /** @param array<string,mixed> $reference */
      public function reference_is_unresolved( array $reference ): bool;
      /** @param array<string,mixed> $payload @return array{hmacKeyId:string,hmac:string} */
      public function sign_manifest( array $payload ): array;
      /** @param array<string,mixed> $document */
      public function verify_manifest( array $document ): void;
      /** @param array<string,mixed> $document */
      public function validate_manifest_schema( array $document ): void;
      public function strategy_for( string $provider_slug ): ?PromotionStrategy;
      public function state_dir(): string;
      /** @param array<string,mixed> $document exactly one JSON document plus one trailing LF */
      public function canonical_json_document( array $document ): string;
  }
  ```

- [ ] **Step 1: Write the failing normaliser test**

WordPress injects `"theme":"site-theme"` into `core/template-part` blocks when it builds a template from a file. Without stripping it, a promoted file and its resolved form never hash the same and every finalize would self-restore.

```php
public function test_it_strips_the_injected_theme_attribute(): void {
	$markup = '<!-- wp:template-part {"slug":"site-header","theme":"site-theme","area":"header"} /-->';

	self::assertSame(
		'<!-- wp:template-part {"area":"header","slug":"site-header"} /-->',
		ResolvedTemplateNormalizer::strip_theme_attribute( $markup )
	);
}

public function test_it_leaves_other_blocks_untouched(): void {
	$markup = '<!-- wp:paragraph {"theme":"kept"} --><p>x</p><!-- /wp:paragraph -->';

	self::assertSame( $markup, ResolvedTemplateNormalizer::strip_theme_attribute( $markup ) );
}

public function test_it_strips_every_navigation_ref(): void {
	$markup = '<!-- wp:navigation {"ref":12,"layout":{"type":"flex"}} /--><!-- wp:navigation {"ref":13} /-->';

	self::assertSame(
		'<!-- wp:navigation {"layout":{"type":"flex"}} /--><!-- wp:navigation /-->',
		ResolvedTemplateNormalizer::strip_navigation_refs( $markup )
	);
}
```

Run: `ddev composer test:unit -- --filter ResolvedTemplateNormalizerTest` → FAIL.

- [ ] **Step 2: Implement `ResolvedTemplateNormalizer`**

Both methods must run without WordPress (they are unit-tested), so use `preg_replace_callback` over `/<!--\s+wp:(template-part|navigation)\s*(\{.*?\})?\s*(\/)?-->/s`, `json_decode` the attribute object, `unset()` the target key, `ksort()` the rest, re-encode with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, and emit `<!-- wp:name /-->` when nothing remains. Leave markup unchanged when the JSON does not decode. Handle both the self-closing and the paired (`<!-- wp:navigation … --> … <!-- /wp:navigation -->`) forms.

- [ ] **Step 3: Implement `BundleView` and `StateGateway`**

`StateGateway::load_bundle()`:

```php
public function load_bundle( string $path_or_dash ): BundleView {
	try {
		return new BundleView( StateBundle::load( $path_or_dash, $this->signer(), $this->validator() ) );
	} catch ( StateException $exception ) {
		throw PromotionException::from_state_exception( $exception );
	}
}
```

Every other Task 2 call is wrapped identically — catch `StateException`, rethrow via `from_state_exception()`. **No message inspection anywhere.**

- `read_live_record()`: `StateRegistry::provider( $provider_slug )?->record( $this->record_key( $provider_slug, $record_slug ) )?->to_array()`, then add `'provider' => $provider_slug`. `null` when the provider or the record is absent.
- `normalize_block_markup()`: `ResolvedTemplateNormalizer::strip_theme_attribute()` first, then `Normalizer::normalize_block_markup()`.
- `hash_markup( $m )`: `Normalizer::hash( array( 'markup' => $m ) )` — identical to `StateRecord::content_hash()` for a template record, so a manifest hash and a bundle `contentHash` are directly comparable.
- `hash_content()`: `Normalizer::hash( $content )`. `hash_string()`: `Normalizer::hash_string( $value )`.
- `scan_references()`: `ReferenceScanner::scan( $markup, $record_key )`. `reference_is_unresolved()`: `ReferenceScanner::is_unresolved( $reference )`.
- `sign_manifest()` / `verify_manifest()`: the `HmacSigner` INSTANCE (`HmacSigner::from_environment()`), `PURPOSE_MANIFEST`.
- `validate_manifest_schema()`: `( new SchemaValidator() )->validate( $document, SchemaValidator::SCHEMA_PROMOTION_MANIFEST )`.
- `strategy_for()`: `PromotionStrategies::for_provider( $provider_slug )`.
- `state_dir()`: `StateDirectory::ensure()`. `canonical_json_document()`: `Normalizer::canonical_json_document( $document )`.

`BundleView::record()` calls `$this->bundle->record( $key )`, returns `null` when absent, otherwise `$record->to_array() + array( 'provider' => $record->provider_slug() )`.

- [ ] **Step 4: Write the integration smoke test**

`tests/Integration/Promotion/StateGatewayTest.php` (extends `Tests\Integration\IntegrationTestCase`) asserts:

- `provider_exists( 'templates' )` is true, `provider_exists( 'nope' )` false.
- `read_live_record( 'templates', 'page' )` is `null` with no override, and returns a record with `contentHash` and `content.markup` after one is created with `wp_insert_post()` (`post_type` `wp_template`, `post_name` `page`, `post_status` `publish`) plus `wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' )` — a template row without its `wp_theme` term is invisible to the resolver.
- `hash_markup( $markup )` equals that live record's `contentHash` for the same markup (proves the hash convention matches Task 2's).
- `load_bundle()` on a file whose `hmac` was altered throws `PromotionException` with exit code **4**.
- `load_bundle()` with `AGENCY_PROMOTION_HMAC_KEYS` unset throws `PromotionException` with exit code **1** (the regression message-based mapping caused).
- `strategy_for( 'templates' )` is `null` before Task 7 registers anything — the registry ships empty.

- [ ] **Step 5: Run and commit**

Run: `ddev composer test:unit -- --filter ResolvedTemplateNormalizerTest && ddev composer test:integration -- --filter StateGatewayTest`
Expected: PASS.

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/StateGateway.php web/app/mu-plugins/agency-platform/src/State/Promotion/BundleView.php web/app/mu-plugins/agency-platform/src/State/Promotion/ResolvedTemplateNormalizer.php tests/Unit/AgencyPlatform/Promotion/ResolvedTemplateNormalizerTest.php tests/Integration/Promotion/StateGatewayTest.php
git commit -m "feat: add promotion state gateway seam"
```

---

### Task 4: Manifest store — signature-first verification, atomic writes, no stream I/O

**Files:**
- Create: `src/State/Promotion/ManifestStore.php`
- Test: `tests/Integration/Promotion/ManifestStoreTest.php`

**Interfaces:**
- Consumes: `PromotionManifest` (Task 2), `StateGateway` (Task 3), `PromotionException` (Task 1).
- Produces:
  ```php
  final class ManifestStore {
      /** @param callable():string|null $stdin_reader null reads php://stdin. */
      public function __construct( private StateGateway $gateway, private $stdin_reader = null ) {}

      /** Reads a path, or STDIN when $path_or_dash is '-'. Verification order is
       *  fixed: decode -> require signature fields -> verify HMAC -> validate schema. */
      public function load( string $path_or_dash ): PromotionManifest;
      /** Signs, schema-validates and serialises. Writes NOTHING. */
      public function render( PromotionManifest $manifest ): string;
      /** Atomic temp-write + rename. Never writes to a stream, never accepts '-'. */
      public function write( PromotionManifest $manifest, string $path ): void;
      public function canonical_path( string $promotion_id ): string;
      public function write_canonical( PromotionManifest $manifest ): void;
      public function load_canonical( string $promotion_id ): PromotionManifest;
      public function canonical_exists( string $promotion_id ): bool;
  }
  ```

- [ ] **Step 1: Write the failing verification-order tests**

```php
public function test_load_rejects_a_tampered_manifest_with_exit_code_four(): void {
	$path     = $this->write_signed_manifest();
	$document = json_decode( file_get_contents( $path ), true );

	$document['records'][0]['preparedFileHash'] = str_repeat( 'a', 64 );
	file_put_contents( $path, wp_json_encode( $document ) );

	$this->assert_exit_code( 4, fn() => $this->store->load( $path ) );
}

public function test_a_signed_manifest_broken_into_an_invalid_shape_is_still_tamper(): void {
	// The HMAC must be checked BEFORE the schema. Deleting a required field
	// from a signed document is tampering, not a hard error.
	$path     = $this->write_signed_manifest();
	$document = json_decode( file_get_contents( $path ), true );

	unset( $document['baseCommit'] );
	file_put_contents( $path, wp_json_encode( $document ) );

	$this->assert_exit_code( 4, fn() => $this->store->load( $path ) );
}

public function test_a_manifest_with_no_signature_fields_is_tamper(): void {
	$path     = $this->write_signed_manifest();
	$document = json_decode( file_get_contents( $path ), true );

	unset( $document['hmac'], $document['hmacKeyId'] );
	file_put_contents( $path, wp_json_encode( $document ) );

	$this->assert_exit_code( 4, fn() => $this->store->load( $path ) );
}

public function test_malformed_json_is_a_hard_error(): void {
	file_put_contents( $this->tmp_dir . '/broken.json', '{not json' );

	$this->assert_exit_code( 1, fn() => $this->store->load( $this->tmp_dir . '/broken.json' ) );
}

public function test_a_correctly_signed_but_schema_invalid_document_is_a_hard_error(): void {
	$path = $this->write_signed_manifest_with_extra_field();

	$this->assert_exit_code( 1, fn() => $this->store->load( $path ) );
}

public function test_render_returns_a_document_and_writes_nothing(): void {
	$before = scandir( $this->tmp_dir );

	self::assertStringContainsString( '"hmac"', $this->store->render( $this->manifest ) );
	self::assertSame( $before, scandir( $this->tmp_dir ) );
}

public function test_write_is_atomic_and_leaves_no_temporary_file(): void {
	$this->store->write( $this->manifest, $this->tmp_dir . '/manifest.json' );

	self::assertSame(
		array( 'manifest.json' ),
		array_values( array_diff( scandir( $this->tmp_dir ), array( '.', '..' ) ) )
	);
}

public function test_load_reads_stdin_for_a_dash(): void {
	$json  = $this->store->render( $this->manifest );
	$store = new ManifestStore( $this->gateway, static fn(): string => $json );

	self::assertSame( $this->manifest->promotion_id(), $store->load( '-' )->promotion_id() );
}
```

`set_up()` sets `AGENCY_PROMOTION_HMAC_KEYS` (a key of 32 or more characters) and `AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID` with `putenv()`, and unsets them in `tear_down()`.

Run: `ddev composer test:integration -- --filter ManifestStoreTest` → FAIL.

- [ ] **Step 2: Implement `ManifestStore::load()` with the corrected order**

```php
public function load( string $path_or_dash ): PromotionManifest {
	$document = json_decode( $this->read( $path_or_dash ), true );

	if ( ! is_array( $document ) ) {
		throw PromotionException::hard( sprintf( 'Manifest "%s" is not valid JSON.', $path_or_dash ) );
	}

	// 1. A transportable artifact with no signature is indistinguishable from
	//    a stripped one, so treat it as tampering, not as a shape problem.
	if ( ! isset( $document['hmac'], $document['hmacKeyId'] )
		|| ! is_string( $document['hmac'] ) || ! is_string( $document['hmacKeyId'] ) ) {
		throw PromotionException::tamper( sprintf( 'Manifest "%s" carries no signature.', $path_or_dash ) );
	}

	// 2. Verify BEFORE validating the shape: a signed document edited into an
	//    invalid shape must report exit 4, not exit 1.
	$this->gateway->verify_manifest( $document );

	// 3. Only now is the content trusted enough to schema-check.
	$this->gateway->validate_manifest_schema( $document );

	return PromotionManifest::from_array( $document );
}
```

`read()` uses the injected `$stdin_reader` (or `file_get_contents( 'php://stdin' )`) for `-`, otherwise `file_get_contents( $path )` with the approved `phpcs:ignore`; an unreadable file is `PromotionException::hard()`.

- [ ] **Step 3: Implement `render()`, `write()` and the canonical helpers**

`render()`: `$data = $manifest->to_array()`, `unset( $data['hmac'], $data['hmacKeyId'] )`, `sign_manifest()`, merge the returned pair back in, `validate_manifest_schema()` on the signed document (catches a schema break introduced by this code), then `$this->gateway->canonical_json_document( $data )` — Task 2's canonical encoder gives deterministic bytes plus exactly one trailing LF.

**CORRECTION (orchestrator, Unit 3A) — `write()` MUST go through the shared web-root guard.**

As originally written this step took an operator-supplied `$path` and wrote to it with no containment check. That is a direct recurrence of Unit 2's twenty-third defect, the CRITICAL one its whole-unit review found: a second write path that bypassed the guard let customer state be written into the public web root, where everything is served over HTTP. A signed promotion manifest carries the target site UUID, every prepared file path and every content hash, so it must never land under `web/`.

There is exactly ONE implementation of that rule and this task calls it rather than writing a second copy — two implementations of one security rule drifting apart is how the original defect arose:

```php
public static function StateDirectory::resolve_output( string $path, string $setting ): string;
```

It normalises backslashes, resolves a relative path against the repository root, collapses `.` and `..` lexically, resolves symlinks in existing components, and rejects anything landing inside `<repo root>/web`. **It returns the canonical path the caller must actually write**, so the guard and the write can never disagree. It is confirmed usable inside the DDEV container: `GitBaseline::repo_root()` reads `AGENCY_REPO_ROOT` or walks up from `ABSPATH` looking for `web/` plus `composer.json`, and never shells out to git.

`write()`: first `$resolved = StateDirectory::resolve_output( $path, '--manifest' )`, wrapped so a `StateException` is rethrown through `PromotionException::from_state_exception()`. Then `wp_mkdir_p( dirname( $resolved ) )`, write `render()`'s output to `$resolved . '.' . wp_generate_uuid4() . '.tmp'` in the same directory, `fflush()`, `fclose()`, `chmod( $tmp, 0600 )`, `rename( $tmp, $resolved )`. **Every subsequent operation uses `$resolved`, never the raw `$path`.** On any failure `unlink()` the temp file and throw `PromotionException::hard()`. Every native call carries the approved `phpcs:ignore`. **`write()` rejects `-` with `PromotionException::hard()`; the command layer decides whether a document goes to STDOUT.** `write_canonical()` routes through `write()` so there is still only one write path.

The guard must be proven BY ATTACK, not by reading the diff — that is how Unit 2 verified the original fix. `ManifestStoreTest` adds cases for an absolute path inside the web root, a relative path inside the web root, a `..` traversal that lands in the web root, a path nested deep under `web/app/uploads/`, and a legitimate `var/agency-state/` path that still writes. Each refusal asserts BOTH that it throws AND that `false === file_exists( $path )` — a test that only asserted the throw would pass even if the file had been written first.

`canonical_path()` returns `$this->gateway->state_dir() . '/promotions/' . $promotion_id . '.json'`. `write_canonical()` creates that directory with `wp_mkdir_p()` and `chmod( $dir, 0700 )`. `load_canonical()` throws `PromotionException::hard( 'No finalized promotion found for <id>. Run --finalize on this host first.' )` when the file is absent.

- [ ] **Step 4: Run the tests**

Run: `ddev composer test:integration -- --filter ManifestStoreTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/ManifestStore.php tests/Integration/Promotion/ManifestStoreTest.php
git commit -m "feat: add signed atomic promotion manifest store"
```

---

### Task 5: Git context and the local prepare lock

**Files:**
- Create: `src/State/Promotion/GitRepository.php`
- Create: `src/State/Promotion/PrepareLock.php`
- Test: `tests/Integration/Promotion/GitRepositoryTest.php`
- Test: `tests/Integration/Promotion/PrepareLockTest.php`

**Interfaces:**
- Consumes: `PromotionException` (Task 1), `EnvironmentConfig`, `StateDirectory`.
- Produces:
  ```php
  final class GitRepository {
      public function __construct( private string $root ) {}
      /** AGENCY_REPO_ROOT, else `git rev-parse --show-toplevel`. */
      public static function discover(): self;
      public function root(): string;
      public function head_commit(): string;                  // 40-hex
      public function current_branch(): ?string;              // null when detached
      /** @return list<string> repo-relative paths with any working-tree or index change */
      public function dirty_paths(): array;
      public function commit_exists( string $sha ): bool;
      /** @return string|null file content at that commit, or null when absent */
      public function file_at_commit( string $sha, string $repo_relative_path ): ?string;
      public function relative_path( string $absolute_path ): string;
      public function absolute_path( string $repo_relative_path ): string;
  }

  final class PrepareLock {
      public function __construct( private string $lock_file ) {}
      public static function for_state_dir(): self;           // <AGENCY_STATE_DIR>/prepare.lock
      public function acquire(): void;                        // throws lock_conflict() (exit 3)
      public function release(): void;                        // safe to call twice
  }
  ```

- [ ] **Step 1: Implement `GitRepository`**

One private method `run( array $argv ): array{stdout:string, exit:int}` using `proc_open( array_merge( array( 'git', '-C', $this->root ), $argv ), $descriptors, $pipes )` — the array form never invokes a shell, so no argument can be injected. Add the approved `phpcs:ignore`. `proc_open` returning `false` throws `PromotionException::hard( 'git is not available; --prepare and --seal require it.' )`.

- `discover()`: `EnvironmentConfig::get( 'AGENCY_REPO_ROOT' )` when set; else run `git rev-parse --show-toplevel` from `dirname( __DIR__, 7 )`. From `src/State/Promotion`, seven parents reach the repository root (`Promotion → State → src → agency-platform → mu-plugins → app → web → repository`). Trim the result and throw on a non-zero exit.
- `head_commit()`: `rev-parse HEAD`.
- `current_branch()`: `symbolic-ref --quiet --short HEAD`; a non-zero exit means detached → `null`.
- `dirty_paths()`: `status --porcelain --untracked-files=all`; take `substr( $line, 3 )`; for a rename line (`R  old -> new`) keep the right-hand path; for a quoted path (`"…"`) run `stripcslashes()` after removing the quotes.
- `commit_exists()`: `cat-file -e <sha>^{commit}` → exit 0.
- `file_at_commit()`: `show <sha>:<path>` → stdout, or `null` on a non-zero exit.
- `relative_path()` / `absolute_path()`: normalise `\` to `/` on both sides; `relative_path()` throws when the path is outside the root.

- [ ] **Step 2: Implement `PrepareLock`**

`for_state_dir()` builds `StateDirectory::ensure() . '/prepare.lock'`. `acquire()` runs `wp_mkdir_p( dirname( $lock_file ) )`, `fopen( $lock_file, 'c' )`, `flock( $handle, LOCK_EX | LOCK_NB )`; on failure it closes the handle and throws `PromotionException::lock_conflict( 'Another prepare run holds <file>.' )`, and on success it keeps the handle in a property. `release()` runs `flock( $handle, LOCK_UN )`, `fclose()`, and nulls the property.

- [ ] **Step 3: Write and run the tests**

**CORRECTION (orchestrator, Unit 3A pre-Task-1 audit) — this test may NOT use the ambient repository.**

The original text said "`GitRepositoryTest` runs against the real repository". That gate is red by
construction in this engagement's own environment and was proven so before Task 1 started. Unit 3A
works in a git worktree at `C:\Users\Aleksandar\Projects\wt\bt-task-3`, whose `.git` is a FILE
reading `gitdir: C:/Users/Aleksandar/Projects/wordpress-template/.git/worktrees/bt-task-3`. That
Windows path is outside the DDEV mount, so inside the container — where the integration suite runs
— every git command fails:

```text
$ ddev exec git status --porcelain
fatal: not a git repository: /var/www/html/C:/Users/Aleksandar/Projects/wordpress-template/.git/worktrees/bt-task-3
```

`git` itself is present at `/usr/bin/git`; only the repository is unreachable. An ambient-repository
test would also be non-deterministic even where it did run, because it would assert over whatever
branch, HEAD and dirty state the developer happens to have.

`GitRepositoryTest` therefore builds a **throwaway fixture repository** and runs `GitRepository`
against it. This is the same pattern Task 10's `PromotionPreparerTest` already uses (`git init`,
one commit), so the plan becomes self-consistent rather than acquiring a new convention.

- `set_up()` creates a temp directory, runs `git init`, `git config user.email`, `git config
  user.name`, and `git -c commit.gpgsign=false commit` of a small fixture tree containing a
  `composer.json` whose `name` is `agency/agency-starter` and a `templates/page.html`. Set
  `GIT_CONFIG_GLOBAL=/dev/null` and `GIT_CONFIG_SYSTEM=/dev/null` for every fixture git call so a
  host `~/.gitconfig` (hooks, `init.defaultBranch`, signing) cannot change the result.
- `tear_down()` removes the temp directory.
- The first assertion runs `git --version` and FAILS the test with `Git is required for the
  promotion release gate.` when Git is unavailable. It never skips, and a missing environment
  stays a failed gate.
- Assertions against the fixture: `head_commit()` is 40 hex characters; `root()` contains
  `composer.json`; `commit_exists( head_commit() )` is true; `commit_exists( str_repeat( '0', 40 ) )`
  is false; `file_at_commit( head_commit(), 'composer.json' )` contains `"agency/agency-starter"`;
  `file_at_commit( head_commit(), 'no/such/file' )` is `null`.
- `current_branch()` returns the fixture's branch name, and after `git checkout --detach` it
  returns `null`. Assert BOTH — a `current_branch()` that always returned `null` would otherwise
  pass.
- `dirty_paths()` is empty on the fresh fixture; after writing an untracked file it contains that
  path; after modifying a tracked file it contains that path. Assert all three — an implementation
  that always returned an empty array would otherwise pass, and the run-level dirty-tree refusal in
  Task 10 depends entirely on this method.
- `relative_path()` / `absolute_path()` round-trip inside the fixture, and `relative_path()` throws
  for a path outside the root.

**The discovery assertion is kept but corrected.** The original required
`GitRepository::discover()->root()` to equal `realpath( dirname( __DIR__, 7 ) )`. `__DIR__` in
`tests/Integration/Promotion/` is three levels below the repository root, not seven, so that
assertion was wrong by five levels; seven is the correct count only from the SOURCE file
`src/State/Promotion/GitRepository.php`. Assert the parent count where it is actually used, without
depending on the ambient repository being a git repository:

- Set `AGENCY_REPO_ROOT` to the fixture path and assert `GitRepository::discover()->root()` is the
  fixture path. This proves the override branch.
- Assert the fallback branch's parent count by reflection over the constant rather than by running
  git: compute `dirname( ( new \ReflectionClass( GitRepository::class ) )->getFileName(), 7 )` and
  assert it equals `realpath( dirname( __DIR__, 3 ) )` — the repository root as seen from the test
  file. This is what stops another wrong parent count, and it holds in the container, in a git
  worktree, and in CI alike.

`PrepareLockTest` asserts a second `acquire()` from a second instance on the same file throws with exit code 3, and succeeds after `release()`.

Run: `ddev composer test:integration -- --filter "GitRepositoryTest|PrepareLockTest"`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/GitRepository.php web/app/mu-plugins/agency-platform/src/State/Promotion/PrepareLock.php tests/Integration/Promotion/GitRepositoryTest.php tests/Integration/Promotion/PrepareLockTest.php
git commit -m "feat: add promotion git context and prepare lock"
```

---

### Task 6: Declared-slug guard and the staged prepared-file writer

**Files:**
- Create: `src/State/Promotion/ThemeDeclaredSlugs.php`
- Create: `src/State/Promotion/PreparedFileWriter.php`
- Create: `src/State/Promotion/CanonicalJsonFileWriter.php`
- Create: `src/State/Promotion/StagedPromotionEntry.php`
- Test: `tests/Unit/AgencyPlatform/Promotion/ThemeDeclaredSlugsTest.php`
- Test: `tests/Integration/Promotion/PreparedFileWriterTest.php`
- Test: `tests/Integration/Promotion/CanonicalJsonFileWriterTest.php`

**Interfaces:**
- Consumes: `StateGateway`, `ResolvedTemplateNormalizer` (Task 3), `PromotionException` (Task 1).
- Produces:
  ```php
  final class ThemeDeclaredSlugs {
      /** Built-in hierarchy slugs never need a customTemplates entry. */
      public const CORE_TEMPLATE_SLUGS = array( '404', 'archive', 'index', 'page', 'search', 'single',
          'home', 'front-page', 'singular', 'attachment', 'author', 'category', 'date', 'tag',
          'taxonomy', 'privacy-policy' );
      /** @param array<string,mixed> $theme_json */
      public function __construct( private array $theme_json ) {}
      public static function from_file( string $theme_json_path ): self;
      public function declares_template( string $record_slug ): bool;
      public function declares_template_part( string $record_slug ): bool;
  }

  /**
   * Two-phase writer. Every body is staged as a temp file first; only when every
   * record in the run has staged successfully does commit_all() rename them into
   * place. A failure part-way through restores whatever was already renamed.
   */
  interface StagedPromotionEntry {
      /** @return array<string,mixed> manifest fields for this staged file */
      public function manifest_fields(): array;
      public function commit(): void;
      public function discard(): void;
      public function rollback_committed(): void;
  }

  final class PreparedFileWriter {
      public function __construct( private StateGateway $gateway, private string $theme_dir ) {}
      /** Normalised, promotion-safe body for the exported markup. */
      public function render( string $exported_markup ): string;
      /** The returned entry's manifest_fields() carries:
       *  array{themeRelativePath:string, absolutePath:string, tempPath:string,
       *        preparedFileHash:string, originalFileHash:string|null,
       *        expectedPostResetHash:string, previousBytes:string|null} */
      public function stage( string $theme_relative_path, string $exported_markup, string $promotion_id ): StagedPromotionEntry;
      /** @param list<StagedPromotionEntry> $staged */
      public function commit_all( array $staged ): void;
      /** @param list<StagedPromotionEntry> $staged */
      public function discard_all( array $staged ): void;
  }

  /** Writes canonical JSON directly. It never block-normalises the document. */
  final class CanonicalJsonFileWriter {
      public function __construct( private string $theme_dir ) {}
      /** @param array<string,mixed> $document */
      public function stage( string $theme_relative_path, array $document, string $promotion_id ): StagedPromotionEntry;
  }
  ```

- [ ] **Step 1: Write the failing declared-slug test**

```php
public function test_core_hierarchy_slugs_are_always_declared(): void {
	$slugs = new ThemeDeclaredSlugs( array( 'version' => 3 ) );

	self::assertTrue( $slugs->declares_template( 'page' ) );
	self::assertTrue( $slugs->declares_template( '404' ) );
}

public function test_a_custom_template_needs_a_custom_templates_entry(): void {
	self::assertFalse( ( new ThemeDeclaredSlugs( array( 'version' => 3 ) ) )->declares_template( 'landing' ) );

	$declared = new ThemeDeclaredSlugs(
		array(
			'version'         => 3,
			'customTemplates' => array( array( 'name' => 'landing', 'title' => 'Landing' ) ),
		)
	);

	self::assertTrue( $declared->declares_template( 'landing' ) );
}

public function test_a_template_part_needs_a_template_parts_entry(): void {
	$slugs = new ThemeDeclaredSlugs(
		array(
			'version'       => 3,
			'templateParts' => array( array( 'name' => 'site-header', 'area' => 'header' ) ),
		)
	);

	self::assertTrue( $slugs->declares_template_part( 'site-header' ) );
	self::assertFalse( $slugs->declares_template_part( 'promo-bar' ) );
}
```

Run: `ddev composer test:unit -- --filter ThemeDeclaredSlugsTest` → FAIL.

- [ ] **Step 2: Implement `ThemeDeclaredSlugs`**

`from_file()` reads with `file_get_contents()` (approved `phpcs:ignore`) and `json_decode(..., true)`, throwing `PromotionException::hard()` when the file is unreadable or the JSON is invalid. The two `declares_*` methods do exactly what the tests describe.

- [ ] **Step 3: Implement `PreparedFileWriter`**

`render()`:
1. `ResolvedTemplateNormalizer::strip_theme_attribute()`.
2. `ResolvedTemplateNormalizer::strip_navigation_refs()`.
3. `$this->gateway->normalize_block_markup()`.
4. `str_replace( array( "\r\n", "\r" ), "\n", … )`, then `rtrim( …, "\n" ) . "\n"`.

`stage()`:
1. `$absolute = $this->theme_dir . '/' . $theme_relative_path;` — `is_dir( dirname( $absolute ) )` must be true, otherwise `PromotionException::hard()`.
2. `$previous_bytes` = the current file bytes or `null`; `$original_file_hash` = `hash( 'sha256', $previous_bytes )` or `null`.
3. Write `render()`'s output to `$absolute . '.' . $promotion_id . '.tmp'`, `fflush`, `fclose`, `chmod 0644`. **Do not rename yet.**
4. Return the array, where `preparedFileHash` is `hash( 'sha256', $body )` over the raw bytes and `expectedPostResetHash` is `$this->gateway->hash_markup( $this->gateway->normalize_block_markup( $body ) )` — the value `--finalize` compares the resolved template against.

`commit_all()` renames each `tempPath` to its `absolutePath` in order. If a rename fails, it restores every already-renamed entry from its `previousBytes` (deleting the file when `previousBytes` is `null`), removes the remaining temp files, and throws `PromotionException::hard()`. `discard_all()` unlinks every temp file and is safe to call twice.

**Corrected staged-entry contract.** `stage()` returns a `StagedPromotionEntry`, not a raw array. `manifest_fields()` returns `themeRelativePath`, `absolutePath`, `preparedFileHash`, `originalFileHash`, and `expectedPostResetHash`; `commit()` renames the temp file; `discard()` removes it; and `rollback_committed()` restores `previousBytes` or removes a newly-created file. This contract lets the preparer commit or discard every strategy-owned entry without accessing the strategy's writer.

For `StagedPromotionEntry` values, `commit_all()` calls `commit()` in order. If one call fails, it calls `rollback_committed()` for every entry already committed, calls `discard()` for every entry not committed, and throws `PromotionException::hard()`. `discard_all()` calls `discard()` for every entry and is safe to call twice.

`CanonicalJsonFileWriter::stage()` recursively key-sorts object maps while preserving list order, then serialises only that supplied document with `wp_json_encode( $canonical_document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"`. It checks encoding success and returns the same `StagedPromotionEntry` contract. It does **not** call `PreparedFileWriter::render()`, `ResolvedTemplateNormalizer`, or `Normalizer::normalize_block_markup()`. It is the only writer Global Styles uses for `theme.json`.

- [ ] **Step 4: Write and run the writer integration test**

Create a temporary theme directory in `set_up()` with `templates/` and `parts/`. Assert: `stage()` writes only a `.tmp` file and leaves the target untouched; `commit_all()` leaves exactly one file per record and no `.tmp` residue; the written body ends with exactly one `\n` and contains no `\r`; a `core/navigation` block with `{"ref":42}` loses the `ref`; a `core/template-part` block loses its `theme` attribute; re-rendering the same markup produces the same `preparedFileHash`; `discard_all()` after `stage()` restores the directory to its original listing; a failed `commit_all()` (simulated by making one target directory read-only) restores every already-renamed file to its previous bytes.

Run: `ddev composer test:unit -- --filter ThemeDeclaredSlugsTest && ddev composer test:integration -- --filter PreparedFileWriterTest`
Expected: PASS.

Also run: `ddev composer test:integration -- --filter CanonicalJsonFileWriterTest`
Expected: PASS. The test stages a `theme.json` document with `templateParts`, a `core/navigation`-shaped string value, and CRLF inside a string. After commit, decoding returns the same document and those values are unchanged. This proves `theme.json` did not pass through block-markup normalisation.

- [ ] **Step 5: Commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/ThemeDeclaredSlugs.php web/app/mu-plugins/agency-platform/src/State/Promotion/PreparedFileWriter.php web/app/mu-plugins/agency-platform/src/State/Promotion/CanonicalJsonFileWriter.php web/app/mu-plugins/agency-platform/src/State/Promotion/StagedPromotionEntry.php tests/Unit/AgencyPlatform/Promotion/ThemeDeclaredSlugsTest.php tests/Integration/Promotion/PreparedFileWriterTest.php tests/Integration/Promotion/CanonicalJsonFileWriterTest.php
git commit -m "feat: add declared-slug guard and staged prepared file writer"
```

---

### Task 7: Promotion strategies and the release allow-list

This task is the Release 3/Release 4 boundary. `PromotionStrategies::all()` ships EMPTY, so nothing is promotable until a strategy is registered here. Registering only `templates` and `template-parts` keeps Global Styles export-and-diff for the whole of Release 3, even though Task 2 classifies `global-styles` as `PromotionPolicy::PROMOTABLE`.

**Files:**
- Create: `src/State/Promotion/PreparablePromotionStrategy.php`
- Create: `src/State/Promotion/AbstractBlockTemplateStrategy.php`
- Create: `src/State/Promotion/TemplatePromotionStrategy.php`
- Create: `src/State/Promotion/TemplatePartPromotionStrategy.php`
- Create: `src/State/Promotion/PromotionStrategyRegistrar.php`
- Create: `src/State/Promotion/PromotionSubsystem.php`
- Test: `tests/Integration/Promotion/PromotionStrategyRegistrarTest.php`
- Test: `tests/Integration/Promotion/BlockTemplateStrategyTest.php`

**Interfaces:**
- Consumes: Task 2's `PromotionStrategy`, `PromotionStrategies`, `StateRecord`; `StateGateway`, `PreparedFileWriter`, `ThemeDeclaredSlugs`, `PromotionException`.
- Produces:
  ```php
  /**
   * Task 3's extension of Task 2's PromotionStrategy. Task 2 defines prepare/
   * reset/restore/expected_post_reset_hash; the lifecycle in this task needs a
   * few more capabilities, so it requires this sub-interface and refuses any
   * strategy that only implements the base one.
   */
  interface PreparablePromotionStrategy extends PromotionStrategy {
      /**
       * The two-phase staging entry point this task actually uses.
       *
       * It does NOT redeclare Task 2's inherited `prepare( StateRecord, string ): array`.
       * PHP return types are invariant for non-class types, so narrowing that
       * `array` to `StagedPromotionEntry` is a fatal declaration-compatibility
       * error, and Task 2's interface is merged and out of this task's grant.
       * See the CORRECTION note under Step 1 for how `prepare()` is implemented.
       */
      public function stage( StateRecord $record, string $theme_root ): StagedPromotionEntry;
      public function theme_relative_path( string $record_slug ): string;
      public function declares( ThemeDeclaredSlugs $declared, string $record_slug ): bool;
      /** 'absent' when finalisation deletes the row; 'present' when it resets one in place. */
      public function post_finalize_record_state(): string;
      /** True when expected_post_reset_hash() can only be computed on the target host. */
      public function defers_expected_hash(): bool;
      /** Semantic hash of the state the site resolves to right now, or null when unresolvable. */
      public function resolve_current_hash( string $record_slug ): ?string;
      /** @param array<string,mixed> $manifest_record
       *  @return list<RecordRefusal> per-record refusals discovered at prepare time */
      public function validate_for_promotion( array $bundle_record, BundleView $bundle, array $selected_keys ): array;
      /** @return array<string,mixed> everything needed to recreate the row */
      public function capture_backup( StateRecord $record ): array;
  }

  final class PromotionStrategyRegistrar {
      /** Release 3 registers exactly these two slugs. Release 4 adds global-styles. */
      public const RELEASE_3_SLUGS = array( 'templates', 'template-parts' );
      public function register(): void;                 // adds the named filter callback
      /** Named filter callback — NEVER a closure (master spec §4).
       *  @param array<string, PromotionStrategy> $strategies
       *  @return array<string, PromotionStrategy> */
      public function add_strategies( array $strategies ): array;
  }

  final class PromotionSubsystem {
      public function register(): void;                 // registrar always; CLI only under WP_CLI
  }
  ```

**CORRECTION (orchestrator, Unit 3A pre-Task-1 audit) — the inherited `prepare()`.**

The merged Task 2 interface declares `prepare( StateRecord $record, string $target_path ): array`
returning `array{preparedPath: string, preparedHash: string, originalHash: string|null}`.
Verified in `web/app/mu-plugins/agency-platform/src/State/PromotionStrategy.php`. The plan
previously narrowed that return type to `StagedPromotionEntry` in the sub-interface, which PHP
rejects at class-load time with a fatal declaration-compatibility error, so Task 7's gate was red
by construction. `StagedPromotionEntry` is a Task 6 type and Task 2's file is out of this task's
ownership grant, so the sub-interface adds `stage()` instead and leaves `prepare()` alone.

`AbstractBlockTemplateStrategy::prepare()` therefore exists only to satisfy the inherited
signature and MUST refuse:

```php
public function prepare( StateRecord $record, string $target_path ): array {
	throw PromotionException::hard(
		'PromotionStrategy::prepare() is not the promotion entry point; the lifecycle stages '
		. 'every record with stage() and commits the run through PreparedFileWriter::commit_all(). '
		. 'Called for ' . $record->key() . '.'
	);
}
```

The reason it refuses rather than delegating to `stage()` + `commit()` is the Unit 2 lesson
recorded in the tracking file: a second write path that reaches the filesystem outside the
run-level transaction is how the `--output` web-root leak happened. A single-record commit would
silently break the all-or-nothing guarantee `commit_all()` exists to provide. Refusing is
fail-closed and testable.

`BlockTemplateStrategyTest` must carry a test proving the refusal — assert the thrown
`PromotionException`, assert `exit_code()` is `PromotionExitCode::HARD_ERROR`, and assert the
message names `stage()`. A test that only asserts "an exception was thrown" is not acceptable.

- [ ] **Step 1: Write the failing registrar test**

```php
public function test_release_three_registers_only_templates_and_template_parts(): void {
	PromotionStrategies::reset();
	( new PromotionStrategyRegistrar() )->register();

	self::assertSame( array( 'template-parts', 'templates' ), array_keys( PromotionStrategies::all() ) );
}

public function test_global_styles_has_no_strategy_in_release_three(): void {
	PromotionStrategies::reset();
	( new PromotionStrategyRegistrar() )->register();

	self::assertNull( PromotionStrategies::for_provider( 'global-styles' ) );
}

public function test_every_registered_strategy_is_preparable(): void {
	PromotionStrategies::reset();
	( new PromotionStrategyRegistrar() )->register();

	foreach ( PromotionStrategies::all() as $slug => $strategy ) {
		self::assertInstanceOf( PreparablePromotionStrategy::class, $strategy, "Strategy '{$slug}' must be preparable." );
	}
}

public function test_the_filter_callback_is_a_named_method_not_a_closure(): void {
	$callback = array( new PromotionStrategyRegistrar(), 'add_strategies' );

	self::assertIsCallable( $callback );
	self::assertNotInstanceOf( \Closure::class, $callback );
}
```

Run: `ddev composer test:integration -- --filter PromotionStrategyRegistrarTest` → FAIL.

- [ ] **Step 2: Implement the strategies**

`AbstractBlockTemplateStrategy` holds everything `templates` and `template-parts` share; the two concrete classes supply only `provider_slug()`, the WordPress post type, the theme sub-directory, and the `ThemeDeclaredSlugs` method they call.

| Member | `TemplatePromotionStrategy` | `TemplatePartPromotionStrategy` |
|---|---|---|
| `provider_slug()` | `templates` | `template-parts` |
| post type | `wp_template` | `wp_template_part` |
| `theme_relative_path()` | `templates/<slug>.html` | `parts/<slug>.html` (never nested — master spec §7.5) |
| `declares()` | `declares_template()` | `declares_template_part()` |

Shared behaviour in `AbstractBlockTemplateStrategy`:

- `post_finalize_record_state()` returns `'absent'`; `defers_expected_hash()` returns `false`.
- `theme_relative_path()` rejects a slug that does not match `/^[a-z0-9][a-z0-9_-]*$/`, so nothing can escape the theme directory.
- `stage( StateRecord $record, string $theme_root ): StagedPromotionEntry` creates a `PreparedFileWriter` for `$theme_root`, calls the writer's `stage()` with `theme_relative_path( $record->slug() )` and `$record->content()['markup']`, and returns that entry. It must not publish files itself. `PromotionPreparer`, not the template strategy, owns the all-record commit/discard decision. **This bullet said `prepare(…)` before the CORRECTION above; the inherited `prepare()` refuses.**
- `expected_post_reset_hash( StateRecord $record ): string` is read from the staged entry's `manifest_fields()['expectedPostResetHash']`, cached by canonical record key for the current prepare attempt. Calling it before `stage()` is a hard error.
- `resolve_current_hash( string $record_slug ): ?string` calls `get_block_template( get_stylesheet() . '//' . $record_slug, $this->post_type() )`, returns `null` when that is `null`, and otherwise `$gateway->hash_markup( $gateway->normalize_block_markup( (string) $template->content ) )`. The gateway strips the injected `theme` attribute on this side too, so it is comparable with `expected_post_reset_hash()`.
- `capture_backup( StateRecord $record ): array` returns
  ```php
  array(
      'post'  => get_post( $record->object_id(), ARRAY_A ),
      'terms' => wp_get_object_terms( $record->object_id(), 'wp_theme', array( 'fields' => 'slugs' ) ),
      'meta'  => get_post_meta( $record->object_id() ),
  )
  ```
  A template row without its `wp_theme` term is invisible to the resolver, so the terms are not optional.
- `reset( StateRecord $record ): void` calls `wp_delete_post( $record->object_id(), true )`; a falsy return, or a `get_post()` that still returns a row afterwards, throws `PromotionException::hard( 'Could not delete the database override for <key>.' )` so the finalizer refuses instead of restoring something that was never removed.
- `restore( StateRecord $record, array $backup ): void` implements the full restore described in Task 15 Step 3.
- `validate_for_promotion()` performs the three markup checks (parseable, every block registered, every referenced part present or selected) and returns their refusals; reference refusals come from Task 9 and are added by the preparer.

- [ ] **Step 3: Implement the registrar and subsystem**

```php
final class PromotionStrategyRegistrar {

	public function register(): void {
		add_filter( PromotionStrategies::FILTER, array( $this, 'add_strategies' ) );
	}

	/**
	 * @param array<string, PromotionStrategy> $strategies
	 * @return array<string, PromotionStrategy>
	 */
	public function add_strategies( array $strategies ): array {
		$strategies['templates']      = new TemplatePromotionStrategy();
		$strategies['template-parts'] = new TemplatePartPromotionStrategy();

		return $strategies;
	}
}
```

`PromotionSubsystem::register()` always calls `( new PromotionStrategyRegistrar() )->register()`, then registers `Cli\PromotionCommands` only when `defined( 'WP_CLI' ) && WP_CLI`. This mirrors Task 2's `StateSubsystem` and keeps `Plugin.php` at one line.

- [ ] **Step 4: Write and run the strategy test**

`BlockTemplateStrategyTest` asserts, against a real WordPress install and a temporary theme directory: `theme_relative_path( 'site-header' )` for the part strategy is `parts/site-header.html`; a slug of `../evil` throws; `capture_backup()` includes the `wp_theme` term and every meta key; `reset()` removes the row and a second `reset()` on the same id throws; `resolve_current_hash()` returns `null` for an unknown slug and a 64-character hash for a file-backed template.

Run: `ddev composer test:integration -- --filter "PromotionStrategyRegistrarTest|BlockTemplateStrategyTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/PreparablePromotionStrategy.php web/app/mu-plugins/agency-platform/src/State/Promotion/AbstractBlockTemplateStrategy.php web/app/mu-plugins/agency-platform/src/State/Promotion/TemplatePromotionStrategy.php web/app/mu-plugins/agency-platform/src/State/Promotion/TemplatePartPromotionStrategy.php web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionStrategyRegistrar.php web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionSubsystem.php tests/Integration/Promotion/PromotionStrategyRegistrarTest.php tests/Integration/Promotion/BlockTemplateStrategyTest.php
git commit -m "feat: add template promotion strategies and release allow-list"
```

---

### Task 8: Selector parsing gated by the strategy allow-list

**Files:**
- Create: `src/State/Promotion/PromotionSelector.php`
- Test: `tests/Unit/AgencyPlatform/Promotion/PromotionSelectorTest.php`

**Interfaces:**
- Consumes: `PromotionException` (Task 1).
- Produces:
  ```php
  final class PromotionSelector {
      /**
       * @param callable(string):bool $has_strategy   provider slug -> a preparable strategy is registered
       * @param callable(string):bool $has_record     canonical record key -> present in the bundle
       * @return list<array{provider:string, slug:string, key:string}> sorted by key
       */
      public static function parse( string $select, callable $has_strategy, callable $has_record ): array;
  }
  ```
  Call sites pass `static fn( string $slug ): bool => $gateway->strategy_for( $slug ) instanceof PreparablePromotionStrategy` and `static fn( string $key ): bool => null !== $bundle->record( $key )`. Injecting both as callables keeps this class WordPress-free and unit-testable.

- [ ] **Step 1: Write the failing selector test**

```php
public function test_it_parses_and_sorts_selectors(): void {
	$parsed = PromotionSelector::parse( 'templates:page,template-parts:site-header', $this->has_strategy, $this->has_record );

	self::assertSame( array( 'template-parts:site-header', 'templates:page' ), array_column( $parsed, 'key' ) );
}

public function test_a_provider_with_no_registered_strategy_is_a_hard_error(): void {
	// This is the Release 3 boundary in one assertion: global-styles is
	// promotable in Task 2 but has no strategy until Release 4.
	$this->assert_exit_code(
		1,
		fn() => PromotionSelector::parse( 'global-styles:active', $this->has_strategy, $this->has_record )
	);
}

public function test_an_unknown_provider_is_a_hard_error(): void { /* nope:page → exit 1 */ }

public function test_a_record_missing_from_the_bundle_is_a_hard_error(): void { /* templates:absent → exit 1 */ }

public function test_a_malformed_selector_is_a_hard_error(): void { /* 'templates' with no colon → exit 1 */ }

public function test_duplicate_selectors_collapse(): void {
	$parsed = PromotionSelector::parse( 'templates:page,templates:page', $this->has_strategy, $this->has_record );

	self::assertCount( 1, $parsed );
}
```

Run: `ddev composer test:unit -- --filter PromotionSelectorTest` → FAIL.

- [ ] **Step 2: Implement `PromotionSelector`**

Split on `,`, trim, reject empty items. Each item must match `/^([a-z0-9-]+):([a-z0-9_-]+)$/`, otherwise `PromotionException::hard( 'Selector "x" must be <provider>:<slug>.' )`. Then, in order:

1. `$has_strategy( $provider )` must be true, otherwise `hard( sprintf( 'No promotion strategy is registered for provider "%s". Templates and template parts are promotable in this release; Global Styles promotion arrives with its own release gate.', $provider ) )`.
2. `$has_record( "{$provider}:{$slug}" )` must be true, otherwise `hard( 'Record "x:y" is not in the state bundle.' )`.

Build `key` as `"{$provider}:{$slug}"`, deduplicate on `key`, `usort()` by `key`.

- [ ] **Step 3: Run and commit**

Run: `ddev composer test:unit -- --filter PromotionSelectorTest`
Expected: PASS.

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionSelector.php tests/Unit/AgencyPlatform/Promotion/PromotionSelectorTest.php
git commit -m "feat: gate promotion selectors on the strategy allow-list"
```

---

### Task 9: Navigation policy and reference refusal (§7.4)

Two things make this harder than it looks, and both are handled here:

1. **WordPress core does not treat several published navigations as ambiguous.** `WP_Navigation_Fallback` picks the MOST RECENTLY PUBLISHED `wp_navigation` post. A ref-less Navigation block therefore always resolves to exactly one thing; the question is whether it resolves to the SAME thing the export saw.
2. **Task 2's corrected exporter emits a navigation reference for every Navigation block.** A ref-less block carries the deterministic source fallback identity/hash with a null `ref`; an explicit block carries the referenced record identity/hash. This task scans markup as a count-and-order cross-check, so a malformed export cannot silently omit a block.

**Files:**
- Create: `src/State/Promotion/NavigationBlockScanner.php`
- Create: `src/State/Promotion/NavigationPolicy.php`
- Create: `src/State/Promotion/ReferenceRefusalPolicy.php`
- Test: `tests/Unit/AgencyPlatform/Promotion/NavigationBlockScannerTest.php`
- Test: `tests/Unit/AgencyPlatform/Promotion/NavigationPolicyTest.php`
- Test: `tests/Unit/AgencyPlatform/Promotion/ReferenceRefusalPolicyTest.php`
- Test: `tests/Integration/Promotion/NavigationResolutionTest.php`

**Interfaces:**
- Consumes: `RecordRefusal` (Task 1); Task 2's reference report through `StateGateway::scan_references()`, whose entries carry `record, provider, blockName, attribute, value, kind, resolution, policy, targetKey, targetHash, targetIdentity`.
- Produces:
  ```php
  final class NavigationBlockScanner {
      /** Pure: every core/navigation block in the markup, in document order.
       *  @param list<array<string,mixed>> $blocks parse_blocks() output
       *  @return list<array{ref:int|null}> */
      public static function scan_parsed( array $blocks ): array;
      /** @return list<array{ref:int|null}> */
      public static function scan( string $markup ): array;   // WordPress-coupled: parse_blocks()
  }

  final class NavigationPolicy {
      /**
       * Prepare side. Pure.
       *
       * @param list<array{ref:int|null}>       $navigation_blocks every core/navigation block in the record
       * @param list<array<string,mixed>>       $references        Task 2's scan report for this record
       * @return array{refusals: list<RecordRefusal>,
       *               navigationExpectation: list<array{originalRef:int|null,
       *                   exportedNavigationHash:string, exportedNavigationIdentity:array<string,mixed>|null}>}
       */
      public static function evaluate( string $record_key, string $provider, string $slug,
          array $navigation_blocks, array $references ): array;

      /**
       * Finalize side. Pure comparison against an already-resolved target hash.
       *
       * @param list<array<string,mixed>> $expectation the manifest's navigationExpectation
       * @return RecordRefusal|null null when the record may proceed
       */
      public static function verify_target( string $record_key, string $provider, string $slug,
          array $expectation, ?string $resolved_target_hash, ?array $resolved_identity ): ?RecordRefusal;
  }

  final class ReferenceRefusalPolicy {
      /** Every reference kind EXCEPT navigation. Navigation is NavigationPolicy's job.
       *  @param list<array<string,mixed>> $references
       *  @return list<RecordRefusal> */
      public static function evaluate( string $record_key, string $provider, string $slug, array $references ): array;
  }
  ```

- [ ] **Step 1: Write the failing scanner test**

```php
public function test_it_finds_a_ref_less_navigation_block(): void {
	self::assertSame(
		array( array( 'ref' => null ) ),
		NavigationBlockScanner::scan_parsed(
			array( array( 'blockName' => 'core/navigation', 'attrs' => array(), 'innerBlocks' => array() ) )
		)
	);
}

public function test_it_finds_nested_navigation_blocks_with_their_refs(): void {
	$blocks = array(
		array(
			'blockName'   => 'core/group',
			'attrs'       => array(),
			'innerBlocks' => array(
				array( 'blockName' => 'core/navigation', 'attrs' => array( 'ref' => 12 ), 'innerBlocks' => array() ),
				array( 'blockName' => 'core/navigation', 'attrs' => array( 'ref' => 13 ), 'innerBlocks' => array() ),
			),
		),
	);

	self::assertSame( array( array( 'ref' => 12 ), array( 'ref' => 13 ) ), NavigationBlockScanner::scan_parsed( $blocks ) );
}
```

- [ ] **Step 2: Write the failing navigation policy tests**

```php
public function test_a_single_ref_records_the_exported_hash(): void {
	$outcome = NavigationPolicy::evaluate(
		'templates:page', 'templates', 'page',
		array( array( 'ref' => 12 ) ),
		array( $this->navigation_reference( 12, 'abc', array( 'slug' => 'primary' ) ) )
	);

	self::assertSame( array(), $outcome['refusals'] );
	self::assertSame( 'abc', $outcome['navigationExpectation'][0]['exportedNavigationHash'] );
	self::assertSame( 12, $outcome['navigationExpectation'][0]['originalRef'] );
}

public function test_a_ref_less_source_block_records_the_exported_fallback_hash(): void {
	$outcome = NavigationPolicy::evaluate(
		'templates:page', 'templates', 'page', array( array( 'ref' => null ) ),
		array( $this->navigation_reference( null, 'fallback-hash', array( 'slug' => 'primary' ) ) )
	);

	self::assertSame( array(), $outcome['refusals'] );
	self::assertSame( null, $outcome['navigationExpectation'][0]['originalRef'] );
	self::assertSame( 'fallback-hash', $outcome['navigationExpectation'][0]['exportedNavigationHash'] );
}

public function test_a_ref_less_source_block_with_no_exported_fallback_is_refused(): void {
	$outcome = NavigationPolicy::evaluate( 'templates:page', 'templates', 'page', array( array( 'ref' => null ) ), array() );

	self::assertSame( 'unresolved-navigation-ref', $outcome['refusals'][0]->reason_code );
}

public function test_two_refs_to_different_menus_cannot_stay_equivalent(): void {
	// Removing both refs makes both blocks resolve to the SAME fallback, which
	// silently merges two different menus.
	$outcome = NavigationPolicy::evaluate(
		'templates:page', 'templates', 'page',
		array( array( 'ref' => 12 ), array( 'ref' => 13 ) ),
		array(
			$this->navigation_reference( 12, 'abc', array( 'slug' => 'primary' ) ),
			$this->navigation_reference( 13, 'def', array( 'slug' => 'footer' ) ),
		)
	);

	self::assertSame( 'navigation-refs-not-equivalent', $outcome['refusals'][0]->reason_code );
}

public function test_two_refs_to_the_same_content_are_allowed(): void {
	$outcome = NavigationPolicy::evaluate(
		'templates:page', 'templates', 'page',
		array( array( 'ref' => 12 ), array( 'ref' => 13 ) ),
		array(
			$this->navigation_reference( 12, 'abc', array( 'slug' => 'primary' ) ),
			$this->navigation_reference( 13, 'abc', array( 'slug' => 'primary-copy' ) ),
		)
	);

	self::assertSame( array(), $outcome['refusals'] );
	self::assertCount( 1, $outcome['navigationExpectation'] );
}

public function test_a_ref_with_no_exported_target_hash_is_refused(): void {
	$outcome = NavigationPolicy::evaluate(
		'templates:page', 'templates', 'page',
		array( array( 'ref' => 12 ) ),
		array( $this->navigation_reference( 12, null, null ) )
	);

	self::assertSame( 'unresolved-navigation-ref', $outcome['refusals'][0]->reason_code );
}

public function test_verify_target_refuses_when_nothing_resolves(): void {
	$refusal = NavigationPolicy::verify_target( 'templates:page', 'templates', 'page', $this->expectation( 'abc' ), null, null );

	self::assertSame( 'missing-navigation', $refusal->reason_code );
}

public function test_verify_target_refuses_a_content_mismatch(): void {
	$refusal = NavigationPolicy::verify_target( 'templates:page', 'templates', 'page', $this->expectation( 'abc' ), 'zzz', array( 'slug' => 'other' ) );

	self::assertSame( 'navigation-content-mismatch', $refusal->reason_code );
}

public function test_verify_target_accepts_a_hash_match(): void {
	self::assertNull(
		NavigationPolicy::verify_target( 'templates:page', 'templates', 'page', $this->expectation( 'abc' ), 'abc', array( 'slug' => 'primary' ) )
	);
}
```

- [ ] **Step 3: Write the failing reference-refusal tests**

```php
public function test_an_attachment_reference_is_refused(): void {
	$refusals = ReferenceRefusalPolicy::evaluate(
		'templates:page', 'templates', 'page',
		array( $this->reference( ReferenceScanner::KIND_ATTACHMENT, 'core/image', 'id', 42 ) )
	);

	self::assertCount( 1, $refusals );
	self::assertSame( 'unresolved-reference', $refusals[0]->reason_code );
	self::assertSame( 'core/image', $refusals[0]->block_name );
	self::assertSame( '42', $refusals[0]->referenced_value );
	self::assertNotSame( '', (string) $refusals[0]->suggested_policy );
}

public function test_a_synced_pattern_reference_is_refused(): void { /* KIND_SYNCED_PATTERN → unresolved-reference */ }

public function test_a_site_logo_reference_carries_the_real_id(): void {
	$refusals = ReferenceRefusalPolicy::evaluate(
		'template-parts:site-header', 'template-parts', 'site-header',
		array( $this->reference( ReferenceScanner::KIND_SITE_LOGO, 'core/site-logo', 'siteLogo', 7 ) )
	);

	self::assertSame( '7', $refusals[0]->referenced_value );
}

public function test_a_resolved_reference_is_not_refused(): void {
	$reference               = $this->reference( ReferenceScanner::KIND_ATTACHMENT, 'core/image', 'id', 42 );
	$reference['resolution'] = ReferenceScanner::RESOLUTION_RESOLVED;

	self::assertSame( array(), ReferenceRefusalPolicy::evaluate( 'templates:page', 'templates', 'page', array( $reference ) ) );
}

public function test_navigation_references_are_ignored_here(): void {
	self::assertSame(
		array(),
		ReferenceRefusalPolicy::evaluate(
			'templates:page', 'templates', 'page',
			array( $this->reference( ReferenceScanner::KIND_NAVIGATION, 'core/navigation', 'ref', 12 ) )
		)
	);
}
```

Run: `ddev composer test:unit -- --filter "NavigationBlockScannerTest|NavigationPolicyTest|ReferenceRefusalPolicyTest"`
Expected: FAIL — classes not found.

- [ ] **Step 4: Implement the three classes**

`NavigationBlockScanner::scan_parsed()` walks `blockName`/`innerBlocks` recursively and returns `array( 'ref' => is_int( $attrs['ref'] ?? null ) ? $attrs['ref'] : null )` for every `core/navigation` block, in document order. `scan()` is `scan_parsed( parse_blocks( $markup ) )`.

`NavigationPolicy::evaluate()`:

1. Keep the navigation references from Task 2's corrected report in document order. Match each scanned block to one reference. The `value` is an integer for an explicit reference and `null` for a source ref-less fallback reference.
2. `array() === $navigation_blocks` returns an empty result — nothing to check.
3. A scanned block with no matching report entry, or an entry with a `null` `targetHash`, produces refusal `unresolved-navigation-ref`. This includes a ref-less source block when export could not resolve one deterministic fallback.
4. Collect the distinct `targetHash` values. More than one distinct value produces refusal `navigation-refs-not-equivalent`, detail: `'This record points at <n> different navigations. Removing every ref would make them all resolve to the same fallback menu on the target. Promote a record with one navigation, or keep this one database-owned.'`
5. Otherwise return exactly one `navigationExpectation` entry built from the first block's `ref` (possibly null), its `targetHash`, and its `targetIdentity`.

`NavigationPolicy::verify_target()`: `array() === $expectation` returns `null`. A `null` `$resolved_target_hash` produces `missing-navigation`. A hash that differs from `$expectation[0]['exportedNavigationHash']` produces `navigation-content-mismatch`, with `referenced_value` set to the resolved identity's slug for a readable report. Equal hashes return `null`. Therefore both a source ref-less block and a block whose explicit `ref` was removed proceed only when the target's core fallback resolves the exported source hash.

`ReferenceRefusalPolicy::evaluate()` skips `ReferenceScanner::KIND_NAVIGATION` entries and any entry where `ReferenceScanner::RESOLUTION_RESOLVED === $reference['resolution']`, and turns every other entry into a `RecordRefusal` with `reason_code = 'unresolved-reference'`, `block_name`/`attribute`/`referenced_value` copied from the reference, `detail` naming the kind, and `suggested_policy` taken from the reference's own `policy` string (Task 2 already writes §7.4's "suggested policy or required mapping" text there) with a fallback of `'This reference is environment-specific. Keep the record database-owned, or replace the reference with a theme asset before promoting.'`

- [ ] **Step 5: Write the target-side resolution integration test**

`tests/Integration/Promotion/NavigationResolutionTest.php` proves the target-side resolution matches WordPress core:

```php
public function test_the_target_fallback_is_the_most_recently_published_navigation(): void {
	$older = $this->create_navigation( 'primary', '2026-01-01 00:00:00' );
	$newer = $this->create_navigation( 'secondary', '2026-06-01 00:00:00' );

	self::assertSame( $newer, $this->finalizer_navigation_fallback_id() );
}

public function test_a_draft_navigation_is_never_the_fallback(): void { /* draft newer than the published one is ignored */ }

public function test_the_resolved_hash_uses_the_navigation_provider_convention(): void {
	// The target hash must be read from Task 2's own navigation records, so the
	// comparison never depends on guessing that provider's content shape.
	$id = $this->create_navigation( 'primary', '2026-01-01 00:00:00' );

	self::assertSame(
		$this->navigation_record_content_hash( $id ),
		$this->finalizer_navigation_fallback_hash()
	);
}
```

The helper under test is a private method on `PromotionFinalizer` (Task 14) exposed through a small named public method `resolve_navigation_fallback(): array{id:int|null, hash:string|null, identity:array|null}`. Implement it here so this test can drive it:

```php
public function resolve_navigation_fallback(): array {
	$posts = get_posts(
		array(
			'post_type'      => 'wp_navigation',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);

	if ( array() === $posts ) {
		return array( 'id' => null, 'hash' => null, 'identity' => null );
	}

	// Read the hash through Task 2's own navigation provider so the comparison
	// uses that provider's content convention rather than a guess.
	foreach ( $this->gateway->live_records( 'navigation' ) as $record ) {
		if ( (int) ( $record['objectId'] ?? 0 ) === $posts[0]->ID ) {
			return array(
				'id'       => $posts[0]->ID,
				'hash'     => (string) $record['contentHash'],
				'identity' => array( 'slug' => $record['slug'], 'status' => $record['status'] ),
			);
		}
	}

	return array( 'id' => $posts[0]->ID, 'hash' => null, 'identity' => null );
}
```

- [ ] **Step 6: Run and commit**

Run: `ddev composer test:unit -- --filter "NavigationBlockScannerTest|NavigationPolicyTest|ReferenceRefusalPolicyTest" && ddev composer test:integration -- --filter NavigationResolutionTest`
Expected: PASS.

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/NavigationBlockScanner.php web/app/mu-plugins/agency-platform/src/State/Promotion/NavigationPolicy.php web/app/mu-plugins/agency-platform/src/State/Promotion/ReferenceRefusalPolicy.php tests/Unit/AgencyPlatform/Promotion/NavigationBlockScannerTest.php tests/Unit/AgencyPlatform/Promotion/NavigationPolicyTest.php tests/Unit/AgencyPlatform/Promotion/ReferenceRefusalPolicyTest.php tests/Integration/Promotion/NavigationResolutionTest.php
git commit -m "feat: enforce the v1 navigation and reference refusal policy"
```

---

### Task 10: `PromotionPreparer` (§7.5)

**Files:**
- Create: `src/State/Promotion/PromotionPreparer.php`
- Test: `tests/Integration/Promotion/PromotionPreparerTest.php`

**Interfaces:**
- Consumes: everything produced by Tasks 1–9.
- Produces:
  ```php
  final class PromotionPreparer {
      public function __construct(
          private StateGateway $gateway,
          private ManifestStore $store,
          private GitRepository $git,
          private PrepareLock $lock
      ) {}

      /**
       * @param array{source:string, select:string, manifest:string} $arguments
       * @return array{manifest: PromotionManifest, outcome: PromotionOutcome}
       */
      public function prepare( array $arguments ): array;
  }
  ```

- [ ] **Step 1: Write the failing run-level refusal tests**

`set_up()` writes a signed bundle fixture, points `AGENCY_STATE_DIR` at a temp directory, sets `AGENCY_TARGET_SITE_UUID` to the bundle's `siteUuid`, creates a throwaway git repository (`git init`, one commit) holding a copy of `templates/`, `parts/` and `theme.json`, filters `stylesheet_directory` to that copy with a NAMED callback, and registers the Task 7 strategies.

```php
public function test_prepare_refuses_to_run_in_production(): void {
	$this->with_environment( 'production', function (): void {
		$this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );
	} );
}

public function test_prepare_refuses_a_target_site_uuid_mismatch(): void {
	putenv( 'AGENCY_TARGET_SITE_UUID=00000000-0000-4000-8000-000000000000' );

	$this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );
}

public function test_prepare_refuses_a_detached_head_without_the_opt_in(): void {
	$this->detach_head();

	$this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );

	putenv( 'AGENCY_ALLOW_DETACHED_HEAD=1' );

	self::assertSame( 0, $this->preparer->prepare( $this->arguments() )['outcome']->exit_code() );
}

public function test_prepare_refuses_an_unexpected_branch(): void { /* AGENCY_EXPECTED_BRANCH=release → exit 1 */ }

public function test_prepare_refuses_an_undeclared_custom_template_slug(): void { /* templates:landing → exit 1 */ }

public function test_prepare_refuses_an_unrelated_dirty_file(): void {
	file_put_contents( $this->repo_root . '/README.md', 'dirty' );

	$this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );
}

public function test_prepare_allows_a_dirty_tree_when_every_change_matches_a_recorded_hash(): void {
	$first = $this->preparer->prepare( $this->arguments() );

	// The prepared file is now a working-tree modification whose bytes match
	// the recorded preparedFileHash, so a re-run must not false-refuse.
	$second = $this->preparer->prepare( $this->arguments() );

	self::assertSame( 0, $second['outcome']->exit_code() );
	self::assertSame(
		$first['manifest']->record( 'templates:page' )['preparedFileHash'],
		$second['manifest']->record( 'templates:page' )['preparedFileHash']
	);
}

public function test_prepare_refuses_a_prepared_file_that_was_edited_by_hand(): void {
	$this->preparer->prepare( $this->arguments() );
	file_put_contents( $this->prepared_file, "<!-- wp:paragraph --><p>hand edit</p><!-- /wp:paragraph -->\n" );

	// The path is on the allow-list, but its bytes match neither the recorded
	// prepared hash nor the recorded original hash.
	$this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );
	self::assertStringContainsString( 'hand edit', file_get_contents( $this->prepared_file ) );
}

public function test_prepare_refuses_a_sealed_manifest(): void {
	$this->seal_the_manifest();

	$this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments() ) );
}

public function test_prepare_refuses_a_finalized_manifest(): void { /* finalizeStatus != pending → exit 1 */ }

public function test_prepare_refuses_a_re_run_against_a_different_bundle(): void { /* changed exportId → exit 1 */ }

public function test_a_tampered_bundle_exits_four_before_any_file_is_written(): void {
	$this->tamper_with_bundle();

	$this->assert_exit_code( 4, fn() => $this->preparer->prepare( $this->arguments() ) );
	self::assertSame( $this->original_template_body, file_get_contents( $this->template_path ) );
}

public function test_a_failure_after_staging_leaves_no_partial_file(): void {
	$this->make_parts_directory_read_only();

	$this->assert_exit_code( 1, fn() => $this->preparer->prepare( $this->arguments( 'template-parts:site-header,templates:page' ) ) );
	self::assertSame( $this->original_template_body, file_get_contents( $this->template_path ) );
	self::assertSame( array(), glob( $this->theme_dir . '/templates/*.tmp' ) );
}

public function test_prepare_calls_the_selected_strategy_and_uses_its_staged_entry(): void {
	$strategy = new RecordingPreparableStrategy( $this->real_template_strategy() );
	$this->register_strategy( 'templates', $strategy );

	$this->preparer->prepare( $this->arguments( 'templates:page' ) );

	self::assertSame( array( 'templates:page' ), $strategy->prepared_keys() );
	self::assertTrue( $strategy->staged_entry_was_committed() );
}
```

Run: `ddev composer test:integration -- --filter PromotionPreparerTest` → FAIL.

- [ ] **Step 2: Implement the run-level guard sequence**

In this exact order, throwing `PromotionException` on each failure:

1. `'production' === wp_get_environment_type()` → `hard( '--prepare is a local operation and must never run in production.' )`.
2. `$this->lock->acquire()` (exit 3 on conflict). Wrap everything below in `try { … } finally { $this->lock->release(); }`.
3. `$bundle = $this->gateway->load_bundle( $arguments['source'] );` — a bad signature surfaces as exit 4.
4. `$target_uuid = EnvironmentConfig::get( 'AGENCY_TARGET_SITE_UUID' );` — null or empty is `hard()`; `$target_uuid !== $bundle->site_uuid()` is `hard()` naming both values.
5. `$branch = $this->git->current_branch();` — `null === $branch && ! PromotionSettings::flag( 'AGENCY_ALLOW_DETACHED_HEAD' )` is `hard()`; an `AGENCY_EXPECTED_BRANCH` that does not equal `$branch` is `hard()`.
6. `$existing = ( '-' !== $arguments['manifest'] && file_exists( $arguments['manifest'] ) ) ? $this->store->load( $arguments['manifest'] ) : null;` Then, when `$existing` is non-null:
   - `$existing->is_sealed()` → `hard( 'Manifest <path> is already sealed to <sha>. Start a new promotion instead of re-preparing a sealed one.' )`.
   - `'pending' !== $existing->finalize_status()` → `hard( 'Manifest <path> has already been finalized.' )`.
   - `$existing->export_id() !== $bundle->export_id()` → `hard( 'The existing manifest was prepared from a different export.' )`.
7. **Dirty-tree check with hash verification.** Build `$allowed` as a map of `preparedFilePath => array( preparedFileHash, originalFileHash )` from `$existing`. For each path in `$this->git->dirty_paths()`:
   - not in `$allowed` → `hard()` listing the offending paths;
   - in `$allowed` but its current sha256 matches NEITHER the recorded `preparedFileHash` NOR the recorded `originalFileHash` → `hard( 'Prepared file <path> was modified outside the promotion. Restore it or start a new promotion.' )`.
   This is what stops a hand-edited prepared file from being silently overwritten.
8. `$selection = PromotionSelector::parse( $arguments['select'], $has_strategy, $has_record );`
9. `$declared = ThemeDeclaredSlugs::from_file( get_stylesheet_directory() . '/theme.json' );` — the first selector whose strategy's `declares()` is false is `hard( 'Slug "x" is not declared in theme.json; v1 only promotes files already declared in Git.' )`.

- [ ] **Step 3: Implement the per-record staging loop**

**Corrected strategy-owned staging rule.** The direct `PreparedFileWriter::stage()` call described below is superseded. For each selected record, call `$entry = $strategy->prepare( $state_record, get_stylesheet_directory() );`, require `$entry instanceof StagedPromotionEntry`, and store it in `$staged`. The preparer must never stage a file directly. Read `preparedFilePath`, `themeRelativePath`, `preparedFileHash`, `originalFileHash`, and `expectedPostResetHash` from `$entry->manifest_fields()` when building the record. This makes each selected strategy's `prepare()` method mandatory and gives the preparation run one common commit/discard contract.

For each selection entry, in sorted key order, collecting into `$staged`, `$records`, `$outcomes` and `$refusals`:

1. `$record = $bundle->record( $key );` and `$state_record = $bundle->state_record( $key );`
2. **Idempotent skip.** When `$existing` holds this record, its on-disk file's sha256 equals the recorded `preparedFileHash`, AND `$record['contentHash']` equals the recorded `originalContentHash`, carry the existing manifest record forward with outcome `skipped` and stage nothing.
3. `$strategy_refusals = $strategy->validate_for_promotion( $record, $bundle, $selected_keys );` — markup parses, every block name is registered (`WP_Block_Type_Registry::get_instance()->is_registered()`), every `core/template-part` slug resolves to a file under `parts/` or is itself selected. Any refusal ends this record.
4. `$navigation = NavigationPolicy::evaluate( $key, $provider, $slug, NavigationBlockScanner::scan( $markup ), $record['references'] );` — any refusal ends this record.
5. `$reference_refusals = ReferenceRefusalPolicy::evaluate( $key, $provider, $slug, $record['references'] );` — any refusal ends this record.
6. `$entry = $writer->stage( $strategy->theme_relative_path( $slug ), $markup, $promotion_id );` — staged only, not renamed.
7. Build the manifest record with every schema field: `key`, `provider`, `slug`, `objectId` from `$record['objectId']`, `originalContentHash` from `$record['contentHash']`, `originalModifiedGmt` from `$record['modifiedGmt']`, `preparedFilePath` from `$this->git->relative_path( $entry['absolutePath'] )`, `themeRelativePath`, `preparedFileHash`, `originalFileHash`, `referenceScan` from `$record['references']`, `navigationExpectation`, `expectedPostResetHash` (`null` when `$strategy->defers_expected_hash()`), `preResetResolvedHash` `null`, `postFinalizeRecordState` `null`, `postFinalizeSemanticHash` `null`, `postFinalizeModifiedGmt` `null`, `finalizeStatus` `'pending'`, `finalizeRefusalReason` `null`, `rollbackStatus` `'not-attempted'`, `rollbackRefusalReason` `null`, `restoredObjectId` `null`. Outcome `prepared`.

- [ ] **Step 4: Implement the durable commit sequence**

**Corrected commit rule.** The writer-owned `commit_all()`/`discard_all()` wording below is superseded for this lifecycle. The preparer calls `commit()` for each strategy-owned `StagedPromotionEntry` in selection order. If one commit fails, it calls `rollback_committed()` for every entry committed by this attempt and `discard()` for every entry not committed. Any exception after staging and before a full commit calls `discard()` for all uncommitted entries before it is rethrown. No temporary file remains.

Order matters — a crash must always leave a state a re-run can recover from:

1. Assemble the manifest (`PromotionManifest::create( wp_generate_uuid4(), gmdate( 'Y-m-d\TH:i:s\Z' ), $bundle->header(), $target_uuid, $this->git->head_commit(), PromotionSettings::verification_commands() )`, reusing `$existing->promotion_id()` on a re-run), attach every record and refusal.
2. **Save the manifest FIRST** (`$this->store->write()` for a path; nothing is written for `-`, whose document the command layer emits). A manifest that names files not yet on disk is recoverable: the re-run's dirty check accepts a file matching either the prepared or the original hash, and the idempotent skip re-writes anything that does not match.
3. `$writer->commit_all( $staged );` — the two-phase rename. On failure it restores whatever it already renamed and throws.
4. On any exception between steps 2 and 3, call `$writer->discard_all( $staged )` in a `catch` before rethrowing, so no `.tmp` residue survives.
5. `Logger::log( 'promotion', 'prepared', array( 'promotionId' => …, 'records' => …, 'refusals' => … ) );`
6. Return `array( 'manifest' => $manifest, 'outcome' => new PromotionOutcome( $outcomes, $refusals ) )`.

- [ ] **Step 5: Write the per-record refusal tests and run everything**

Add: a two-record selection where one has an unresolved attachment reference exits 2 and writes only the clean record's file; a selection where every record is refused exits 1; a refused record leaves no file and no `.tmp` residue.

Run: `ddev composer test:integration -- --filter PromotionPreparerTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionPreparer.php tests/Integration/Promotion/PromotionPreparerTest.php
git commit -m "feat: add promotion prepare with refusal matrix"
```

---

### Task 11: `PromotionSealer` (§7.7)

**Files:**
- Create: `src/State/Promotion/PromotionSealer.php`
- Test: `tests/Integration/Promotion/PromotionSealerTest.php`

**Interfaces:**
- Consumes: `ManifestStore` (Task 4), `GitRepository` (Task 5), `PromotionManifest` (Task 2), `PromotionException` (Task 1).
- Produces:
  ```php
  final class PromotionSealer {
      public function __construct( private ManifestStore $store, private GitRepository $git ) {}
      public function seal( string $manifest_path_or_dash, string $deploy_commit ): PromotionManifest;
  }
  ```

- [ ] **Step 1: Write the failing sealer test**

```php
public function test_seal_binds_the_deploy_commit_and_re_signs(): void {
	$sealed = $this->sealer->seal( $this->manifest_path, $this->commit_sha );

	self::assertSame( $this->commit_sha, $sealed->deploy_commit() );
	self::assertTrue( $sealed->is_sealed() );
	// Loading re-verifies the signature, so this proves it was re-signed.
	self::assertSame( $this->commit_sha, $this->store->load( $this->manifest_path )->deploy_commit() );
}

public function test_seal_exits_four_when_a_prepared_file_changed_after_prepare(): void {
	file_put_contents( $this->prepared_file, "<!-- wp:paragraph --><p>tampered</p><!-- /wp:paragraph -->\n" );

	$this->assert_exit_code( 4, fn() => $this->sealer->seal( $this->manifest_path, $this->commit_sha ) );
}

public function test_seal_exits_one_when_the_prepared_file_is_not_in_that_commit(): void {
	$this->assert_exit_code( 1, fn() => $this->sealer->seal( $this->manifest_path, $this->earlier_commit_sha ) );
}

public function test_seal_is_idempotent_for_the_same_commit(): void {
	$this->sealer->seal( $this->manifest_path, $this->commit_sha );

	self::assertSame( $this->commit_sha, $this->sealer->seal( $this->manifest_path, $this->commit_sha )->deploy_commit() );
}

public function test_seal_refuses_a_second_different_commit(): void {
	$this->sealer->seal( $this->manifest_path, $this->commit_sha );

	$this->assert_exit_code( 1, fn() => $this->sealer->seal( $this->manifest_path, $this->other_commit_sha ) );
}

public function test_seal_refuses_a_malformed_sha(): void { /* 'HEAD' → exit 1 */ }
```

Run: `ddev composer test:integration -- --filter PromotionSealerTest` → FAIL.

- [ ] **Step 2: Implement `PromotionSealer::seal()`**

1. `$manifest = $this->store->load( $path );` (signature then schema; tamper gives exit 4).
2. `$sha` must match `/^[0-9a-f]{40}$/`, otherwise `hard()`. `$this->git->commit_exists( $sha )` must be true, otherwise `hard()`.
3. Already sealed: the same SHA continues (idempotent); a different SHA is `hard( 'Manifest is already sealed to <other>.' )`.
4. For every record read `$this->git->absolute_path( $record['preparedFilePath'] )`; a missing file or a sha256 that differs from `preparedFileHash` is `PromotionException::tamper()`.
5. For every record `$this->git->file_at_commit( $sha, $record['preparedFilePath'] )` must be non-null and hash to `preparedFileHash`, otherwise `hard( 'Prepared file <path> is not committed in <sha>. Commit the prepared files before sealing.' )`.
6. `$manifest = $manifest->with_deploy_commit( $sha, gmdate( 'Y-m-d\TH:i:s\Z' ) );`
7. For a real path, `$this->store->write( $manifest, $path )` — the atomic temp-write-plus-rename from Task 4 on the SAME path (master spec §7.7). For `-`, return the manifest and let the command layer emit `render()`'s output.
8. `Logger::log( 'promotion', 'sealed', array( 'promotionId' => …, 'deployCommit' => $sha ) );`

- [ ] **Step 3: Run and commit**

Run: `ddev composer test:integration -- --filter PromotionSealerTest`
Expected: PASS.

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionSealer.php tests/Integration/Promotion/PromotionSealerTest.php
git commit -m "feat: add the promotion seal step"
```

---

### Task 12: Per-record-key locking with a mutation mutex and heartbeat (§7.10)

**Files:**
- Create: `src/State/Promotion/RecordLockManager.php`
- Test: `tests/Integration/Promotion/RecordLockManagerTest.php`

**Interfaces:**
- Consumes: `PromotionSettings`, `PromotionException` (Task 1).
- Produces:
  ```php
  final class RecordLockManager {
      public const OPTION_PREFIX = 'agency_promotion_lock_';
      public const MUTEX_PREFIX  = 'agency_promotion_lock_mutex_';

      public function __construct( private string $promotion_id, private string $owner ) {}

      /** Sorted acquisition. Re-enters locks this promotion already owns. On the
       *  first conflict, releases every lock acquired by THIS attempt and throws
       *  PromotionException::lock_conflict().
       *  @param list<string> $record_keys canonical "provider:slug" keys */
      public function acquire( array $record_keys ): void;
      /** Releases only locks this promotion owns. Safe to call twice.
       *  @param list<string> $record_keys */
      public function release( array $record_keys ): void;
      /** Refreshes expiry. Throws lock_conflict() when a lock was lost.
       *  @param list<string> $record_keys */
      public function heartbeat( array $record_keys ): void;
      /** @return array{promotionId:string, startedAtUtc:string, owner:string, expiresAtUtc:string, recordKey:string}|null */
      public function inspect( string $record_key ): ?array;
      public static function option_name( string $record_key ): string;   // OPTION_PREFIX . hash('sha256', $key)
      public static function mutex_name( string $record_key ): string;
  }
  ```

- [ ] **Step 1: Write the failing locking tests**

```php
public function test_locks_are_acquired_in_sorted_key_order(): void {
	( new RecordLockManager( $this->promotion_id, 'deploy-1' ) )
		->acquire( array( 'templates:page', 'template-parts:site-header' ) );

	$other = new RecordLockManager( wp_generate_uuid4(), 'deploy-2' );

	$this->assert_exit_code( 3, fn() => $other->acquire( array( 'template-parts:site-header' ) ) );
}

public function test_a_conflicting_attempt_releases_its_own_locks_before_exiting_three(): void {
	( new RecordLockManager( wp_generate_uuid4(), 'deploy-1' ) )->acquire( array( 'templates:page' ) );

	$second = new RecordLockManager( wp_generate_uuid4(), 'deploy-2' );
	$this->assert_exit_code( 3, fn() => $second->acquire( array( 'template-parts:site-header', 'templates:page' ) ) );

	self::assertNull( $second->inspect( 'template-parts:site-header' ) );
}

public function test_the_same_promotion_re_enters_its_own_locks(): void {
	$manager = new RecordLockManager( $this->promotion_id, 'deploy-1' );
	$manager->acquire( array( 'templates:page' ) );
	$manager->acquire( array( 'templates:page' ) );

	self::assertSame( $this->promotion_id, $manager->inspect( 'templates:page' )['promotionId'] );
}

public function test_an_expired_lock_is_reclaimable_and_its_stale_metadata_is_replaced(): void {
	putenv( 'AGENCY_PROMOTION_LOCK_TTL=1' );
	( new RecordLockManager( wp_generate_uuid4(), 'abandoned' ) )->acquire( array( 'templates:page' ) );
	$this->age_lock( 'templates:page', 5 );

	$fresh = new RecordLockManager( $this->promotion_id, 'deploy-2' );
	$fresh->acquire( array( 'templates:page' ) );

	self::assertSame( $this->promotion_id, $fresh->inspect( 'templates:page' )['promotionId'] );
}

public function test_an_abandoned_heartbeat_cannot_steal_a_reclaimed_lock(): void {
	// The race the mutex closes: the old owner reads its metadata, a new
	// promotion reclaims the expired gate, and the old heartbeat writes back.
	putenv( 'AGENCY_PROMOTION_LOCK_TTL=1' );
	$old = new RecordLockManager( wp_generate_uuid4(), 'abandoned' );
	$old->acquire( array( 'templates:page' ) );
	$this->age_lock( 'templates:page', 5 );

	$new = new RecordLockManager( $this->promotion_id, 'deploy-2' );
	$new->acquire( array( 'templates:page' ) );

	$this->assert_exit_code( 3, fn() => $old->heartbeat( array( 'templates:page' ) ) );
	self::assertSame( $this->promotion_id, $new->inspect( 'templates:page' )['promotionId'] );
}

public function test_heartbeat_refreshes_a_held_lock(): void {
	putenv( 'AGENCY_PROMOTION_LOCK_TTL=60' );
	$manager = new RecordLockManager( $this->promotion_id, 'deploy-1' );
	$manager->acquire( array( 'templates:page' ) );
	$before = $manager->inspect( 'templates:page' )['expiresAtUtc'];
	$this->age_lock( 'templates:page', 30 );

	$manager->heartbeat( array( 'templates:page' ) );

	self::assertGreaterThan( $before, $manager->inspect( 'templates:page' )['expiresAtUtc'] );
}

public function test_release_removes_both_options_and_never_touches_another_owner(): void {
	$mine = new RecordLockManager( $this->promotion_id, 'deploy-1' );
	$mine->acquire( array( 'templates:page' ) );

	$stranger = new RecordLockManager( wp_generate_uuid4(), 'deploy-2' );
	$stranger->release( array( 'templates:page' ) );

	self::assertSame( $this->promotion_id, $mine->inspect( 'templates:page' )['promotionId'] );

	$mine->release( array( 'templates:page' ) );

	self::assertNull( $mine->inspect( 'templates:page' ) );
	self::assertFalse( get_option( RecordLockManager::option_name( 'templates:page' ) . '.lock' ) );
}

public function test_a_missing_core_lock_api_fails_closed(): void { /* probe returns false → exit 1, no lock created */ }
```

`age_lock()` rewrites the gate option's timestamp to `time() - $seconds` with `update_option()`.

Run: `ddev composer test:integration -- --filter RecordLockManagerTest` → FAIL.

- [ ] **Step 2: Implement the atomic gate and the mutation mutex**

`add_option()` is check-then-act and genuinely races. Master spec §11.11 forbids direct SQL, and WordPress core's own answer to this exact problem is `WP_Upgrader::create_lock()` / `release_lock()`, which perform an `INSERT IGNORE` and honour a release timeout. Use them, and fail closed when they are absent:

```php
private function require_core_lock_api(): void {
	if ( ! class_exists( 'WP_Upgrader' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	if ( ! method_exists( 'WP_Upgrader', 'create_lock' ) || ! method_exists( 'WP_Upgrader', 'release_lock' ) ) {
		throw PromotionException::hard(
			'This WordPress build does not expose WP_Upgrader::create_lock()/release_lock(); promotion locking cannot be made atomic without it. Refusing to finalize.'
		);
	}
}
```

Three options are involved per record key:

| Option | Purpose |
|---|---|
| `agency_promotion_lock_<sha256>.lock` | core's atomic gate (created by `WP_Upgrader::create_lock()`) |
| `agency_promotion_lock_<sha256>` | the §7.10 lock record: `promotionId`, `startedAtUtc`, `owner`, `expiresAtUtc`, `recordKey`; non-autoloaded |
| `agency_promotion_lock_mutex_<sha256>.lock` | a short mutation mutex held ONLY while gate and metadata are being changed together |

**Every** gate/metadata mutation — acquire, reclaim, heartbeat, release — runs inside `with_mutex( $record_key, callable $mutation )`, which calls `WP_Upgrader::create_lock( self::mutex_name( $key ), PromotionSettings::integer( MUTEX_TTL, 30 ) )`, retries up to 25 times with `usleep( 200000 )` between attempts, throws `lock_conflict()` when it never wins, and always releases in a `finally`. This is what stops an abandoned heartbeat from writing over a lock another promotion has just reclaimed: the heartbeat re-reads the metadata INSIDE the mutex and aborts when the `promotionId` is no longer its own.

- [ ] **Step 3: Implement acquire / release / heartbeat**

`acquire( array $keys )`:

1. `require_core_lock_api();` then `sort( $keys );` — master spec §7.10 requires sorted acquisition so two overlapping promotions cannot deadlock.
2. `$ttl = PromotionSettings::integer( PromotionSettings::LOCK_TTL, 900 );`
3. For each key, inside `with_mutex()`: when `WP_Upgrader::create_lock( self::option_name( $key ), $ttl )` returns true, overwrite the metadata option with this promotion's record (`delete_option()` then `add_option( …, '', false )`, so a reclaimed gate never keeps the previous owner's metadata) and push the key onto `$acquired`. When it returns false, read the metadata: a `promotionId` equal to this one refreshes `expiresAtUtc` and continues (re-entry); anything else releases every key in `$acquired` and throws `lock_conflict( sprintf( 'Record "%s" is locked by promotion %s (owner %s) until %s.', … ) )`.

`release( array $keys )` — for each key, inside `with_mutex()`: read the metadata; act only when its `promotionId` is ours; then `delete_option( self::option_name( $key ) )` and `WP_Upgrader::release_lock( self::option_name( $key ) )`.

`heartbeat( array $keys )` — for each key, inside `with_mutex()`: a missing metadata record or a foreign `promotionId` throws `lock_conflict( 'Lock for "x" is no longer held by this promotion; it was reclaimed or released.' )`. Otherwise `update_option( self::option_name( $key ) . '.lock', time(), false )` (refreshes the gate's TTL clock) and `update_option( self::option_name( $key ), $metadata_with_new_expiry, false )`.

- [ ] **Step 4: Run and commit**

Run: `ddev composer test:integration -- --filter RecordLockManagerTest`
Expected: PASS.

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/RecordLockManager.php tests/Integration/Promotion/RecordLockManagerTest.php
git commit -m "feat: add per-record promotion locking with heartbeat"
```

---

### Task 13: Protected chunked promotion backups with retention (§7.9)

**Files:**
- Create: `src/State/Promotion/PromotionBackup.php`
- Test: `tests/Unit/AgencyPlatform/Promotion/BackupChunkingTest.php`
- Test: `tests/Integration/Promotion/PromotionBackupTest.php`

**Interfaces:**
- Consumes: `PromotionSettings`, `PromotionException` (Task 1).
- Produces:
  ```php
  final class PromotionBackup {
      public const OPTION_PREFIX = 'agency_promotion_backup_';
      public const INDEX_OPTION  = 'agency_promotion_backups_index';
      public const INDEX_LOCK    = 'agency_promotion_backup_index';

      public function __construct( private string $promotion_id ) {}

      /** Idempotent: an existing backup for this promotion+key is left untouched.
       *  @param array<string,mixed> $record_payload */
      public function store( string $record_key, array $record_payload ): void;
      /** @return array<string,mixed>|null */
      public function retrieve( string $record_key ): ?array;
      public function exists( string $record_key ): bool;
      public function mark_finalized( string $finalized_at_utc ): void;
      public function mark_settled( string $settlement_status, string $settled_at_utc ): void;
      /** @return list<array{promotionId:string, finalizedAtUtc:string, settlementStatus:string,
       *      settledAtUtc:string|null, records:int, recordKeys:list<string>, bytes:int,
       *      retentionUntilUtc:string, prunable:bool}> */
      public static function list_all(): array;
      /** @return list<string> pruned promotion ids */
      public static function prune( string $older_than, bool $dry_run ): array;
      /** Promotions that finalized the same record key AFTER the given timestamp.
       *  @return list<string> */
      public static function later_claims( string $record_key, string $finalized_at_utc, string $excluding_promotion_id ): array;
      /** @return list<string> */
      public static function split_payload( string $json, int $chunk_bytes ): array;
      public static function parse_older_than( string $value ): int;   // seconds; accepts "30d", "12h", "3600"
  }
  ```

- [ ] **Step 1: Write the failing pure-logic tests**

```php
public function test_a_small_payload_is_a_single_chunk(): void {
	self::assertSame( array( 'abc' ), PromotionBackup::split_payload( 'abc', 10 ) );
}

public function test_a_large_payload_splits_on_exact_byte_boundaries(): void {
	$chunks = PromotionBackup::split_payload( str_repeat( 'x', 25 ), 10 );

	self::assertCount( 3, $chunks );
	self::assertSame( 25, strlen( implode( '', $chunks ) ) );
}

public function test_older_than_parsing(): void {
	self::assertSame( 2592000, PromotionBackup::parse_older_than( '30d' ) );
	self::assertSame( 43200, PromotionBackup::parse_older_than( '12h' ) );
	self::assertSame( 3600, PromotionBackup::parse_older_than( '3600' ) );
}

public function test_a_malformed_older_than_value_throws(): void {
	$this->expectException( PromotionException::class );

	PromotionBackup::parse_older_than( 'soon' );
}
```

Run: `ddev composer test:unit -- --filter BackupChunkingTest` → FAIL.

- [ ] **Step 2: Implement storage**

- `$prefix = self::OPTION_PREFIX . $this->promotion_id . '_' . hash( 'sha256', $record_key );` — 24 + 36 + 1 + 64 = 125 characters, comfortably inside the 191-character `option_name` index limit even with the `_c0000` suffix.
- `store()` returns early when `exists( $record_key )` (idempotent re-finalisation). `$json = wp_json_encode( $record_payload );` a `false` return is `PromotionException::hard()`. Split with `PromotionSettings::integer( CHUNK_BYTES, 500000 )` — object caches commonly cap values near 1 MB. Store each chunk with `add_option( $prefix . '_c' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ), $chunk, '', false )` — **non-autoloaded**, as master spec §7.9 requires. Store `<prefix>_meta` with `chunks`, `bytes`, `sha256` of `$json` and `recordKey`.
- `retrieve()` reads the meta, concatenates the chunks in order, verifies the sha256 (a mismatch is `PromotionException::hard( 'Backup for "x" is corrupt.' )`), then `json_decode(..., true)`.
- Index maintenance: `INDEX_OPTION` is a non-autoloaded map `promotionId => array( 'finalizedAtUtc', 'settlementStatus', 'settledAtUtc', 'recordKeys', 'bytes' )`. Every index write is a read-modify-write shared by concurrent promotions, so guard it with `WP_Upgrader::create_lock( self::INDEX_LOCK, 30 )`, retry up to 10 times with `usleep( 200000 )`, throw `PromotionException::lock_conflict()` on exhaustion, and release in a `finally`.
- `later_claims()` scans the index for promotions whose `recordKeys` contains the key, whose `finalizedAtUtc` is later than the supplied timestamp, whose id is not the excluded one, and whose `settlementStatus` is not `rolled-back`.

- [ ] **Step 3: Implement listing, retention and pruning**

- `retentionUntilUtc` = `max( finalizedAtUtc, settledAtUtc ) + AGENCY_PROMOTION_BACKUP_RETENTION_DAYS days` (default 30). An abandoned, never-settled promotion therefore gets the same window instead of being orphaned forever.
- `list_all()` returns one row per index entry, sorted by `finalizedAtUtc` descending, with `prunable` true when `time() > retentionUntilUtc`.
- `prune( $older_than, $dry_run )`: `$cutoff = time() - self::parse_older_than( $older_than );` an entry is pruned when its retention has expired AND its `finalizedAtUtc` is older than `$cutoff`. `$dry_run` returns the ids without deleting. Otherwise `delete_option()` every chunk, meta and index entry, then delete the canonical manifest at `<AGENCY_STATE_DIR>/promotions/<id>.json`. Log each prune with `Logger::log( 'promotion', 'backup-pruned', … )`.

- [ ] **Step 4: Write and run the integration test**

`PromotionBackupTest` asserts: a payload larger than a 1000-byte chunk size round-trips byte-identically; every stored option is non-autoloaded (assert the names are absent from `wp_load_alloptions()`); `store()` twice for the same key leaves the chunk count unchanged; `list_all()` reports the record keys and a `prunable` flag; `prune( '30d', true )` deletes nothing while returning ids; `prune( '30d', false )` on an entry inside the retention window deletes nothing; the same call after `mark_settled()` with an aged timestamp deletes it; `later_claims()` finds a second promotion that finalised the same key afterwards and ignores one that has since rolled back.

Run: `ddev composer test:unit -- --filter BackupChunkingTest && ddev composer test:integration -- --filter PromotionBackupTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionBackup.php tests/Unit/AgencyPlatform/Promotion/BackupChunkingTest.php tests/Integration/Promotion/PromotionBackupTest.php
git commit -m "feat: add chunked promotion backups with retention"
```

---

### Task 14: `PromotionFinalizer` (§7.8)

**Files:**
- Create: `src/State/Promotion/PromotionFinalizer.php`
- Test: `tests/Integration/Promotion/PromotionFinalizerTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–13.
- Produces:
  ```php
  final class PromotionFinalizer {
      public function __construct( private StateGateway $gateway, private ManifestStore $store ) {}
      /** @return array{manifest: PromotionManifest, outcome: PromotionOutcome} */
      public function finalize( string $manifest_path_or_dash ): array;
      /** @return array{id:int|null, hash:string|null, identity:array<string,mixed>|null} */
      public function resolve_navigation_fallback(): array;
  }
  ```

- [ ] **Step 1: Write the failing run-level refusal tests**

```php
public function test_finalize_refuses_an_unsealed_manifest(): void {
	$this->assert_exit_code( 1, fn() => $this->finalizer->finalize( $this->unsealed_manifest_path ) );
}

public function test_finalize_refuses_a_deploy_commit_mismatch(): void {
	putenv( 'AGENCY_DEPLOY_COMMIT=' . str_repeat( 'b', 40 ) );

	$this->assert_exit_code( 1, fn() => $this->finalizer->finalize( $this->manifest_path ) );
}

public function test_finalize_refuses_a_theme_version_mismatch(): void {
	// The stylesheet still matches; only the version moved. The deployed files
	// are therefore not the ones the manifest was prepared against.
	$this->set_manifest_theme_version( '9.9.9' );

	$this->assert_exit_code( 1, fn() => $this->finalizer->finalize( $this->manifest_path ) );
}

public function test_finalize_refuses_a_stylesheet_uuid_url_or_environment_mismatch(): void { /* four cases, each exit 1 */ }

public function test_finalize_refuses_a_missing_or_altered_deployed_file(): void {
	file_put_contents( $this->deployed_file, "<!-- wp:paragraph --><p>swapped</p><!-- /wp:paragraph -->\n" );

	$this->assert_exit_code( 4, fn() => $this->finalizer->finalize( $this->manifest_path ) );
}

public function test_finalize_exits_three_when_another_promotion_holds_a_record_lock(): void {
	( new RecordLockManager( wp_generate_uuid4(), 'other' ) )->acquire( array( 'templates:page' ) );

	$this->assert_exit_code( 3, fn() => $this->finalizer->finalize( $this->manifest_path ) );
}

public function test_a_client_edit_after_export_refuses_that_record(): void {
	$this->edit_template_override_after_export();

	$outcome = $this->finalizer->finalize( $this->manifest_path );

	self::assertSame( 1, $outcome['outcome']->exit_code() );
	self::assertNotNull( $this->read_override( 'page' ) );
	self::assertSame( 'concurrent-edit', $outcome['manifest']->record( 'templates:page' )['finalizeRefusalReason'] );
}

public function test_a_record_deleted_after_export_is_refused_not_silently_accepted(): void {
	// A missing pending record means somebody deleted the override between
	// export and finalize. That is a concurrent change, not an idempotent
	// re-run, and it must never be reported as a success.
	$this->delete_override( 'page' );

	$outcome = $this->finalizer->finalize( $this->manifest_path );

	self::assertSame( 1, $outcome['outcome']->exit_code() );
	self::assertSame( 'concurrent-delete', $outcome['manifest']->record( 'templates:page' )['finalizeRefusalReason'] );
}

public function test_locks_are_released_for_every_record_that_was_not_promoted(): void {
	$this->edit_template_override_after_export();

	$this->finalizer->finalize( $this->manifest_path );

	self::assertNull( ( new RecordLockManager( $this->promotion_id, 'x' ) )->inspect( 'templates:page' ) );
}

public function test_a_failed_reset_refuses_without_restoring(): void {
	$this->make_delete_post_fail();

	$outcome = $this->finalizer->finalize( $this->manifest_path );

	self::assertSame( 'reset-failed', $outcome['manifest']->record( 'templates:page' )['finalizeRefusalReason'] );
	self::assertCount( 1, $this->overrides_named( 'page' ) );   // no duplicate restore
}

public function test_an_unresolvable_template_after_reset_restores_the_record(): void {
	$this->delete_the_theme_template_file();

	$outcome = $this->finalizer->finalize( $this->manifest_path );

	self::assertSame( 'post-reset-unresolved', $outcome['manifest']->record( 'templates:page' )['finalizeRefusalReason'] );
	self::assertNotNull( $this->read_override( 'page' ) );
}
```

Run: `ddev composer test:integration -- --filter PromotionFinalizerTest` → FAIL.

- [ ] **Step 2: Implement the run-level guard sequence**

1. `$manifest = $this->store->load( $path );` — tamper gives exit 4.
2. `null === $manifest->deploy_commit()` is `hard( 'Manifest is not sealed. Run --seal with the deploy commit first.' )`.
3. `EnvironmentConfig::get( 'AGENCY_DEPLOY_COMMIT' ) !== $manifest->deploy_commit()` is `hard()` naming both values.
4. `get_stylesheet() !== $manifest->active_theme()['stylesheet']` is `hard()`. **Also** compare `wp_get_theme()->get( 'Version' )` against `$manifest->active_theme()['version']` — a version bump means the deployed theme is not the one the manifest was prepared against.
5. `get_option( 'agency_platform_site_uuid' ) !== $manifest->site_uuid()` is `hard()`. Also compare `home_url()` against `site_url()` and `wp_get_environment_type()` against `environment()` — master spec §7.7 requires all four checked together, never the UUID alone.
6. Resolve every record's deployed file as `get_stylesheet_directory() . '/' . $record['themeRelativePath']`; a missing file or a sha256 differing from `preparedFileHash` is `PromotionException::tamper()`.
7. `$pending` = record keys whose `finalizeStatus` is not `promoted` (idempotent re-run). Empty `$pending` returns an all-`skipped` outcome, exit 0, without touching locks.
8. `$locks = new RecordLockManager( $manifest->promotion_id(), PromotionSettings::text( PromotionSettings::DEPLOYMENT_ID, $manifest->promotion_id() ) ); $locks->acquire( $pending );` — a conflict propagates as exit 3.
9. `$backup = new PromotionBackup( $manifest->promotion_id() );`

- [ ] **Step 3: Implement the per-record promotion loop**

Wrap the whole loop in `try { … } finally { … }`. The `finally` releases the locks of every record NOT left in `finalizeStatus === 'promoted'`, so a refused or self-restored record never holds a lock until its TTL, and an exception mid-loop cannot strand one either.

For each pending key, in sorted order (master spec §7.8's ten numbered steps):

1. `$live = $this->gateway->live_state_record( $provider, $slug );`
   - `null` → per-record refusal, `finalizeRefusalReason = 'concurrent-delete'`, detail: `'The database override no longer exists. It was removed between export and finalisation; re-export before promoting.'` **This is NOT an idempotent success.**
2. `$live->content_hash() !== $record['originalContentHash']` OR `$live->modified_gmt() !== $record['originalModifiedGmt']` (both possibly `null`) → refusal `concurrent-edit`.
3. **Navigation expectation.** When `$record['navigationExpectation']` is non-empty, call `resolve_navigation_fallback()` (Task 9 Step 5) and `NavigationPolicy::verify_target()`. A returned refusal ends this record.
4. **Backup.** `$backup->store( $key, $strategy->capture_backup( $live ) );`
5. **Reset.** `$strategy->reset( $live );` — the strategy checks `wp_delete_post()`'s return AND re-reads with `get_post()` to confirm the row is gone. A `PromotionException` here becomes refusal `reset-failed`; **do not restore**, because nothing was removed.
6. **Re-resolve.** Call `wp_cache_flush_runtime()` when it exists — the deleted row can still sit in this process's runtime object cache. Then `$actual = $strategy->resolve_current_hash( $slug );`
   - `null === $actual` → restore from the backup, refusal `post-reset-unresolved`.
7. **Compare.** For a strategy with `defers_expected_hash() === false`, compare `$actual` against `$record['expectedPostResetHash']`. A mismatch restores from the backup and gives refusal `post-reset-mismatch`. The backup is NOT deleted.
8. **Success.** `finalizeStatus = 'promoted'`, `postFinalizeRecordState = $strategy->post_finalize_record_state()`, `postFinalizeSemanticHash = $actual`, and `postFinalizeModifiedGmt` = the re-read live record's `modifiedGmt` for a `present` state or `null` for `absent`. Reset `rollbackStatus` to `not-attempted`.

After the loop: `finalizeStatus` on the manifest becomes `complete` when every record is `promoted`, otherwise `partial`; set `finalizedAtUtc` and `backupId` (the promotion id); **reset `settlementStatus` to `pending`** so a manifest that was previously rolled back is settleable again; `$backup->mark_finalized( … );` `$this->store->write_canonical( $manifest );` and, when `$path` was a real file, `$this->store->write( $manifest, $path )` too. Locks for promoted records stay HELD — `--confirm` or `--rollback` releases them. Log with `Logger::log( 'promotion', 'finalized', … )`.

- [ ] **Step 4: Write the success and idempotency tests, then run**

Add: a clean run promotes the record, deletes the DB override, and leaves the template resolving from the file; a second `finalize()` on the same manifest exits 0 and changes nothing; a two-record run where one is refused exits 2 and releases only the refused record's lock.

Run: `ddev composer test:integration -- --filter PromotionFinalizerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionFinalizer.php tests/Integration/Promotion/PromotionFinalizerTest.php
git commit -m "feat: add promotion finalize with backup and semantic verification"
```

---

### Task 15: Confirm and rollback (§7.9)

**Files:**
- Create: `src/State/Promotion/PromotionConfirmer.php`
- Create: `src/State/Promotion/PromotionRollback.php`
- Test: `tests/Integration/Promotion/PromotionSettlementTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–14.
- Produces:
  ```php
  final class PromotionConfirmer {
      public function __construct( private ManifestStore $store ) {}
      /** @return array{manifest: PromotionManifest, outcome: PromotionOutcome} */
      public function confirm( string $manifest_path_or_dash ): array;
  }

  final class PromotionRollback {
      /** Leaves-first restore order (master spec §7.9 "dependency-safe order"). */
      public const PROVIDER_ORDER = array( 'template-parts', 'templates', 'global-styles' );

      public function __construct( private StateGateway $gateway, private ManifestStore $store ) {}
      /** @return array{manifest: PromotionManifest, outcome: PromotionOutcome} */
      public function rollback( string $manifest_path_or_dash ): array;
  }
  ```

- [ ] **Step 1: Write the failing settlement tests**

```php
public function test_confirm_marks_the_promotion_successful_and_keeps_the_backup(): void {
	$outcome = $this->confirmer->confirm( $this->manifest_path );

	self::assertSame( 'confirmed', $outcome['manifest']->settlement_status() );
	self::assertTrue( ( new PromotionBackup( $this->promotion_id ) )->exists( 'templates:page' ) );
}

public function test_confirm_releases_every_record_lock(): void {
	$this->confirmer->confirm( $this->manifest_path );

	self::assertNull( ( new RecordLockManager( $this->promotion_id, 'x' ) )->inspect( 'templates:page' ) );
}

public function test_confirm_is_idempotent(): void {
	$this->confirmer->confirm( $this->manifest_path );

	self::assertSame( 0, $this->confirmer->confirm( $this->manifest_path )['outcome']->exit_code() );
}

public function test_confirm_refuses_a_promotion_that_was_never_finalized(): void { /* exit 1 */ }

public function test_rollback_restores_the_original_database_record(): void {
	$outcome = $this->rollback->rollback( $this->manifest_path );

	self::assertSame( 0, $outcome['outcome']->exit_code() );
	self::assertSame( $this->original_content_hash, $this->gateway->read_live_record( 'templates', 'page' )['contentHash'] );
}

public function test_rollback_restores_multi_value_meta_as_separate_rows(): void {
	// update_post_meta() would collapse three values into one serialized array.
	$outcome = $this->rollback->rollback( $this->manifest_path );

	self::assertSame( array( 'a', 'b', 'c' ), get_post_meta( $outcome['manifest']->record( 'templates:page' )['restoredObjectId'], 'multi', false ) );
}

public function test_rollback_records_the_restored_object_id_and_modification_marker(): void {
	// WordPress derives post_modified from post_date on insert, so the restored
	// row's marker differs from the original. Recording it is what lets a later
	// finalize pass its concurrency check.
	$record = $this->rollback->rollback( $this->manifest_path )['manifest']->record( 'templates:page' );

	self::assertNotNull( $record['restoredObjectId'] );
	self::assertSame(
		get_post( $record['restoredObjectId'] )->post_modified_gmt,
		$record['originalModifiedGmt']
	);
	self::assertSame( 'pending', $record['finalizeStatus'] );
}

public function test_rollback_remains_possible_after_confirm_within_the_retention_window(): void {
	$this->confirmer->confirm( $this->manifest_path );

	self::assertSame( 0, $this->rollback->rollback( $this->manifest_path )['outcome']->exit_code() );
}

public function test_rollback_is_idempotent(): void {
	$this->rollback->rollback( $this->manifest_path );

	// The second run must SKIP the already-restored record, not re-check it
	// and report record-recreated against its own restoration.
	$second = $this->rollback->rollback( $this->manifest_path );

	self::assertSame( 0, $second['outcome']->exit_code() );
	self::assertSame( 'skipped', $second['outcome']->outcomes()['templates:page'] );
	self::assertCount( 1, $this->overrides_named( 'page' ) );
}

public function test_rollback_refuses_a_record_a_client_recreated_after_finalize(): void {
	$this->create_override( 'page', '<!-- wp:paragraph --><p>newer client work</p><!-- /wp:paragraph -->' );

	$outcome = $this->rollback->rollback( $this->manifest_path );

	self::assertSame( 1, $outcome['outcome']->exit_code() );
	self::assertStringContainsString( 'newer client work', $this->read_override( 'page' )->post_content );
	self::assertSame( 'record-recreated', $outcome['manifest']->record( 'templates:page' )['rollbackRefusalReason'] );
}

public function test_rollback_refuses_a_record_a_newer_promotion_has_claimed(): void {
	$this->register_later_promotion_for( 'templates:page' );

	self::assertSame(
		'claimed-by-newer-promotion',
		$this->rollback->rollback( $this->manifest_path )['manifest']->record( 'templates:page' )['rollbackRefusalReason']
	);
}

public function test_rollback_refuses_when_the_backup_was_already_pruned(): void { /* exit 1, reason backup-missing */ }

public function test_rollback_restores_parts_before_templates(): void {
	$order = $this->recorded_restore_order();

	self::assertSame( array( 'template-parts:site-header', 'templates:page' ), $order );
}

public function test_rollback_releases_every_lock_acquired_by_its_attempt_including_refusals(): void {
	$this->create_override( 'page', '<!-- wp:paragraph --><p>newer client work</p><!-- /wp:paragraph -->' );
	$this->rollback->rollback( $this->manifest_path );

	foreach ( array( 'template-parts:site-header', 'templates:page' ) as $key ) {
		self::assertNull( ( new RecordLockManager( $this->promotion_id, 'x' ) )->inspect( $key ) );
	}
}
```

Run: `ddev composer test:integration -- --filter PromotionSettlementTest` → FAIL.

- [ ] **Step 2: Implement `PromotionConfirmer`**

1. `$input = $this->store->load( $path );` — authenticates the supplied manifest and yields the promotion id.
2. `$manifest = $this->store->load_canonical( $input->promotion_id() );` — the host-side copy is authoritative.
3. `'pending' === $manifest->finalize_status()` is `hard( 'Promotion <id> was never finalized.' )`.
4. An already-`confirmed` manifest returns an all-`skipped` outcome (idempotent).
5. Set `settlementStatus` `confirmed`, `settledAtUtc` now, `retentionUntilUtc` = now + `AGENCY_PROMOTION_BACKUP_RETENTION_DAYS` days.
6. `( new PromotionBackup( $id ) )->mark_settled( 'confirmed', $settled_at_utc );` — the backup is deliberately NOT deleted (master spec §7.9); only `wp agency promotion-backups prune` removes it, after the retention window.
7. `( new RecordLockManager( $id, $owner ) )->release( $manifest->record_keys() );`
8. `$this->store->write_canonical( $manifest );` plus the input file when it was a path. Log `Logger::log( 'promotion', 'confirmed', … )`.

- [ ] **Step 3: Implement `PromotionRollback`**

Steps 1–3 as above, plus: a `settlementStatus` already `rolled-back` returns an all-`skipped` outcome.

4. Re-acquire the record locks (confirm released them). A lock held by another promotion propagates as exit 3. Capture the exact canonical keys acquired by this attempt. Wrap the loop in `try { … } finally { $locks->release( $acquired_attempt_keys ); }`. Release every lock the attempt acquired or re-entered, including locks for records that were refused, skipped, restored, or caused an exception. No rollback refusal may hold a lock until TTL expiry.
5. Order the keys by `PROVIDER_ORDER` index, then reverse canonical-key order within each provider.
6. **Skip** any record whose `rollbackStatus` is already `restored` or `restored-hash-mismatch` (outcome `skipped`) — that is what makes a second run idempotent instead of reporting `record-recreated` against its own restoration. Also skip records whose `finalizeStatus` is `refused` or `restored`; they were never changed, or were already put back.
7. For each remaining record:
   - `$payload = $backup->retrieve( $key );` — `null` gives refusal `backup-missing`.
   - **Absent case** (`postFinalizeRecordState === 'absent'`): `$this->gateway->read_live_record( … )` must still be `null`. A record that exists again means the client re-edited after finalisation → refuse with `record-recreated`, never overwrite (master spec §7.9 deleted-record rule).
   - **Present case**: the live `contentHash` must equal `postFinalizeSemanticHash` and the live `modifiedGmt` must equal `postFinalizeModifiedGmt`; otherwise refuse with `changed-since-finalize`.
   - **Newer promotion**: `PromotionBackup::later_claims( $key, (string) $manifest->finalized_at_utc(), $id )` must be empty; otherwise refuse with `claimed-by-newer-promotion`.
   - **Restore** through `$strategy->restore( $record, $payload )` — see Step 4.
   - **Verify**: re-read through the gateway. A `contentHash` differing from `originalContentHash` sets `rollbackStatus` `restored-hash-mismatch` (report it, exit non-zero, but never delete the restored row — deleting would destroy data twice over). A match sets `rollbackStatus` `restored`.
   - **Record the new identity**: write `restoredObjectId`, and overwrite `objectId` and `originalModifiedGmt` from the re-read row, then set `finalizeStatus` back to `pending`, `postFinalizeRecordState`/`postFinalizeSemanticHash`/`postFinalizeModifiedGmt` back to `null`. This is the valid post-rollback transition that makes a later `--finalize` on the same manifest work.
8. `settlementStatus` becomes `rolled-back` when every applicable record restored, otherwise `partially-rolled-back`; the manifest's `finalizeStatus` returns to `pending` when at least one record did. `mark_settled()`, release the settled locks, `write_canonical()`, log. The outcome yields 0 / 2 / 1 by the shared rule.

- [ ] **Step 4: Implement the strategy restore correctly**

`AbstractBlockTemplateStrategy::restore( StateRecord $record, array $backup ): void`:

```php
private const WRITABLE_POST_FIELDS = array(
	'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_content_filtered',
	'post_title', 'post_excerpt', 'post_status', 'post_type', 'comment_status', 'ping_status',
	'post_password', 'post_name', 'to_ping', 'pinged', 'post_parent', 'menu_order',
	'post_mime_type', 'guid',
);
```

- Build the insert array from `WRITABLE_POST_FIELDS` only. **`post_modified` and `post_modified_gmt` are deliberately excluded** — WordPress derives them from `post_date` on insert and silently ignores supplied values, so passing them would create a manifest that disagrees with the database.
- `$new_id = wp_insert_post( $data, true );` — `is_wp_error( $new_id )` throws `PromotionException::hard()` with the error message.
- `$terms = wp_set_object_terms( $new_id, $backup['terms'], 'wp_theme' );` — `is_wp_error( $terms )` throws.
- Meta: `foreach ( $backup['meta'] as $key => $values ) { foreach ( (array) $values as $value ) { add_post_meta( $new_id, $key, maybe_unserialize( $value ) ); } }` — **`add_post_meta()` per value**, because `update_post_meta()` would collapse a multi-value key into a single serialized array. A `false` return for any call throws.
- Return the new id to the caller through a public `last_restored_object_id(): ?int` accessor, so the rollback can record `restoredObjectId` without changing Task 2's interface signature.

- [ ] **Step 5: Run and commit**

Run: `ddev composer test:integration -- --filter PromotionSettlementTest`
Expected: PASS.

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionConfirmer.php web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionRollback.php tests/Integration/Promotion/PromotionSettlementTest.php
git commit -m "feat: add promotion confirm and refusing rollback"
```

---

### Task 16: Vertical-slice workflow proof (§9.5) — the milestone gate

The minimal `prepare → seal → finalize → rollback → confirm` path now exists. Master spec §9.5 requires proving it end to end BEFORE the full refusal, retention, command, wrapper and tamper matrices are built. **Do not start Task 17 until this passes.**

**Files:**
- Test: `tests/Integration/Promotion/WorkflowProofTest.php`

**Interfaces:**
- Consumes: `PromotionPreparer`, `PromotionSealer`, `PromotionFinalizer`, `PromotionConfirmer`, `PromotionRollback`, `ManifestStore`, `StateGateway`.
- Produces: nothing new — this task adds only the proof.

- [ ] **Step 1: Write the single end-to-end test**

One test method, executed in order, mirroring master spec §9.5's bullet list exactly:

```php
public function test_the_full_template_and_part_promotion_loop(): void {
	// 1. A client edit exists in the database for a template and for a part.
	$this->create_override( 'wp_template', 'page', $this->edited_template_markup );
	$this->create_override( 'wp_template_part', 'site-header', $this->edited_part_markup );

	// 2. Export. StateBundle::load() verifies the signature and stateHash
	//    before any promotable content becomes readable.
	$bundle_path = $this->export_bundle();

	// 3. Prepare both records.
	$prepared = $this->preparer->prepare(
		array(
			'source'   => $bundle_path,
			'select'   => 'template-parts:site-header,templates:page',
			'manifest' => $this->manifest_path,
		)
	);
	self::assertSame( 0, $prepared['outcome']->exit_code() );
	self::assertStringContainsString( 'client edit', file_get_contents( $this->theme_dir . '/templates/page.html' ) );

	// 4. Commit, then seal against that commit.
	$sha    = $this->commit_prepared_files();
	$sealed = $this->sealer->seal( $this->manifest_path, $sha );
	self::assertSame( $sha, $sealed->deploy_commit() );

	// 5. Finalize against the sealed commit.
	putenv( 'AGENCY_DEPLOY_COMMIT=' . $sha );
	$finalized = $this->finalizer->finalize( $this->manifest_path );
	self::assertSame( 0, $finalized['outcome']->exit_code() );

	// 6. Semantic equality: the override is gone and the template resolves
	//    from the promoted file with the same content.
	self::assertNull( $this->gateway->read_live_record( 'templates', 'page' ) );
	self::assertSame(
		$finalized['manifest']->record( 'templates:page' )['expectedPostResetHash'],
		$this->resolved_hash( 'page', 'wp_template' )
	);

	// 7. Roll it back.
	$rolled_back = $this->rollback->rollback( $this->manifest_path );
	self::assertSame( 0, $rolled_back['outcome']->exit_code() );
	self::assertSame( $this->original_content_hash, $this->gateway->read_live_record( 'templates', 'page' )['contentHash'] );

	// 8. Re-finalize and confirm. This only works because rollback returned
	//    the records to `pending` and rewrote their objectId and modification
	//    marker from the restored rows.
	$refinalized = $this->finalizer->finalize( $this->manifest_path );
	self::assertSame( 0, $refinalized['outcome']->exit_code() );
	self::assertSame( 'confirmed', $this->confirmer->confirm( $this->manifest_path )['manifest']->settlement_status() );

	// 9. A record a client has since re-created must NOT be clobbered.
	$this->create_override( 'wp_template', 'page', '<!-- wp:paragraph --><p>newer client work</p><!-- /wp:paragraph -->' );
	$refused = $this->rollback->rollback( $this->manifest_path );
	self::assertSame( 1, $refused['outcome']->exit_code() );
	self::assertSame( 'record-recreated', $refused['manifest']->record( 'templates:page' )['rollbackRefusalReason'] );
	self::assertStringContainsString( 'newer client work', $this->read_override( 'page' )->post_content );
}
```

The fixture creates a throwaway git repository holding a copy of the theme (`templates/`, `parts/`, `theme.json`), points `get_stylesheet_directory()` at it through the `stylesheet_directory` filter with a NAMED callback, registers the Task 7 strategies, and restores the original directory in `tear_down()`. `commit_prepared_files()` must pass `-c user.email=…` and `-c user.name=…` inline, because a CI container often has no git identity configured.

- [ ] **Step 2: Run it and fix the collaborators until it passes**

Run: `ddev composer test:integration -- --filter WorkflowProofTest`
Expected: FAIL initially. Fix the collaborators — never weaken an assertion, because each one is a master spec §9.5 bullet.

- [ ] **Step 3: Run the whole suite**

Run: `ddev composer verify`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
ddev composer verify:fast
git add tests/Integration/Promotion/WorkflowProofTest.php
git commit -m "test: prove the template and part promotion workflow end to end"
```

---

### Task 17: The CLI surface — one result, one STDOUT writer

**CARRIED FORWARD FROM TASK 7 — close this here.** `PromotionSubsystem::register()` currently reaches `Cli\PromotionCommands` through a STRING class name plus `class_exists()`, because the class did not exist when Task 7 shipped and the plan's literal text (`new PromotionCommands()`) fails PHPStan with `class.notFound`. The `defined( 'WP_CLI' ) && WP_CLI` guard does NOT protect it: PHPStan does not fold `defined()` to false. That workaround is correct for the intermediate commits and WRONG to keep — a string reference is invisible to static analysis, so a later rename would break the CLI silently. Once `src/Cli/PromotionCommands.php` exists in this task, **replace the string and the `class_exists()` check with a direct `use` and `new PromotionCommands()`**, keeping only the `WP_CLI` guard, and confirm PHPStan is clean. Also add `src/State/Promotion/PromotionSubsystem.php` to this task's owned-file list for that one edit.

**Files:**
- Create: `src/State/Promotion/PromotionCommandRunner.php`
- Create: `src/Cli/PromotionCommands.php`
- Test: `tests/Integration/Promotion/PromotionCommandRunnerTest.php`

**Interfaces:**
- Consumes: every lifecycle class, plus Task 2's `StateCommandResult` and `CliOutput`.
- Produces:
  ```php
  /** Every command body, free of WP_CLI. Never throws out of a public method. */
  final class PromotionCommandRunner {
      /** @param callable():string|null $stdin_reader null reads php://stdin. */
      public function __construct( ?callable $stdin_reader = null );
      /** @param array<string,mixed> $assoc_args */
      public function promote_overrides( array $assoc_args ): StateCommandResult;
      /** @param array<int,string> $args @param array<string,mixed> $assoc_args */
      public function promotion_backups( array $args, array $assoc_args ): StateCommandResult;
  }

  final class PromotionCommands {
      public function register(): void;
      /** @return array<string, array{0: class-string, 1: string}> */
      public static function commands(): array;
      public static function promote_overrides( array $args, array $assoc_args = array() ): void;
      public static function promotion_backups( array $args, array $assoc_args = array() ): void;
  }
  ```

- [ ] **Step 1: Write the failing runner tests**

```php
public function test_stdout_carries_exactly_one_json_document(): void {
	$result = $this->runner->promote_overrides( $this->prepare_args_with_dash_manifest() );

	self::assertSame( 0, $result->exit_code );
	self::assertStringEndsWith( "\n", $result->stdout );
	$document = json_decode( $result->stdout, true, 512, JSON_THROW_ON_ERROR );
	self::assertIsArray( $document );
	// A second JSON value or diagnostics after the first makes JSON_THROW_ON_ERROR
	// fail. Exact canonical bytes also reject an extra leading or trailing line.
	self::assertSame( Normalizer::canonical_json_document( $document ), $result->stdout );
}

public function test_prepare_with_a_dash_manifest_emits_the_manifest_itself(): void {
	$document = json_decode( $this->runner->promote_overrides( $this->prepare_args_with_dash_manifest() )->stdout, true );

	self::assertArrayHasKey( 'promotionId', $document );
	self::assertArrayHasKey( 'hmac', $document );
}

public function test_seal_with_a_dash_manifest_reads_stdin_and_emits_the_sealed_manifest(): void {
	$runner   = new PromotionCommandRunner( fn(): string => $this->rendered_manifest );
	$document = json_decode( $runner->promote_overrides( array( 'seal' => true, 'manifest' => '-', 'deploy-commit' => $this->sha ) )->stdout, true );

	self::assertSame( $this->sha, $document['deployCommit'] );
}

public function test_finalize_with_a_dash_manifest_emits_one_result_document_not_a_manifest(): void {
	$runner   = new PromotionCommandRunner( fn(): string => $this->sealed_manifest );
	$document = json_decode( $runner->promote_overrides( array( 'finalize' => true, 'manifest' => '-' ) )->stdout, true );

	self::assertArrayHasKey( 'outcomes', $document );
	self::assertArrayNotHasKey( 'hmac', $document );
}

public function test_heartbeat_with_a_dash_manifest_reads_stdin(): void { /* one result document, exit 0 */ }

public function test_diagnostics_never_reach_stdout(): void {
	$result = $this->runner->promote_overrides( array( 'prepare' => true ) );   // missing options

	self::assertSame( 1, $result->exit_code );
	self::assertJson( trim( $result->stdout ) );
	self::assertStringContainsString( '--source', $result->stderr );
}

public function test_two_mode_flags_are_a_hard_error(): void {
	self::assertSame( 1, $this->runner->promote_overrides( array( 'prepare' => true, 'seal' => true ) )->exit_code );
}

public function test_no_mode_flag_is_a_hard_error(): void { /* exit 1 */ }

public function test_a_lock_conflict_exits_three(): void { /* exit 3, JSON error payload */ }

public function test_a_tampered_manifest_exits_four(): void { /* exit 4, JSON error payload */ }

public function test_backup_list_defaults_to_json(): void {
	$result = $this->runner->promotion_backups( array( 'list' ), array() );

	self::assertJson( trim( $result->stdout ) );
}

public function test_backup_list_can_render_a_table(): void {
	$result = $this->runner->promotion_backups( array( 'list' ), array( 'format' => 'table' ) );

	self::assertStringContainsString( 'promotionId', $result->stdout );
}

public function test_backup_prune_dry_run_deletes_nothing(): void { /* exit 0, ids listed, options intact */ }
```

Run: `ddev composer test:integration -- --filter PromotionCommandRunnerTest` → FAIL.

- [ ] **Step 2: Implement `PromotionCommandRunner`**

`promote_overrides()` reads exactly one mode flag from `$assoc_args`: `prepare`, `seal`, `finalize`, `confirm`, `rollback`, `heartbeat`. Zero or more than one is a hard error. Required options per mode: `prepare` needs `source`, `select`, `manifest`; `seal` needs `manifest` and `deploy-commit`; the rest need `manifest`. A missing option is a hard error naming it.

Each mode returns a `StateCommandResult` built from ONE of two payload shapes:

| Mode | `--manifest` value | STDOUT payload |
|---|---|---|
| `prepare` | path | `$outcome->to_array()` |
| `prepare` | `-` | `$store->render( $manifest )` — the manifest itself |
| `seal` | path | `$outcome->to_array()` (a single-entry `sealed` outcome) |
| `seal` | `-` | `$store->render( $manifest )` |
| `finalize` / `confirm` / `rollback` / `heartbeat` | path or `-` | `$outcome->to_array()` |

Failures produce `array( 'ok' => false, 'error' => $exception->getMessage(), 'exitCode' => $exception->exit_code() )` on STDOUT, the message on STDERR, and `$exception->exit_code()` as the result's exit code. Every payload is serialised once with `Normalizer::canonical_json_document()` except the manifest cases, which emit `render()`'s exact bytes. **The runner writes to no stream; it only returns the strings.**

`heartbeat` (the only mode with no collaborator class of its own):

```php
private function heartbeat( string $manifest_path ): PromotionOutcome {
	$store    = new ManifestStore( new StateGateway(), $this->stdin_reader );
	$manifest = $store->load_canonical( $store->load( $manifest_path )->promotion_id() );

	// Refresh ONLY the records whose promotion mutation is still active. A
	// refused or self-restored record released its lock at finalize.
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

	return new PromotionOutcome( array_fill_keys( $keys, 'skipped' ), array() );
}
```

`promotion_backups()` takes `list` or `prune` as the first positional argument; anything else is a hard error. `list` renders `PromotionBackup::list_all()` as JSON by default and as a table when `--format=table` (columns `promotionId`, `finalizedAtUtc`, `settlementStatus`, `records`, `bytes`, `retentionUntilUtc`, `prunable`). `prune` reads `--older-than` (default `30d`) and `--dry-run`, calls `PromotionBackup::prune()`, and returns the pruned ids; with `--dry-run` the STDERR summary is prefixed `DRY RUN`.

- [ ] **Step 3: Implement `Cli/PromotionCommands.php`**

The registrar mirrors `AgencyCommands::register()`'s house pattern — the `defined( 'WP_CLI' ) && WP_CLI` guard, then `\WP_CLI::add_command()` for each entry in `commands()`. Each static command method is three lines:

```php
public static function promote_overrides( array $args, array $assoc_args = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $args is required by the WP-CLI command signature; this command takes no positional arguments.
	CliOutput::emit( ( new PromotionCommandRunner() )->promote_overrides( $assoc_args ) );
}
```

`CliOutput::emit()` (Task 2) writes STDOUT through `WP_CLI::line()`, STDERR through `WP_CLI::warning()`, and halts with the result's exit code. **This class is the only place in the task that touches a stream.** Give each command a full WP-CLI docblock with an `## OPTIONS` section describing every flag, matching the style of `AgencyCommands::sanitize()`.

- [ ] **Step 4: Run and commit**

Run: `ddev composer test:integration -- --filter PromotionCommandRunnerTest`
Expected: PASS.

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionCommandRunner.php web/app/mu-plugins/agency-platform/src/Cli/PromotionCommands.php tests/Integration/Promotion/PromotionCommandRunnerTest.php
git commit -m "feat: add the promotion CLI surface"
```

---

### Task 18: Complete the §11.13 promotion test matrix

**Files:**
- Modify: every test file created in Tasks 4–17
- Test: `tests/Integration/Promotion/HmacKeyringTest.php` (new)

**Interfaces:**
- Consumes: everything already built. Produces nothing new.

- [ ] **Step 1: Fill the remaining §11.13 unit cases**

Implement every §11.13 **unit** bullet that concerns promotion (skip the export, normalisation, capability and save-validation bullets — they belong to Tasks 1 and 2). The ones not yet covered:

- **Cross-purpose replay.** Sign a document with `PURPOSE_BUNDLE` and assert `StateGateway::verify_manifest()` rejects it with exit 4; sign one with `PURPOSE_MANIFEST` and assert `StateBundle::load()` rejects it with exit 4.
- **Manifest tamper per mutable field**: `deployCommit`, `records[].preparedFileHash`, `records[].expectedPostResetHash`, `siteUuid` — each altered field gives exit 4.
- **HMAC keyring** (`HmacKeyringTest`): an unset `AGENCY_PROMOTION_HMAC_KEYS` is exit 1 with no WordPress-salt fallback; an empty JSON object is the same; a key shorter than 32 characters is rejected; a manifest whose `hmacKeyId` is not in the keyring is rejected; a manifest signed with an OLD key id still verifies while that key remains in the keyring (rotation); advancing `AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID` changes the `hmacKeyId` on newly signed manifests.

- [ ] **Step 2: Fill the remaining §11.13 integration cases**

- **Overlapping finalize.** Two sealed manifests sharing one record key and differing on another: the second exits 3 and holds NO lock afterwards (proving it released what it acquired).
- **Deployed file missing entirely** (not just altered) causes refusal.
- **Reset failure restores the original record**: assert the restored row's `post_content`, `wp_theme` term and every meta key match the original, and that multi-value meta stayed multi-value.
- **Backup pruning only after the retention window**: a confirmed promotion inside its window is not pruned; the same promotion after an aged `settledAtUtc` is.
- **Export-only providers stay export-only**: `PromotionSelector::parse()` exits 1 for `navigation`, `synced-patterns`, `fonts`, `media-references`, `custom-css`, `content` AND `global-styles` (the last one is the Release 3 boundary).
- **Additional CSS is never promotable**: `custom-css:global-styles` and `custom-css:custom-css-post` both exit 1 at selection.

- [ ] **Step 3: Run the full matrix**

Run: `ddev composer verify`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
ddev composer verify:fast
git add tests/Integration/Promotion/ManifestStoreTest.php tests/Integration/Promotion/GitRepositoryTest.php tests/Integration/Promotion/PrepareLockTest.php tests/Integration/Promotion/PreparedFileWriterTest.php tests/Integration/Promotion/BlockTemplateStrategyTest.php tests/Integration/Promotion/PromotionPreparerTest.php tests/Integration/Promotion/PromotionSealerTest.php tests/Integration/Promotion/RecordLockManagerTest.php tests/Integration/Promotion/PromotionBackupTest.php tests/Integration/Promotion/PromotionFinalizerTest.php tests/Integration/Promotion/PromotionSettlementTest.php tests/Integration/Promotion/WorkflowProofTest.php tests/Integration/Promotion/PromotionCommandRunnerTest.php tests/Integration/Promotion/HmacKeyringTest.php
git commit -m "test: complete the promotion locking rollback and tamper matrix"
```

---

### Task 19: `scripts/promote-overrides` — the deployment-side orchestration wrapper

This runs where CI runs, NOT on the WordPress host (master spec §6): many production hosts have no Node, no Playwright browsers and no repository checkout. The wrapper drives the host only through remote WP-CLI.

**Files:**
- Create: `scripts/promote-overrides`
- Test: `tests/Integration/Promotion/PromoteOverridesWrapperTest.php`

**Interfaces:**
- Consumes: the `wp agency promote-overrides` surface (Task 17).
- Produces: the wrapper contract below.

- [ ] **Step 1: Write the failing wrapper contract test**

The test lives in the `integration` suite so it runs inside DDEV/CI on Linux. It creates a temp directory holding a fake remote command (a shell script that appends its argv to a log and exits with a code read from a control file) and a fake Playwright command (the same pattern), then runs the wrapper with `proc_open` and asserts on the logs. Its first assertion runs `bash --version` and fails with `bash is required for the promotion wrapper release gate.` when Bash is unavailable. It never skips.

```php
public function test_the_wrapper_finalizes_verifies_then_confirms(): void {
	$this->fake_remote_exit( 0 );
	$this->fake_playwright_exit( 0 );

	self::assertSame( 0, $this->run_wrapper() );
	self::assertStringContainsString( 'agency promote-overrides --finalize', $this->remote_log() );
	self::assertStringContainsString( 'agency promote-overrides --confirm', $this->remote_log() );
	self::assertStringNotContainsString( '--rollback', $this->remote_log() );
}

public function test_a_remote_command_containing_quotes_is_executed_as_written(): void {
	// The documented example is: ssh deploy@example.com 'cd /var/www && wp'
	// Word-splitting that string would break it, so the adapter must run it
	// through a shell while quoting only the arguments the wrapper adds.
	$this->set_remote_command( sprintf( "%s 'cd /var/www && wp'", $this->fake_remote_path ) );
	$this->fake_remote_exit( 0 );
	$this->fake_playwright_exit( 0 );

	self::assertSame( 0, $this->run_wrapper() );
	self::assertStringContainsString( 'cd /var/www && wp agency promote-overrides --finalize', $this->remote_log() );
}

public function test_a_failing_verification_rolls_back_and_exits_non_zero(): void {
	$this->fake_remote_exit( 0 );
	$this->fake_playwright_exit( 1 );

	self::assertNotSame( 0, $this->run_wrapper() );
	self::assertStringContainsString( '--rollback', $this->remote_log() );
	self::assertStringNotContainsString( '--confirm', $this->remote_log() );
}

public function test_a_lost_heartbeat_stops_verification_and_rolls_back(): void {
	// Without this the lock can be reclaimed while Playwright keeps running,
	// and the wrapper would confirm a promotion it no longer owns.
	$this->fake_remote_exit_for( 'heartbeat', 3 );
	$this->fake_playwright_sleeps( 30 );

	self::assertNotSame( 0, $this->run_wrapper() );
	self::assertStringContainsString( '--rollback', $this->remote_log() );
	self::assertStringNotContainsString( '--confirm', $this->remote_log() );
}

public function test_settlement_runs_at_most_once_even_when_a_trap_fires(): void {
	$this->fake_remote_exit( 0 );
	$this->fake_playwright_exit( 1 );

	$this->run_wrapper();

	self::assertSame( 1, substr_count( $this->remote_log(), '--rollback' ) );
}

public function test_it_preserves_logs_after_a_rollback(): void {
	$this->fake_remote_exit( 0 );
	$this->fake_playwright_exit( 1 );

	$this->run_wrapper();

	self::assertFileExists( $this->log_dir . '/' . $this->promotion_id . '/verify.log' );
	self::assertFileExists( $this->log_dir . '/' . $this->promotion_id . '/rollback.log' );
}

public function test_it_honours_the_deploy_url_and_project(): void {
	$this->fake_remote_exit( 0 );
	$this->fake_playwright_exit( 0 );

	$this->run_wrapper();

	self::assertStringContainsString( $this->remote_state_dir, $this->remote_log() );
	self::assertStringContainsString( 'WP_BASE_URL=https://deploy.example.test', $this->playwright_log() );
	self::assertStringContainsString( '--project=chromium-desktop', $this->playwright_log() );
}

public function test_a_lock_conflict_at_finalize_aborts_without_rollback(): void {
	$this->fake_remote_exit( 3 );

	self::assertSame( 3, $this->run_wrapper() );
	self::assertStringNotContainsString( '--rollback', $this->remote_log() );
}

public function test_missing_required_environment_variables_exit_one(): void { /* unset AGENCY_DEPLOY_URL → exit 1 */ }

public function test_dry_run_prints_the_commands_without_executing_them(): void {
	self::assertSame( 0, $this->run_wrapper( array( '--dry-run' ) ) );
	self::assertFileDoesNotExist( $this->remote_log_path );
}
```

Run: `ddev composer test:integration -- --filter PromoteOverridesWrapperTest` → FAIL.

- [ ] **Step 2: Write the wrapper**

`scripts/promote-overrides`, `#!/usr/bin/env bash`, `set -euo pipefail`, with a header comment in the style of `scripts/verify`. It requires Bash and Node. It does not require GNU `timeout`; the wrapper owns the timeout through its polling loop.

**Remote adapter.** `AGENCY_REMOTE_WP_CLI_COMMAND` is a shell command string that legitimately contains quotes, so it must be executed by a shell, while the arguments the wrapper appends must be quoted individually:

```bash
run_remote() {
  local quoted=''
  local arg
  for arg in "$@"; do
    quoted+=" $(printf '%q' "$arg")"
  done
  bash -c "${AGENCY_REMOTE_WP_CLI_COMMAND}${quoted}"
}
```

**Lifecycle flags** guarantee settlement happens at most once:

```bash
FINALIZED=0
SETTLED=0

settle() {                    # settle confirm | settle rollback
  [ "$SETTLED" -eq 1 ] && return 0
  [ "$FINALIZED" -eq 0 ] && return 0
  SETTLED=1
  run_remote agency promote-overrides "--$1" --manifest="$REMOTE_MANIFEST" 2>&1 | tee "$LOG_DIR/$1.log"
}

trap 'settle rollback' ERR INT TERM
trap '[ -z "${HEARTBEAT_PID:-}" ] || kill "$HEARTBEAT_PID" 2>/dev/null || true; settle rollback' EXIT
```

Flow:

1. Parse `--manifest=<local path>` (required) and `--dry-run`. Anything else exits 1 with usage on STDERR.
2. Require `AGENCY_REMOTE_WP_CLI_COMMAND`, `AGENCY_DEPLOY_URL`, `AGENCY_REMOTE_STATE_DIR`, `AGENCY_PLAYWRIGHT_PROJECT`, `AGENCY_VERIFICATION_TIMEOUT`. List every missing one and exit 1.
3. Read `promotionId` with `node -e 'process.stdout.write(JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")).promotionId)' "$MANIFEST"` — Node is guaranteed wherever Playwright runs, so no `jq` is needed.
4. `REMOTE_MANIFEST="${AGENCY_REMOTE_STATE_DIR%/}/$(basename "$MANIFEST")"`. The wrapper does NOT transfer the file — master spec §7.7 makes placing the protected CI artifact into `AGENCY_STATE_DIR` the deployment pipeline's job. Print that expectation to STDERR.
5. `LOG_DIR="${AGENCY_PROMOTION_LOG_DIR:-./promotion-logs}/$PROMOTION_ID"`; `mkdir -p "$LOG_DIR"`.
6. With `--dry-run`, echo every command it would run to STDOUT and exit 0 before any trap is armed.
7. Finalize into `$LOG_DIR/finalize.log`, capturing the exit code with `set +e` around the call. `0` or `2` proceeds and sets `FINALIZED=1`. `3`, `1` or `4` exits with that same code and does NOT roll back — a lock conflict released its own locks, and a hard error either happened before any mutation or was already self-restored.
8. Start the heartbeat loop in the background, writing a sentinel on failure:
   ```bash
   ( while sleep "${AGENCY_HEARTBEAT_INTERVAL:-60}"; do
       run_remote agency promote-overrides --heartbeat --manifest="$REMOTE_MANIFEST" >>"$LOG_DIR/heartbeat.log" 2>&1 \
         || { touch "$LOG_DIR/heartbeat.failed"; break; }
     done ) &
   HEARTBEAT_PID=$!
   ```
9. Start verification in the background and poll:
   ```bash
   ( WP_BASE_URL="$AGENCY_DEPLOY_URL" ${AGENCY_PLAYWRIGHT_COMMAND:-npx playwright test} \
       --project="$AGENCY_PLAYWRIGHT_PROJECT" "${AGENCY_PLAYWRIGHT_TESTS:-tests/e2e}" ) >"$LOG_DIR/verify.log" 2>&1 &
   VERIFY_PID=$!
   DEADLINE=$(( $(date +%s) + verify_timeout_seconds ))
   while kill -0 "$VERIFY_PID" 2>/dev/null; do
     if [ -f "$LOG_DIR/heartbeat.failed" ]; then
       echo "error: the promotion lock was lost; stopping verification." >&2
       kill "$VERIFY_PID" 2>/dev/null || true
       VERIFY_STATUS=1
       break
     fi
     if [ "$(date +%s)" -ge "$DEADLINE" ]; then
       echo "error: verification exceeded AGENCY_VERIFICATION_TIMEOUT." >&2
       kill "$VERIFY_PID" 2>/dev/null || true
       VERIFY_STATUS=1
       break
     fi
     sleep 2
   done
   ```
   Set the shell-local `verify_timeout_seconds` from `AGENCY_VERIFICATION_TIMEOUT` before this block. It accepts `600` and `10m`, rejects an empty or malformed value with exit 1, and is never exported as a second `AGENCY_*` variable. The deadline arithmetic never depends on `timeout` suffix parsing.
10. Verification passed → `settle confirm`; exit `0`, or `2` when finalize was partial.
11. Verification failed, timed out, or lost its heartbeat → `settle rollback`, print the log directory to STDERR, and exit non-zero (`1`, or the rollback's own exit code when that is higher).

- [ ] **Step 3: Make the script executable and run the tests**

```bash
git update-index --chmod=+x scripts/promote-overrides
```

Run: `ddev composer test:integration -- --filter PromoteOverridesWrapperTest`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
ddev composer verify:fast
git add scripts/promote-overrides tests/Integration/Promotion/PromoteOverridesWrapperTest.php
git commit -m "feat: add deployment-side promote-overrides orchestration wrapper"
```

---

### Task 20: Playwright state coverage (§11.14) — ancestry-gated

**Ancestry gate.** Run `git status --porcelain` and `git merge-base --is-ancestor feat/block-theme-fse-migration HEAD` before starting this task. Status must be empty and ancestry must exit `0`. The integration branch must not change while this unit runs. `package.json` is shared, and this task's one script line lands on top of merged Releases 1 and 2. Task 4 has not started and is not a dependency.

**Files:**
- Create: `tests/e2e/helpers/promotion.ts`
- Create: `tests/e2e/promotion-lifecycle.spec.ts`
- Create: `tests/fixtures/promotion/create-part-override.php`
- Create: `tests/fixtures/promotion/cleanup.php`
- Modify: `package.json` (ONE added script)

**Interfaces:**
- Consumes: the `wp agency` command surface and the running DDEV site.
- Produces: `npm run test:e2e:promotion`.

The two §11.14 items this task owns are "frontend unchanged after prepare/finalise" and "rollback restores frontend state". Use text and DOM assertions rather than screenshots: visual baselines in this repository are Linux-CI-authoritative (see the header comment in `playwright.config.ts`), and a mutating lifecycle test must not add baselines.

**Disposable proof checkout rule.** Run this spec only from a disposable proof worktree and disposable DDEV project created from the clean Unit 3A branch. The proof worktree owns its Git commit, promoted file changes, database, and `var/agency-state` paths. Stop that DDEV project and remove the verified disposable worktree after the test. Do not run this mutating test in the implementation checkout, and do not use `git reset`, `git checkout`, or another history-rewriting cleanup command.

- [ ] **Step 1: Write the fixtures**

`tests/fixtures/promotion/create-part-override.php` is a `wp eval-file` script that inserts a `wp_template_part` post with `post_name` `site-header`, `post_status` `publish`, a `post_content` containing the literal marker `PROMOTION E2E OVERRIDE`, and `wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' )` — without that term the override is invisible to the template resolver. It is idempotent.

`tests/fixtures/promotion/cleanup.php` force-deletes every `wp_template_part` row named `site-header`, then deletes every option whose name starts with `agency_promotion_lock_`, `agency_promotion_backup_`, or equals `agency_promotion_backups_index`, using `get_option()`/`delete_option()` over the index rather than a raw query. It prints the promotion ids it cleaned so the spec can also unlink their canonical manifests.

- [ ] **Step 2: Write the helper**

`tests/e2e/helpers/promotion.ts` exports:

```ts
/** Runs a WP-CLI command against the site under test. Resolves `wp` on PATH
 *  first (CI/DDEV container), then falls back to `ddev wp` (host runs).
 *  `env` is merged into the child process environment. */
export function wpCli( args: string[], env?: NodeJS.ProcessEnv ): string;

/** True when a WP-CLI command can actually be executed here. */
export function wpCliAvailable(): boolean;

/** True when `git status --porcelain` is empty — the precondition for mutating the checkout. */
export function repoIsClean(): boolean;

/** Absolute path of the active theme directory, read from WP-CLI. */
export function themeDir(): string;

/** Current bytes of a theme-relative file, for later restoration. */
export function capturePartFile( themeRelativePath: string ): string;

/** Writes previously captured bytes back into the theme. */
export function restoreOriginalPartFile( themeRelativePath: string, contents: string ): void;

/** Stages ONLY the given theme-relative file and commits it. Returns the new SHA. */
export function commitPreparedFile( themeRelativePath: string ): string;

/** Every AGENCY_* value the promotion commands need, resolved from the live site. */
export function promotionEnv(): NodeJS.ProcessEnv;
```

`promotionEnv()` returns:

```ts
{
	AGENCY_PROMOTION_HMAC_KEYS: JSON.stringify( { 'e2e': 'e2e-promotion-key-that-is-long-enough-32+' } ),
	AGENCY_PROMOTION_HMAC_SIGNING_KEY_ID: 'e2e',
	AGENCY_TARGET_SITE_UUID: wpCli( [ 'option', 'get', 'agency_platform_site_uuid' ] ).trim(),
	AGENCY_STATE_DIR: 'var/agency-state',
	AGENCY_REPO_ROOT: process.cwd(),
}
```

`AGENCY_DEPLOY_COMMIT` is added per call after the commit exists. Every `wpCli()` call in the spec passes `promotionEnv()`, because the site's own `.env` carries none of these.

- [ ] **Step 3: Write the failing spec**

```ts
const PART = 'parts/site-header.html';

test.describe( 'promotion lifecycle keeps the frontend stable', () => {
	let original = '';

	test.beforeAll( () => {
		if ( ! wpCliAvailable() ) {
			throw new Error( 'WP-CLI is required for the promotion lifecycle gate.' );
		}
		if ( ! repoIsClean() ) {
			throw new Error( 'The promotion lifecycle gate requires a clean working tree.' );
		}
		original = capturePartFile( PART );
		wpCli( [] );
	} );

	test.afterAll( () => {
		wpCli( [ 'eval-file', 'tests/fixtures/promotion/cleanup.php' ], promotionEnv() );
		rmSync( 'var/agency-state/e2e-bundle.json', { force: true } );
		rmSync( 'var/agency-state/e2e-manifest.json', { force: true } );
		rmSync( 'var/agency-state/promotions', { recursive: true, force: true } );
	} );

	test( 'the frontend is unchanged by prepare and finalize, and rollback restores it', async ( { page } ) => {
		const env = promotionEnv();

		// 1. Baseline: what a visitor sees from the Git-owned template part.
		await page.goto( '/' );
		const gitText = await page.locator( 'header.wp-block-template-part' ).innerText();

		// 2. A client edit lands in the database.
		wpCli( [ 'eval-file', 'tests/fixtures/promotion/create-part-override.php' ], env );
		await page.reload();
		const overriddenText = await page.locator( 'header.wp-block-template-part' ).innerText();
		expect( overriddenText ).not.toBe( gitText );

		// 3. Export, prepare, commit, seal, finalize.
		wpCli( [ 'agency', 'state-export', '--output=var/agency-state/e2e-bundle.json' ], env );
		wpCli( [ 'agency', 'promote-overrides', '--prepare', '--source=var/agency-state/e2e-bundle.json',
			'--select=template-parts:site-header', '--manifest=var/agency-state/e2e-manifest.json' ], env );

		const sha = commitPreparedFile( PART );
		wpCli( [ 'agency', 'promote-overrides', '--seal', '--manifest=var/agency-state/e2e-manifest.json',
			`--deploy-commit=${ sha }` ], env );
		wpCli( [ 'agency', 'promote-overrides', '--finalize', '--manifest=var/agency-state/e2e-manifest.json' ],
			{ ...env, AGENCY_DEPLOY_COMMIT: sha } );

		// 4. The visitor sees exactly the same thing — promotion is invisible.
		await page.reload();
		expect( await page.locator( 'header.wp-block-template-part' ).innerText() ).toBe( overriddenText );

		// 5. Simulate a deployment rollback: put the original file back.
		restoreOriginalPartFile( PART, original );
		await page.reload();
		expect( await page.locator( 'header.wp-block-template-part' ).innerText() ).toBe( gitText );

		// 6. `--rollback` re-applies the database override, so the client's work returns.
		wpCli( [ 'agency', 'promote-overrides', '--rollback', '--manifest=var/agency-state/e2e-manifest.json' ],
			{ ...env, AGENCY_DEPLOY_COMMIT: sha } );
		await page.reload();
		expect( await page.locator( 'header.wp-block-template-part' ).innerText() ).toBe( overriddenText );
	} );
} );
```

`commitPreparedFile()` stages ONLY `web/app/themes/site-theme/parts/site-header.html`, never the whole theme directory. The disposable proof worktree is removed after DDEV stops, so no Git cleanup command runs inside the spec.

- [ ] **Step 4: Add the npm script**

Insert one line next to the existing `test:e2e` entries in `package.json`:

```json
"test:e2e:promotion": "playwright test tests/e2e/promotion-lifecycle.spec.ts"
```

No dependency changed, so the lock file is untouched and CI's `npm ci` sync check is unaffected.

- [ ] **Step 5: Run and commit**

Run: `npm run test:e2e:promotion`
Expected: PASS. Missing WP-CLI, a dirty checkout, or a missing command is a failed required gate. It must not skip.

```bash
ddev composer verify:fast
git status --porcelain          # confirm only this task's files changed
git add tests/e2e/helpers/promotion.ts tests/e2e/promotion-lifecycle.spec.ts tests/fixtures/promotion/create-part-override.php tests/fixtures/promotion/cleanup.php package.json
git commit -m "test: add promotion lifecycle frontend coverage"
git status --porcelain          # now empty
```

---

### Task 21: CI job and `Plugin.php` registration — the LAST Release 3 commits

**Ancestry gate.** Run `git status --porcelain` and `git merge-base --is-ancestor feat/block-theme-fse-migration HEAD` immediately before this task. Status must be empty and ancestry must exit `0`. Both files are shared, and `Plugin.php` must be a clean one-line addition on top of merged Releases 1 and 2. Task 4 has not started and is not a dependency.

**Files:**
- Modify: `.github/workflows/ci.yml` (ONE required step in the existing DDEV-backed `e2e` job)
- Modify: `web/app/mu-plugins/agency-platform/src/Plugin.php` (ONE added line)

**Interfaces:**
- Consumes: `PromotionSubsystem::register()` (Task 7).
- Produces: nothing new.

- [ ] **Step 1: Add the required promotion step to CI**

Add this step to the existing DDEV-backed `e2e` job after the normal E2E and parity steps. That job already checks out the repository, starts DDEV, installs WordPress, installs Node dependencies, and installs Chromium. Reusing it avoids a second copy of the complete setup sequence.

```yaml
      # Required release gate. The spec uses a test-only key inside its
      # isolated DDEV site. Production keyrings still come from the host's
      # secret store.
      - name: Run promotion lifecycle end-to-end gate
        env:
          WP_BASE_URL: https://agency-starter.ddev.site
        run: npm run test:e2e:promotion
```

The spec uses the fixed test-only key returned by `promotionEnv()`. It does not use a repository secret. Missing WP-CLI, a dirty checkout, or a missing command fails this required gate. It never skips for those conditions.

- [ ] **Step 2: Add the one `Plugin.php` line**

Add `new \AgencyPlatform\State\Promotion\PromotionSubsystem(),` to the `$providers` array in `Plugin::boot()`, immediately after Task 2's `StateSubsystem` entry. **Use the fully qualified name and add no `use` import**, exactly as Task 2 does, so the diff is one added line and zero removed.

Verify:

```bash
git diff --stat web/app/mu-plugins/agency-platform/src/Plugin.php   # 1 insertion, 0 deletions
```

- [ ] **Step 3: Verify the commands are reachable**

Run: `ddev wp agency promote-overrides --help`
Expected: the WP-CLI help page listing every flag from Task 17.

Run: `ddev wp agency promotion-backups list`
Expected: `[]` on STDOUT and exit 0.

- [ ] **Step 4: Release 3 verification and commit**

```bash
ddev composer verify:fast
ddev composer verify
npm run lint
npm run build
npm run test:e2e
git status --porcelain          # confirm only these two files changed
git add .github/workflows/ci.yml web/app/mu-plugins/agency-platform/src/Plugin.php
git commit -m "feat: register the promotion subsystem and CI proof"
git status --porcelain          # now empty
```

**Stop after this commit.** Return control to the Sol orchestrator. The orchestrator runs the Release 3 review and gates, merges `feat/bt-task-3-promotion-lifecycle` into `feat/block-theme-fse-migration`, updates the tracking file, and stops its DDEV environment. Do not start Task 22 on the Release 3 branch.

---

## Release 4 — Global Styles promotion (independent; must NOT block Releases 1–3)

Unit 3A runs only on `feat/bt-task-3-promotion-lifecycle`. The orchestrator must complete the Release 3 gate, review, clean-status check, and non-squash merge of that branch into `feat/block-theme-fse-migration` before it creates `feat/bt-task-3-global-styles`. Unit 3B runs only on that new branch. No Task 22 or Task 23 change may be committed on the Unit 3A branch.

The orchestrator creates `feat/bt-task-3-global-styles` from the integration branch after Release 3 is merged. Before Task 22, verify:

```bash
git branch --show-current
git status --porcelain
git merge-base --is-ancestor feat/block-theme-fse-migration HEAD
```

Expected: branch `feat/bt-task-3-global-styles`, empty status, and ancestry exit 0. Release 4 has its own review and merge gate.

Until Task 23's gate passes, `PromotionStrategyRegistrar` registers no `global-styles` strategy, so `PromotionSelector` refuses `global-styles:active` outright and Global Styles stays export-and-diff. No Release 3 code path names `ThemeJsonAdapter` or `GlobalStylesPromotionStrategy`.

### Task 22: Theme JSON adapter with an injectable, fail-closed capability probe (§7.6)

`WP_Theme_JSON_Resolver` is documented as an internal Core API not intended for plugin use. **This class is the ONLY file in the project allowed to name any Theme JSON internal — including its cache-clearing calls.** `GlobalStylesPromotionStrategy` must never call `clean_cached_data()` itself.

**Files:**
- Create: `src/State/Promotion/ThemeJsonAdapter.php`
- Test: `tests/Integration/Promotion/ThemeJsonAdapterTest.php`

**Interfaces:**
- Consumes: `PromotionException` (Task 1).
- Produces:
  ```php
  final class ThemeJsonAdapter {
      /** Every internal Core symbol this adapter depends on. */
      public const REQUIRED_CLASSES = array( 'WP_Theme_JSON', 'WP_Theme_JSON_Data', 'WP_Theme_JSON_Resolver' );
      public const REQUIRED_METHODS = array(
          'WP_Theme_JSON_Resolver' => array( 'get_theme_data', 'get_user_data', 'get_merged_data',
              'clean_cached_data', 'get_user_global_styles_post_id' ),
          'WP_Theme_JSON'          => array( 'get_raw_data', 'merge' ),
          'WP_Theme_JSON_Data'     => array( 'get_theme_json', 'update_with' ),
      );
      public const REQUIRED_FUNCTIONS = array( 'wp_get_global_settings', 'wp_get_global_styles' );
      public const REQUIRED_CONSTANTS = array( 'WP_Theme_JSON::LATEST_SCHEMA' );

      /** @param callable(string):bool|null $probe test seam: symbol name -> exists. */
      public function __construct( ?callable $probe = null );

      public function supported(): bool;
      /** @return list<string> the symbols that are missing */
      public function missing_symbols(): array;
      /** @throws PromotionException exit 1 when supported() is false. */
      public function require_support(): void;

      /** @return array<string,mixed> */
      public function theme_origin(): array;
      /** @return array<string,mixed> */
      public function user_origin(): array;
      /** @return array{settings: array<string,mixed>, styles: array<string,mixed>} */
      public function resolved(): array;
      /** WordPress-aware merge — never array_merge_recursive.
       *  @param array<string,mixed> $theme_json @param array<string,mixed> $user_origin
       *  @return array<string,mixed> */
      public function merge_user_into_theme( array $theme_json, array $user_origin ): array;
      /** @param array<string,mixed> $data */
      public function validate_theme_json( array $data ): void;
      /** Clears every Theme JSON cache this adapter owns. The ONLY cache entry point. */
      public function refresh_caches(): void;
      /** @return array{settings: array<string,mixed>, styles: array<string,mixed>} */
      public function resolved_without_user_origin(): array;
      /** Named filter callback — NEVER a closure (master spec §4). @param mixed $theme_json */
      public function filter_empty_user_data( $theme_json );
      /** @param array<string,mixed> $user_origin */
      public function write_user_origin( array $user_origin ): void;
      public function reset_user_origin(): void;
      /** @return array<string,mixed>|null the raw user-origin post content, or null when no post exists */
      public function user_origin_post(): ?array;
      public function latest_schema(): int;
  }
  ```

- [ ] **Step 1: Write the failing compatibility test**

```php
public function test_the_adapter_supports_the_locked_wordpress_version(): void {
	$adapter = new ThemeJsonAdapter();

	self::assertSame( array(), $adapter->missing_symbols(), 'A WordPress update changed the Theme JSON API shape.' );
	self::assertTrue( $adapter->supported() );
}

public function test_the_probe_covers_every_declared_symbol(): void {
	$seen    = array();
	$adapter = new ThemeJsonAdapter(
		static function ( string $symbol ) use ( &$seen ): bool {
			$seen[] = $symbol;
			return true;
		}
	);

	$adapter->missing_symbols();

	self::assertContains( 'WP_Theme_JSON_Data', $seen );
	self::assertContains( 'WP_Theme_JSON_Data::update_with', $seen );
	self::assertContains( 'WP_Theme_JSON::LATEST_SCHEMA', $seen );
	self::assertContains( 'wp_get_global_settings()', $seen );
}

public function test_it_fails_closed_when_a_symbol_is_missing(): void {
	$adapter = new ThemeJsonAdapter(
		static fn( string $symbol ): bool => 'WP_Theme_JSON_Resolver::get_user_data' !== $symbol
	);

	self::assertFalse( $adapter->supported() );
	$this->assert_exit_code( 1, fn() => $adapter->require_support() );
}

public function test_merge_preserves_non_style_top_level_keys(): void {
	$merged = ( new ThemeJsonAdapter() )->merge_user_into_theme(
		array(
			'version'       => 3,
			'templateParts' => array( array( 'name' => 'site-header', 'area' => 'header' ) ),
			'settings'      => array( 'color' => array( 'custom' => true ) ),
		),
		array( 'version' => 3, 'styles' => array( 'color' => array( 'background' => '#fff' ) ) )
	);

	self::assertSame( array( array( 'name' => 'site-header', 'area' => 'header' ) ), $merged['templateParts'] );
	self::assertSame( '#fff', $merged['styles']['color']['background'] );
}

public function test_resolved_without_user_origin_differs_when_a_user_style_exists(): void { /* proves the simulation removes the origin */ }

public function test_a_missing_global_styles_post_is_created_not_fatal(): void {
	$this->delete_the_global_styles_post();

	self::assertIsArray( ( new ThemeJsonAdapter() )->user_origin() );
}

public function test_a_failed_write_is_a_hard_error(): void { /* wp_update_post returns WP_Error → exit 1 */ }
```

Run: `ddev composer test:integration -- --filter ThemeJsonAdapterTest` → FAIL.

- [ ] **Step 2: Implement the adapter**

- The default probe answers `class_exists()`, `method_exists()`, `function_exists()`, and `defined()`/`constant()` for a `Class::CONST` entry. `missing_symbols()` walks `REQUIRED_CLASSES`, then every `Class::method` pair in `REQUIRED_METHODS`, then `REQUIRED_FUNCTIONS` (as `name()`), then `REQUIRED_CONSTANTS`, and returns the ones the probe rejects. `supported()` is `array() === missing_symbols()`. `require_support()` throws `PromotionException::hard( 'This WordPress version does not expose the Theme JSON API shape this adapter needs: <list>. Global Styles promotion is refused.' )`.
- Every public method calls `require_support()` first.
- `merge_user_into_theme()`:
  ```php
  $merged = new \WP_Theme_JSON(
      array( 'version' => $theme_json['version'] ?? $this->latest_schema(),
             'settings' => $theme_json['settings'] ?? array(),
             'styles'   => $theme_json['styles'] ?? array() ),
      'theme'
  );
  $merged->merge(
      new \WP_Theme_JSON(
          array( 'version' => $user_origin['version'] ?? $this->latest_schema(),
                 'settings' => $user_origin['settings'] ?? array(),
                 'styles'   => $user_origin['styles'] ?? array() ),
          'custom'
      )
  );
  $raw = $merged->get_raw_data();
  ```
  Then rebuild the result as `$theme_json` with only `settings` and `styles` replaced from `$raw`. Every other top-level key (`$schema`, `version`, `templateParts`, `customTemplates`, `patterns`, `blockTypes`) is copied through verbatim — losing `templateParts` would un-register the theme's parts and break the Site Editor.
- `refresh_caches()` calls `\WP_Theme_JSON_Resolver::clean_cached_data()` and `wp_cache_flush_runtime()` when it exists. **Nothing outside this class may call either.**
- `resolved_without_user_origin()`: `add_filter( 'wp_theme_json_data_user', array( $this, 'filter_empty_user_data' ) )`, `refresh_caches()`, read `wp_get_global_settings()` and `wp_get_global_styles()`, then `remove_filter( … )` and `refresh_caches()` again inside a `finally`. `filter_empty_user_data()` returns `new \WP_Theme_JSON_Data( array( 'version' => $this->latest_schema() ), 'custom' )`. **Named method, never a closure.**
- `user_origin_post()` resolves the post id with `\WP_Theme_JSON_Resolver::get_user_global_styles_post_id()`. A `0`/falsy id after that call is `PromotionException::hard( 'WordPress could not create the Global Styles post for this theme.' )` — core creates one on demand, so a failure here is real.
- `write_user_origin()` / `reset_user_origin()` call `wp_update_post( array( 'ID' => $id, 'post_content' => wp_json_encode( … ) ), true )`; `is_wp_error()` throws `PromotionException::hard()`. `reset_user_origin()` writes `array( 'version' => $this->latest_schema(), 'isGlobalStylesUserThemeJSON' => true )` — WordPress always keeps exactly one such post per theme, so the reset is an update, never a delete. That is why the Global Styles record's `postFinalizeRecordState` is `present`.
- `validate_theme_json()` asserts `version` is an integer, that `new \WP_Theme_JSON( $data, 'theme' )` does not throw, and that `get_raw_data()`'s `settings`/`styles` still contain every input key (catching silently dropped properties).

- [ ] **Step 3: Run and commit**

Run: `ddev composer test:integration -- --filter ThemeJsonAdapterTest`
Expected: PASS.

Because this repository targets exactly one WordPress version (resolved by `composer.lock`), there is deliberately no version matrix. `test_the_adapter_supports_the_locked_wordpress_version()` is the tripwire: re-run this suite on every WordPress dependency update (Dependabot PR).

```bash
ddev composer verify:fast
git add web/app/mu-plugins/agency-platform/src/State/Promotion/ThemeJsonAdapter.php tests/Integration/Promotion/ThemeJsonAdapterTest.php
git commit -m "feat: add fail-closed theme json adapter"
```

---

### Task 23: Global Styles promotion strategy and the resolved-output-equivalence gate — Release 4 gate

**The data-flow contract, stated once.** Master spec §7.6 step 3 requires the fully resolved settings/styles to be recorded BEFORE promotion. The bundle cannot carry them (Task 2 exports the user origin, not a resolved snapshot, and this task may not change that). The contract is therefore **target-side**: at finalize, AFTER the concurrency check has proven the live user origin still matches the exported record, the adapter records the current resolved output on the production host. That snapshot is provably produced by the same origin the bundle exported, so it is a valid "before" state. It is held in memory across the reset and its hash is persisted as `preResetResolvedHash` for audit and rollback. `expectedPostResetHash` stays `null` for this record, which is why `defers_expected_hash()` exists and why the schema allows null.

**Files:**
- Create: `src/State/Promotion/GlobalStylesPromotionStrategy.php`
- Modify: `src/State/Promotion/PromotionStrategyRegistrar.php` (add the third strategy)
- Modify: `src/State/Promotion/PromotionFinalizer.php` (the deferred-hash branch)
- Test: `tests/Integration/Promotion/GlobalStylesPromotionTest.php`
- Modify test: `tests/Integration/Promotion/PromotionStrategyRegistrarTest.php` (Release 4 registration contract)

**Interfaces:**
- Consumes: `ThemeJsonAdapter` (Task 22) and everything from Release 3.
- Produces:
  ```php
  final class GlobalStylesPromotionStrategy implements PreparablePromotionStrategy {
      public function __construct( private ThemeJsonAdapter $adapter, private StateGateway $gateway,
          private CanonicalJsonFileWriter $json_writer ) {}
      public function provider_slug(): string;                 // 'global-styles'
      public function theme_relative_path( string $record_slug ): string;   // 'theme.json'
      public function declares( ThemeDeclaredSlugs $declared, string $record_slug ): bool;  // 'active' === $record_slug
      public function post_finalize_record_state(): string;    // 'present'
      public function defers_expected_hash(): bool;            // true
      /** Steps 1-6 of master spec §7.6, driven by the EXPORTED user origin. */
      public function prepare( StateRecord $record, string $theme_root ): StagedPromotionEntry;
      public function expected_post_reset_hash( StateRecord $record ): string;   // throws: deferred
      /** Step 3, on the target: snapshot before anything changes. */
      public function capture_pre_reset_state(): array;
      /** Step 7. */
      public function reset( StateRecord $record ): void;
      /** Steps 8-10. @param array{settings:array,styles:array} $expected_resolved
       *  @return array{equivalent: bool, difference: string|null, hash: string} */
      public function verify_resolved_equivalence( array $expected_resolved ): array;
      public function resolve_current_hash( string $record_slug ): ?string;
      /** @param array<string,mixed> $backup */
      public function restore( StateRecord $record, array $backup ): void;
      /** @return array<string,mixed> */
      public function capture_backup( StateRecord $record ): array;
      /** @param array{settings:array<string,mixed>, styles:array<string,mixed>} $resolved */
      public static function resolved_hash( array $resolved ): string;
  }
  ```

- [ ] **Step 1: Write the failing equivalence test**

Operate on a COPY of the theme in a temp directory. **Never touch the repository's real `theme.json`, which Task 1 owns.**

```php
public function test_prepare_uses_the_exported_user_origin_not_the_local_one(): void {
	// The bundle carries production's user origin. A different local origin
	// must not leak into the promoted theme.json.
	$this->save_user_global_style( array( 'styles' => array( 'color' => array( 'background' => '#local' ) ) ) );

	$prepared = $this->strategy->prepare( $this->exported_record_with_background( '#exported' ), $this->theme_json_path );
	$body     = file_get_contents( $prepared['preparedPath'] );

	self::assertStringContainsString( '#exported', $body );
	self::assertStringNotContainsString( '#local', $body );
}

public function test_a_user_style_round_trips_without_changing_resolved_output(): void {
	$this->save_user_global_style( array( 'styles' => array( 'color' => array( 'background' => '#101010' ) ) ) );

	$prepared = $this->strategy->prepare( $this->exported_record(), $this->theme_json_path );
	file_put_contents( $this->theme_json_path, file_get_contents( $prepared['preparedPath'] ) );

	$before = $this->strategy->capture_pre_reset_state();
	$this->strategy->reset( $this->live_record() );
	$outcome = $this->strategy->verify_resolved_equivalence( $before );

	self::assertTrue( $outcome['equivalent'], (string) $outcome['difference'] );
}

public function test_promotion_is_refused_when_resolved_output_changes(): void {
	$this->save_user_global_style( $this->style_the_merge_cannot_express() );

	$outcome = $this->finalizer->finalize( $this->global_styles_manifest_path );

	self::assertSame( 1, $outcome['outcome']->exit_code() );
	self::assertSame( 'resolved-output-drift', $outcome['manifest']->record( 'global-styles:active' )['finalizeRefusalReason'] );
	// The user origin must be back exactly as it was.
	self::assertSame( $this->original_user_origin_hash, $this->gateway->read_live_record( 'global-styles', 'active' )['contentHash'] );
}

public function test_the_pre_reset_hash_is_recorded_in_the_manifest(): void {
	$record = $this->finalizer->finalize( $this->global_styles_manifest_path )['manifest']->record( 'global-styles:active' );

	self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $record['preResetResolvedHash'] );
	self::assertSame( 'present', $record['postFinalizeRecordState'] );
}

public function test_theme_json_keeps_its_template_parts_after_promotion(): void {
	$prepared = $this->strategy->prepare( $this->exported_record(), $this->theme_json_path );

	self::assertArrayHasKey( 'templateParts', json_decode( file_get_contents( $prepared['preparedPath'] ), true ) );
}

public function test_the_strategy_makes_global_styles_selectable_only_now(): void {
	// The Release 3 boundary, from the other side: with the strategy
	// registered, the selector accepts what it used to refuse.
	PromotionStrategies::reset();
	( new PromotionStrategyRegistrar() )->register();

	self::assertNotNull( PromotionStrategies::for_provider( 'global-styles' ) );
	self::assertCount( 1, PromotionSelector::parse( 'global-styles:active', $this->has_strategy, $this->has_record ) );
}
```

Run: `ddev composer test:integration -- --filter GlobalStylesPromotionTest` → FAIL.

- [ ] **Step 2: Implement `GlobalStylesPromotionStrategy`**

`prepare()` implements the same `PreparablePromotionStrategy` contract as templates and parts. It reads the *exported* user origin only from `$record->content()`, builds `$merged` with `ThemeJsonAdapter::merge_user_into_theme()`, validates it, and returns `$this->json_writer->stage( 'theme.json', $merged, $record->key() )`. It never reads `$this->adapter->user_origin()` during preparation. It never uses `PreparedFileWriter`, `ResolvedTemplateNormalizer`, or block-markup normalisation for `theme.json`.

Follow master spec §7.6's ten numbered steps, never using a generic recursive `array_merge`:

1–2. `prepare()` reads the theme origin from the on-disk `theme.json` and the user origin from `$record->content()` — **the EXPORTED production origin**, not `$this->adapter->user_origin()`.
3. On the target, `capture_pre_reset_state()` returns `$this->adapter->resolved()`.
4. `$merged = $this->adapter->merge_user_into_theme( $theme_json, $exported_user_origin );`
5. Stage `$merged` through `CanonicalJsonFileWriter`, which writes raw canonical JSON bytes and exactly one trailing newline. Do not send `theme.json` through `PreparedFileWriter` or any block-markup normaliser.
6. `$this->adapter->validate_theme_json( $merged );`
7. `reset()` calls `$this->adapter->reset_user_origin()`.
8–9. `verify_resolved_equivalence()` calls `$this->adapter->refresh_caches()`, re-reads `$this->adapter->resolved()`, and compares `resolved_hash()` values. **It never names a Theme JSON internal itself.**
10. A difference returns `equivalent => false` with a human-readable first divergent key path; the finalizer then restores the user origin from the backup and refuses with `resolved-output-drift`.

`resolved_hash()` canonicalises by recursively `ksort()`ing and passing through `$this->gateway->hash_content()` — resolved settings/styles are order-insensitive, so a raw encode would produce false drift.

`capture_backup()` returns `array( 'post' => get_post( $record->object_id(), ARRAY_A ), 'terms' => array(), 'meta' => get_post_meta( $record->object_id() ) )`. `restore()` uses `wp_update_post()` on the EXISTING post (the row always exists) rather than `wp_insert_post()`, and checks `is_wp_error()`.

`expected_post_reset_hash()` throws `PromotionException::hard( 'Global Styles computes its expectation on the target host; call capture_pre_reset_state() instead.' )` so a mis-wired caller fails loudly.

- [ ] **Step 3: Wire the deferred-hash branch into the finalizer**

In `PromotionFinalizer`'s per-record loop, when `$strategy->defers_expected_hash()` is true:

- The finalizer already received the staged `theme.json` from `PromotionPreparer` through `GlobalStylesPromotionStrategy::prepare()`. It must continue to call the selected strategy for capture, reset, verification, backup, and restore; it must not reproduce Theme JSON merging or cache handling itself.
- Between the concurrency check (step 2) and the backup (step 4), call `$expected = $strategy->capture_pre_reset_state();` and record `preResetResolvedHash` = `GlobalStylesPromotionStrategy::resolved_hash( $expected )`.
- After the reset, call `$outcome = $strategy->verify_resolved_equivalence( $expected );` instead of comparing against `expectedPostResetHash`. `false === $outcome['equivalent']` restores from the backup and refuses with `resolved-output-drift`.
- `postFinalizeSemanticHash` becomes the re-read live record's `contentHash` (the reset user origin), and `postFinalizeModifiedGmt` its `modifiedGmt`, because `postFinalizeRecordState` is `present`.

- [ ] **Step 4: Register the third strategy**

Add one line to `PromotionStrategyRegistrar::add_strategies()`:

```php
$strategies['global-styles'] = new GlobalStylesPromotionStrategy( new ThemeJsonAdapter(), new StateGateway(), new CanonicalJsonFileWriter( get_stylesheet_directory() ) );
```

Update `RELEASE_3_SLUGS`'s docblock to note that Release 4 added the third slug. Update `PromotionStrategyRegistrarTest.php` with a Release 4 test that registers the registrar, asserts `array( 'global-styles', 'template-parts', 'templates' )` as the sorted keys, and asserts that `global-styles` is a `PreparablePromotionStrategy`. This test is Unit 3B-owned because it changes only after the Release 3 merge gate.

- [ ] **Step 5: Run everything**

Run: `ddev composer verify && npm run lint && npm run build && npm run test:e2e && npm run test:visual && npm run test:accessibility`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
ddev composer verify:fast
git status --porcelain          # confirm only this task's files changed
git add web/app/mu-plugins/agency-platform/src/State/Promotion/GlobalStylesPromotionStrategy.php web/app/mu-plugins/agency-platform/src/State/Promotion/PromotionStrategyRegistrar.php tests/Integration/Promotion/GlobalStylesPromotionTest.php tests/Integration/Promotion/PromotionStrategyRegistrarTest.php
git commit -m "feat: migrate global styles through the theme json adapter"
git status --porcelain          # now empty
```

---

## Definition of done

### Release 3 gate (master spec §13 — verify each line before opening the merge)

- [ ] Promotion is selective and dependency-aware: `--select=<provider>:<slug>` drives everything; the v1 navigation policy allows a ref-less source block only when Task 2 exported WordPress core's deterministic fallback identity/hash and finalisation resolves the same fallback hash. Explicit-ref removal uses the same check. A missing or unresolvable source/target fallback, an unresolvable ref, and two refs that cannot stay equivalent after ref removal are refused; synced-pattern, attachment, gallery, cover, site-logo, font-file, plugin-block, post-meta and unknown references are still hard-refused with the full §7.4 report (Tasks 9, 10, 14).
- [ ] Promotion is sealed before finalisation: `--seal` binds `deployCommit`, re-verifies every prepared file hash, proves the content is inside that commit, and re-signs; `--finalize` refuses an unsealed manifest (Tasks 11, 14).
- [ ] Bundles and manifests are signed and tampering is detected with exit `4`; the manifest store verifies the signature BEFORE the schema; the keyring is mandatory; rotation works; a bundle signature cannot be replayed as a manifest signature (Tasks 3, 4, 18).
- [ ] Per-record-key locking holds from `--finalize` through `--confirm`/`--rollback`, acquires in sorted order, releases its own locks on conflict and exits `3`, re-enters same-promotion locks, reclaims expired locks, cannot be stolen back by an abandoned heartbeat, releases every non-promoted record's lock before returning, and is refreshed by `--heartbeat` for the promoted records only (Tasks 12, 14, 17).
- [ ] Concurrent changes are never overwritten: a record edited after export is refused; a record DELETED after export is refused as `concurrent-delete`, never silently accepted; rollback refuses a record a newer promotion claimed, a record changed since finalisation, and a deleted record that has been re-created (Tasks 14, 15, 16).
- [ ] Finalisation is backed up and reversible; backups are non-autoloaded, chunked, retained past `--confirm`, and pruned only by the explicit command after the retention window; restore recreates multi-value meta as separate rows and records the restored object id and modification marker (Tasks 13, 15, 18).
- [ ] `--rollback` is idempotent (already-restored records are skipped) and leaves the manifest in a re-finalisable state (Tasks 15, 16).
- [ ] `scripts/promote-overrides` orchestrates remote WP-CLI finalize through a shell-safe adapter, CI Playwright verification, heartbeat refresh with loss detection, and confirm or automatic rollback, settling at most once and exiting non-zero after a rollback with logs preserved (Task 19).
- [ ] The CLI writes exactly one JSON document to STDOUT per run, all diagnostics to STDERR, honours every `-` direction including `heartbeat`, and lists backups as JSON by default (Task 17).
- [ ] Every §11.13 promotion, locking, rollback, seal, heartbeat and manifest-tamper unit and integration test passes (Task 18).
- [ ] The §11.14 state Playwright items pass: the frontend is unchanged after prepare/finalise, and rollback restores frontend state (Task 20).
- [ ] The §9.5 vertical-slice workflow proof passes end to end, and it was proven BEFORE the full matrices were built (Task 16).
- [ ] `ddev composer verify`, `npm run lint`, `npm run build` and `npm run test:e2e` all pass, and `git status --porcelain` is empty after the final commit — no bundle, manifest, backup payload, secret or customer data is committed.
- [ ] No Release 3 code path names `ThemeJsonAdapter` or `GlobalStylesPromotionStrategy`, and `PromotionStrategies::all()` contains exactly `template-parts` and `templates`, so Global Styles remains export-and-diff.

### Release 4 gate (master spec §13 — strictly separable, merged after Release 3)

- [ ] Global Styles round-trips without resolved-output drift, comparing a target-side pre-reset snapshot against the post-reset resolution on the WordPress version resolved by `composer.lock` (Tasks 22, 23).
- [ ] `prepare()` merges the EXPORTED production user origin, never the local one (Task 23).
- [ ] Every internal Theme JSON call — including cache clearing — is inside `ThemeJsonAdapter`, which probes every class, method, function and constant it uses, fails closed on an unsupported shape through an injectable probe, and handles a missing Global Styles post, a creation failure and an update failure (Task 22).
- [ ] Promotion of `global-styles:active` is refused whenever resolved output changes, and the user origin is restored intact (Task 23).
- [ ] `theme.json` keeps `templateParts`, `customTemplates` and every other non-style top-level key after promotion (Task 23).
- [ ] `ddev composer verify`, `npm run lint`, `npm run build`, `npm run test:e2e`, `npm run test:visual` and `npm run test:accessibility` all pass, and `git status --porcelain` is empty after the final commit.
