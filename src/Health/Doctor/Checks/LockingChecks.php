<?php

declare(strict_types=1);

namespace Quraba\Backup\Health\Doctor\Checks;

use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Health\CheckResult;
use Quraba\Backup\Health\Doctor\DoctorCheck;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Support\Process\ChildEnvironment;
use Quraba\Backup\Support\Process\ProcessFactory;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * Proves flock() works on the lock filesystem, within one process and
 * across processes. Uses a private temporary probe file (never the real
 * operation locks) and removes it afterwards.
 */
final readonly class LockingChecks implements DoctorCheck
{
    /** PHP run by the child: tries a non-blocking exclusive lock on argv[1]. */
    private const string CHILD_PROBE = '$h = @fopen($argv[1], "c+"); if ($h === false) { echo "error"; exit(0); } $wb = 0; echo flock($h, LOCK_EX | LOCK_NB, $wb) ? "acquired" : "busy";';

    public function __construct(
        private PackagePaths $paths,
        private ProcessFactory $processes,
        private OperationCoordinator $coordinator,
    ) {}

    public function name(): string
    {
        return 'locking';
    }

    public function run(): array
    {
        $directory = is_dir($this->paths->locks) ? $this->paths->locks : PathGuard::nearestExistingAncestor($this->paths->locks);

        if ($directory === null || ! is_dir($directory)) {
            return [CheckResult::fail('locking.flock', 'File locking', 'No existing directory to probe file locking in.')];
        }

        $probe = $directory.'/.quraba-doctor-'.(string) new Ulid.'.lock';
        $handle = @fopen($probe, 'x+');

        if ($handle === false) {
            return [CheckResult::fail('locking.flock', 'File locking', sprintf('Could not create a probe lock file in [%s].', $directory))];
        }

        try {
            $wouldBlock = 0;

            if (! @flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                return [CheckResult::fail('locking.flock', 'File locking', 'flock() failed on the lock filesystem; operations would fail closed.')];
            }

            return [
                CheckResult::pass('locking.flock', 'File locking', sprintf('flock() works in [%s].', $directory)),
                $this->crossProcess($probe),
                $this->operationState(),
            ];
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            @unlink($probe);
        }
    }

    private function crossProcess(string $probe): CheckResult
    {
        if (! is_file(PHP_BINARY)) {
            return CheckResult::warn('locking.cross_process', 'Cross-process locking', 'The PHP binary path is unknown; the cross-process probe was skipped.');
        }

        try {
            $process = $this->processes->make([PHP_BINARY, '-r', self::CHILD_PROBE, $probe], null, ChildEnvironment::build(), 20.0);
            $process->run();
            $answer = trim($process->getOutput());
        } catch (Throwable $exception) {
            return CheckResult::warn('locking.cross_process', 'Cross-process locking', 'The cross-process probe could not run: '.$exception->getMessage());
        }

        return match ($answer) {
            'busy' => CheckResult::pass('locking.cross_process', 'Cross-process locking', 'A second process is correctly refused while the lock is held.'),
            'acquired' => CheckResult::fail('locking.cross_process', 'Cross-process locking', 'A second process acquired a lock that was already held: this filesystem does not provide working flock() semantics (e.g. some network filesystems). Use a local directory for QURABA_BACKUP_LOCK_PATH.'),
            default => CheckResult::warn('locking.cross_process', 'Cross-process locking', 'The cross-process probe returned an unexpected answer.'),
        };
    }

    private function operationState(): CheckResult
    {
        try {
            return $this->coordinator->isWriteOperationRunning()
                ? CheckResult::warn('locking.operation', 'Operation lock', 'A write-affecting Quraba Backup operation is currently running.')
                : CheckResult::pass('locking.operation', 'Operation lock', 'No write-affecting operation is running.');
        } catch (Throwable $exception) {
            return CheckResult::fail('locking.operation', 'Operation lock', $exception->getMessage());
        }
    }
}
