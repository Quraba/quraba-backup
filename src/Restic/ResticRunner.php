<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Psr\Log\LoggerInterface;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\ProcessExecutionFailed;
use Quraba\Backup\Exceptions\ResticCommandFailed;
use Quraba\Backup\Exceptions\ResticUnavailable;
use Quraba\Backup\Restore\Live\MediaStagingArea;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Support\Process\ChildEnvironment;
use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceArea;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Throwable;

/**
 * THE single process boundary for Restic. No other package class executes
 * Restic.
 *
 * - Only the typed operations below exist; there is deliberately no way to
 *   run an arbitrary Restic command.
 * - Commands are argument arrays handed to Symfony Process (no shell).
 * - The child environment is minimal: inherited variables (including every
 *   .env secret Laravel loaded) are stripped; the repository, password file
 *   path and storage credentials are injected explicitly. Secrets never
 *   appear in argv.
 * - Every process has a positive, operation-specific timeout.
 * - stdout/stderr are redacted before they are returned, logged or thrown.
 * - A launch failure (ProcessExecutionFailed) is distinguished from Restic
 *   exiting unsuccessfully (a ResticResult with a non-zero exit code, which
 *   callers turn into a classified exception with throwIfFailed()).
 */
final class ResticRunner
{
    private ?ResticBinary $binary = null;

    private ?string $binaryFingerprint = null;

