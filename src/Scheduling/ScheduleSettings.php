<?php

declare(strict_types=1);

namespace Quraba\Backup\Scheduling;

use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Schema;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Models\BackupSetting;

/** Allowlisted runtime overrides; deployment config remains the fallback. */
final readonly class ScheduleSettings
{
    public function __construct(private Repository $config) {}

    /** @return array{value: mixed, source: string} */
    public function effective(string $key): array
    {
        if (! in_array($key, self::keys(), true)) {
            throw new ConfigurationException('The schedule setting is not editable.');
        }
        $default = $this->config->get('quraba-backup.schedule.'.$key);
        $sentinel = new \stdClass;
        $override = $this->hasTable() ? BackupSetting::read('schedule.'.$key, $sentinel) : $sentinel;
        $value = $override === $sentinel ? $default : $override;
        $this->validate($key, $value);

        return ['value' => $value, 'source' => $override === $sentinel ? 'config' : 'database'];
    }

    /** @return array<string, array{value: mixed, source: string}> */
    public function all(): array
    {
        $values = [];
        foreach (self::keys() as $key) {
            $values[$key] = $this->effective($key);
        }

        return $values;
    }

    public function set(string $key, mixed $value): void
    {
        $this->validate($key, $value);
        BackupSetting::put('schedule.'.$key, $value);
    }

    /** @param array<string, mixed> $values */
    public function saveAll(array $values): void
    {
        if (array_keys($values) !== self::keys()) {
            throw new ConfigurationException('Only the complete allowlisted schedule may be saved.');
        }
        foreach ($values as $key => $value) {
            $this->validate($key, $value);
        }
        (new BackupSetting)->getConnection()->transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                BackupSetting::put('schedule.'.$key, $value);
            }
        });
    }

    public function reset(string $key): void
    {
        if (! in_array($key, self::keys(), true)) {
            throw new ConfigurationException('The schedule setting is not editable.');
        }
        BackupSetting::query()->where('key', 'schedule.'.$key)->first()?->forceDelete();
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return ['enabled', 'timezone', 'database', 'media', 'recovery'];
    }

    private function validate(string $key, mixed $value): void
    {
        if (! in_array($key, self::keys(), true)) {
            throw new ConfigurationException('The schedule setting is not editable.');
        }
        if ($key === 'enabled') {
            if (! is_bool($value)) {
                throw new ConfigurationException('Schedule enabled must be a boolean.');
            }

            return;
        }
        if ($key === 'timezone') {
            if ($value !== null && (! is_string($value) || ! in_array($value, DateTimeZone::listIdentifiers(), true))) {
                throw new ConfigurationException('Schedule timezone must be a valid IANA timezone.');
            }

            return;
        }
        if (! is_array($value) || array_diff(array_keys($value), ['enabled', 'frequency', 'day', 'time']) !== [] || ! is_bool($value['enabled'] ?? null)) {
            throw new ConfigurationException('Invalid schedule profile settings.');
        }
        // Validate even disabled profiles so invalid values cannot lie dormant.
        ScheduleDefinition::fromConfig($key, [...$value, 'enabled' => true]);
    }

    private function hasTable(): bool
    {
        $connection = (new BackupSetting)->getConnectionName();

        return Schema::connection($connection)->hasTable('quraba_backup_settings');
    }
}
