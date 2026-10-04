<?php

declare(strict_types=1);

namespace Quraba\Backup\Operations;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

final class OperationAccess
{
    public static function allows(Authenticatable $actor, string $ability): bool
    {
        $callback = config('quraba-backup.filament.authorization.'.$ability);
        if (is_callable($callback)) {
            return $callback($actor) === true;
        }

        return Gate::forUser($actor)->allows('quraba-backup.'.$ability);
    }
}
