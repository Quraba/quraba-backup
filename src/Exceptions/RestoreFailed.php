<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

use Throwable;

/**
 * A restore could not resolve, reconstruct, validate or apply its source.
 *
 * A failed dry run never implies application damage. For a live restore the
 * failure code alone does not say whether the application was changed: that
 * is decided by the restore journal (see the destructive boundary).
 */
final class RestoreFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'restore.failed';

    public static function sourceUnavailable(string $detail = ''): self
    {
        return new self('The restore source cannot be used'.self::suffix($detail), 'restore.source_unavailable');
    }

    public static function sourceConflict(string $detail = ''): self
    {
        return new self('The local catalog and the remote manifest disagree about the restore source'.self::suffix($detail), 'restore.source_conflict');
    }

    public static function sourceExpired(string $detail = ''): self
    {
        return new self('The restore source was expired by retention'.self::suffix($detail), 'restore.source_expired');
    }

    public static function sourceChanged(string $detail = ''): self
    {
        return new self('The frozen restore source changed after it was validated'.self::suffix($detail), 'restore.source_changed');
    }

    public static function reconstructionFailed(string $detail = ''): self
    {
        return new self('Reconstructing the backup in the private restore workspace failed'.self::suffix($detail), 'restore.reconstruction_failed');
    }

    public static function mappingFailed(string $detail = ''): self
    {
        return new self('The restored media cannot be mapped safely to its destinations'.self::suffix($detail), 'restore.media_mapping_failed');
    }

    public static function scratchUnsafe(string $detail = ''): self
    {
        return new self('The scratch validation database is not safe to use'.self::suffix($detail), 'restore.scratch_unsafe');
    }

    public static function scratchImportFailed(string $detail = ''): self
    {
        return new self('Importing the dump into the scratch validation database failed'.self::suffix($detail), 'restore.scratch_import_failed');
    }

    /**
     * The scratch database could not be emptied again. This is reported in
     * its own right — never hidden behind the import's own outcome — because
     * the scratch database is now contaminated.
     */
    public static function scratchCleanupFailed(string $detail, ?Throwable $previous = null): self
    {
        return new self('The scratch validation database could not be cleaned and is contaminated'.self::suffix($detail), 'restore.scratch_cleanup_failed', $previous);
    }

    public static function confirmationRequired(string $detail = ''): self
    {
        return new self('A live restore replaces application data and was not confirmed'.self::suffix($detail), 'restore.confirmation_required');
    }

    public static function unresolvedRestore(string $detail = ''): self
    {
        return new self('An earlier live restore is unresolved'.self::suffix($detail), 'restore.unresolved_restore');
    }

    public static function quiescenceUnproven(string $detail = ''): self
    {
        return new self('A live restore requires proof that every application writer is stopped'.self::suffix($detail), 'restore.quiescence_unproven');
    }

    public static function safetyBackupFailed(string $detail = ''): self
    {
        return new self('The pre-change safety backup could not be verified, so nothing was changed'.self::suffix($detail), 'restore.safety_backup_failed');
    }

    public static function journalFailed(string $detail = '', ?Throwable $previous = null): self
    {
        return new self('The restore journal could not be used'.self::suffix($detail), 'restore.journal_failed', $previous);
    }

    public static function catalogUnavailable(string $detail = ''): self
    {
        return new self('The local backup catalog is unavailable'.self::suffix($detail), 'restore.catalog_unavailable');
    }

    public static function cleanHostRefused(string $detail = ''): self
    {
        return new self('The target is not a proven empty clean host'.self::suffix($detail), 'restore.clean_host_refused');
    }

    public static function databaseTargetUnsafe(string $detail = ''): self
    {
        return new self('The live database target could not be proven'.self::suffix($detail), 'restore.database_target_unsafe');
    }

    public static function databaseApplyFailed(string $detail = '', ?Throwable $previous = null): self
    {
        return new self('Replacing the live database failed'.self::suffix($detail), 'restore.database_apply_failed', $previous);
    }

    public static function databaseVerificationFailed(string $detail = ''): self
    {
        return new self('The restored database could not be verified'.self::suffix($detail), 'restore.database_verification_failed');
    }

    public static function stagingUnavailable(string $detail = ''): self
    {
        return new self('A private media staging area on the same filesystem could not be established'.self::suffix($detail), 'restore.media_staging_unavailable');
    }

    public static function mediaApplyFailed(string $detail = ''): self
    {
        return new self('Replacing a live media root failed'.self::suffix($detail), 'restore.media_apply_failed');
    }

    public static function mediaVerificationFailed(string $detail = ''): self
    {
        return new self('A restored media root could not be verified'.self::suffix($detail), 'restore.media_verification_failed');
    }

    public static function envBootstrapFailed(string $detail = ''): self
    {
        return new self('The archived .env could not be recovered'.self::suffix($detail), 'recovery.env_bootstrap_failed');
    }

    private static function suffix(string $detail): string
    {
        return $detail === '' ? '.' : ': '.$detail;
    }
}
