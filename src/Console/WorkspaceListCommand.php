<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Workspace\WorkspaceInfo;
use Quraba\Backup\Workspace\WorkspaceManager;
use Throwable;

final class WorkspaceListCommand extends PackageCommand
{
    use ResolvesAbandonmentThreshold;

    protected $signature = 'backup:workspace:list
        {--older-than= : Hours after which an inactive workspace counts as abandoned (default from config)}
        {--json : Output machine-readable JSON}';

    protected $description = 'List operation workspaces and flag abandoned ones (never deletes anything).';

    public function handle(WorkspaceManager $workspaces): int
    {
        try {
            $hours = $this->abandonmentHours();
            $list = $workspaces->list($hours * 3600);
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson([
                'ok' => true,
                'directory' => $workspaces->baseDirectory(),
                'abandoned_after_hours' => $hours,
                'workspaces' => array_map(static fn (WorkspaceInfo $info): array => $info->toArray(), $list),
            ]);

            return self::SUCCESS;
        }

        if ($list === []) {
            $this->components->info('No operation workspaces found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Workspace', 'Created (UTC)', 'Age', 'State'],
            array_map(static fn (WorkspaceInfo $info): array => [
                'op-'.$info->id,
                $info->createdAt->toIso8601ZuluString(),
                self::humanAge($info->ageSeconds),
                $info->active ? 'active' : ($info->abandoned ? 'abandoned' : 'inactive (recent)'),
            ], $list),
        );

        return self::SUCCESS;
    }

    private static function humanAge(int $seconds): string
    {
        return match (true) {
            $seconds < 3600 => intdiv($seconds, 60).'m',
            $seconds < 172800 => intdiv($seconds, 3600).'h',
            default => intdiv($seconds, 86400).'d',
        };
    }
}
