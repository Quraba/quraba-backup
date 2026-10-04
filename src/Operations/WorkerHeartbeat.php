<?php

declare(strict_types=1);

namespace Quraba\Backup\Operations;

use Carbon\CarbonImmutable;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;
use Throwable;

final readonly class WorkerHeartbeat
{
    public function __construct(private PackagePaths $paths) {}

    public function beat(): void
    {
        $directory = PackagePaths::ensureDirectory($this->paths->root.'/runtime');
        PathGuard::assertRealWithin($directory, PackagePaths::ensureDirectory($this->paths->root));
        $path = $directory.'/worker-heartbeat.json';
        if (is_link($path)) {
            throw new \RuntimeException('Worker heartbeat path is a symbolic link.');
        }
        RestoreJournalStore::atomicWrite($path, json_encode(['observed_at' => now('UTC')->toIso8601String()], JSON_THROW_ON_ERROR));
    }

    public function observedAt(): ?string
    {
        $path = $this->paths->root.'/runtime/worker-heartbeat.json';
        if (is_link($path) || ! is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && is_string($data['observed_at'] ?? null) ? $data['observed_at'] : null;
    }

    public function recentlyObserved(): bool
    {
        $observed = $this->observedAt();

        if ($observed === null) {
            return false;
        }
        try {
            return CarbonImmutable::parse($observed)->greaterThan(CarbonImmutable::now('UTC')->subMinutes(10));
        } catch (Throwable) {
            return false;
        }
    }
}
