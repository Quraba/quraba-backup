<?php

declare(strict_types=1);

namespace Quraba\Backup\Health;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Archive\ArchiveStore;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Enums\HealthState;
use Quraba\Backup\Enums\MaintenanceOperation;
use Quraba\Backup\Enums\MaintenanceStatus;
use Quraba\Backup\Enums\RestoreMode;
use Quraba\Backup\Enums\RestoreStatus;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\RepositoryIdentityMismatch;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Manifest\ManifestStore;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Restic\RepositoryIdentityGuard;
use Quraba\Backup\Restic\RepositoryState;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\SnapshotIdentity;
use Quraba\Backup\Restic\SnapshotKind;
use Quraba\Backup\Restore\Journal\RestoreJournal;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Restore\Live\SafetyBackupInspector;
use Quraba\Backup\Retention\RetentionExecutor;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Workspace\WorkspaceManager;
use Throwable;

/**
 * Recovery health: can this application actually be recovered right now?
 *
 * Catalog truth is combined with cheap physical sampling of the newest
 * important artifacts (archive object exists with the recorded size; the
 * exact Restic snapshot ID still resolves). Nothing is downloaded and no
 * `restic check` runs here. A remote service that cannot be reached makes
 * the affected checks UNKNOWN — never healthy, never failed by guess.
 *
 * Read-only: no locks are taken and nothing is written.
 */
