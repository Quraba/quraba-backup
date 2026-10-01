<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Retention\RetentionExecutor;
use Throwable;

final class RetentionCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:retention
        {--execute : Delete precisely the expired artifacts after a fresh safety check}
        {--scheduled : Invoked by Laravel scheduler}
        {--json : Output machine-readable JSON}';

    protected $description = 'Plan backup retention; --execute physically deletes exact expired artifacts.';

    public function handle(RetentionExecutor $retention): int
    {
        try {
            $scheduledExecute = (bool) $this->option('scheduled') && (bool) $this->laravel->make('config')->get('quraba-backup.retention.execute_scheduled', false);
            $report = $retention->run((bool) $this->option('execute') || $scheduledExecute);
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => $report->succeeded(), ...$report->toArray()]);
        } else {
            $this->line(sprintf('Retention %s: %s (maintenance %s)', $report->executed ? 'execution' : 'plan', $report->status->value, $report->maintenanceRunUuid));

            foreach ($report->plan->decisions as $decision) {
                $this->line(sprintf('%s %s %s: %s', $decision->expire ? 'EXPIRE' : 'KEEP', $decision->candidate->runUuid, $decision->candidate->family, implode(', ', $decision->reasons)));
            }

            if ($report->inspectionError !== null) {
                $this->components->warn($this->redactor()->redact($report->inspectionError));
            }

            if ($report->failure !== null) {
                $this->components->error('['.$report->failure->code.'] '.$report->failure->message);
            }
        }

        return $report->succeeded() ? self::SUCCESS : self::FAILURE;
    }
}