    public function __construct(
        private readonly ResticConfig $config,
        private readonly ResticBinaryResolver $resolver,
        private readonly RepositoryContextResolver $repositories,
        private readonly ProcessFactory $processes,
        private readonly ResticRedactor $redactor,
        private readonly PackagePaths $paths,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The resolved, verified binary. Verification is cached for the life of
     * the PHP process but is repeated whenever the file changes on disk, so a
     * long-running process can never keep using a replaced, unverified binary.
     */
    public function binary(): ResticBinary
    {
        if (! $this->config->enabled) {
            throw new ResticUnavailable('Restic is disabled (QURABA_BACKUP_RESTIC_ENABLED=false).');
        }

        if ($this->binary !== null && $this->binaryFingerprint === self::fingerprint($this->binary->path)) {
            return $this->binary;
        }

        $this->binary = $this->resolver->resolve($this->probeBinary(...));
        $this->binaryFingerprint = self::fingerprint($this->binary->path);

        return $this->binary;
    }

    /**
     * Runs `<path> version --json` against a candidate binary (installer and
     * resolver use this to verify binaries before trusting them).
     */
    public function probeBinary(string $path): ResticVersionInfo
    {
        if (! is_file($path)) {
            throw ProcessExecutionFailed::launchFailed(ResticOperation::Version->label(), sprintf('[%s] is not an existing file', $path));
        }

        $result = $this->execute(ResticOperation::Version, ['version', '--json'], $path, null);

        if (! $result->successful()) {
            throw new ResticCommandFailed(sprintf('`restic version` exited with code %d: %s', $result->exitCode, $this->redactor->diagnostic($result->stderr, 500)));
        }

        return ResticVersionInfo::fromJson($result->json());
    }

    public function version(): ResticVersionInfo
    {
        return $this->binary()->version;
    }

    /**
     * Initializes a new repository. Only ever called by the explicit
     * repository initialization flow, never as a reaction to an error.
     *
     * @internal use {@see ResticRepository::initialize()}
     */
    public function init(): ResticResult
    {
        return $this->runRepository(ResticOperation::Init, [
            'init',
            '--repository-version', (string) ResticRelease::REPOSITORY_VERSION,
        ]);
    }

    /**
     * Reads the repository config (cheapest proof of existence + password).
     */
    public function catConfig(): ResticResult
    {
        return $this->runRepository(ResticOperation::CatConfig, ['cat', 'config', '--no-lock']);
    }

    /**
     * Lists snapshots, filtered EITHER by tags OR by exact IDs.
     *
     * Restic tag semantics: tags joined by commas inside ONE --tag flag must
     * ALL be present (AND); repeating --tag would mean OR. Identity filters
     * are therefore always passed as a single comma-joined --tag value.
     *
     * Restic ignores tag filters when explicit IDs are given ("Ignoring
     * filters: explicit snapshot ids are given"), so combining them is
     * refused here: callers must check the identity of an exact-ID result
     * themselves. An unknown ID (or one Restic fails to load) is silently
     * skipped with exit 0, so an empty exact-ID result is NOT proof of
     * absence; absence is only ever proven from a tag-filtered listing.
     *
     * @param  list<string>  $tags  all tags must match
     * @param  list<string>  $snapshotIds  exact full snapshot IDs only
     */
    public function snapshots(array $tags = [], array $snapshotIds = []): ResticResult
    {
        if ($tags !== [] && $snapshotIds !== []) {
            throw new ResticCommandFailed('Restic ignores tag filters when explicit snapshot IDs are given; query by tags or by IDs, not both.');
        }

        $arguments = ['snapshots', '--json', '--no-lock'];

        if ($tags !== []) {
            $arguments[] = '--tag';
            $arguments[] = implode(',', ResticTag::assertAll($tags));
        }

        if ($snapshotIds !== []) {
            $arguments[] = '--';

            foreach ($snapshotIds as $snapshotId) {
                $arguments[] = Identifiers::assertFullSnapshotId($snapshotId);
            }
        }

        return $this->runRepository(ResticOperation::Snapshots, $arguments);
    }

    public function listLocks(): ResticResult
    {
        return $this->runRepository(ResticOperation::ListLocks, ['list', 'locks', '--no-lock']);
    }

    public function stats(?string $snapshotId = null, string $mode = 'restore-size'): ResticResult
    {
        if (! in_array($mode, ['restore-size', 'files-by-contents', 'raw-data', 'blobs-per-file'], true)) {
            throw new ResticCommandFailed(sprintf('Unsupported stats mode [%s].', $mode));
        }

        $arguments = ['stats', '--json', '--no-lock', '--mode', $mode];

        if ($snapshotId !== null) {
            $arguments[] = Identifiers::assertFullSnapshotId($snapshotId);
        }

        return $this->runRepository(ResticOperation::Stats, $arguments);
    }

    public function backup(ResticBackupRequest $request): ResticResult
    {
        $arguments = ['backup', '--json', '--host', $request->host];

        foreach ($request->tags as $tag) {
            $arguments[] = '--tag';
            $arguments[] = $tag;
        }

        return $this->runRepository(ResticOperation::Backup, [...$arguments, '--', ...$request->paths]);
    }

    /**
     * Restores an exact snapshot into a private operation workspace. Restoring
     * into live application paths is structurally impossible through this API.
     */
    public function restore(string $snapshotId, OperationWorkspace $workspace): ResticResult
    {
        $snapshotId = Identifiers::assertFullSnapshotId($snapshotId);
        $target = $workspace->directory(WorkspaceArea::Restore, 'snapshot-'.substr($snapshotId, 0, 16));

        if ((@scandir($target) ?: []) !== ['.', '..']) {
            throw new ResticCommandFailed('The restore target inside the workspace must be empty.');
        }

        return $this->runRepository(ResticOperation::Restore, ['restore', $snapshotId, '--target', $target, '--json']);
    }

    /**
     * Restores ONE media root of an exact snapshot into a private operation
     * workspace: the root directory itself (with its own mode and times) and
     * everything below it — none of its ancestors. The result is
     * `{workspace}/restore/snapshot-…/{root name}/{root directory}`.
     */
    public function restoreRoot(string $snapshotId, string $snapshotPath, OperationWorkspace $workspace, string $rootName): ResticResult
    {
        self::assertRootName($rootName);

        return $this->restoreRootInto($snapshotId, $snapshotPath, $workspace->directory(WorkspaceArea::Restore, 'snapshot-'.substr(Identifiers::assertFullSnapshotId($snapshotId), 0, 16).'/'.$rootName));
    }

    /**
     * The same, into a private media staging area of a live restore. The
     * area is created and proven private, empty and outside every live root
     * by MediaStaging; live application paths are never a Restic target.
     */
    public function restoreRootToStaging(string $snapshotId, string $snapshotPath, MediaStagingArea $area, string $rootName): ResticResult
    {
        self::assertRootName($rootName);
        $target = $area->target.'/'.$rootName;

        if (is_link($area->target) || ! is_dir($area->target) || file_exists($target) || is_link($target) || ! @mkdir($target, 0700)) {
            throw new ResticCommandFailed('The media staging target for a root could not be created fresh inside its staging area.');
        }

        return $this->restoreRootInto($snapshotId, $snapshotPath, $target);
    }

    /**
     * The path of a snapshot root as Restic addresses it inside a snapshot:
     * absolute, forward slashes, `C:/x` as `/C/x`.
     */
    public static function snapshotPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = (string) preg_replace('~^([A-Za-z]):/~', '/$1/', $path);

        if (! str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path.'/', '/../') || str_contains($path.'/', '/./') || str_contains($path, '//') || rtrim($path, '/') === '') {
            throw new ResticCommandFailed('A snapshot root must be a clean absolute path.');
        }

