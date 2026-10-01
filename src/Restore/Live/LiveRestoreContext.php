<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Live;

use Quraba\Backup\Consistency\QuiescenceSession;
use Quraba\Backup\Coordination\HeldLocks;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Restore\Journal\RestoreJournal;
use Quraba\Backup\Workspace\OperationWorkspace;

/**
 * The working state of one live restore while it runs.
 *
 * @internal used only by {@see LiveRestoreService}
 */
final class LiveRestoreContext
{
    public ?string $restoreUuid = null;

    /** The last journal version PROVEN on disk. */
    public ?RestoreJournal $journal = null;

    /** Mirror row in the catalog; null on a clean host without catalog tables. */
    public ?RestoreRun $audit = null;

    public ?OperationWorkspace $workspace = null;

    public ?QuiescenceSession $session = null;

    /** @var list<MediaStagingArea> */
    public array $areas = [];

    /** @var array<string, mixed> */
    public array $report;

    public function __construct(
        public readonly string $runUuid,
        public readonly RestoreProfile $profile,
        public readonly bool $cleanHost,
        public readonly HeldLocks $locks,
    ) {
        $this->report = [
            'ok' => false,
            'mode' => 'live',
            'status' => 'failed',
            'restore_uuid' => null,
            'run_uuid' => $runUuid,
            'requested_profile' => $profile->value,
            'clean_host' => $cleanHost,
            'source' => null,
            'consistency' => null,
            'archive_verified' => null,
            'archive_sha256' => null,
            'app_key_compatibility' => null,
            'release_compatibility' => null,
            'db_validation_level' => null,
            'repository_id' => null,
            'snapshot_id' => null,
            'live_database' => null,
            'media_roots' => [],
            'safety_backup' => null,
            'quiescence' => null,
            'destructive_boundary_crossed' => false,
            'journal' => null,
            'warnings' => [],
            'blockers' => [],
            'follow_up' => [],
            'notice' => 'Nothing was changed in the live application.',
        ];
    }

    public function warn(string $message): void
    {
        $warnings = is_array($this->report['warnings']) ? $this->report['warnings'] : [];
        $warnings[] = $message;
        $this->report['warnings'] = array_values($warnings);
    }
}
