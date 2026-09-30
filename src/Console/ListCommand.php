<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;

/**
 * Local catalog browsing: never contacts B2 or Restic.
 */
final class ListCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:list
        {--limit=20 : Number of most recent runs}
        {--profile= : Only database, media or recovery runs}
        {--json : Output machine-readable JSON}';

    protected $description = 'List backup runs from the local catalog (no remote calls).';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $query = BackupRun::query()->with('artifacts')->orderByDesc('id')->limit($limit);

        $profile = $this->option('profile');

        if (is_string($profile) && $profile !== '') {
            $filter = BackupProfile::tryFrom($profile);

            if ($filter === null) {
                $this->components->error('--profile must be database, media or recovery.');

                return self::FAILURE;
            }

            $query->where('profile', $filter->value);
        }

        $rows = $query->get()->map(fn (BackupRun $run): array => $this->row($run))->all();

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => true, 'runs' => $rows]);

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->info('No backup runs recorded yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Date (UTC)', 'Run UUID', 'Profile', 'Trigger', 'Consistency', 'Archive', 'Media', 'Status'],
            array_map(static fn (array $row): array => [
                $row['requested_at'], $row['run_uuid'], $row['profile'], $row['trigger'], $row['consistency'],
                $row['archive_status'], $row['media_status'], $row['status'],
            ], $rows),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, string|null>
     */
    private function row(BackupRun $run): array
    {
        $status = static function (ArtifactKind $kind) use ($run): string {
            $artifact = $run->artifacts->first(static fn (BackupArtifact $a): bool => $a->kind === $kind);

            return $artifact?->status->value ?? '-';
        };

        return [
            'requested_at' => $run->requested_at?->toIso8601ZuluString(),
            'run_uuid' => $run->uuid,
            'profile' => $run->profile->value,
            'trigger' => $run->trigger->value,
            'consistency' => $run->consistency->value,
            'archive_status' => $status(ArtifactKind::ApplicationArchive),
            'media_status' => $status(ArtifactKind::ResticSnapshot),
            'manifest_status' => $status(ArtifactKind::RemoteManifest),
            'status' => $run->status->value,
            'failure_code' => $run->failure_code,
        ];
    }
}