final readonly class BackupHealthService
{
    public function __construct(
        private Repository $config,
        private IdentityResolver $identities,
        private ArchiveStore $archives,
        private ResticRepository $repository,
        private RepositoryIdentityGuard $repositoryIdentity,
        private ManifestStore $manifests,
        private RetentionExecutor $retention,
        private WorkspaceManager $workspaces,
        private OperationCoordinator $coordinator,
        private SecretRedactor $redactor,
        private RestoreJournalStore $journals,
        private SafetyBackupInspector $safetyBackups,
    ) {}

    public function check(bool $sample = true): HealthReport
    {
        $now = CarbonImmutable::now('UTC');

        try {
            $this->identities->current();
            BackupRun::query()->limit(1)->get();
        } catch (Throwable $exception) {
            return new HealthReport([
                CheckResult::fail('health.catalog', 'Backup catalog', 'The identity or the catalog is unavailable: '.$this->redactor->redact($exception->getMessage()), [], HealthState::Unknown),
                // Restore journals live outside the database: an interrupted
                // restore that emptied it is exactly what must be reported.
                $this->liveRestoreCheck($now, false),
            ], $now);
        }

        $archive = $this->newestVerified(ArtifactKind::ApplicationArchive);
        $snapshot = $this->newestVerified(ArtifactKind::ResticSnapshot);
        $recoveryPoint = $this->newestRecoveryPoint(false);
        $quiesced = $this->newestRecoveryPoint(true);

        $checks = [
            $this->ageCheck('health.database_backup', 'Newest database backup', 'database', $archive?->run, $now),
            $this->databaseObjectCheck($archive),
            $this->ageCheck('health.media_snapshot', 'Newest media snapshot', 'media', $snapshot?->run, $now),
            $this->ageCheck('health.recovery_point', 'Newest complete Recovery Point', 'recovery', $recoveryPoint, $now),
            $this->quiescedCheck($quiesced, $now),
            $this->partialRuns($now),
            $this->unresolvedRuns(),
            $this->repeatedFailures(),
        ];

        if ($sample) {
            $checks = [...$checks, ...$this->physicalSamples($archive, $snapshot, $recoveryPoint)];
            $checks[] = $this->repositoryCheck($snapshot !== null);
        } else {
            $checks[] = CheckResult::skip('health.samples', 'Physical sampling', 'Skipped (--offline): catalog-only view.');
        }

        return new HealthReport([
            ...$checks,
            $this->maintenanceCheck(),
            $this->workspaceCheck(),
            $this->retainedRestoreWorkspaceCheck(),
            $this->retentionCheck($now),
            $this->resticCheckFreshness($now, $snapshot !== null),
            $this->restoreCheck($now),
            $this->liveRestoreCheck($now, $sample),
        ], $now);
    }

    private function newestVerified(ArtifactKind $kind): ?BackupArtifact
    {
        return BackupArtifact::query()
            ->where('kind', $kind->value)
            ->where('status', ArtifactStatus::Verified->value)
            ->whereHas('run', static fn ($query) => $query->whereIn('status', [BackupStatus::Completed->value, BackupStatus::Partial->value]))
            ->with('run')
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->first();
    }

    private function databaseObjectCheck(?BackupArtifact $archive): CheckResult
    {
        if ($archive === null) {
            return CheckResult::warn('health.database_objects', 'Database object completeness', 'No verified database archive can prove complete database object protection.');
        }

        $database = $archive->metadata['database'] ?? null;
        $exact = is_array($database) ? ($database['exact_object_completeness'] ?? null) : null;
        if ($exact === true) {
            return CheckResult::pass('health.database_objects', 'Database object completeness', 'The newest database archive records complete object protection.');
        }

        return CheckResult::warn('health.database_objects', 'Database object completeness', 'The newest database archive does not prove complete object protection. Review database.event_policy, database.dump_routines and the doctor privilege checks.', ['run_uuid' => $archive->run->uuid, 'database' => $database]);
    }

    private function newestRecoveryPoint(bool $quiescedOnly): ?BackupRun
    {
        $query = BackupRun::query()
            ->where('profile', BackupProfile::Recovery->value)
            ->where('status', BackupStatus::Completed->value)
            ->with('artifacts')
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        if ($quiescedOnly) {
            $query->where('consistency', ConsistencyLevel::Quiesced->value);
        }

        foreach ($query->get() as $run) {
            $verified = $run->artifacts->filter(static fn (BackupArtifact $a): bool => $a->status === ArtifactStatus::Verified)->map(static fn (BackupArtifact $a): string => $a->kind->value)->all();

            if (in_array(ArtifactKind::ApplicationArchive->value, $verified, true) && in_array(ArtifactKind::ResticSnapshot->value, $verified, true)) {
                return $run;
            }
        }

        return null;
    }

    private function ageCheck(string $id, string $label, string $family, ?BackupRun $run, CarbonImmutable $now): CheckResult
    {
        $limit = $this->hours('quraba-backup.health.max_age_hours.'.$family);

        if ($limit === null) {
            return CheckResult::skip($id, $label, 'Not monitored (threshold disabled).');
        }

        if ($run === null) {
            return CheckResult::fail($id, $label, sprintf('No verified %s exists.', match ($family) {
                'recovery' => 'complete Recovery Point',
                'quiesced_recovery' => 'quiesced Recovery Point',
                'media' => 'media snapshot',
                default => 'database backup',
            }), ['max_age_hours' => $limit]);
        }

        $taken = $run->started_at ?? $run->requested_at ?? $now;
        $age = max(0, $now->getTimestamp() - $taken->getTimestamp()) / 3600;
        $details = ['run_uuid' => $run->uuid, 'captured_at' => $taken->toIso8601ZuluString(), 'age_hours' => round($age, 1), 'max_age_hours' => $limit, 'consistency' => $run->consistency->value];

        return $age <= $limit
            ? CheckResult::pass($id, $label, sprintf('%s (%.1f h old, limit %d h).', $run->uuid, $age, $limit), $details)
            : CheckResult::fail($id, $label, sprintf('The newest one (%s) is %.1f h old; the limit is %d h.', $run->uuid, $age, $limit), $details);
    }

    private function quiescedCheck(?BackupRun $run, CarbonImmutable $now): CheckResult
    {
        if ($this->hours('quraba-backup.health.max_age_hours.quiesced_recovery') === null) {
            return CheckResult::skip('health.quiesced_recovery_point', 'Newest quiesced Recovery Point', 'Not required (set health.max_age_hours.quiesced_recovery to monitor).');
        }

        return $this->ageCheck('health.quiesced_recovery_point', 'Newest quiesced Recovery Point', 'quiesced_recovery', $run, $now);
    }

    private function partialRuns(CarbonImmutable $now): CheckResult
    {
        $window = $this->hours('quraba-backup.health.partial_window_hours') ?? 170;
        $partial = BackupRun::query()->where('status', BackupStatus::Partial->value)->where('requested_at', '>=', $now->subHours($window))->pluck('uuid')->all();

        return $partial === []
            ? CheckResult::pass('health.partial_runs', 'Partial runs', sprintf('None in the last %d h.', $window))
            : CheckResult::warn('health.partial_runs', 'Partial runs', sprintf('%d partial run(s) in the last %d h: not every component was verified.', count($partial), $window), ['run_uuids' => $partial]);
    }

    private function unresolvedRuns(): CheckResult
    {
        $indeterminate = BackupRun::query()->where('status', BackupStatus::Indeterminate->value)->pluck('uuid')->all();
        $active = BackupRun::query()->whereIn('status', [BackupStatus::Preflighting->value, BackupStatus::Running->value, BackupStatus::Verifying->value])->get();
        $busy = $this->safely(fn (): bool => $this->coordinator->isWriteOperationRunning(), true);
        $stalledAfter = ($this->hours('quraba-backup.health.stalled_run_hours') ?? 12) * 3600;
        $stalled = [];

        foreach ($active as $run) {
            $age = CarbonImmutable::now('UTC')->getTimestamp() - ($run->requested_at ?? CarbonImmutable::now('UTC'))->getTimestamp();

            if (! $busy || $age > $stalledAfter) {
                $stalled[] = $run->uuid;
            }
        }

        if ($indeterminate === [] && $stalled === []) {
            return CheckResult::pass('health.unresolved_runs', 'Unresolved runs', $active->isEmpty() ? 'No indeterminate or interrupted runs.' : 'A backup is running.');
        }

        return CheckResult::warn('health.unresolved_runs', 'Unresolved runs', sprintf('%d indeterminate and %d interrupted/stalled run(s); run "php artisan quraba:backup:reconcile".', count($indeterminate), count($stalled)), [
            'indeterminate' => $indeterminate,
            'stalled' => $stalled,
        ]);
    }

    private function repeatedFailures(): CheckResult
    {
        $limit = max(1, $this->positiveInteger('quraba-backup.health.max_consecutive_failures', 3));
        $failing = [];

        foreach (BackupProfile::cases() as $profile) {
            $recent = BackupRun::query()
                ->where('profile', $profile->value)
                ->whereIn('status', [BackupStatus::Completed->value, BackupStatus::Partial->value, BackupStatus::Failed->value])
                ->orderByDesc('requested_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->pluck('status')
                ->all();

            if (count($recent) === $limit && array_filter($recent, static fn (mixed $s): bool => $s !== BackupStatus::Failed && $s !== BackupStatus::Failed->value) === []) {
                $failing[] = $profile->value;
            }
        }

        return $failing === []
            ? CheckResult::pass('health.repeated_failures', 'Repeated failures', sprintf('No profile failed %d times in a row.', $limit))
            : CheckResult::warn('health.repeated_failures', 'Repeated failures', sprintf('The last %d run(s) of [%s] all failed; see "php artisan quraba:backup:list".', $limit, implode(', ', $failing)), ['profiles' => $failing]);
    }

    /**
     * @return list<CheckResult>
     */
    private function physicalSamples(?BackupArtifact $archive, ?BackupArtifact $snapshot, ?BackupRun $recoveryPoint): array
    {
        $archives = [];
        $snapshots = [];

        foreach ([$archive, ...($recoveryPoint?->artifacts->all() ?? [])] as $artifact) {
            if ($artifact instanceof BackupArtifact && $artifact->status === ArtifactStatus::Verified && $artifact->kind === ArtifactKind::ApplicationArchive && $artifact->locator !== null) {
                $archives[$artifact->locator] = (int) $artifact->byte_size;
            }
        }

        foreach ([$snapshot, ...($recoveryPoint?->artifacts->all() ?? [])] as $artifact) {
            if ($artifact instanceof BackupArtifact && $artifact->status === ArtifactStatus::Verified && $artifact->kind === ArtifactKind::ResticSnapshot && $artifact->snapshot_id !== null) {
                $snapshots[$artifact->snapshot_id] = $artifact;
            }
        }

        return [$this->archiveSample($archives), $this->snapshotSample($snapshots)];
    }

    /**
     * @param  array<string, int>  $archives  locator => expected bytes
     */
    private function archiveSample(array $archives): CheckResult
    {
        if ($archives === []) {
            return CheckResult::skip('health.archive_sample', 'Archive physically present', 'No verified archive to sample.');
        }

        $results = [];

        try {
            foreach ($archives as $locator => $bytes) {
                $results[$locator] = $this->archives->sample($locator, $bytes);
            }
        } catch (Throwable $exception) {
            return CheckResult::fail('health.archive_sample', 'Archive physically present', 'Remote storage could not be reached: '.$this->redactor->redact($exception->getMessage()), [], HealthState::Unknown);
        }

        $bad = array_filter($results, static fn (string $state): bool => $state !== 'present');

        return $bad === []
            ? CheckResult::pass('health.archive_sample', 'Archive physically present', sprintf('%d newest archive(s) exist with their recorded size.', count($results)), ['archives' => $results])
            : CheckResult::fail('health.archive_sample', 'Archive physically present', sprintf('%d archive(s) recorded as verified are missing or changed remotely.', count($bad)), ['archives' => $results]);
    }

    /**
     * @param  array<string, BackupArtifact>  $snapshots
     */
    private function snapshotSample(array $snapshots): CheckResult
    {
        if ($snapshots === []) {
            return CheckResult::skip('health.snapshot_sample', 'Snapshot still resolves', 'No verified snapshot to sample.');
        }

        try {
            $identity = $this->identities->current();
            $found = [];

            foreach ($this->repository->snapshots([], array_keys($snapshots)) as $resolved) {
                $found[$resolved->id] = $resolved;
            }

            // Restic silently skips IDs it could not load, so a missing ID is
            // only believed after a tag-filtered listing also lacks it.
            if (count($found) !== count($snapshots)) {
                foreach ($this->repository->snapshots(SnapshotIdentity::applicationSelector($identity)) as $listed) {
                    if (isset($snapshots[$listed->id])) {
                        $found[$listed->id] = $listed;
                    }
                }
            }
        } catch (Throwable $exception) {
            return CheckResult::fail('health.snapshot_sample', 'Snapshot still resolves', 'The Restic repository could not be queried: '.$this->redactor->redact($exception->getMessage()), [], HealthState::Unknown);
        }

        $states = [];

        foreach ($snapshots as $id => $artifact) {
            $kindValue = $artifact->metadata['kind'] ?? null;
            $kind = is_string($kindValue) ? SnapshotKind::tryFrom($kindValue) : null;
            $states[$id] = ! isset($found[$id]) ? 'missing'
                : ($kind !== null && SnapshotIdentity::for($identity, $kind, $artifact->run->uuid)->matches($found[$id]) ? 'present' : 'identity_mismatch');
        }

        $missing = array_keys(array_filter($states, static fn (string $s): bool => $s !== 'present'));

        return $missing === []
            ? CheckResult::pass('health.snapshot_sample', 'Snapshot still resolves', sprintf('%d newest snapshot ID(s) resolve exactly.', count($states)), ['snapshots' => $states])
            : CheckResult::fail('health.snapshot_sample', 'Snapshot still resolves', sprintf('%d snapshot(s) recorded as verified no longer exist in the repository.', count($missing)), ['snapshots' => $states]);
    }

    private function repositoryCheck(bool $snapshotsExpected): CheckResult
    {
        if (! $snapshotsExpected && ! $this->repository->isConfigured()) {
            return CheckResult::skip('health.repository', 'Restic repository', 'No media snapshots exist and no repository is configured.');
        }

        try {
            $inspection = $this->repository->inspect();
            $expected = $this->repositoryIdentity->expected();
            $expectedId = $expected === null ? $this->manifests->repositoryIdConsensus($this->identities->current()) : $expected->repository_id;
        } catch (RepositoryIdentityMismatch $exception) {
            return CheckResult::fail('health.repository', 'Restic repository', $this->redactor->redact($exception->getMessage()));
        } catch (Throwable $exception) {
            return CheckResult::fail('health.repository', 'Restic repository', 'The repository could not be inspected: '.$this->redactor->redact($exception->getMessage()), [], HealthState::Unknown);
        }

        $details = ['state' => $inspection->state->value, 'repository_id' => $inspection->repositoryId, 'expected_repository_id' => $expectedId];

        return match (true) {
            $inspection->state === RepositoryState::Unreachable, $inspection->state === RepositoryState::Locked => CheckResult::fail('health.repository', 'Restic repository', $inspection->message, $details, HealthState::Unknown),
            ! $inspection->state->isReady() => CheckResult::fail('health.repository', 'Restic repository', $inspection->message, $details),
            $expectedId === null => CheckResult::warn('health.repository', 'Restic repository', 'Reachable, but not bound to this application yet (the first media backup binds it).', $details),
            ! hash_equals($expectedId, (string) $inspection->repositoryId) => CheckResult::fail('health.repository', 'Restic repository', sprintf('The configured location holds repository %s, but this application is bound to %s.', $inspection->repositoryId, $expectedId), $details),
            default => CheckResult::pass('health.repository', 'Restic repository', sprintf('Reachable and it is the expected repository (%s).', $inspection->repositoryId), $details),
        };
    }

    private function maintenanceCheck(): CheckResult
    {
        $busy = $this->safely(fn (): bool => $this->coordinator->isWriteOperationRunning(), true);
        $open = BackupMaintenanceRun::query()
            ->whereIn('status', [MaintenanceStatus::Pending->value, MaintenanceStatus::Running->value, MaintenanceStatus::Indeterminate->value])
            ->get()
            ->filter(static fn (BackupMaintenanceRun $m): bool => $m->status === MaintenanceStatus::Indeterminate || ! $busy);
        $intents = BackupRun::query()->whereIn('status', [BackupStatus::Completed->value, BackupStatus::Partial->value])->get()
            ->filter(static fn (BackupRun $run): bool => is_array($run->metadata['retention_pending'] ?? null))
            ->count();

        if ($open->isEmpty() && $intents === 0) {
            return CheckResult::pass('health.maintenance', 'Maintenance', 'No unresolved maintenance.');
        }

        return CheckResult::warn('health.maintenance', 'Maintenance', sprintf('%d unresolved maintenance run(s) and %d unsettled retention deletion(s); run "php artisan quraba:backup:reconcile".', $open->count(), $intents), [
            'maintenance_runs' => array_values($open->map(static fn (BackupMaintenanceRun $m): array => ['uuid' => $m->uuid, 'operation' => $m->operation->value, 'status' => $m->status->value])->all()),
            'retention_intents' => $intents,
        ]);
    }

    private function workspaceCheck(): CheckResult
    {
        try {
            $hours = $this->hours('quraba-backup.workspace.abandoned_after_hours') ?? 24;
            $abandoned = $this->workspaces->abandoned($hours * 3600);
        } catch (Throwable $exception) {
            return CheckResult::warn('health.workspaces', 'Workspaces', 'Workspaces could not be listed: '.$this->redactor->redact($exception->getMessage()));
        }

        return $abandoned === []
            ? CheckResult::pass('health.workspaces', 'Workspaces', 'No abandoned workspaces.')
            : CheckResult::warn('health.workspaces', 'Workspaces', sprintf('%d abandoned workspace(s) hold disk space; inspect "php artisan quraba:backup:workspace:list" before cleanup.', count($abandoned)), ['count' => count($abandoned)]);
    }

    private function retainedRestoreWorkspaceCheck(): CheckResult
    {
        try {
            $retained = $this->workspaces->retainedRestores();
            $unresolvedRetained = array_values(array_filter($retained, fn (array $row): bool => $row['restore_uuid'] === 'invalid-marker' || ($this->journals->find($row['restore_uuid'])?->isUnresolved() ?? true)));
        } catch (Throwable $exception) {
            return CheckResult::warn('health.restore_workspaces', 'Retained restore workspaces', 'Retained restore workspaces could not be assessed: '.$this->redactor->redact($exception->getMessage()));
        }

        return $unresolvedRetained === []
            ? CheckResult::pass('health.restore_workspaces', 'Retained restore workspaces', 'No unresolved restore workspace is retained.')
            : CheckResult::warn('health.restore_workspaces', 'Retained restore workspaces', sprintf('%d unresolved restore workspace(s) retain private evidence, potentially including plaintext SQL. Reconcile the exact restore UUID before explicit cleanup.', count($unresolvedRetained)), ['restores' => $unresolvedRetained]);
    }

    private function retentionCheck(CarbonImmutable $now): CheckResult
    {
        try {
            $plan = $this->retention->plan();
        } catch (Throwable $exception) {
            return CheckResult::fail('health.retention', 'Retention', 'The retention policy cannot be evaluated: '.$this->redactor->redact($exception->getMessage()));
        }

        $eligible = count($plan->expired());
        $last = $this->lastCompleted(MaintenanceOperation::Retention, executedOnly: true);
        $maxAge = $this->hours('quraba-backup.health.retention_max_age_hours') ?? 192;
        $details = ['eligible_runs' => $eligible, 'last_executed_at' => $last?->finished_at?->toIso8601ZuluString()];

        if ($eligible === 0) {
            return CheckResult::pass('health.retention', 'Retention', 'No backup is eligible for expiry.', $details);
        }

        if ($last?->finished_at !== null && $now->getTimestamp() - $last->finished_at->getTimestamp() <= $maxAge * 3600) {
            return CheckResult::pass('health.retention', 'Retention', sprintf('%d run(s) are eligible; retention last executed %s.', $eligible, $last->finished_at->toIso8601ZuluString()), $details);
        }

        return CheckResult::warn('health.retention', 'Retention', sprintf('%d run(s) are eligible for expiry but retention has not been executed within %d h; review "php artisan quraba:backup:retention" and run it with --execute.', $eligible, $maxAge), $details);
    }

    private function resticCheckFreshness(CarbonImmutable $now, bool $snapshotsExist): CheckResult
    {
        if (! $snapshotsExist) {
            return CheckResult::skip('health.restic_check', 'Repository integrity check', 'No media snapshots yet.');
        }

        $days = $this->positiveInteger('quraba-backup.health.check_max_age_days', 35);
        $last = $this->lastCompleted(MaintenanceOperation::ResticCheck, executedOnly: false);

        if ($last?->finished_at === null) {
            return CheckResult::warn('health.restic_check', 'Repository integrity check', 'The repository was never checked; run "php artisan quraba:backup:restic:check".');
        }

        $age = ($now->getTimestamp() - $last->finished_at->getTimestamp()) / 86400;

        return $age <= $days
            ? CheckResult::pass('health.restic_check', 'Repository integrity check', sprintf('Last successful check %.0f day(s) ago.', $age), ['last_check_at' => $last->finished_at->toIso8601ZuluString()])
            : CheckResult::warn('health.restic_check', 'Repository integrity check', sprintf('The last successful check is %.0f day(s) old (limit %d).', $age, $days), ['last_check_at' => $last->finished_at->toIso8601ZuluString()]);
    }

    private function restoreCheck(CarbonImmutable $now): CheckResult
    {
        $unresolved = RestoreRun::query()->whereNotIn('status', array_map(static fn (RestoreStatus $status): string => $status->value, RestoreStatus::settled()))->get();
        $live = $unresolved->filter(static fn (RestoreRun $r): bool => $r->mode === RestoreMode::Restore);

        if ($live->isNotEmpty()) {
            return CheckResult::fail('health.restores', 'Restores', sprintf('%d live restore(s) are unresolved; the application state is UNKNOWN until they are reconciled.', $live->count()), [
                'restore_uuids' => array_values($live->pluck('uuid')->all()),
            ], HealthState::Unknown);
        }

        $busy = $this->safely(fn (): bool => $this->coordinator->isRestorePreparationRunning(), true);
        $stuck = $busy ? collect() : $unresolved;
        $recentFailedDryRuns = RestoreRun::query()
            ->where('mode', RestoreMode::DryRun->value)
            ->where('status', RestoreStatus::Failed->value)
            ->where('created_at', '>=', $now->subDays($this->positiveInteger('quraba-backup.health.restore_diagnostic_days', 7)))
            ->count();

        $details = ['interrupted_dry_runs' => array_values($stuck->pluck('uuid')->all()), 'recent_failed_dry_runs' => $recentFailedDryRuns];

        if ($stuck->isNotEmpty()) {
            return CheckResult::warn('health.restores', 'Restores', sprintf('%d interrupted restore dry run(s); they changed nothing, but should be investigated.', $stuck->count()), $details);
        }

        // Failed dry runs are diagnostics about a backup source, not damage.
        return CheckResult::pass('health.restores', 'Restores', $recentFailedDryRuns === 0 ? 'No unresolved restores.' : sprintf('No unresolved restores (%d recent dry run(s) failed; diagnostic only — see quraba:backup:restore output).', $recentFailedDryRuns), $details);
    }

    /**
     * Live restores, judged from the external restore journals (the
     * authority — the catalog rows above are only their mirror):
     *
     *  - unresolved AFTER the destructive boundary → the application state is
     *    UNKNOWN;
     *  - a destructive restore whose safety backup is not present remotely →
     *    FAILED (UNKNOWN when remote storage cannot be asked);
     *  - unresolved BEFORE the boundary → degraded: nothing was changed, but
     *    it blocks further restores and may have left maintenance mode on.
     */
    private function liveRestoreCheck(CarbonImmutable $now, bool $sample): CheckResult
    {
        try {
            $all = $this->journals->all();
        } catch (Throwable $exception) {
            return CheckResult::fail('health.live_restores', 'Live restores', 'The restore journals cannot be read: '.$this->redactor->redact($exception->getMessage()), [], HealthState::Unknown);
        }

        if ($all['unreadable'] !== []) {
            return CheckResult::fail('health.live_restores', 'Live restores', sprintf('%d restore journal(s) cannot be read; they may describe a restore that changed the application.', count($all['unreadable'])), ['unreadable' => $all['unreadable']], HealthState::Unknown);
        }

        $after = array_values(array_filter($all['journals'], static fn (RestoreJournal $j): bool => $j->isUnresolved() && $j->crossedDestructiveBoundary()));
        $before = array_values(array_filter($all['journals'], static fn (RestoreJournal $j): bool => $j->isUnresolved() && ! $j->crossedDestructiveBoundary()));

        if ($after !== []) {
            return CheckResult::fail('health.live_restores', 'Live restores', sprintf('%d live restore(s) stopped AFTER the destructive boundary; the application state is UNKNOWN. Run "php artisan quraba:backup:restore-reconcile".', count($after)), ['restores' => self::journalSummaries($after)], HealthState::Unknown);
        }

        $days = $this->positiveInteger('quraba-backup.retention.safety_days', 30);
        $unknown = [];

        foreach ($all['journals'] as $journal) {
            $settled = $journal->settledAt();
            $relevant = $journal->crossedDestructiveBoundary() && $journal->safetyRequired() && ($settled === null || $settled->addDays($days)->greaterThan($now));

            if (! $relevant) {
                continue;
            }

            $safety = $journal->safetyRunUuid();

            if ($safety === null) {
                return CheckResult::fail('health.live_restores', 'Live restores', sprintf('Restore %s changed the application but its journal names no safety backup.', $journal->restoreUuid()), ['restore' => $journal->summary()], HealthState::Failed);
            }

            if (! $sample) {
                continue;
            }

            $state = $this->safetyBackups->inspect($safety, $this->identities->current());

            if ($state['state'] === 'missing') {
                return CheckResult::fail('health.live_restores', 'Live restores', sprintf('The safety backup %s of destructive restore %s is missing remotely: %s.', $safety, $journal->restoreUuid(), $state['detail']), ['restore' => $journal->summary()], HealthState::Failed);
            }

            if ($state['state'] === 'unknown') {
                $unknown[] = $journal->restoreUuid();
            }
        }

        if ($unknown !== []) {
            return CheckResult::fail('health.live_restores', 'Live restores', 'The safety backup of a destructive restore could not be observed remotely.', ['restores' => $unknown], HealthState::Unknown);
        }

        if ($before !== []) {
            return CheckResult::warn('health.live_restores', 'Live restores', sprintf('%d live restore(s) were interrupted before changing anything; reconcile them (the application may still be in maintenance mode).', count($before)), ['restores' => self::journalSummaries($before)]);
        }

        return CheckResult::pass('health.live_restores', 'Live restores', sprintf('No unresolved live restore (%d journal(s)).', count($all['journals'])));
    }

    /**
     * @param  list<RestoreJournal>  $journals
     * @return list<array<string, mixed>>
     */
    private static function journalSummaries(array $journals): array
    {
        return array_map(static fn (RestoreJournal $journal): array => $journal->summary(), $journals);
    }

    private function lastCompleted(MaintenanceOperation $operation, bool $executedOnly): ?BackupMaintenanceRun
    {
        $query = BackupMaintenanceRun::query()
            ->where('operation', $operation->value)
            ->where('status', MaintenanceStatus::Completed->value)
            ->orderByDesc('finished_at')
            ->orderByDesc('id');

        if ($executedOnly) {
            $query->where('dry_run', false);
        }

        return $query->first();
    }

    private function hours(string $key): ?int
    {
        $value = $this->config->get($key);

        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value <= 0) {
            throw new ConfigurationException(sprintf('[%s] must be a positive number of hours or null.', $key));
        }

        return $value;
    }

    private function positiveInteger(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            throw new ConfigurationException(sprintf('[%s] must be a positive integer.', $key));
        }

        return $value;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $probe
     * @param  T  $fallback
     * @return T
     */
    private function safely(callable $probe, mixed $fallback): mixed
    {
        try {
            return $probe();
        } catch (Throwable) {
            return $fallback;
        }
    }
}
