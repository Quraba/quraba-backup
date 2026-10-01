<?php

declare(strict_types=1);

namespace Quraba\Backup\Recovery;

use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;
use Quraba\Backup\Coordination\LockName;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\MaintenanceOperation;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Manifest\ManifestBuilder;
use Quraba\Backup\Manifest\RemoteManifest;
use Quraba\Backup\Manifest\RemoteManifestCatalog;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Restic\RepositoryIdentityGuard;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\ResticSnapshot;
use Quraba\Backup\Restic\SnapshotIdentity;
use Quraba\Backup\Restic\SnapshotKind;
use Quraba\Backup\Retention\RetentionTombstone;
use Quraba\Backup\Retention\RetentionTombstoneStore;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Storage\RemoteStorage;
use Quraba\Backup\Support\LocalCatalog;
use Throwable;

/**
 * Rebuilds the local backup catalog from remote truth: after catalog loss,
 * on a clean host, or after restoring a database whose catalog is older
 * than the backups that exist remotely.
 *
 * Inputs: valid immutable manifests of this application and environment,
 * retention expiry records, the physical existence of every archive object
 * (present with the exact recorded size), the application-scoped Restic
 * snapshot listing (exact ID with this run's tags) and the repository
 * identity.
 *
 * A component becomes a VERIFIED artifact row only when it was just
 * observed physically. A manifest's claim alone is never enough: a
 * component that is recorded as expired is adopted as EXPIRED, one that is
 * simply gone as FAILED, and a run with no physically present component is
 * not adopted at all. Runs the catalog already knows are left to
 * `quraba:backup:reconcile`; the only change made to them is marking an
 * artifact expired when its remote expiry record AND its physical absence
 * agree. Remote data without a manifest is reported, never touched.
 *
 * Plan only by default (no lock, no write). `apply` holds the global and
 * maintenance locks and is audited.
 */
