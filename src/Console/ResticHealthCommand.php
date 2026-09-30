<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Health\ResticHealthService;
use Throwable;

final class ResticHealthCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:restic:health
        {--json : Output machine-readable JSON}';

    protected $description = 'Cheap Restic health check (binary, version, configuration, repository). Not a `restic check`.';

    public function handle(): int
    {
        try {
            $report = $this->laravel->make(ResticHealthService::class)->check();
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => $report->state()->isEstablished(), ...$report->toArray()]);
        } else {
            $this->renderReport($report, 'Restic health');
        }

        return $report->state()->isEstablished() ? self::SUCCESS : self::FAILURE;
    }
}
