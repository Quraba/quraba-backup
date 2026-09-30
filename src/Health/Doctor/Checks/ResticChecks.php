<?php

declare(strict_types=1);

namespace Quraba\Backup\Health\Doctor\Checks;

use Illuminate\Contracts\Container\Container;
use Quraba\Backup\Health\CheckResult;
use Quraba\Backup\Health\Doctor\DoctorCheck;
use Quraba\Backup\Health\ResticHealthService;
use Quraba\Backup\Restic\Installer\Bzip2Decompressor;
use Quraba\Backup\Restic\ResticConfig;
use Throwable;

/**
 * Restic binary, version, password file and repository state (the same
 * cheap checks as backup:restic:health). The doctor never installs Restic;
 * it tells the operator what to run.
 */
final readonly class ResticChecks implements DoctorCheck
{
    public function __construct(private Container $container) {}

    public function name(): string
    {
        return 'restic';
    }

    public function run(): array
    {
        try {
            $config = $this->container->make(ResticConfig::class);
        } catch (Throwable $exception) {
            return [CheckResult::fail('restic.config', 'Restic configuration', $exception->getMessage())];
        }

        $results = [CheckResult::pass('restic.config', 'Restic configuration', sprintf('Pinned Restic version %s.', $config->version))];

        $decompressor = $this->container->make(Bzip2Decompressor::class);
        $results[] = $decompressor->isAvailable()
            ? CheckResult::pass('restic.installer_decompression', 'Installer decompression', 'Available via '.$decompressor->method().'.')
            : CheckResult::warn('restic.installer_decompression', 'Installer decompression', 'Neither ext-bz2 nor a bzip2 binary is available; backup:install-restic cannot unpack the release.');

        // Binary failures already carry the "php artisan backup:install-restic" guidance.
        return [...$results, ...$this->container->make(ResticHealthService::class)->check()->checks];
    }
}
