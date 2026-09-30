<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Restic\Installer\InstallOutcome;
use Quraba\Backup\Restic\Installer\ResticInstaller;
use Throwable;

final class InstallResticCommand extends PackageCommand
{
    protected $signature = 'backup:install-restic
        {--force : Replace an existing managed binary (explicit reinstall/upgrade to the pinned version)}';

    protected $description = 'Install the package-pinned Restic release (SHA-256 verified, no root required).';

    public function handle(): int
    {
        try {
            $installer = $this->laravel->make(ResticInstaller::class);
            $plan = $installer->plan();

            $this->components->twoColumnDetail('Restic version (pinned)', $plan['version']);
            $this->components->twoColumnDetail('Platform', $plan['platform']);
            $this->components->twoColumnDetail('Target', $plan['target']);
            $this->components->twoColumnDetail('Pinned SHA-256', (string) ($plan['pinned_sha256'] ?? 'none'));

            $result = $installer->install((bool) $this->option('force'));
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        match ($result->outcome) {
            InstallOutcome::AlreadyInstalled => $this->components->info(sprintf('Restic %s is already installed and verified at %s. Nothing to do.', $result->version->version, $result->path)),
            InstallOutcome::Installed => $this->components->info(sprintf('Installed and verified Restic %s at %s.', $result->version->version, $result->path)),
            InstallOutcome::Replaced => $this->components->info(sprintf('Replaced the managed binary with verified Restic %s at %s.', $result->version->version, $result->path)),
        };

        if ($result->archiveSha256 !== null) {
            $this->components->twoColumnDetail('Archive SHA-256 (verified)', $result->archiveSha256);
        }

        return self::SUCCESS;
    }
}
