<?php

declare(strict_types=1);

namespace Quraba\Backup\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\RestoreStatus;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;

/**
 * Reads retention inputs from the local catalog.
 *
 * Only terminal runs (completed, partial) with at least one VERIFIED,
 * non-expired archive or snapshot become candidates; runs in any other state
 * (including indeterminate) are invisible to retention.
 */
final readonly class RetentionInventory
{
    public function __construct(
        private Repository $config,
        private RestoreJournalStore $journals,
    ) {}

    /**
     * @return array<string, RetentionPolicy>
     */
    public function policies(): array
    {
        $policies = [];

        foreach (RetentionPolicy::FAMILIES as $family) {
            $policies[$family] = RetentionPolicy::fromConfig($family, $this->config->get('quraba-backup.retention.'.$family));
        }

        return $policies;
    }

    /**
     * @return list<RetentionCandidate>
     */
    public function candidates(): array
    {
        $candidates = [];

        $runs = BackupRun::query()
            ->whereIn('status', [BackupStatus::Completed->value, BackupStatus::Partial->value])
            ->with('artifacts')
            ->orderBy('id')
            ->get();

        foreach ($runs as $run) {
            $candidate = self::candidate($run);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    public static function candidate(BackupRun $run): ?RetentionCandidate
    {
        $archive = null;
        $snapshot = null;

        /** @var BackupArtifact $artifact */
        foreach ($run->artifacts as $artifact) {
            if ($artifact->status !== ArtifactStatus::Verified) {
                continue;
            }

            if ($artifact->kind === ArtifactKind::ApplicationArchive) {
                $archive = $artifact;
            } elseif ($artifact->kind === ArtifactKind::ResticSnapshot) {
                $snapshot = $artifact;
            }
        }

        if (($archive === null && $snapshot === null) || $run->requested_at === null) {
            return null;
        }

        $kind = $snapshot?->metadata['kind'] ?? null;

        return new RetentionCandidate(
            runUuid: $run->uuid,
            family: $run->profile->value,
            status: $run->status->value,
            trigger: $run->trigger->value,
            consistency: $run->consistency->value,
            requestedAt: $run->requested_at,
            pinnedUntil: $run->pinned_until,
            archiveLocator: $archive?->locator,
            snapshotId: $snapshot?->snapshot_id,
            snapshotKind: is_string($kind) ? $kind : null,
        );
    }

    /**
     * Runs that restores depend on, from EXPLICIT evidence — the restore
     * journals first, the catalog's restore rows second — never from timing
     * guesses:
     *
     *  - the frozen source and the safety backup of every unresolved live
     *    restore (running, died, or indeterminate and not yet resolved);
     *  - the safety backup of a restore that failed or was indeterminate,
     *    indefinitely, until that restore is explicitly resolved;
     *  - the safety backup of a settled restore for `retention.safety_days`
     *    after it was settled;
     *  - a pre-restore safety backup no journal or restore row accounts for:
     *    safety data is never expired on an assumption.
     *
     * @return array<string, string> run UUID => reason
     */
    public function externalProtections(): array
    {
        $protections = [];
        $now = CarbonImmutable::now('UTC');
        $days = $this->config->get('quraba-backup.retention.safety_days', 30);
        $days = is_int($days) && $days >= 1 ? $days : 30;
        $accounted = [];
        $journaled = [];

        foreach ($this->journals->all()['journals'] as $journal) {
            $journaled[$journal->restoreUuid()] = true;
            $safety = $journal->safetyRunUuid();

            if ($safety !== null) {
                $accounted[$safety] = true;
            }

            if ($journal->isUnresolved()) {
                $protections[$journal->sourceRunUuid()] = 'live_restore_source_unresolved';

                if ($safety !== null) {
                    $protections[$safety] = 'live_restore_safety_backup_unresolved';
                }

                continue;
            }

            if ($safety === null) {
                continue;
            }

            $settled = $journal->settledAt();

            if ($settled === null) {
                $protections[$safety] ??= 'restore_safety_backup_until_resolved';
            } elseif ($settled->addDays($days)->greaterThan($now)) {
                $protections[$safety] ??= 'restore_safety_backup_window';
            }
        }

        foreach (RestoreRun::query()->get() as $restore) {
            $settled = in_array($restore->status, RestoreStatus::settled(), true);

            if (! $settled && $restore->source_run_uuid !== null) {
                $protections[$restore->source_run_uuid] ??= 'unresolved_restore_source';
            }

            if ($restore->pre_change_run_uuid === null) {
                continue;
            }

            $accounted[$restore->pre_change_run_uuid] = true;

            // With a journal, the journal already decided.
            if (isset($journaled[$restore->uuid])) {
                continue;
            }

            if (! $settled) {
                $protections[$restore->pre_change_run_uuid] ??= 'restore_safety_backup_unresolved';
            } elseif (($restore->updated_at ?? $now)->addDays($days)->greaterThan($now)) {
                $protections[$restore->pre_change_run_uuid] ??= 'restore_safety_backup_window';
            }
        }

        foreach (BackupRun::query()->where('trigger', BackupTrigger::PreRestore->value)->pluck('uuid') as $uuid) {
            if (is_string($uuid) && ! isset($accounted[$uuid])) {
                $protections[$uuid] ??= 'pre_restore_safety_unlinked';
            }
        }

        return $protections;
    }

    /**
     * Whether destructive retention must not run at all: while a live
     * restore is unresolved (or a journal cannot even be read) the state of
     * the application is unknown and no backup may be removed.
     */
    public function blockedByRestore(): ?string
    {
        $all = $this->journals->all();

        if ($all['unreadable'] !== []) {
            return sprintf('%d restore journal(s) cannot be read', count($all['unreadable']));
        }

        foreach ($all['journals'] as $journal) {
            if ($journal->isUnresolved()) {
                return sprintf('live restore %s is unresolved (phase %s)', $journal->restoreUuid(), $journal->phase()->value);
            }
        }

        return null;
    }
}
