<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Manifest\RemoteManifestCatalog;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\SnapshotIdentity;
use Quraba\Backup\Retention\RetentionTombstoneStore;
use Quraba\Backup\Storage\RemoteStorage;
use Throwable;

final class DiscoverCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:discover
        {--remote : Scan immutable remote manifests and retention tombstones}
        {--json : Output machine-readable JSON}';

    protected $description = 'Discover this application/environment backups from remote storage without a local catalog.';

    public function handle(
        IdentityResolver $identities,
        RemoteManifestCatalog $catalog,
        RetentionTombstoneStore $tombstones,
        RemoteStorage $storage,
        ResticRepository $repository,
    ): int {
        if (! $this->option('remote')) {
            return $this->failWith(new \InvalidArgumentException('Specify --remote to scan remote manifests.'));
        }

        try {
            $identity = $identities->current();
            $scan = $catalog->scan($identity);
            $expired = $tombstones->all($identity);
            $snapshots = [];

            if ($repository->isConfigured()) {
                foreach ($repository->snapshots(SnapshotIdentity::applicationSelector($identity)) as $snapshot) {
                    $snapshots[$snapshot->id] = true;
                }
            }

            $rows = [];
            foreach ($scan->manifests as $manifest) {
                $tombstone = $expired['tombstones'][$manifest->runUuid] ?? null;
                $archiveExpired = $tombstone?->covers('application_archive') ?? false;
                $snapshotExpired = $tombstone?->covers('media_snapshot') ?? false;
                $archiveAvailable = $manifest->archiveLocator !== null && ! $archiveExpired
                    && $storage->objects()->exists($storage->layout()->assertManaged($manifest->archiveLocator));
                $snapshotAvailable = $manifest->snapshotId !== null && ! $snapshotExpired && isset($snapshots[$manifest->snapshotId]);

                $rows[] = [
                    'run_uuid' => $manifest->runUuid,
                    'created_at' => $manifest->createdAt->toIso8601ZuluString(),
                    'profile' => $manifest->profile->value,
                    'status' => $manifest->status->value,
                    'consistency' => $manifest->consistency->value,
                    'complete_recovery_point' => $manifest->recoveryPoint && $archiveAvailable && $snapshotAvailable,
                    'repository_id' => $manifest->repositoryId,
                    'archive_available' => $archiveAvailable,
                    'snapshot_available' => $snapshotAvailable,
                    'expired_components' => $tombstone === null ? [] : $tombstone->components,
                    'manifest' => $manifest->locator,
                ];
            }
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => true, 'app_id' => $identity->appId, 'environment' => $identity->environment, 'runs' => $rows, 'malformed_manifests' => $scan->malformed, 'malformed_tombstones' => $expired['malformed']]);
        } else {
            $this->table(['Run UUID', 'Created UTC', 'Profile', 'Status', 'Recovery Point', 'Archive', 'Snapshot', 'Expired'], array_map(static fn (array $row): array => [
                $row['run_uuid'], $row['created_at'], $row['profile'], $row['status'], $row['complete_recovery_point'] ? 'yes' : 'no', $row['archive_available'] ? 'yes' : 'no', $row['snapshot_available'] ? 'yes' : 'no', implode(',', $row['expired_components']),
            ], $rows));
            if ($scan->malformed !== [] || $expired['malformed'] !== []) {
                $this->components->warn('Some remote documents were malformed and were excluded.');
            }
        }

        return self::SUCCESS;
    }
}
