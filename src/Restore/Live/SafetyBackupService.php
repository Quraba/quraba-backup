<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Live;

use Closure;
use Quraba\Backup\Archive\ArchiveStore;
use Quraba\Backup\Backup\BackupManager;
use Quraba\Backup\Backup\RunFinalizer;
use Quraba\Backup\Coordination\HeldLocks;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Manifest\RemoteManifestCatalog;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\SnapshotIdentity;
use Throwable;

/**
 * The verified pre-change safety backup that must exist before a live
 * restore crosses its destructive boundary:
 *
 *   database restore → database safety backup
 *   media restore    → media safety snapshot
 *   full restore     → full safety Recovery Point
 *
 * It is an ordinary backup run of the existing BackupManager (trigger
 * `pre_restore`, linked to the restore UUID, pinned at once). "The backup
 * command returned" is not enough: the run must be COMPLETED, every required
 * component VERIFIED in the catalog, the immutable manifest readable from
 * remote storage with the same identities, the archive object present with
 * its exact size and the exact snapshot ID listed for this application. Any
 * doubt refuses, and nothing has been changed at that point.
 */
final readonly class SafetyBackupService
{
    public function __construct(
        private BackupManager $backups,
        private ArchiveStore $archives,
        private ResticRepository $repository,
        private RemoteManifestCatalog $manifests,
    ) {}

    public static function profileFor(RestoreProfile $profile): BackupProfile
    {
        return match ($profile) {
            RestoreProfile::Database => BackupProfile::Database,
            RestoreProfile::Media => BackupProfile::Media,
            RestoreProfile::Full => BackupProfile::Recovery,
        };
    }

    /**
     * @param  Closure(BackupRun): void  $announce  receives the safety run before it executes
     * @return array<string, mixed> evidence recorded in the restore journal
     *
     * @throws RestoreFailed when the safety backup is not positively verified
     */
    public function take(RestoreProfile $profile, HeldLocks $locks, string $restoreUuid, ApplicationIdentity $identity, Closure $announce): array
    {
        $backupProfile = self::profileFor($profile);
        $runUuid = null;

        try {
            $result = $this->backups->runSafetyBackup($backupProfile, $locks, $restoreUuid, static function (BackupRun $run) use ($announce, &$runUuid): void {
                $runUuid = $run->uuid;
                $announce($run);
            });
        } catch (RestoreFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw RestoreFailed::safetyBackupFailed(sprintf('the safety backup%s did not finish: %s', $runUuid === null ? '' : ' '.$runUuid, $exception->getMessage()));
        }

        $run = $result->run;

        if ($result->status !== BackupStatus::Completed || $result->manifestLocator === null) {
            throw RestoreFailed::safetyBackupFailed(sprintf('safety backup %s ended %s (%s)', $run->uuid, $result->status->value, $run->failure_code ?? 'no failure code'));
        }

        return $this->verify($run->refresh(), $backupProfile, $identity);
    }

    /**
     * Positive physical proof of a completed safety run, including its
     * catalog rows.
     *
     * @return array<string, mixed>
     *
     * @throws RestoreFailed
     */
    public function verify(BackupRun $run, BackupProfile $profile, ApplicationIdentity $identity): array
    {
        $artifacts = [];

        /** @var BackupArtifact $artifact */
        foreach ($run->artifacts()->get() as $artifact) {
            $artifacts[$artifact->kind->value] = $artifact;
        }

        $evidence = ['run_uuid' => $run->uuid, 'profile' => $profile->value, 'status' => $run->status->value, 'trigger' => $run->trigger->value];

        foreach ([...RunFinalizer::requiredKinds($profile), ArtifactKind::RemoteManifest] as $kind) {
            if (($artifacts[$kind->value] ?? null)?->status !== ArtifactStatus::Verified) {
                throw RestoreFailed::safetyBackupFailed(sprintf('the %s of safety backup %s is not verified', $kind->value, $run->uuid));
            }
        }

        try {
            $manifest = $this->manifests->find($identity, $run->uuid);
        } catch (Throwable $exception) {
            throw RestoreFailed::safetyBackupFailed('the safety manifest could not be read back: '.$exception->getMessage());
        }

        if ($manifest === null) {
            throw RestoreFailed::safetyBackupFailed(sprintf('no immutable manifest exists remotely for safety backup %s', $run->uuid));
        }

        $evidence['manifest'] = $manifest->locator;

        if ($profile !== BackupProfile::Media) {
            $archive = $artifacts[ArtifactKind::ApplicationArchive->value] ?? throw RestoreFailed::safetyBackupFailed('the safety backup has no application archive');

            if ($archive->locator === null || $archive->sha256 === null || $archive->byte_size === null
                || $manifest->archiveLocator !== $archive->locator || $manifest->archiveSha256 !== $archive->sha256) {
                throw RestoreFailed::safetyBackupFailed('the safety manifest does not describe the verified safety archive');
            }

            try {
                $sample = $this->archives->sample($archive->locator, $archive->byte_size);
            } catch (Throwable $exception) {
                throw RestoreFailed::safetyBackupFailed('the safety archive could not be observed remotely: '.$exception->getMessage());
            }

            if ($sample !== 'present') {
                throw RestoreFailed::safetyBackupFailed(sprintf('the safety archive is %s in remote storage', $sample));
            }

            $evidence['archive_locator'] = $archive->locator;
            $evidence['archive_sha256'] = $archive->sha256;
            $evidence['archive_bytes'] = $archive->byte_size;
        }

        if ($profile !== BackupProfile::Database) {
            $snapshot = $artifacts[ArtifactKind::ResticSnapshot->value] ?? throw RestoreFailed::safetyBackupFailed('the safety backup has no media snapshot');

            if ($snapshot->snapshot_id === null || $manifest->snapshotId !== $snapshot->snapshot_id) {
                throw RestoreFailed::safetyBackupFailed('the safety manifest does not describe the verified safety snapshot');
            }

            try {
                $listed = false;

                foreach ($this->repository->snapshots(SnapshotIdentity::applicationSelector($identity)) as $candidate) {
                    $listed = $listed || $candidate->id === $snapshot->snapshot_id;
                }
            } catch (Throwable $exception) {
                throw RestoreFailed::safetyBackupFailed('the safety snapshot could not be observed in the repository: '.$exception->getMessage());
            }

            if (! $listed) {
                throw RestoreFailed::safetyBackupFailed('the exact safety snapshot ID is not listed for this application');
            }

            $evidence['snapshot_id'] = $snapshot->snapshot_id;
            $evidence['repository_id'] = $manifest->repositoryId;
        }

        return $evidence;
    }
}
