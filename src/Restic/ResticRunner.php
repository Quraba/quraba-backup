<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Psr\Log\LoggerInterface;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\ProcessExecutionFailed;
use Quraba\Backup\Exceptions\ResticCommandFailed;
use Quraba\Backup\Exceptions\ResticUnavailable;
use Quraba\Backup\Support\PackagePaths;
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
     * @param  list<string>  $tags  all tags must match (Restic AND semantics within one --tag)
     */
    public function snapshots(array $tags = []): ResticResult
    {
        $arguments = ['snapshots', '--json', '--no-lock'];

        if ($tags !== []) {
            $arguments[] = '--tag';
            $arguments[] = implode(',', ResticTag::assertAll($tags));
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

    public function check(bool $readData = false): ResticResult
    {
        return $this->runRepository(ResticOperation::Check, $readData ? ['check', '--read-data'] : ['check']);
    }

    /**
     * Forgets exact snapshots only. Policy-based `--keep-*` forgetting is
     * deliberately not exposed: retention computes exact IDs itself.
     *
     * @param  non-empty-list<string>  $snapshotIds
     */
    public function forget(array $snapshotIds): ResticResult
    {
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

        return $stat === false ? null : implode(':', [$stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime']]);
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
