<?php

declare(strict_types=1);

namespace Quraba\Backup\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Enums\BackupProfile;
use WeakMap;

/**
 * Registers the package's backup schedules with Laravel's scheduler.
 *
 * Requirements on the host: one cron entry running `php artisan schedule:run`
 * (every minute, or every 5 minutes on shared hosting). No queue worker,
 * Supervisor or Redis is involved: events run the `quraba:backup:run` command
 * in the scheduler process. Overlap is prevented by the package's own flock
 * operation lock (a second run is refused while one is active), which needs
 * no cache store. Events also run while the site is in maintenance mode, so an
 * operator's downtime does not silently skip backups.
 */
final class BackupScheduler
{
    /** @var WeakMap<Schedule, list<Event>> */
    private WeakMap $registered;

    public function __construct(private readonly Repository $config)
    {
        $this->registered = new WeakMap;
    }

    /**
     * @return list<ScheduleDefinition>
     */
    public function definitions(): array
    {
        if (! (bool) $this->config->get('quraba-backup.enabled', true) || ! (bool) $this->config->get('quraba-backup.schedule.enabled', true)) {
            return [];
        }

        $definitions = [];

        foreach (BackupProfile::cases() as $profile) {
            $settings = $this->config->get('quraba-backup.schedule.'.$profile->value);
            $definition = ScheduleDefinition::fromConfig($profile, is_array($settings) ? $settings : null);

            if ($definition !== null) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * Idempotent per Schedule instance: resolving the scheduler twice never
     * registers duplicate events.
     *
     * @return list<Event>
     */
    public function register(Schedule $schedule): array
    {
        if (isset($this->registered[$schedule])) {
            return $this->registered[$schedule];
        }

        $events = [];

        foreach ($this->definitions() as $definition) {
            $event = $schedule->command('quraba:backup:run', [
                '--profile='.$definition->profile->value,
                '--scheduled',
            ]);

            match ($definition->frequency) {
                'daily' => $event->dailyAt($definition->time),
                'weekly' => $event->weeklyOn($definition->weekDay(), $definition->time),
                default => $event->monthlyOn($definition->monthDay(), $definition->time),
            };

            $timezone = $this->config->get('quraba-backup.schedule.timezone');

            if (is_string($timezone) && $timezone !== '') {
                $event->timezone($timezone);
            }

            $event->name('quraba-backup:'.$definition->profile->value)
                ->description(sprintf('Quraba Backup %s backup (%s)', $definition->profile->value, $definition->describe()))
                ->evenInMaintenanceMode();

            $events[] = $event;
        }

        return $this->registered[$schedule] = $events;
    }
}
