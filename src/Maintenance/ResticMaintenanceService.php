<?php

declare(strict_types=1);

namespace Quraba\Backup\Maintenance;

use Quraba\Backup\Coordination\LockName;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\MaintenanceOperation;
use Quraba\Backup\Enums\MaintenanceStatus;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Restic\RepositoryIdentityGuard;
use Quraba\Backup\Restic\ResticResult;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

/**
 * Explicit Restic repository maintenance: `check` and `prune`.
 *
 * Both hold the global and maintenance locks for their whole duration (no
 * backup, retention or other maintenance can run meanwhile), verify that
 * the configured repository is the one this application is bound to, and
 * are audited as maintenance runs. Restic locks are never removed
 * automatically; a locked repository is reported, not unlocked.
 *
 * `check` is separate from the cheap health service; `--read-data` reads
 * every pack (expensive: B2 download traffic) and is opt-in. `prune`
 * defaults to Restic's own `--dry-run`, is never scheduled by the package
 * and never runs as a side effect of retention.
 */
final readonly class ResticMaintenanceService
{
    public function __construct(
        private OperationCoordinator $coordinator,
        private ResticRunner $runner,
        private RepositoryIdentityGuard $repositoryIdentity,
        private SecretRedactor $redactor,
    ) {}

    public function check(bool $readData): MaintenanceOutcome
    {
        $mode = $readData ? 'read_data' : 'standard';

        return $this->perform(MaintenanceOperation::ResticCheck, false, $mode, fn (): ResticResult => $this->runner->check($readData));
    }

    public function prune(bool $execute): MaintenanceOutcome
    {
        return $this->perform(MaintenanceOperation::ResticPrune, ! $execute, $execute ? 'execute' : 'dry_run', fn (): ResticResult => $this->runner->prune(dryRun: ! $execute));
    }

    /**
     * @param  callable(): ResticResult  $execute
     */
    private function perform(MaintenanceOperation $operation, bool $dryRun, string $mode, callable $execute): MaintenanceOutcome
    {
        $locks = $this->coordinator->beginWriteOperation('restic '.$operation->value, LockName::Maintenance);

        try {
            $audit = BackupMaintenanceRun::plan($operation, $dryRun, [['operation' => $operation->value, 'mode' => $mode]]);
            $audit->markRunning();
            $issued = false;

            try {
                $repositoryId = $this->repositoryIdentity->verifyOpenRepository();
                $audit->mergeMetadata(['repository_id' => $repositoryId, 'mode' => $mode]);

                $issued = true;
                $result = $execute();
                $summary = self::summary($result);
                $audit->mergeMetadata(['exit_code' => $result->exitCode, 'duration_seconds' => round($result->durationSeconds, 1), 'summary' => $summary]);

                $result->throwIfFailed();
            } catch (Throwable $exception) {
                $failure = FailureDetails::fromThrowable($exception, $operation->value, $this->redactor);
                $uncertain = $issued && $operation === MaintenanceOperation::ResticPrune && ! $dryRun;
                if ($uncertain) {
                    $audit->markIndeterminate($failure);
                } else {
                    $audit->markFailed($failure);
                }

                return new MaintenanceOutcome($audit->uuid, $operation, $dryRun, $mode, $uncertain ? MaintenanceStatus::Indeterminate : MaintenanceStatus::Failed, isset($summary) ? $summary : [], $failure);
            }

            $audit->markCompleted($dryRun || $operation === MaintenanceOperation::ResticCheck ? [] : [['operation' => $operation->value, 'repository_id' => $repositoryId]]);

            return new MaintenanceOutcome($audit->uuid, $operation, $dryRun, $mode, MaintenanceStatus::Completed, $summary, null);
        } finally {
            $locks->release();
        }
    }

    /**
     * The last meaningful (already redacted) lines of Restic's output.
     *
     * @return list<string>
     */
    private static function summary(ResticResult $result): array
    {
        $text = trim($result->stdout) !== '' ? $result->stdout : $result->stderr;
        $lines = array_values(array_filter(array_map(trim(...), preg_split('/\r?\n/', $text) ?: []), static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '[')));

        return array_map(static fn (string $line): string => mb_substr($line, 0, 300), array_slice($lines, -12));
    }
}
