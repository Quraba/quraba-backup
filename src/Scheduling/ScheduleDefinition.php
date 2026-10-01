<?php

declare(strict_types=1);

namespace Quraba\Backup\Scheduling;

use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Exceptions\ConfigurationException;

/**
 * One validated schedule (a backup profile or a maintenance task), expressed
 * with Laravel's own frequency methods (no custom cron language).
 *
 * Times must fall on a 5-minute boundary: shared hosts such as cPanel /
 * Namecheap commonly run `schedule:run` only every 5 minutes, and an event is
 * only due in the minute it matches.
 */
final readonly class ScheduleDefinition
{
    public const array FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /** Maintenance tasks that can be scheduled besides the backup profiles. */
    public const array MAINTENANCE_TASKS = ['retention', 'restic_check'];

    private function __construct(
        public string $task,
        public ?BackupProfile $profile,
        public string $frequency,
        public string $time,
        public ?int $day,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $settings
     */
    public static function fromConfig(BackupProfile|string $task, ?array $settings): ?self
    {
        $profile = $task instanceof BackupProfile ? $task : BackupProfile::tryFrom($task);
        $task = $task instanceof BackupProfile ? $task->value : $task;

        if ($profile === null && ! in_array($task, self::MAINTENANCE_TASKS, true)) {
            throw new ConfigurationException(sprintf('Unknown scheduled task [%s].', $task));
        }

        if ($settings === null || ! (bool) ($settings['enabled'] ?? false)) {
            return null;
        }

        $key = 'quraba-backup.schedule.'.$task;
        $frequency = $settings['frequency'] ?? 'daily';
        $time = $settings['time'] ?? '02:00';
        $day = $settings['day'] ?? null;

        if (! is_string($frequency) || ! in_array($frequency, self::FREQUENCIES, true)) {
            throw new ConfigurationException(sprintf('[%s.frequency] must be one of: %s.', $key, implode(', ', self::FREQUENCIES)));
        }

        if (! is_string($time) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches) !== 1) {
            throw new ConfigurationException(sprintf('[%s.time] must be HH:MM (24h).', $key));
        }

        if (((int) $matches[2]) % 5 !== 0) {
            throw new ConfigurationException(sprintf('[%s.time] must be on a 5-minute boundary (e.g. 02:05) so hosts that run the scheduler every 5 minutes never miss it.', $key));
        }

        if (is_string($day) && preg_match('/^\d+$/', $day) === 1) {
            $day = (int) $day;
        }

        $day = match ($frequency) {
            'daily' => null,
            'weekly' => is_int($day) && $day >= 0 && $day <= 6 ? $day : throw new ConfigurationException(sprintf('[%s.day] must be 0 (Sunday) to 6 for weekly schedules.', $key)),
            'monthly' => is_int($day) && $day >= 1 && $day <= 28 ? $day : throw new ConfigurationException(sprintf('[%s.day] must be 1 to 28 for monthly schedules.', $key)),
        };

        return new self($task, $profile, $frequency, $time, $day);
    }

    /**
     * @return int<0, 6>
     */
    public function weekDay(): int
    {
        $day = (int) $this->day;

        return $day < 0 ? 0 : ($day > 6 ? 6 : $day);
    }

    /**
     * @return int<1, 28>
     */
    public function monthDay(): int
    {
        $day = (int) $this->day;

        return $day < 1 ? 1 : ($day > 28 ? 28 : $day);
    }

    public function describe(): string
    {
        return match ($this->frequency) {
            'daily' => sprintf('daily at %s', $this->time),
            'weekly' => sprintf('weekly on day %d at %s', (int) $this->day, $this->time),
            default => sprintf('monthly on day %d at %s', (int) $this->day, $this->time),
        };
    }
}
