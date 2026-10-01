<?php

declare(strict_types=1);

namespace Quraba\Backup\Notifications;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;
use Psr\Log\LoggerInterface;
use Quraba\Backup\Health\HealthReport;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PrivateFile;
use Throwable;

/** Event dispatch and optional delivery never affect backup correctness. */
final readonly class NoticeDispatcher
{
    public function __construct(
        private Repository $config,
        private Dispatcher $events,
        private LoggerInterface $logger,
        private PackagePaths $paths,
    ) {}

    public function emit(OperationalNotice $notice): void
    {
        try {
            $this->events->dispatch($notice);
        } catch (Throwable $exception) {
            $this->logDeliveryFailure($exception);
        }

        if (! (bool) $this->config->get('quraba-backup.notifications.enabled', false)) {
            return;
        }

        try {
            $callback = $this->config->get('quraba-backup.notifications.callback');
            if (is_callable($callback)) {
                $callback($notice);
            }
            $recipient = $this->config->get('quraba-backup.notifications.notifiable');
            $channels = $this->config->get('quraba-backup.notifications.channels', []);
            if (is_callable($recipient) && is_array($channels) && $channels !== []) {
                $via = array_values(array_filter($channels, static fn (mixed $channel): bool => is_string($channel) && in_array($channel, ['mail', 'database'], true)));
                if ($via !== []) {
                    Notification::send($recipient($notice), new OperationalNotification($notice, $via));
                }
            }
        } catch (Throwable $exception) {
            $this->logDeliveryFailure($exception);
        }
    }

    public function healthTransition(HealthReport $report): void
    {
        if (! (bool) $this->config->get('quraba-backup.notifications.enabled', false)) {
            return;
        }

        try {
            $path = PackagePaths::ensureDirectory($this->paths->root).'/notification-health.state';
            $handle = is_file($path) ? @fopen($path, 'c+b') : PrivateFile::create($path);
            if ($handle === false) {
                throw new \RuntimeException('health state cannot be opened');
            }
            try {
                PrivateFile::assertStillPrivate($path, $handle);
                if (! flock($handle, LOCK_EX)) {
                    throw new \RuntimeException('health state cannot be locked');
                }
                rewind($handle);
                $previous = trim((string) fread($handle, 32));
                $current = $report->state()->value;
                if ($previous === $current) {
                    return;
                }
                rewind($handle);
                if (! ftruncate($handle, 0) || fwrite($handle, $current."\n") !== strlen($current) + 1 || ! fflush($handle) || ! fsync($handle)) {
                    throw new \RuntimeException('health state cannot be persisted');
                }
            } finally {
                @flock($handle, LOCK_UN);
                fclose($handle);
            }
            if ($current !== 'healthy' || (bool) $this->config->get('quraba-backup.notifications.notify_recovery', false)) {
                $this->emit(new OperationalNotice('health.'.$current, 'application', ['previous' => $previous === '' ? null : $previous]));
            }
        } catch (Throwable $exception) {
            $this->logDeliveryFailure($exception);
        }
    }

    private function logDeliveryFailure(Throwable $exception): void
    {
        $this->logger->warning('Quraba Backup operational notification delivery failed.', ['exception_type' => $exception::class]);
    }
}
