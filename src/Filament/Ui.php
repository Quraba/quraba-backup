<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament;

use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Quraba\Backup\Scheduling\ScheduleDefinition;
use Throwable;

final class Ui
{
    public static function dateTime(?CarbonInterface $time): string
    {
        if ($time === null) {
            return '—';
        }

        $timezone = config('app.timezone', 'UTC');

        return $time->copy()->setTimezone(is_string($timezone) ? $timezone : 'UTC')->locale(app()->getLocale())->translatedFormat('j M Y H:i');
    }

    public static function dateTimeValue(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return self::dateTime($value);
        }

        if (! is_string($value) || $value === '') {
            return '—';
        }

        try {
            return self::dateTime(CarbonImmutable::parse($value));
        } catch (Throwable) {
            return '—';
        }
    }

    public static function maintenanceMode(bool $readOnly): string
    {
        return self::text($readOnly ? 'statuses.read_only' : 'statuses.executable');
    }

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
