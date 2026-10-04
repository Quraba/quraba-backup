<?php

declare(strict_types=1);

namespace Quraba\Backup\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface PendingOperationActorResolver
{
    public function resolve(string $type, string $id): ?Authenticatable;
}
