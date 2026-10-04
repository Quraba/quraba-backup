<?php

declare(strict_types=1);

namespace Quraba\Backup\Operations;

use Illuminate\Support\Facades\Schema;
use Quraba\Backup\Models\PendingOperation;
use Throwable;

/** Read-only availability checks for catalog tables used by the optional panel. */
final class PanelTableAvailability
{
    private const array TABLES = [
        'operations' => 'quraba_pending_operations',
        'runs' => 'quraba_backup_runs',
        'artifacts' => 'quraba_backup_artifacts',
        'restores' => 'quraba_restore_runs',
        'maintenance' => 'quraba_backup_maintenance_runs',
        'settings' => 'quraba_backup_settings',
    ];

    public function has(string $key): bool
    {
        $table = self::TABLES[$key] ?? null;
        if ($table === null) {
            throw new \InvalidArgumentException('Unknown panel table.');
        }

        try {
            return Schema::connection((new PendingOperation)->getConnectionName())->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
