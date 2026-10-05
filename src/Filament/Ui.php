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
        return self::describeSchedule($schedule->frequency, $schedule->day, $schedule->time);
    }

    /** @param array<string, mixed> $setting */
    public static function scheduleSetting(array $setting): string
    {
        if (! ($setting['enabled'] ?? false)) {
            return self::value('disabled');
        }

        $frequency = $setting['frequency'] ?? 'daily';
        $day = $setting['day'] ?? null;
        $time = $setting['time'] ?? '';

        return self::describeSchedule(
            is_string($frequency) ? $frequency : 'daily',
            is_int($day) ? $day : (is_string($day) && ctype_digit($day) ? (int) $day : null),
            is_string($time) ? $time : '',
        );
    }

    public static function checkLabel(string $id, string $fallback): string
    {
        $key = 'quraba-backup::filament.checks.'.$id;
        $translated = __($key);

        return $translated === $key ? $fallback : $translated;
    }

    private static function describeSchedule(string $frequency, ?int $day, string $time): string
    {
        return self::text('schedule_descriptions.'.$frequency, [
            'day' => $frequency === 'weekly' && $day !== null ? self::text('weekdays.'.$day) : $day,
            'time' => $time,
        ]);
    }
}
