<?php

declare(strict_types=1);

namespace Quraba\Backup\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Quraba\Backup\Coordination\LockName;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\MaintenanceOperation;
use Quraba\Backup\Enums\MaintenanceStatus;
use Quraba\Backup\Exceptions\RetentionFailed;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Restic\RepositoryIdentityGuard;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Restic\ResticSnapshot;
use Quraba\Backup\Restic\SnapshotIdentity;
use Quraba\Backup\Restic\SnapshotKind;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Storage\RemoteStorage;
use Throwable;

/**
 * Plans retention and — only with an explicit execute flag — performs it.
 *
 * Per expired run, strictly in this order:
 *
 *   revalidate protections → record the intent on the run (catalog)
 *   → delete the EXACT archive object → prove it absent
 *   → confirm the EXACT full snapshot ID is this run's → `restic forget ID`
 *     → prove it absent from a tag-filtered listing
 *   → write the immutable remote tombstone
 *   → only then mark the artifacts EXPIRED.
 *
 * Nothing is ever selected by prefix, by "latest" or by Restic keep-policies;
 * nothing is marked expired before its absence is proven; prune is never run.
 * The first failure stops the pass. If it happened after a deletion was
 * issued, the maintenance run is INDETERMINATE and the run keeps its intent
 * record so reconciliation can settle it from physical evidence (deleted
 * artifacts are never recreated).
 *
 * Execution holds the global and maintenance locks; plan-only takes no lock.
 */
