<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Health\Doctor\DoctorService;
use Throwable;

final class DoctorCommand extends PackageCommand
{
    protected $signature = 'backup:doctor
        {--json : Output machine-readable JSON}';

    protected $description = 'Check whether this host and configuration can run Quraba Backup safely.';

    public function handle(): int
    {
        try {
            $report = $this->laravel->make(DoctorService::class)->run();
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => ! $report->hasFailures(), ...$report->toArray()]);
        } else {
            $this->renderReport($report, 'Quraba Backup doctor');

            if ($report->hasFailures()) {
                $this->line('  Required checks failed. Fix every FAIL before relying on backups.');
            }
        }

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
