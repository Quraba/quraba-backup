<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Quraba\Backup\Operations\OperationAccess;

/** Host-owned authorization: callbacks or named Laravel Gates, deny by default. */
final class BackupPanelAccess
{
    public static function allows(string $ability): bool
    {
        $user = Filament::auth()->user();
        if ($user === null) {
            return false;
        }

        return self::allowsFor($user, $ability);
    }

    public static function allowsFor(Authenticatable $user, string $ability): bool
    {
        return OperationAccess::allows($user, $ability);
    }

    public static function authorize(string $ability): void
    {
        abort_unless(self::allows($ability), 403);
    }
}
