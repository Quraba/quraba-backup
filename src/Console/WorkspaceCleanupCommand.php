<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Workspace\CleanupReport;
use Quraba\Backup\Workspace\WorkspaceInfo;
use Quraba\Backup\Workspace\WorkspaceManager;
use Throwable;

final class WorkspaceCleanupCommand extends PackageCommand
{
    use ResolvesAbandonmentThreshold;

    protected $signature = 'quraba:backup:workspace:cleanup
        {--older-than= : Hours after which an inactive workspace counts as abandoned (default from config, minimum 1)}
        {--execute : Actually delete abandoned workspaces (default is a plan only)}
        {--json : Output machine-readable JSON}';

    protected $description = 'Plan (default) or execute removal of abandoned operation workspaces. Active workspaces are never removed.';

    public function handle(WorkspaceManager $workspaces): int
    {
        try {
            $hours = $this->abandonmentHours();
            $candidates = $workspaces->abandoned($hours * 3600);
            $execute = (bool) $this->option('execute');
            $reports = $execute ? $workspaces->cleanupAbandoned($hours * 3600) : [];
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        $failed = array_filter($reports, static fn (CleanupReport $report): bool => ! $report->succeeded());

        if ($this->wantsJson()) {
            $this->writeJson([
                'ok' => $failed === [],
                'mode' => $execute ? 'execute' : 'plan',
                'abandoned_after_hours' => $hours,
                'planned' => array_map(static fn (WorkspaceInfo $info): array => $info->toArray(), $candidates),
                'results' => array_map(static fn (CleanupReport $report): array => [
                    'id' => $report->workspaceId,
                    'removed' => $report->removed,
                    'errors' => $report->errors,
                ], $reports),
            ]);

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($candidates === []) {
            $this->components->info(sprintf('No abandoned workspaces older than %d hour(s).', $hours));

            return self::SUCCESS;
        }

        if (! $execute) {
            $this->components->warn(sprintf('PLAN ONLY: %d abandoned workspace(s) would be removed. Re-run with --execute to delete them.', count($candidates)));

            foreach ($candidates as $candidate) {
                $this->line(sprintf('  - op-%s  (created %s)', $candidate->id, $candidate->createdAt->toIso8601ZuluString()));
            }

            return self::SUCCESS;
        }

        foreach ($reports as $report) {
            $report->succeeded()
                ? $this->components->twoColumnDetail('op-'.$report->workspaceId, '<fg=green>removed</>')
                : $this->components->twoColumnDetail('op-'.$report->workspaceId, '<fg=red>'.$this->escape(implode(' ', $report->errors)).'</>');
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
