<?php

declare(strict_types=1);

namespace Quraba\Backup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Quraba\Backup\Models\Casts\UtcDateTime;

/**
 * Common base for catalog models: UTC timestamps and an optional dedicated
 * catalog connection.
 */
abstract class PackageModel extends Model
{
    /**
     * Every package model writes UTC instants, independent of app.timezone.
     */
    public function freshTimestamp(): Carbon
    {
        return Carbon::now('UTC');
    }

    public function getConnectionName(): ?string
    {
        $configured = config('quraba-backup.database.catalog_connection');

        return is_string($configured) && $configured !== '' ? $configured : parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
        ];
    }
}