        return rtrim($path, '/');
    }

    /**
     * `restic restore ID:{parent} --include /{directory}`: Restic restores
     * the subfolder `parent` of the snapshot, limited to the root directory,
     * so no ancestor directory (and none of its metadata) is ever written.
     */
    private function restoreRootInto(string $snapshotId, string $snapshotPath, string $target): ResticResult
    {
        $snapshotId = Identifiers::assertFullSnapshotId($snapshotId);
        $path = self::snapshotPath($snapshotPath);
        $directory = basename($path);
        $parent = substr($path, 0, -strlen($directory) - 1);

        // Include patterns are globs: a root whose name is a pattern cannot be addressed exactly.
        if ($directory === '' || strpbrk($directory, '*?[]\\!') !== false) {
            throw new ResticCommandFailed('The media root directory name cannot be used as an exact restore pattern.');
        }

        if (is_link($target) || ! is_dir($target) || (@scandir($target) ?: []) !== ['.', '..']) {
            throw new ResticCommandFailed('The restore target of a media root must be an existing, empty, real directory.');
        }

        return $this->runRepository(ResticOperation::Restore, ['restore', $parent === '' ? $snapshotId : $snapshotId.':'.$parent, '--target', $target, '--include', '/'.$directory, '--json']);
    }

    private static function assertRootName(string $rootName): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $rootName) !== 1) {
            throw new ResticCommandFailed('Invalid media root name.');
        }
    }

    public function check(bool $readData = false): ResticResult
    {
        return $this->runRepository(ResticOperation::Check, $readData ? ['check', '--read-data'] : ['check']);
    }

    /**
     * Forgets exact snapshots only. Policy-based `--keep-*` forgetting is
     * deliberately not exposed: retention computes exact IDs itself.
     *
     * @param  list<string>  $snapshotIds  validated as non-empty at runtime
     */
    public function forget(array $snapshotIds): ResticResult
    {
        if ($snapshotIds === []) {
            throw new \InvalidArgumentException('Restic forget requires at least one exact snapshot ID.');
        }

        $ids = array_map(Identifiers::assertFullSnapshotId(...), $snapshotIds);

        return $this->runRepository(ResticOperation::Forget, ['forget', '--json', '--', ...$ids]);
    }

    public function prune(bool $dryRun): ResticResult
    {
        return $this->runRepository(ResticOperation::Prune, $dryRun ? ['prune', '--dry-run'] : ['prune']);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runRepository(ResticOperation $operation, array $arguments): ResticResult
    {
        $binary = $this->binary();
        $context = $this->repositories->resolve();

        return $this->execute($operation, $arguments, $binary->path, $context);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function execute(ResticOperation $operation, array $arguments, string $binaryPath, ?RepositoryContext $context): ResticResult
    {
        $timeout = $this->config->timeout($operation->timeoutClass());
        $process = $this->processes->make(
            [$binaryPath, ...$arguments],
            $context === null ? null : $this->workingDirectory(),
            ChildEnvironment::build($this->environment($context)),
            (float) $timeout,
        );

        $started = hrtime(true);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->logFailure($operation, 'timeout', null, $this->redactor->diagnostic($process->getErrorOutput(), 500));

            throw ProcessExecutionFailed::timedOut($operation->label(), (string) $timeout);
        } catch (ProcessStartFailedException|ProcessRuntimeException $exception) {
            $detail = $this->redactor->diagnostic($exception->getMessage(), 500);
            $this->logFailure($operation, 'launch_failed', null, $detail);

            throw ProcessExecutionFailed::launchFailed($operation->label(), $detail);
        } catch (Throwable $exception) {
            $detail = $this->redactor->diagnostic($exception->getMessage(), 500);
            $this->logFailure($operation, 'launch_failed', null, $detail);

            throw ProcessExecutionFailed::launchFailed($operation->label(), $detail);
        }

        $duration = (hrtime(true) - $started) / 1e9;
        $exitCode = $process->getExitCode() ?? -1;
        $stdout = $this->redactor->redact($process->getOutput());
        $stderr = $this->redactor->diagnostic($process->getErrorOutput());

        // 126/127 without output: the kernel could not execute the binary.
        if (in_array($exitCode, [126, 127], true) && trim($stdout) === '' && $stderr === '') {
            $this->logFailure($operation, 'launch_failed', $exitCode, '');

            throw ProcessExecutionFailed::launchFailed($operation->label(), sprintf('the binary could not be executed (exit %d)', $exitCode));
        }

        $result = new ResticResult($operation, $exitCode, $stdout, $stderr, $duration);

        if (! $result->successful()) {
            $this->logFailure($operation, 'exit', $exitCode, $stderr);
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function environment(?RepositoryContext $context): array
    {
        if ($context === null) {
            // `restic version` needs nothing but a minimal environment.
            return [];
        }

        $environment = [
            'RESTIC_CACHE_DIR' => PackagePaths::ensureDirectory($this->paths->cache),
            'TMPDIR' => PackagePaths::ensureDirectory($this->paths->root.'/tmp'),
            // Non-interactive runs: at most one progress/status message per minute.
            'RESTIC_PROGRESS_FPS' => '0.016666',
        ];

        $environment['RESTIC_REPOSITORY'] = $context->location->repository;
        $environment['RESTIC_PASSWORD_FILE'] = $context->passwordFile;

        if ($context->location->region !== null) {
            $environment['AWS_DEFAULT_REGION'] = $context->location->region;
        }

        if ($context->credentials !== null) {
            $environment = [...$environment, ...$context->credentials->toEnvironment()];
        }

        return $environment;
    }

    private static function fingerprint(string $path): ?string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        if ($stat === false) {
            return null;
        }

        // PHP can report zero or unstable inode values on Windows. Include
        // content identity so a replaced executable is never trusted from a
        // stale per-process version probe.
        $parts = [$stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime']];
        if (PathGuard::isWindows()) {
            $digest = @hash_file('sha256', $path);
            if ($digest === false) {
                return null;
            }
            $parts[] = $digest;
        }

        return implode(':', $parts);
    }

    private function workingDirectory(): string
    {
        return PackagePaths::ensureDirectory($this->paths->root);
    }

    private function logFailure(ResticOperation $operation, string $kind, ?int $exitCode, string $diagnostic): void
    {
        $this->logger->warning('Quraba Backup Restic operation failed.', [
            'operation' => $operation->value,
            'failure' => $kind,
            'exit_code' => $exitCode,
            'diagnostic' => $this->redactor->diagnostic($diagnostic, 1000),
        ]);
    }
}