final readonly class CatalogRebuilder
{
    public function __construct(
        private OperationCoordinator $coordinator,
        private IdentityResolver $identities,
        private RemoteManifestCatalog $manifests,
        private RetentionTombstoneStore $tombstones,
        private RemoteStorage $remote,
        private ResticRepository $repository,
        private RepositoryIdentityGuard $repositoryIdentity,
        private LocalCatalog $catalog,
        private SecretRedactor $redactor,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(bool $apply): array
    {
        $identity = $this->identities->current();

        if (! $apply) {
            return $this->report($this->plan($identity), false, [], null);
        }

        if (! $this->catalog->available()) {
            throw RestoreFailed::catalogUnavailable('the catalog tables do not exist; run the package migrations (php artisan migrate) before rebuilding the catalog');
        }

        $locks = $this->coordinator->beginWriteOperation('catalog rebuild', LockName::Maintenance);

        try {
            $plan = $this->plan($identity);
            $audit = BackupMaintenanceRun::plan(MaintenanceOperation::CatalogRebuild, false, array_values(array_filter($plan['runs'], static fn (array $run): bool => $run['action'] !== 'present')), ['manifests' => count($plan['runs'])]);
            $audit->markRunning();
            $applied = [];

            try {
                if ($plan['repository_id'] !== null) {
                    // Binds (or validates) the local repository identity first.
                    $this->repositoryIdentity->verifyOpenRepository();
                }

                foreach ($plan['runs'] as $run) {
                    $result = match ($run['action']) {
                        'adopt' => $this->adopt($run, $identity),
                        'expire_locally' => $this->expireLocally($run),
                        default => null,
                    };

                    if ($result !== null) {
                        $applied[] = $result;
                    }
                }
            } catch (Throwable $exception) {
                $failure = FailureDetails::fromThrowable($exception, 'catalog.rebuild', $this->redactor);
                $audit->markFailed($failure, $applied);

                return $this->report($plan, true, $applied, $failure);
            }

            $audit->markCompleted($applied);
            $this->logger->notice('Quraba Backup catalog rebuilt from remote truth.', ['adopted' => count($applied)]);

            return $this->report($plan, true, $applied, null);
        } finally {
            $locks->release();
        }
    }

    /**
     * @return array{runs: list<array<string, mixed>>, malformed: list<array{locator: string, reason: string}>, malformed_expiry_records: list<string>, repository_id: ?string, unknown_remote: array{archives: int, snapshots: int}, catalog_available: bool}
     */
    private function plan(ApplicationIdentity $identity): array
    {
        $scan = $this->manifests->scan($identity);
        $expired = $this->tombstones->all($identity);
        $layout = $this->remote->layout();
        $objects = $this->remote->objects();
        $catalogAvailable = $this->catalog->available();
        $needsRestic = $scan->manifests !== [] && array_filter($scan->manifests, static fn (RemoteManifest $m): bool => $m->snapshotId !== null) !== [];
        $snapshots = [];
        $repositoryId = null;

        if ($needsRestic) {
            // Physical truth is required: a repository that cannot be read
            // stops the rebuild instead of trusting manifest claims.
            $inspection = $this->repository->inspect();

            if (! $inspection->state->isReady() || $inspection->repositoryId === null) {
                throw RestoreFailed::sourceUnavailable('the Restic repository cannot be read, so snapshots named by manifests cannot be verified physically ('.$inspection->state->value.')');
            }

            $repositoryId = $inspection->repositoryId;

            foreach ($this->repository->snapshots(SnapshotIdentity::applicationSelector($identity)) as $snapshot) {
                $snapshots[$snapshot->id] = $snapshot;
            }
        }

        $runs = [];
        $claimedArchives = [];
        $claimedSnapshots = [];

        foreach ($scan->manifests as $manifest) {
            $record = $expired['tombstones'][$manifest->runUuid] ?? null;
            $components = [];

            if ($manifest->archiveLocator !== null) {
                $claimedArchives[$manifest->archiveLocator] = true;
                $components['application_archive'] = $this->archiveState($manifest, $record);
            }

            if ($manifest->snapshotId !== null) {
                $claimedSnapshots[$manifest->snapshotId] = true;
                $components['media_snapshot'] = $this->snapshotState($manifest, $record, $identity, $snapshots, $repositoryId);
            }

            $local = $catalogAvailable ? BackupRun::query()->where('uuid', $manifest->runUuid)->with('artifacts')->first() : null;

            $runs[] = [
                'run_uuid' => $manifest->runUuid,
                'created_at' => $manifest->createdAt->toIso8601ZuluString(),
                'profile' => $manifest->profile->value,
                'trigger' => $manifest->trigger->value,
                'manifest_status' => $manifest->status->value,
                'manifest' => $manifest->locator,
                'components' => $components,
                'local_status' => $local?->status->value,
                'action' => $this->action($local, $components),
            ];
        }

        $unknownArchives = 0;

        foreach ($objects->listFiles($layout->archivesRoot()) as $path) {
            $unknownArchives += isset($claimedArchives[$path]) ? 0 : 1;
        }

        return [
            'runs' => $runs,
            'malformed' => $scan->malformed,
            'malformed_expiry_records' => $expired['malformed'],
            'repository_id' => $repositoryId,
            'unknown_remote' => ['archives' => $unknownArchives, 'snapshots' => count(array_diff_key($snapshots, $claimedSnapshots))],
            'catalog_available' => $catalogAvailable,
        ];
    }

    private function archiveState(RemoteManifest $manifest, ?RetentionTombstone $record): string
    {
        $locator = $this->remote->layout()->assertManaged((string) $manifest->archiveLocator);
        $objects = $this->remote->objects();
        $exists = $objects->exists($locator);

        if ($record?->covers('application_archive') === true) {
            return $exists ? 'expired_but_present' : 'expired';
        }

        if (! $exists) {
            return 'missing';
        }

        return $objects->size($locator) === $manifest->archiveBytes ? 'verified' : 'size_mismatch';
    }

    /**
     * @param  array<string, ResticSnapshot>  $snapshots
     */
    private function snapshotState(RemoteManifest $manifest, ?RetentionTombstone $record, ApplicationIdentity $identity, array $snapshots, ?string $repositoryId): string
    {
        $snapshot = $snapshots[(string) $manifest->snapshotId] ?? null;

        if ($record?->covers('media_snapshot') === true) {
            return $snapshot === null ? 'expired' : 'expired_but_present';
        }

        if ($repositoryId === null || $manifest->repositoryId !== $repositoryId) {
            return 'other_repository';
        }

        if ($snapshot === null) {
            return 'missing';
        }

        $kind = SnapshotKind::tryFrom((string) $manifest->snapshotKind);

        return $kind !== null && SnapshotIdentity::for($identity, $kind, $manifest->runUuid)->matches($snapshot) ? 'verified' : 'identity_mismatch';
    }

    /**
     * @param  array<string, string>  $components
     */
    private function action(?BackupRun $local, array $components): string
    {
        if ($local === null) {
            return in_array('verified', $components, true) ? 'adopt' : 'skip_nothing_present';
        }

        if (! in_array($local->status, [BackupStatus::Completed, BackupStatus::Partial], true)) {
            // A stale or interrupted local row: reconciliation owns it.
            return 'needs_reconcile';
        }

        foreach ($components as $name => $state) {
            $kind = $name === 'application_archive' ? ArtifactKind::ApplicationArchive : ArtifactKind::ResticSnapshot;
            $artifact = $local->artifacts->first(static fn (BackupArtifact $a): bool => $a->kind === $kind);

            if ($state === 'expired' && $artifact?->status === ArtifactStatus::Verified) {
                return 'expire_locally';
            }
        }

        return 'present';
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function adopt(array $plan, ApplicationIdentity $identity): array
    {
        $runUuid = is_string($plan['run_uuid']) ? $plan['run_uuid'] : '';
        $manifest = $this->manifests->find($identity, $runUuid) ?? throw RestoreFailed::sourceUnavailable('a manifest disappeared during the rebuild');
        $components = is_array($plan['components']) ? $plan['components'] : [];
        $now = CarbonImmutable::now('UTC')->toIso8601ZuluString();

        $run = BackupRun::adopt($manifest->runUuid, $manifest->profile, $manifest->trigger, $manifest->consistency, $manifest->createdAt, [
            'package_version' => $manifest->packageVersion,
            'app_id' => $manifest->appId,
            'environment' => $manifest->environment,
            'restic_repository_id' => $manifest->repositoryId,
            'catalog_rebuilt' => ['at' => $now, 'manifest' => $manifest->locator, 'components' => $components],
        ]);

        $missing = null;

        foreach ($components as $name => $state) {
            $kind = $name === 'application_archive' ? ArtifactKind::ApplicationArchive : ArtifactKind::ResticSnapshot;
            $artifact = $run->addArtifact($kind, ['catalog_rebuilt_at' => $now, 'physical_state' => $state]);

            if (! in_array($state, ['verified', 'expired'], true)) {
                // Claimed by the manifest, not observable: never verified.
                $missing = FailureDetails::make('catalog.component_'.(is_string($state) ? $state : 'missing'), 'catalog.rebuild', sprintf('The %s named by the manifest is %s remotely.', (string) $name, is_string($state) ? str_replace('_', ' ', $state) : 'missing'), $this->redactor);
                $artifact->markFailed($missing);

                continue;
            }

            $artifact->markVerifying();

            if ($kind === ArtifactKind::ApplicationArchive) {
                $artifact->markVerifiedObject((string) $manifest->archiveLocator, (string) $manifest->archiveSha256, (int) $manifest->archiveBytes, [
                    'adopted' => true,
                    'verification' => $state === 'verified' ? 'object present with the exact recorded size; SHA-256 taken from the immutable manifest (not re-hashed)' : 'recorded as expired by retention',
                ]);
            } else {
                $artifact->markVerifiedSnapshot((string) $manifest->snapshotId, [
                    'kind' => $manifest->snapshotKind,
                    'roots' => $manifest->snapshotRoots,
                    'repository_id' => $manifest->repositoryId,
                    'adopted' => true,
                ]);
            }

            if ($state === 'expired') {
                $artifact->markExpired();
            }
        }

        // The manifest was just read: it exists, with exactly this content.
        $canonical = ManifestBuilder::canonicalJson($this->remote->objects()->read($manifest->locator, RemoteManifestCatalog::MAX_MANIFEST_BYTES)) ?? throw RestoreFailed::sourceUnavailable('a manifest could not be re-read');
        $manifestArtifact = $run->addArtifact(ArtifactKind::RemoteManifest);
        $manifestArtifact->markVerifying();
        $manifestArtifact->markVerifiedObject($manifest->locator, hash('sha256', $canonical), strlen($canonical), ['adopted' => true, 'schema_version' => $manifest->schemaVersion]);

        $status = $missing === null ? $manifest->status : BackupStatus::Partial;
        $failure = $missing ?? ($status === BackupStatus::Partial ? FailureDetails::make('backup.partial', 'catalog.rebuild', 'The manifest records this run as partial.', $this->redactor) : null);
        $run->refresh()->finalizeAdoption($status, $failure);

        return ['run_uuid' => $runUuid, 'action' => 'adopted', 'status' => $status->value, 'components' => $components];
    }

    /**
     * A known run whose artifact is still VERIFIED locally although its
     * remote expiry record and its physical absence agree.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function expireLocally(array $plan): array
    {
        $run = BackupRun::query()->where('uuid', $plan['run_uuid'])->with('artifacts')->firstOrFail();
        $expired = [];

        foreach (is_array($plan['components']) ? $plan['components'] : [] as $name => $state) {
            if ($state !== 'expired') {
                continue;
            }

            $kind = $name === 'application_archive' ? ArtifactKind::ApplicationArchive : ArtifactKind::ResticSnapshot;

            /** @var BackupArtifact $artifact */
            foreach ($run->artifacts as $artifact) {
                if ($artifact->kind === $kind && $artifact->status === ArtifactStatus::Verified) {
                    $artifact->markExpired();
                    $expired[] = $name;
                }
            }
        }

        return ['run_uuid' => $run->uuid, 'action' => 'expired_locally', 'components' => $expired];
    }

    /**
     * @param  array{runs: list<array<string, mixed>>, malformed: list<array{locator: string, reason: string}>, malformed_expiry_records: list<string>, repository_id: ?string, unknown_remote: array{archives: int, snapshots: int}, catalog_available: bool}  $plan
     * @param  list<array<string, mixed>>  $applied
     * @return array<string, mixed>
     */
    private function report(array $plan, bool $apply, array $applied, ?FailureDetails $failure): array
    {
        $counts = [];

        foreach ($plan['runs'] as $run) {
            $action = is_string($run['action']) ? $run['action'] : 'unknown';
            $counts[$action] = ($counts[$action] ?? 0) + 1;
        }

        ksort($counts);

        return [
            'ok' => $failure === null,
            'mode' => $apply ? 'apply' : 'plan',
            'catalog_available' => $plan['catalog_available'],
            'repository_id' => $plan['repository_id'],
            'actions' => $counts,
            'runs' => $plan['runs'],
            'applied' => $applied,
            'malformed_manifests' => $plan['malformed'],
            'malformed_expiry_records' => $plan['malformed_expiry_records'],
            'unknown_remote' => $plan['unknown_remote'],
            'error' => $failure?->toArray(),
            'notice' => $apply ? 'Runs were adopted only from physical evidence.' : 'Plan only: nothing was changed. Use --apply to adopt.',
        ];
    }
}
