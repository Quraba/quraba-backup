<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\RestoreMode;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Workspace\WorkspaceManager;
use Throwable;

/** Strictly non-destructive restore preparation; no live apply API exists. */
final readonly class RestoreDryRunService
{
    public function __construct(
        private Repository $config,
        private OperationCoordinator $coordinator,
        private IdentityResolver $identities,
        private RestoreSourceResolver $sources,
        private RestorePreflight $preflight,
        private ArchiveReconstructor $archives,
        private ResticReconstructor $media,
        private RestoreDatabaseValidator $database,
        private WorkspaceManager $workspaces,
        private SecretRedactor $redactor,
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
            'warnings' => [],
            'blockers' => [],
            'notice' => 'Nothing was changed in the live application.',
        ];

        try {
            $audit = RestoreRun::request(RestoreMode::DryRun, $profile, $runUuid);
            $result['restore_run_uuid'] = $audit->uuid;
            $audit->markResolving();
            $identity = $this->identities->current();
            $source = $this->sources->resolve($runUuid, $profile);
            $result['source'] = $source->origin;
            $result['consistency'] = $source->consistency;
            $result['repository_id'] = $source->repositoryId;
            $result['snapshot_id'] = $source->snapshotId;
            $audit->freezeSource($source->archiveLocator, $source->archiveSha256, $source->snapshotId);
            $audit->mergeMetadata(['source' => $source->identities(), 'origin' => $source->origin, 'manifest_schema' => $source->manifestSchema]);

            $workspace = $this->workspaces->create();
            $preflight = $this->preflight->check($source, $profile, $workspace);
            $result['required_disk_bytes'] = $preflight['required_bytes'];
            $result['free_disk_bytes'] = $preflight['free_bytes'];
            $result['atomic_rename'] = $preflight['atomic_rename'];
            $result['warnings'] = $preflight['warnings'];
            if ($preflight['blockers'] !== []) {
                $result['blockers'] = $preflight['blockers'];
                throw RestoreFailed::reconstructionFailed('preflight found blockers');
            }

            $audit->markReconstructing();
            $archive = null;
            if ($profile !== RestoreProfile::Media) {
                $archive = $this->archives->reconstruct($source, $identity, $workspace);
                $result['archive_verified'] = $archive['archive_verified'];
                $result['archive_sha256'] = $archive['archive_sha256'];
                $result['app_key_compatibility'] = $archive['app_key_compatibility'];
                $result['release_compatibility'] = $archive['release_compatibility'];
                if ($archive['release_compatibility'] === 'warning') {
                    $result['warnings'][] = 'The backup release fingerprint differs from this installation; review compatibility before a future live restore.';
                }
                if ($archive['app_key_compatibility'] === 'mismatch') {
                    $result['blockers'][] = 'The backup APP_KEY fingerprint differs from the current application.';
                }
            }

            if ($profile !== RestoreProfile::Database) {
                $mappings = $this->media->reconstruct($source, $identity, $workspace);
                $result['media_roots'] = array_map(static fn (MediaRootMapping $mapping): array => $mapping->toArray(), $mappings);
            }

            $audit->markValidating();
            if ($archive !== null) {
                $setting = $this->config->get('quraba-backup.restore.db_validation_level', 'artifact');
                $level = is_string($setting) ? DbValidationLevel::tryFrom($setting) : null;
                if ($level === null) {
                    throw RestoreFailed::reconstructionFailed('invalid restore DB validation level');
                }
                $validation = $this->database->validate($archive['database_dump'], $archive['metadata'], $level, $workspace);
                $result['db_validation_level'] = $validation['level'];
                $result['database_validation'] = $validation;
            }

            if ($result['blockers'] !== []) {
                throw RestoreFailed::reconstructionFailed('validation found blockers');
            }

            $cleanup = $workspace->cleanup();
            $workspace = null;
            if (! $cleanup->succeeded()) {
                throw RestoreFailed::reconstructionFailed('private workspace cleanup failed');
            }

            $audit->mergeMetadata(['validation' => $result['database_validation'] ?? null, 'warnings' => $result['warnings']]);
            $audit->markCompleted();
            $result['ok'] = true;
        } catch (Throwable $exception) {
            $failure = FailureDetails::fromThrowable($exception, 'restore.dry_run', $this->redactor);
            $result['error'] = $failure->toArray();
            if ($result['blockers'] === []) {
                $result['blockers'][] = $failure->message;
            }
            if ($audit !== null && ! $audit->status->isTerminal()) {
                $audit->markFailed($failure);
            }
        } finally {
            if ($workspace !== null) {
                $cleanup = $workspace->cleanup();
                if (! $cleanup->succeeded()) {
                    $result['blockers'][] = 'Private workspace cleanup failed; inspect abandoned workspaces.';
                    $result['ok'] = false;
                }
            }
            $locks->release();
        }

        return $result;
    }
}
