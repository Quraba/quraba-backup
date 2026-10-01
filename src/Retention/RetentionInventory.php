<?php

declare(strict_types=1);

namespace Quraba\Backup\Retention;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\RestoreStatus;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\RestoreRun;

/**
 * Reads retention inputs from the local catalog.
 *
 * Only terminal runs (completed, partial) with at least one VERIFIED,
 * non-expired archive or snapshot become candidates; runs in any other state
 * (including indeterminate) are invisible to retention.
 */
final readonly class RetentionInventory
{
    public function __construct(private Repository $config) {}

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
     * Runs that unresolved restores depend on: their sources and their
     * pre-change safety backups.
     *
     * @return array<string, string> run UUID => reason
     */
    public function externalProtections(): array
    {
        $protections = [];

        $restores = RestoreRun::query()
            ->whereNotIn('status', [RestoreStatus::Completed->value, RestoreStatus::Failed->value])
            ->get();

        foreach ($restores as $restore) {
            if ($restore->source_run_uuid !== null) {
                $protections[$restore->source_run_uuid] = 'unresolved_restore_source';
            }
        }

        // Pre-change safety backups of ANY restore stay protected (reserved;
        // their expiry arrives with live restore).
        foreach (RestoreRun::query()->whereNotNull('pre_change_run_uuid')->pluck('pre_change_run_uuid') as $uuid) {
            if (is_string($uuid)) {
                $protections[$uuid] = 'restore_safety_backup';
            }
        }

        return $protections;
    }
}
