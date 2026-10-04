<?php

declare(strict_types=1);

namespace Quraba\Backup\Operations;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Quraba\Backup\Contracts\PendingOperationActorResolver;

final class EloquentActorResolver implements PendingOperationActorResolver
{
    public function resolve(string $type, string $id): ?Authenticatable
    {
        if (! class_exists($type) || ! is_subclass_of($type, Model::class) || ! is_subclass_of($type, Authenticatable::class)) {
            return null;
        }

        $actor = $type::query()->find($id);

        return $actor instanceof Authenticatable ? $actor : null;
    }
}
