<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Backup\BackupReconciler;
use Throwable;

final class ReconcileCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:reconcile
        {--dry-run : Inspect physical evidence without changing the catalog or remote storage}
        {--json : Output machine-readable JSON}';

    protected $description = 'Resolve interrupted backup runs from physical evidence (never creates duplicate artifacts).';

    public function handle(BackupReconciler $reconciler): int
    {
        try {
            $report = $reconciler->reconcile((bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => $report->unresolved() === 0, ...$report->toArray()]);

            return $report->unresolved() === 0 ? self::SUCCESS : self::FAILURE;
        }

        if ($report->items === []) {
            $this->components->info('No interrupted backup runs.');

            return self::SUCCESS;
        }

        foreach ($report->items as $item) {
            $this->components->twoColumnDetail(
                sprintf('%s (%s)', $item['run_uuid'], $item['profile']),
                sprintf('%s → %s', $item['before'], $item['after']),
            );
        }

        if ($report->dryRun) {
            $this->components->info('Dry run: nothing was changed.');
        }

        if ($report->unresolved() > 0) {
            $this->components->warn(sprintf('%d run(s) remain indeterminate and need operator attention.', $report->unresolved()));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
