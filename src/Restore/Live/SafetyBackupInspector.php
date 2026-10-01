<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Live;

use Quraba\Backup\Archive\ArchiveStore;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Manifest\RemoteManifestCatalog;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\SnapshotIdentity;
use Throwable;

/**
 * What remote storage says about a safety backup, WITHOUT the local catalog
 * (a restored catalog is older than the safety backup and does not contain
 * it). Read-only; used by restore reconciliation and by health.
 */
final readonly class SafetyBackupInspector
{
    public function __construct(
        private RemoteManifestCatalog $manifests,
        private ArchiveStore $archives,
        private ResticRepository $repository,
    ) {}

    /**
     * @return array{state: 'present'|'missing'|'unknown', detail: string, manifest?: string}
     */
    public function inspect(string $runUuid, ApplicationIdentity $identity): array
    {
        try {
            $manifest = $this->manifests->find($identity, $runUuid);

            if ($manifest === null) {
                return ['state' => 'missing', 'detail' => 'no immutable manifest exists for the safety backup'];
            }

            if ($manifest->archiveLocator !== null && $manifest->archiveBytes !== null) {
                $sample = $this->archives->sample($manifest->archiveLocator, $manifest->archiveBytes);

                if ($sample !== 'present') {
                    return ['state' => 'missing', 'detail' => 'the safety archive is '.$sample, 'manifest' => $manifest->locator];
                }
            }

            if ($manifest->snapshotId !== null) {
                $listed = false;

                foreach ($this->repository->snapshots(SnapshotIdentity::applicationSelector($identity)) as $candidate) {
                    $listed = $listed || $candidate->id === $manifest->snapshotId;
                }

                if (! $listed) {
                    return ['state' => 'missing', 'detail' => 'the exact safety snapshot is not listed', 'manifest' => $manifest->locator];
                }
            }

            return ['state' => 'present', 'detail' => 'the manifest and every component it names are present', 'manifest' => $manifest->locator];
        } catch (Throwable $exception) {
            return ['state' => 'unknown', 'detail' => 'remote storage or the repository could not be inspected ('.class_basename($exception).')'];
        }
    }
}
