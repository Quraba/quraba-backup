<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Health\BackupHealthService;
use Quraba\Backup\Notifications\NoticeDispatcher;
use Throwable;

final class HealthCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:health
        {--json : Output machine-readable JSON}';

    protected $description = 'Report recovery health using catalog freshness and cheap physical samples.';

    public function handle(BackupHealthService $health, NoticeDispatcher $notices): int
    {
        try {
            $report = $health->check();
            $notices->healthTransition($report);
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => $report->state()->isEstablished(), ...$report->toArray()]);
        } else {
            $this->renderReport($report, 'Backup recovery health');
        }

        return $report->state()->isEstablished() ? self::SUCCESS : self::FAILURE;
    }
}
