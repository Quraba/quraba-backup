<?php

declare(strict_types=1);

namespace Quraba\Backup\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;
use WeakMap;

/**
 * Registers the package's schedules with Laravel's scheduler.
 *
 * Requirements on the host: one cron entry running `php artisan schedule:run`
 * (every minute, or every 5 minutes on shared hosting). No queue worker,
 * Supervisor or Redis is involved.
 *
 * Long-running work: Laravel runs foreground events one after another, so a
 * multi-hour backup would delay every other task of the host. On POSIX hosts
 * the package's events therefore use Laravel's own runInBackground(): the
 * scheduler starts the command through a shell with `&` and returns at once;
 * Laravel's hidden `schedule:finish` reports the exit code back to the
 * event's failure callbacks. No cache mutex is involved (withoutOverlapping is
 * not used): the package's flock operation lock remains the overlap
 * authority, and a refused overlapping run exits non-zero and is logged.
 * Windows (development only) keeps foreground execution.
 *
 * Failures are never silent: every run is recorded in the catalog (health
 * reports failed, partial, indeterminate and missing backups), refusals that
 * happen before a run exists are logged by the command, the last output of
 * each task is kept in private storage (logs/scheduler-{task}.log) and a
 * non-zero exit is logged by the failure callback.
 *
 * Maintenance mode: by default scheduled events follow Laravel's rule and do
 * NOT run while the application is down — a deployment window may hold a
 * half-migrated database or half-copied files. `even_in_maintenance_mode` opts in.
 */
final class BackupScheduler
{
    /** @var WeakMap<Schedule, list<Event>> */
    private WeakMap $registered;

    public function __construct(
        private readonly Repository $config,
        private readonly PackagePaths $paths,
        private readonly LoggerInterface $logger,
    ) {
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

        foreach ([...array_map(static fn (BackupProfile $p): string => $p->value, BackupProfile::cases()), ...ScheduleDefinition::MAINTENANCE_TASKS] as $task) {
            $settings = $this->config->get('quraba-backup.schedule.'.$task);
            $definition = ScheduleDefinition::fromConfig($task, is_array($settings) ? $settings : null);

            if ($definition !== null) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * Whether this host can run scheduled tasks in the background through
     * Laravel's runInBackground() (a POSIX shell and proc_open are needed).
     */
    public static function platformSupportsBackground(): bool
    {
        return ! PathGuard::isWindows() && function_exists('proc_open') && is_executable('/bin/sh');
    }

    /**
     * The effective mode: `auto` (default) runs in the background wherever
     * supported; `true` requires it; `false` forces foreground execution.
     */
    public function runsInBackground(): bool
    {
        $mode = $this->config->get('quraba-backup.schedule.background', 'auto');
        $mode = is_string($mode) ? strtolower(trim($mode)) : $mode;

        return match (true) {
            $mode === 'auto', $mode === null, $mode === '' => self::platformSupportsBackground(),
            $mode === true, $mode === 'true', $mode === '1' => self::platformSupportsBackground()
                ? true
                : throw new ConfigurationException('quraba-backup.schedule.background is true, but this platform cannot run scheduled tasks in the background (a POSIX shell and proc_open are required).'),
            $mode === false, $mode === 'false', $mode === '0' => false,
            default => throw new ConfigurationException('quraba-backup.schedule.background must be auto, true or false.'),
        };
    }

    public function runsInMaintenanceMode(): bool
    {
        return (bool) $this->config->get('quraba-backup.schedule.even_in_maintenance_mode', false);
    }

    public function outputPath(string $task): string
    {
        return $this->paths->root.'/logs/scheduler-'.$task.'.log';
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

        $background = $this->runsInBackground();
        $events = [];

        foreach ($this->definitions() as $definition) {
            $event = $schedule->command(...$this->command($definition));

            match ($definition->frequency) {
                'daily' => $event->dailyAt($definition->time),
                'weekly' => $event->weeklyOn($definition->weekDay(), $definition->time),
                default => $event->monthlyOn($definition->monthDay(), $definition->time),
            };

            $timezone = $this->config->get('quraba-backup.schedule.timezone');

            if (is_string($timezone) && $timezone !== '') {
                $event->timezone($timezone);
            }

            $task = $definition->task;
            $output = $this->outputPath($task);

            $event->name('quraba-backup:'.$task)
                ->description(sprintf('Quraba Backup %s (%s)', $task, $definition->describe()))
                ->sendOutputTo($output)
                ->before(static function () use ($output): void {
                    PackagePaths::ensureDirectory(dirname($output));
                })
                ->onFailure(function () use ($task, $event, $output): void {
                    $this->logger->error('A scheduled Quraba Backup task exited unsuccessfully.', [
                        'task' => $task,
                        'exit_code' => $event->exitCode,
                        'output' => $output,
                    ]);
                });

            if ($background) {
                $event->runInBackground();
            }

            if ($this->runsInMaintenanceMode()) {
                $event->evenInMaintenanceMode();
            }

            $events[] = $event;
        }

        return $this->registered[$schedule] = $events;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function command(ScheduleDefinition $definition): array
    {
        return match ($definition->task) {
            'retention' => ['quraba:backup:retention', ['--scheduled']],
            'restic_check' => ['quraba:backup:restic:check', ['--scheduled']],
            default => ['quraba:backup:run', ['--profile='.$definition->task, '--scheduled']],
        };
    }
}
