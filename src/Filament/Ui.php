<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament;

use BackedEnum;
use Quraba\Backup\Scheduling\ScheduleDefinition;

final class Ui
{
    /** @param array<string, bool|float|int|string|null> $replace */
    public static function text(string $key, array $replace = []): string
    {
        return __('quraba-backup::filament.'.$key, $replace);
    }

    public static function value(mixed $value, string $group = 'statuses'): string
    {
        $raw = $value instanceof BackedEnum ? $value->value : $value;
        if (! is_string($raw) || $raw === '') {
            return self::text('statuses.unknown');
        }

        $key = 'quraba-backup::filament.'.$group.'.'.$raw;
        $translated = __($key);

        return $translated === $key ? str_replace('_', ' ', $raw) : $translated;
    }

    public static function schedule(ScheduleDefinition $schedule): string
    {
        return self::text('schedule_descriptions.'.$schedule->frequency, [
            'day' => $schedule->day,
            'time' => $schedule->time,
        ]);
    }

    public static function checkLabel(string $id, string $fallback): string
    {
        $key = 'quraba-backup::filament.checks.'.$id;
        $translated = __($key);

        return $translated === $key ? $fallback : $translated;
    }
}
