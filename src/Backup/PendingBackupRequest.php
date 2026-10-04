<?php

declare(strict_types=1);

namespace Quraba\Backup\Backup;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\BackupSetting;

final readonly class PendingBackupRequest
{
    public function __construct(private Repository $config) {}

    public function request(BackupProfile $profile): BackupRun
    {
        if (! $this->config->get('quraba-backup.enabled') || ! $this->config->get('quraba-backup.filament.pending_enabled')) {
            throw new \DomainException('Panel backup requests are disabled.');
        }

        $connection = (new BackupSetting)->getConnection();

        return $connection->transaction(function () use ($profile): BackupRun {
            BackupSetting::query()->insertOrIgnore(['key' => 'filament.backup_request_guard', 'value' => '{"v":true}', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            BackupSetting::query()->where('key', 'filament.backup_request_guard')->lockForUpdate()->firstOrFail();

            $pending = BackupRun::query()->where('trigger', BackupTrigger::Api->value)->where('status', BackupStatus::Pending->value);
            if ((clone $pending)->count() >= 10) {
                throw new \DomainException('The pending backup limit has been reached.');
            }
            if (BackupRun::query()->where('trigger', BackupTrigger::Api->value)
                ->whereIn('status', [BackupStatus::Pending->value, BackupStatus::Preflighting->value, BackupStatus::Running->value, BackupStatus::Verifying->value])
                ->where('profile', $profile->value)->exists()) {
                throw new \DomainException('A backup of this type is already pending or running.');
            }

            return BackupRun::request($profile, BackupTrigger::Api);
        });
    }
}
