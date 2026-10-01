<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Journal;

/**
 * The phases of a live restore, in the only order they may be recorded.
 *
 * Everything up to and including {@see self::Applying} is non-destructive.
 * The destructive boundary is crossed by the first `*_starting` record of a
 * component (database clear, or a media root swap), which is written to the
 * journal BEFORE the mutation it announces.
 *
 * Full restore order (decided, not accidental): the database is replaced
 * first, then each media root. See docs/restore.md for the reasoning.
 */
enum JournalPhase: string
{
    case Created = 'created';
    case Validated = 'validated';
    case Quiesced = 'quiesced';
    case SafetyBackupStarting = 'safety_backup_starting';
    case SafetyBackupVerified = 'safety_backup_verified';
    case Applying = 'applying';
    case DatabaseClearStarting = 'db_clear_starting';
    case DatabaseCleared = 'db_cleared';
    case DatabaseImportStarting = 'db_import_starting';
    case DatabaseImportCompleted = 'db_import_completed';
    case DatabaseVerified = 'db_verified';
    case AuditRestored = 'audit_restored';
    case MediaApplying = 'media_applying';
    case MediaApplied = 'media_applied';
    case Verifying = 'verifying';
    case Verified = 'verified';

    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /**
     * Phases in which a restore that stopped has provably changed nothing.
     */
    public function isBeforeApplying(): bool
    {
        return $this->rank() <= self::Applying->rank();
    }
}
