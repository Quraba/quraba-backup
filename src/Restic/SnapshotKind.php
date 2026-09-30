<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

enum SnapshotKind: string
{
    /** Media-only backup profile. */
    case Media = 'media';

    /** Media component of a Recovery Point. */
    case RecoveryMedia = 'recovery_media';

    /** Reserved: pre-restore safety snapshots (restore phase). */
    case SafetyMedia = 'safety_media';
}
