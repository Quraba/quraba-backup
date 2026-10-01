<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\RestoreMode;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Exceptions\QurabaBackupException;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Support\LocalCatalog;
use Quraba\Backup\Workspace\WorkspaceManager;
use Throwable;

/**
 * Strictly non-destructive restore preparation. This service has no way to
 * change the live application: it depends on none of the live restore
 * services, and it holds only the restore lock.
 *
 * On a clean host (no catalog tables yet) the dry run still works from the
 * remote manifest; it then simply has no audit row to write.
 */
final readonly class RestoreDryRunService
{
    public function __construct(
        private OperationCoordinator $coordinator,
        private RestorePreparation $preparation,
        private WorkspaceManager $workspaces,
        private SecretRedactor $redactor,
        private LocalCatalog $catalog,
    ) {}

    /** @return array<string, mixed> */
    public function run(string $runUuid, RestoreProfile $profile): array
    {
        $locks = $this->coordinator->beginRestorePreparation('restore dry run');
        $audit = null;
        $workspace = null;
        $result = [
            'ok' => false,
            'mode' => 'dry_run',
            'run_uuid' => $runUuid,
            'requested_profile' => $profile->value,
            'source' => null,
            'consistency' => null,
            'archive_verified' => null,
            'archive_sha256' => null,
            'app_key_compatibility' => null,
            'release_compatibility' => null,
            'db_validation_level' => null,
            'repository_id' => null,
            'snapshot_id' => null,
            'media_roots' => [],
            'required_disk_bytes' => null,
            'free_disk_bytes' => null,
            'atomic_rename' => null,
            'scratch_contaminated' => null,
            'warnings' => [],
            'blockers' => [],
            'notice' => 'Nothing was changed in the live application.',
        ];

        try {
            if ($this->catalog->has('quraba_restore_runs')) {
                $audit = RestoreRun::request(RestoreMode::DryRun, $profile, $runUuid);
                $result['restore_run_uuid'] = $audit->uuid;
                $audit->markResolving();
            } else {
                $result['restore_run_uuid'] = null;
                $result['warnings'][] = 'No local backup catalog exists on this host, so this dry run is not recorded in an audit table.';
            }

            $workspace = $this->workspaces->create();

            $prepared = $this->preparation->prepare($runUuid, $profile, $workspace, $result, static function (string $stage, ?RestoreSource $source) use ($audit, $profile): void {
                if ($audit === null) {
                    return;
                }

                if ($stage === 'resolved' && $source !== null) {
                    $audit->freezeSource(
                        $profile === RestoreProfile::Media ? null : $source->archiveLocator,
                        $profile === RestoreProfile::Media ? null : $source->archiveSha256,
                        $profile === RestoreProfile::Database ? null : $source->snapshotId,
                    );
                    $audit->mergeMetadata(['source' => $source->identities(), 'origin' => $source->origin, 'manifest_schema' => $source->manifestSchema]);
                } elseif ($stage === 'reconstructing') {
                    $audit->markReconstructing();
                } elseif ($stage === 'validating') {
                    $audit->markValidating();
                }
            });

            if ($prepared->validation !== null && $prepared->validation['scratch_schema_fingerprint'] !== null) {
                $result['scratch_contaminated'] = false;
            }

            $cleanup = $workspace->cleanup();
            $workspace = null;
            if (! $cleanup->succeeded()) {
                throw RestoreFailed::reconstructionFailed('private workspace cleanup failed');
            }

            $audit?->mergeMetadata(['validation' => $prepared->validation, 'warnings' => $result['warnings']]);
            $audit?->markCompleted();
            $result['ok'] = true;
        } catch (Throwable $exception) {
            $failure = FailureDetails::fromThrowable($exception, 'restore.dry_run', $this->redactor);
            $result['error'] = $failure->toArray();
            if ($exception instanceof QurabaBackupException && $exception->failureCode() === 'restore.scratch_cleanup_failed') {
                // The scratch database — never the live one — was left dirty.
                $result['scratch_contaminated'] = true;
            }
            if ($result['blockers'] === []) {
                $result['blockers'][] = $failure->message;
            }
            if ($audit !== null && ! $audit->status->isTerminal()) {
                if ($result['scratch_contaminated'] === true) {
                    $audit->mergeMetadata(['scratch_contaminated' => true]);
                }
                $audit->markFailed($failure);
            }
        } finally {
            if ($workspace !== null) {
                $cleanup = $workspace->cleanup();
                if (! $cleanup->succeeded()) {
                    $result['blockers'] = [...(is_array($result['blockers']) ? array_values($result['blockers']) : []), 'Private workspace cleanup failed; inspect abandoned workspaces.'];
                    $result['ok'] = false;
                }
            }
            $locks->release();
        }

        return $result;
    }
}
