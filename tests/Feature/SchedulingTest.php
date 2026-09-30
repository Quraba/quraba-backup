<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Scheduling\BackupScheduler;
use Quraba\Backup\Tests\TestCase;

final class SchedulingTest extends TestCase
{
    /**
     * @return list<Event>
     */
    private function packageEvents(): array
    {
        $this->app->forgetInstance(Schedule::class);
        $this->app->forgetInstance(BackupScheduler::class);

        return array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn (Event $event): bool => str_contains((string) $event->command, 'quraba:backup:run'),
        ));
    }

    public function test_default_schedules_are_registered_with_cpanel_safe_times(): void
    {
        $events = $this->packageEvents();

        self::assertCount(3, $events);

        $byProfile = [];
        foreach ($events as $event) {
            preg_match('/--profile=(\w+)/', (string) $event->command, $matches);
            $byProfile[$matches[1]] = $event;
        }

        self::assertSame('0 2 * * *', $byProfile['database']->expression);
        self::assertSame('30 2 * * *', $byProfile['media']->expression);
        self::assertSame('30 3 * * 0', $byProfile['recovery']->expression);

        foreach ($events as $event) {
            self::assertStringContainsString('--scheduled', (string) $event->command);
            self::assertTrue($event->evenInMaintenanceMode, 'Backups still run while the site is down.');
            self::assertSame(0, ((int) explode(' ', $event->expression)[0]) % 5, 'Due minutes must suit a 5-minute cron.');
        }
    }

    public function test_profiles_can_be_disabled_individually_or_globally(): void
    {
        $this->config()->set('quraba-backup.schedule.media.enabled', false);
        self::assertCount(2, $this->packageEvents());

        $this->config()->set('quraba-backup.schedule.enabled', false);
        self::assertSame([], $this->packageEvents());

        $this->config()->set('quraba-backup.schedule.enabled', true);
        $this->config()->set('quraba-backup.enabled', false);
        self::assertSame([], $this->packageEvents(), 'A disabled package schedules nothing.');
    }

    public function test_registration_is_never_duplicated(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $scheduler = $this->app->make(BackupScheduler::class);

        $scheduler->register($schedule);
        $scheduler->register($schedule);

        $count = count(array_filter($schedule->events(), static fn (Event $event): bool => str_contains((string) $event->command, 'quraba:backup:run')));

        self::assertSame(3, $count);
    }

    public function test_invalid_schedule_configuration_is_refused(): void
    {
        $this->config()->set('quraba-backup.schedule.database.time', '02:07');

        $this->expectException(ConfigurationException::class);
        $this->app->make(BackupScheduler::class)->definitions();
    }

    public function test_monthly_and_timezone_settings(): void
    {
        $this->config()->set('quraba-backup.schedule.recovery', ['enabled' => true, 'frequency' => 'monthly', 'day' => 15, 'time' => '04:45']);
        $this->config()->set('quraba-backup.schedule.timezone', 'Asia/Riyadh');

        $recovery = array_values(array_filter($this->packageEvents(), static fn (Event $e): bool => str_contains((string) $e->command, '--profile=recovery')))[0];

        self::assertSame('45 4 15 * *', $recovery->expression);
        self::assertSame('Asia/Riyadh', $recovery->timezone);
    }
}