final readonly class RetentionExecutor
{
    public function __construct(
        private OperationCoordinator $coordinator,
        private IdentityResolver $identities,
        private RetentionInventory $inventory,
        private RetentionPlanner $planner,
        private RemoteStorage $remote,
        private ResticRunner $runner,
        private ResticRepository $repository,
        private RepositoryIdentityGuard $repositoryIdentity,
        private RetentionTombstoneStore $tombstones,
        private SecretRedactor $redactor,
        private LoggerInterface $logger,
    ) {}

    /**
     * Read-only plan from the local catalog (no lock, no audit, no remote access).
     */
    public function plan(): RetentionPlan
    {
        return $this->planner->plan(
            $this->inventory->candidates(),
            $this->inventory->policies(),
            $this->inventory->externalProtections(),
            CarbonImmutable::now('UTC'),
        );
    }

    public function run(bool $execute): RetentionReport
    {
        $identity = $this->identities->current();
        $locks = $execute ? $this->coordinator->beginWriteOperation('retention', LockName::Maintenance) : null;

        try {
            return $this->perform($identity, $execute);
        } finally {
            $locks?->release();
        }
    }

    /**
     * Settles a run whose retention stopped midway (reconciliation): when
     * every component named by its intent record is physically absent, the
     * tombstone is written and the artifacts are marked expired. When any
     * component is still present nothing changes (a later retention pass
     * repeats the deletion); nothing is ever recreated.
     *
     * @return array<string, mixed> evidence
     */
    public function settlePending(BackupRun $run, ApplicationIdentity $identity): array
    {
        $pending = $run->metadata['retention_pending'] ?? null;

        if (! is_array($pending)) {
            return ['run_uuid' => $run->uuid, 'outcome' => 'nothing_pending'];
        }

        $archive = is_string($pending['archive_locator'] ?? null) ? $pending['archive_locator'] : null;
        $snapshot = is_string($pending['snapshot_id'] ?? null) ? $pending['snapshot_id'] : null;
        $present = [];

        if ($archive !== null && $this->remote->objects()->exists($this->remote->layout()->assertManaged($archive))) {
            $present[] = 'application_archive';
        }

        if ($snapshot !== null && in_array($snapshot, $this->applicationSnapshotIds($identity), true)) {
            $present[] = 'media_snapshot';
        }

        if ($snapshot !== null && $this->repository->snapshots([], [$snapshot]) !== []) {
            $present[] = 'media_snapshot';
        }

        if ($present !== []) {
            return ['run_uuid' => $run->uuid, 'outcome' => 'still_present', 'present' => $present];
        }

        $this->settle($run, $identity, is_string($pending['maintenance_run_uuid'] ?? null) ? $pending['maintenance_run_uuid'] : '');

        return ['run_uuid' => $run->uuid, 'outcome' => 'settled', 'components' => $pending['components'] ?? []];
    }

    private function perform(ApplicationIdentity $identity, bool $execute): RetentionReport
    {
        try {
            $plan = $this->plan();
        } catch (Throwable $exception) {
            $audit = BackupMaintenanceRun::plan(MaintenanceOperation::Retention, ! $execute, [], ['stage' => 'plan']);
            $audit->markRunning();
            $audit->markFailed(FailureDetails::fromThrowable($exception, 'retention.plan', $this->redactor));

            throw $exception;
        }

        $audit = BackupMaintenanceRun::plan(
            MaintenanceOperation::Retention,
            ! $execute,
            array_map(static fn (RetentionDecision $d): array => $d->toArray(), $plan->expired()),
            ['policies' => $plan->policies, 'keep' => count($plan->kept()), 'expire' => count($plan->expired())],
        );
        $audit->markRunning();

        [$lingering, $unknown, $inspectionError] = $this->inspect($identity);
        $audit->mergeMetadata(['lingering' => $lingering, 'unknown' => count($unknown), 'inspection_error' => $inspectionError]);

        if (! $execute) {
            $audit->markCompleted();

            return new RetentionReport($audit->uuid, false, $plan, [], $lingering, $unknown, $inspectionError, MaintenanceStatus::Completed, null);
        }

        $expired = [];
        $uncertain = false;
        $failure = null;

        try {
            if ($inspectionError !== null) {
                throw new RetentionFailed('Remote state could not be inspected, so deletions cannot be proven: '.$inspectionError, 'retention.inspection_failed');
            }

            if ($this->needsRestic($plan, $lingering)) {
                $this->repositoryIdentity->verifyOpenRepository();
            }

            foreach ($plan->expired() as $decision) {
                $expired[] = $this->expireRun($decision, $identity, $audit->uuid, $uncertain);
            }

            foreach ($lingering as $item) {
                $expired[] = $this->removeLingering($item, $identity, $uncertain);
            }
        } catch (Throwable $exception) {
            $failure = FailureDetails::fromThrowable($exception, 'retention.execute', $this->redactor);
            $this->logger->warning('Quraba Backup retention stopped.', ['maintenance_run_uuid' => $audit->uuid, 'code' => $failure->code, 'uncertain' => $uncertain]);
        }

        if ($failure === null) {
            $audit->markCompleted($expired);
            $status = MaintenanceStatus::Completed;
        } elseif ($uncertain) {
            $audit->markIndeterminate($failure, $expired);
            $status = MaintenanceStatus::Indeterminate;
        } else {
            $audit->markFailed($failure, $expired);
            $status = MaintenanceStatus::Failed;
        }

        return new RetentionReport($audit->uuid, true, $plan, $expired, $lingering, $unknown, null, $status, $failure);
    }

    /**
     * @return array<string, mixed>
     */
    private function expireRun(RetentionDecision $decision, ApplicationIdentity $identity, string $maintenanceUuid, bool &$uncertain): array
    {
        $candidate = $decision->candidate;
        $run = BackupRun::query()->where('uuid', $candidate->runUuid)->with('artifacts')->first()
            ?? throw RetentionFailed::protectionChanged(sprintf('run %s disappeared from the catalog', $candidate->runUuid));

        $this->revalidate($run, $candidate);

        // Intent before any deletion: an interruption can be settled later.
        $run->mergeMetadata(['retention_pending' => [
            'maintenance_run_uuid' => $maintenanceUuid,
            'components' => $candidate->components(),
            'archive_locator' => $candidate->archiveLocator,
            'snapshot_id' => $candidate->snapshotId,
            'expired_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        ]]);

        $uncertain = true;

        if ($candidate->archiveLocator !== null) {
            $this->deleteArchive($candidate->archiveLocator, $run->uuid);
        }

        if ($candidate->snapshotId !== null) {
            $this->forgetSnapshot($candidate, $identity);
        }

        $tombstone = $this->settle($run->refresh(), $identity, $maintenanceUuid);
        $uncertain = false;

        return [
            'run_uuid' => $run->uuid,
            'family' => $candidate->family,
            'components' => $tombstone->components,
            'archive_locator' => $candidate->archiveLocator,
            'snapshot_id' => $candidate->snapshotId,
            'tombstone' => $this->remote->layout()->tombstone($run->uuid),
        ];
    }

    /**
     * The run must still be exactly what was planned and must not have
     * gained a protection since.
     */
    private function revalidate(BackupRun $run, RetentionCandidate $planned): void
    {
        $current = RetentionInventory::candidate($run);

        if (! in_array($run->status, [BackupStatus::Completed, BackupStatus::Partial], true)
            || $current === null
            || $current->archiveLocator !== $planned->archiveLocator
            || $current->snapshotId !== $planned->snapshotId) {
            throw RetentionFailed::protectionChanged(sprintf('run %s changed since planning', $run->uuid));
        }

        if ($run->isPinned() || $run->trigger->value === 'pre_restore' || isset($this->inventory->externalProtections()[$run->uuid])) {
            throw RetentionFailed::protectionChanged(sprintf('run %s is protected now', $run->uuid));
        }
    }

    private function deleteArchive(string $locator, string $runUuid): void
    {
        $layout = $this->remote->layout();

        // Exactly this run's archive object, nothing broader.
        if ($layout->archiveRunUuid($locator) !== $runUuid) {
            throw new RetentionFailed(sprintf('Refusing to delete [%s]: it is not the exact archive object of run %s.', $locator, $runUuid), 'retention.locator_refused');
        }

        $objects = $this->remote->objects();
        $objects->delete($layout->assertManaged($locator));

        if ($objects->exists($locator)) {
            throw RetentionFailed::deletionUnproven(sprintf('the archive [%s] still exists after deletion', $locator));
        }
    }

    private function forgetSnapshot(RetentionCandidate $candidate, ApplicationIdentity $identity): void
    {
        $snapshotId = (string) $candidate->snapshotId;
        $listed = $this->applicationSnapshots($identity);

        if (! isset($listed[$snapshotId])) {
            // A changed-tag exact snapshot is a conflict, not proof of absence.
            if ($this->repository->snapshots([], [$snapshotId]) !== []) {
                throw new RetentionFailed('An exact snapshot still exists but no longer has this application identity; refusing to expire it.', 'retention.snapshot_refused');
            }

            return;
        }

        $kind = SnapshotKind::tryFrom((string) $candidate->snapshotKind)
            ?? ($candidate->family === BackupProfile::Recovery->value ? SnapshotKind::RecoveryMedia : SnapshotKind::Media);

        if (! SnapshotIdentity::for($identity, $kind, $candidate->runUuid)->matches($listed[$snapshotId])) {
            throw new RetentionFailed(sprintf('Refusing to forget snapshot %s: its tags do not prove it belongs to run %s.', $snapshotId, $candidate->runUuid), 'retention.snapshot_refused');
        }

        $result = $this->runner->forget([$snapshotId]);

        if (isset($this->applicationSnapshots($identity)[$snapshotId])) {
            throw RetentionFailed::deletionUnproven(sprintf('snapshot %s is still listed after `restic forget` (exit %d): %s', $snapshotId, $result->exitCode, mb_substr($result->stderr, 0, 300)));
        }

        if ($this->repository->snapshots([], [$snapshotId]) !== []) {
            throw RetentionFailed::deletionUnproven(sprintf('snapshot %s still resolves by exact ID after forget', $snapshotId));
        }

        if (! $result->successful()) {
            $this->logger->warning('Restic forget reported an error, but the snapshot is proven absent.', ['snapshot_id' => $snapshotId, 'exit_code' => $result->exitCode]);
        }
    }

    /**
     * Tombstone first (remote truth for clean hosts), then the catalog.
     */
    private function settle(BackupRun $run, ApplicationIdentity $identity, string $maintenanceUuid): RetentionTombstone
    {
        $pending = is_array($run->metadata['retention_pending'] ?? null) ? $run->metadata['retention_pending'] : [];
        $components = array_values(array_filter(is_array($pending['components'] ?? null) ? $pending['components'] : [], is_string(...)));

        try {
            $expiredAt = CarbonImmutable::parse(is_string($pending['expired_at'] ?? null) ? $pending['expired_at'] : 'now')->utc();
        } catch (Throwable) {
            $expiredAt = CarbonImmutable::now('UTC');
        }

        $tombstone = $this->tombstones->put(RetentionTombstone::make($run->uuid, $identity, $components, $expiredAt, $maintenanceUuid !== '' ? $maintenanceUuid : (string) Str::uuid7()));

        /** @var BackupArtifact $artifact */
        foreach ($run->artifacts()->get() as $artifact) {
            $component = match ($artifact->kind) {
                ArtifactKind::ApplicationArchive => 'application_archive',
                ArtifactKind::ResticSnapshot => 'media_snapshot',
                ArtifactKind::RemoteManifest => null,
            };

            if ($component !== null && $tombstone->covers($component) && $artifact->status === ArtifactStatus::Verified) {
                $artifact->markExpired();
            }
        }

        $run->mergeMetadata([
            'retention' => [
                'expired_at' => $tombstone->expiredAt->toIso8601ZuluString(),
                'components' => $tombstone->components,
                'maintenance_run_uuid' => $tombstone->maintenanceRunUuid,
                'tombstone' => $this->remote->layout()->tombstone($run->uuid),
            ],
            'retention_pending' => null,
        ]);

        return $tombstone;
    }

    /**
     * Catalog-expired artifacts that are still physically present, and
     * managed remote data the catalog does not know (reported, never
     * deleted: it may belong to a lost catalog).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: ?string}
     */
    private function inspect(ApplicationIdentity $identity): array
    {
        $lingering = [];
        $unknown = [];

        try {
            $layout = $this->remote->layout();
            $expiredArchives = [];
            $knownArchives = [];

            foreach (BackupArtifact::query()->where('kind', ArtifactKind::ApplicationArchive->value)->whereNotNull('locator')->with('run')->get() as $artifact) {
                $knownArchives[(string) $artifact->locator] = true;

                if ($artifact->status === ArtifactStatus::Expired) {
                    $expiredArchives[(string) $artifact->locator] = $artifact->run->uuid;
                }
            }

            foreach ($this->remote->objects()->listFiles($layout->archivesRoot()) as $path) {
                if (isset($expiredArchives[$path])) {
                    $lingering[] = ['component' => 'application_archive', 'run_uuid' => $expiredArchives[$path], 'archive_locator' => $path];
                } elseif (! isset($knownArchives[$path])) {
                    $unknown[] = ['component' => 'application_archive', 'archive_locator' => $path];
                }
            }

            if ($this->repository->isConfigured() && BackupArtifact::query()->where('kind', ArtifactKind::ResticSnapshot->value)->exists()) {
                $expiredSnapshots = [];
                $knownSnapshots = [];

                foreach (BackupArtifact::query()->where('kind', ArtifactKind::ResticSnapshot->value)->with('run')->get() as $artifact) {
                    foreach ([$artifact->snapshot_id, $artifact->metadata['incomplete_snapshot_id'] ?? null] as $id) {
                        if (is_string($id)) {
                            $knownSnapshots[$id] = true;
                        }
                    }

                    if ($artifact->status === ArtifactStatus::Expired && $artifact->snapshot_id !== null) {
                        $expiredSnapshots[$artifact->snapshot_id] = $artifact->run->uuid;
                    }
                }

                foreach ($this->applicationSnapshotIds($identity) as $id) {
                    if (isset($expiredSnapshots[$id])) {
                        $lingering[] = ['component' => 'media_snapshot', 'run_uuid' => $expiredSnapshots[$id], 'snapshot_id' => $id];
                    } elseif (! isset($knownSnapshots[$id])) {
                        $unknown[] = ['component' => 'media_snapshot', 'snapshot_id' => $id];
                    }
                }
            }
        } catch (Throwable $exception) {
            return [$lingering, $unknown, $this->redactor->redact($exception->getMessage())];
        }

        return [$lingering, $unknown, null];
    }

    /**
     * Completes an earlier decision: the artifact is already expired in the
     * catalog (and tombstoned), but the object/snapshot is still present.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function removeLingering(array $item, ApplicationIdentity $identity, bool &$uncertain): array
    {
        $runUuid = $item['run_uuid'] ?? null;
        if (! is_string($runUuid)) {
            throw new RetentionFailed('A lingering artifact lacks a valid run UUID.', 'retention.lingering_refused');
        }
        $run = BackupRun::query()->where('uuid', $runUuid)->first() ?? throw new RetentionFailed('The run of a lingering artifact is missing from the catalog.', 'retention.lingering_refused');
        $component = $item['component'] ?? null;
        $tombstone = $this->tombstones->find($runUuid);
        if (! is_string($component) || $tombstone === null || ! $tombstone->covers($component)) {
            throw new RetentionFailed('A lingering artifact has no matching immutable retention tombstone.', 'retention.lingering_refused');
        }
        $uncertain = true;

        if (($item['component'] ?? null) === 'application_archive') {
            $locator = $item['archive_locator'] ?? null;
            if (! is_string($locator)) {
                throw new RetentionFailed('A lingering archive lacks an exact locator.', 'retention.lingering_refused');
            }
            $this->deleteArchive($locator, $run->uuid);
        } else {
            $snapshotId = $item['snapshot_id'] ?? null;
            if (! is_string($snapshotId)) {
                throw new RetentionFailed('A lingering snapshot lacks an exact ID.', 'retention.lingering_refused');
            }
            $artifact = $run->artifacts()->where('kind', ArtifactKind::ResticSnapshot->value)->first();
            $kind = $artifact?->metadata['kind'] ?? null;

            $this->forgetSnapshot(new RetentionCandidate(
                $run->uuid,
                $run->profile->value,
                $run->status->value,
                $run->trigger->value,
                $run->consistency->value,
                $run->requested_at ?? CarbonImmutable::now('UTC'),
                null,
                null,
                $snapshotId,
                is_string($kind) ? $kind : null,
            ), $identity);
        }

        $uncertain = false;

        return [...$item, 'lingering' => true];
    }

    /**
     * @param  list<array<string, mixed>>  $lingering
     */
    private function needsRestic(RetentionPlan $plan, array $lingering): bool
    {
        foreach ($plan->expired() as $decision) {
            if ($decision->candidate->snapshotId !== null) {
                return true;
            }
        }

        foreach ($lingering as $item) {
            if (($item['component'] ?? null) === 'media_snapshot') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, ResticSnapshot>
     */
    private function applicationSnapshots(ApplicationIdentity $identity): array
    {
        $snapshots = [];

        foreach ($this->repository->snapshots(SnapshotIdentity::applicationSelector($identity)) as $snapshot) {
            $snapshots[$snapshot->id] = $snapshot;
        }

        return $snapshots;
    }

    /**
     * @return list<string>
     */
    private function applicationSnapshotIds(ApplicationIdentity $identity): array
    {
        return array_keys($this->applicationSnapshots($identity));
    }
}
