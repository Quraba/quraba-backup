<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Quraba\Backup\Health\CheckResult;
use Quraba\Backup\Health\HealthReport;
use Quraba\Backup\Notifications\NoticeDispatcher;
use Quraba\Backup\Notifications\OperationalNotice;
use Quraba\Backup\Tests\TestCase;

final class NotificationsTest extends TestCase
{
    public function test_package_event_and_optional_callback_failures_never_escape(): void
    {
        Event::fake([OperationalNotice::class]);
        $this->config()->set('quraba-backup.notifications.enabled', true);
        $this->config()->set('quraba-backup.notifications.callback', static function (): void {
            throw new \RuntimeException('transport failed');
        });

        $this->app->make(NoticeDispatcher::class)->emit(new OperationalNotice('backup.failed', '0198c0de-0000-7000-8000-000000000001'));
        Event::assertDispatched(OperationalNotice::class);
    }

    public function test_health_alerts_are_persisted_only_on_transition(): void
    {
        $seen = [];
        $this->config()->set('quraba-backup.notifications.enabled', true);
        $this->config()->set('quraba-backup.notifications.callback', static function (OperationalNotice $notice) use (&$seen): void {
            $seen[] = $notice->condition;
        });
        $dispatcher = $this->app->make(NoticeDispatcher::class);
        $healthy = new HealthReport([CheckResult::pass('probe', 'Probe', 'okay')], CarbonImmutable::now('UTC'));
        $failed = new HealthReport([CheckResult::fail('probe', 'Probe', 'failed')], CarbonImmutable::now('UTC'));
        $dispatcher->healthTransition($healthy);
        $dispatcher->healthTransition($failed);
        $dispatcher->healthTransition($failed);
        $dispatcher->healthTransition($healthy);
        $dispatcher->healthTransition($failed);

        self::assertSame(['health.failed', 'health.failed'], $seen);
    }
}
