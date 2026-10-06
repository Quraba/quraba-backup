<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament;

use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\PendingOperationStatus;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\PendingOperation;

final class OperatorStatus
{
    /** @param array<string, mixed> $journal */
    public static function restoreStage(array $journal): string
    {
        $terminal = $journal['resolution'] ?? $journal['terminal'] ?? null;
        if ($terminal === 'completed') {
            return Ui::text('operator.restore_completed');
        }
        if (($journal['unresolved'] ?? false) === true || $terminal === 'indeterminate') {
            return Ui::text('operator.restore_indeterminate');
        }
        if ($terminal === 'failed' && empty($journal['destructive_started_at'])) {
            return Ui::text('operator.restore_failed_safe');
        }

        return Ui::text(match ($journal['phase'] ?? null) {
            'created', 'validated', 'quiesced' => 'progress.preparing_restore',
            'safety_backup_starting' => 'progress.safety',
            'safety_backup_verified' => 'progress.verifying',
            'db_clear_starting', 'db_cleared', 'db_import_starting', 'db_import_completed', 'db_verified', 'audit_restored' => 'progress.database',
            'media_applying', 'media_applied' => 'progress.media',
            'verifying', 'verified' => 'progress.final',
            default => 'progress.restoring',
        });
    }

    public static function finding(string $finding): string
    {
        $key = match ($finding) {
            'The backup APP_KEY fingerprint differs from the current application.' => 'errors.app_key',
            'The private workspace has insufficient free disk space for reconstruction.' => 'findings.disk_space',
            'Free workspace disk space could not be measured.' => 'findings.disk_unknown',
            'The archive size is unknown, so staging capacity cannot be proven.', 'Media staging size could not be proven from the exact Restic snapshot.' => 'findings.size_unknown',
            'The configured media destinations are not valid.' => 'findings.media_destinations',
            'A harmless rename probe beside a media destination failed; atomic replacement is required.' => 'findings.media_replacement',
            'Atomic media replacement could not be proven for a future live restore.' => 'findings.media_replacement_warning',
            'The source archive does not prove scheduled events were included. Exact replacement may omit them.' => 'findings.events',
            'The source archive does not prove complete database object protection required by restore.require_complete_database.' => 'findings.database_objects',
            'The backup release fingerprint differs from this installation; review compatibility before relying on the restored application.' => 'findings.release',
            'Private workspace cleanup failed; inspect abandoned workspaces.' => 'findings.cleanup',
            default => 'errors.generic',
        };

        return Ui::text($key);
    }

    /** @param array<string, mixed> $check */
    public static function healthIssue(array $check): string
    {
        $id = $check['id'] ?? null;
        $key = match ($id) {
            'health.catalog' => 'health_issues.catalog',
            'health.database_backup', 'health.media_snapshot', 'health.recovery_point', 'health.quiesced_recovery_point' => 'health_issues.backup_age',
            'health.database_objects' => 'health_issues.database_objects',
            'health.partial_runs', 'health.unresolved_runs', 'health.repeated_failures' => 'health_issues.backup_failures',
            'health.archive_sample', 'health.snapshot_sample' => 'health_issues.missing_source',
            'health.repository' => 'health_issues.storage',
            'health.maintenance', 'health.restores', 'health.live_restores' => 'health_issues.unresolved',
            'health.workspaces', 'health.restore_workspaces' => 'health_issues.workspaces',
            'health.retention', 'health.restic_check' => 'health_issues.maintenance',
            default => 'health_issues.generic',
        };

        return Ui::text($key);
    }

    public static function backupStage(BackupRun $run): string
    {
        return match ($run->status) {
            BackupStatus::Pending => Ui::text('progress.waiting'),
            BackupStatus::Preflighting => Ui::text('progress.preparing'),
            BackupStatus::Running => Ui::text('progress.creating'),
            BackupStatus::Verifying => Ui::text('progress.verifying'),
            default => Ui::value($run->status),
        };
    }

    public static function operationStage(PendingOperation $operation): string
    {
        return match ($operation->status) {
            PendingOperationStatus::Pending, PendingOperationStatus::Claimed => Ui::text('progress.waiting'),
            PendingOperationStatus::Running => $operation->type->value === 'dry_restore'
                ? Ui::text('progress.checking') : Ui::text('progress.restoring'),
            default => Ui::value($operation->status),
        };
    }

    public static function failure(?string $code, ?string $message = null): string
    {
        $key = match ($code) {
            'restic.repository_identity_mismatch', 'restic.repository_identity_conflict' => 'errors.repository_identity',
            'archive.verification_failed', 'restore.reconstruction_failed' => 'errors.archive_verification',
            'restore.safety_backup_failed' => 'errors.safety_backup',
            'restore.quiescence_unproven' => 'errors.changes_not_paused',
            'restore.source_unavailable', 'restore.source_expired' => 'errors.source_unavailable',
            'restore.unresolved_restore' => 'errors.unresolved_restore',
            default => null,
        };

        if ($key !== null) {
            return Ui::text($key);
        }

        if (is_string($message) && str_contains($message, 'APP_KEY')) {
            return Ui::text('errors.app_key');
        }

        return Ui::text('errors.generic');
    }
}
