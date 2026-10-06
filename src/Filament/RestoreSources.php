<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament;

use Illuminate\Database\Eloquent\Builder;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Models\BackupRun;

/** Local picker candidates only; RestoreSourceResolver remains authoritative. */
final class RestoreSources
{
    /** @return Builder<BackupRun> */
    public function eligible(RestoreProfile $scope): Builder
    {
        $profiles = match ($scope) {
            RestoreProfile::Database => [BackupProfile::Database->value, BackupProfile::Recovery->value],
            RestoreProfile::Media => [BackupProfile::Media->value, BackupProfile::Recovery->value],
            RestoreProfile::Full => [BackupProfile::Recovery->value],
        };

        $query = BackupRun::query()->whereIn('profile', $profiles)
            ->where('status', BackupStatus::Completed->value);

        if ($scope !== RestoreProfile::Media) {
            $query->whereHas('artifacts', fn (Builder $artifacts): Builder => $artifacts
                ->where('kind', ArtifactKind::ApplicationArchive->value)
                ->where('status', ArtifactStatus::Verified->value));
        }

        if ($scope !== RestoreProfile::Database) {
            $query->whereHas('artifacts', fn (Builder $artifacts): Builder => $artifacts
                ->where('kind', ArtifactKind::ResticSnapshot->value)
                ->where('status', ArtifactStatus::Verified->value));
        }

        return $query;
    }

    public function contains(string $uuid, RestoreProfile $scope): bool
    {
        return $this->eligible($scope)->where('uuid', $uuid)->exists();
    }

    public function label(BackupRun $run): string
    {
        return Ui::dateTime($run->requested_at).' · '.Ui::value($run->profile, 'backup_types').' · '.Ui::value($run->status);
    }
}
