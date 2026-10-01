<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Backup\BackupManager;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Models\BackupRun;
use Throwable;

final class PendingCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:pending {--json : Output machine-readable JSON}';

    protected $description = 'Process one pending backup requested from the optional admin panel.';

    public function handle(BackupManager $manager): int
    {
        if (! (bool) $this->laravel->make('config')->get('quraba-backup.enabled', true)) {
            return self::SUCCESS;
        }

        try {
            $run = BackupRun::query()
                ->where('trigger', BackupTrigger::Api->value)
                ->where('status', BackupStatus::Pending->value)
                ->orderBy('id')
                ->first();
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($run === null) {
            if ($this->wantsJson()) {
                $this->writeJson(['ok' => true, 'pending' => false]);
            }

            return self::SUCCESS;
        }

        try {
            $result = $manager->runPending($run->uuid);
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => $result->exitCode() === 0, 'run_uuid' => $run->uuid, 'status' => $result->status->value]);
        } else {
            $this->line(sprintf('Backup %s: %s', $run->uuid, $result->status->value));
        }

        return $result->exitCode();
    }
}
