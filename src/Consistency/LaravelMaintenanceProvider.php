<?php

declare(strict_types=1);

namespace Quraba\Backup\Consistency;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Exceptions\QuiescenceFailed;
use Throwable;

/**
 * Puts the application into Laravel maintenance mode for the capture.
 *
 * Maintenance mode only blocks HTTP traffic. Queue workers, the scheduler,
 * external consumers and CLI scripts may keep writing, so this provider
 * claims `quiesced` ONLY when the deployment explicitly declares that no
 * such uncontrolled writers exist (quraba-backup.consistency.
 * no_background_writers). Otherwise the capture is `best_effort`.
 *
 * If the application was already down, it is left down afterwards; the
 * provider only brings up what it brought down itself.
 */
final readonly class LaravelMaintenanceProvider implements QuiescenceProvider
{
    public function __construct(
        private Application $app,
        private Kernel $artisan,
        private bool $noBackgroundWriters,
        private int $retryAfterSeconds = 60,
    ) {}

    public function name(): string
    {
        return 'laravel_maintenance';
    }

    public function claimsQuiescence(): bool
    {
        return $this->noBackgroundWriters;
    }

    public function enter(): QuiescenceSession
    {
        $wasDown = $this->isDown();

        if (! $wasDown) {
            try {
                $exit = $this->artisan->call('down', ['--retry' => (string) $this->retryAfterSeconds]);
            } catch (Throwable $exception) {
                throw new QuiescenceFailed('Entering maintenance mode failed: '.$exception->getMessage());
            }

            if ($exit !== 0 || ! $this->isDown()) {
                throw new QuiescenceFailed('The application could not be put into maintenance mode.');
            }
        }

        $level = $this->noBackgroundWriters ? ConsistencyLevel::Quiesced : ConsistencyLevel::BestEffort;
        $explanation = $this->noBackgroundWriters
            ? 'HTTP traffic was blocked by maintenance mode and the deployment declares no background writers.'
            : 'HTTP traffic was blocked by maintenance mode, but queue workers, the scheduler or other CLI writers may still write (not declared absent), so the capture is best_effort.';

        return new QuiescenceSession(
            $this->name(),
            $level,
            $explanation,
            function () use ($wasDown): void {
                if ($wasDown) {
                    return;
                }

                $exit = $this->artisan->call('up');

                if ($exit !== 0 || $this->isDown()) {
                    throw new QuiescenceFailed('The application could not be brought back up from maintenance mode.');
                }
            },
            fn (): bool => $this->isDown(),
            ! $wasDown,
        );
    }

    private function isDown(): bool
    {
        return $this->app->maintenanceMode()->active();
    }
}
