<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

/** Host-owned authorization: callbacks or named Laravel Gates, deny by default. */
final class BackupPanelAccess
{
    public static function allows(string $ability): bool
    {
        $user = Filament::auth()->user();
        if ($user === null) {
            return false;
        }

        $callback = config('quraba-backup.filament.authorization.'.$ability);
        if (is_callable($callback)) {
            return $callback($user) === true;
        }

        return Gate::forUser($user)->allows('quraba-backup.'.$ability);
    }

    public static function authorize(string $ability): void
    {
        abort_unless(self::allows($ability), 403);
    }
}
