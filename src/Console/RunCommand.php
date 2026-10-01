<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Backup\BackupManager;
use Quraba\Backup\Backup\BackupRunResult;
use Quraba\Backup\Backup\ComponentOutcome;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\QurabaBackupException;
use Throwable;

final class RunCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:run
        {--profile=recovery : database, media or recovery}
        {--json : Output machine-readable JSON}
        {--scheduled : Internal: marks the run as triggered by the scheduler}';

    protected $description = 'Create a verified backup (exit 0 completed, 2 partial, 3 indeterminate, 1 failed/refused).';

    public function handle(BackupManager $manager): int
    {
        $profile = BackupProfile::tryFrom((string) $this->option('profile'));

        if ($profile === null) {
            return $this->failWith(new ConfigurationException('--profile must be database, media or recovery.', 'backup.invalid_profile'));
        }

        $trigger = (bool) $this->option('scheduled') ? BackupTrigger::Scheduled : BackupTrigger::Manual;

        try {
            $result = $manager->run($profile, $trigger);
        } catch (Throwable $exception) {
            // No catalog run exists for a refusal (busy lock, invalid identity):
            // a scheduled one must still leave a trace outside the output file.
            if ($trigger === BackupTrigger::Scheduled) {
                $this->packageLogger()->warning('A scheduled Quraba backup was refused before it started.', [
                    'profile' => $profile->value,
                    'code' => $exception instanceof QurabaBackupException ? $exception->failureCode() : 'unexpected.error',
                    'error' => $this->redactor()->redact($exception->getMessage()),
                ]);
            }

            return $this->failWith($exception);
        }

        if ($trigger === BackupTrigger::Scheduled && $result->exitCode() !== BackupRunResult::EXIT_COMPLETED) {
            $this->packageLogger()->warning('A scheduled Quraba backup did not complete.', [
                'run_uuid' => $result->run->uuid,
                'status' => $result->status->value,
                'failure_code' => $result->run->failure_code,
            ]);
        }

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => $result->exitCode() === BackupRunResult::EXIT_COMPLETED, ...$result->toArray()]);

            return $result->exitCode();
        }

        $this->render($result);

        return $result->exitCode();
    }

    private function render(BackupRunResult $result): void
    {
        $this->components->twoColumnDetail('Run', $result->run->uuid);
        $this->components->twoColumnDetail('Profile', $result->run->profile->value);
        $this->components->twoColumnDetail('Consistency', $result->run->consistency->value);

        foreach ($result->components as $kind => $outcome) {
            $color = match ($outcome->state) {
                ComponentOutcome::VERIFIED => 'green',
                ComponentOutcome::UNCERTAIN => 'yellow',
                default => 'red',
            };

            $detail = $outcome->identity ?? ($outcome->failure !== null ? $outcome->failure->message : '');

            $this->components->twoColumnDetail($kind, sprintf('<fg=%s>%s</> %s', $color, $outcome->state, $this->escape($this->redactor()->redact($detail))));
        }

        if ($result->manifestLocator !== null) {
            $this->components->twoColumnDetail('Manifest', $result->manifestLocator);
        }

        foreach ($result->warnings as $warning) {
            $this->components->warn($this->redactor()->redact($warning));
        }

        match ($result->exitCode()) {
            BackupRunResult::EXIT_COMPLETED => $this->components->info('Backup completed and verified.'),
            BackupRunResult::EXIT_PARTIAL => $this->components->warn('Backup is PARTIAL: not every required component was verified. It is not a complete Recovery Point.'),
            BackupRunResult::EXIT_INDETERMINATE => $this->components->warn('Backup outcome is INDETERMINATE; run "php artisan quraba:backup:reconcile".'),
            default => $this->components->error(sprintf('Backup %s: [%s] %s', $result->quiescenceReleaseFailed ? 'finished but quiescence could not be released' : 'failed', (string) $result->run->failure_code, $this->redactor()->redact((string) $result->run->failure_message))),
        };
    }
}
