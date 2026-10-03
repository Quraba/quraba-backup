<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Integration;

use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\Group;
use Quraba\Backup\Contracts\ReleaseDownloader;
use Quraba\Backup\Restic\Installer\HttpReleaseDownloader;
use Quraba\Backup\Restic\Installer\InstallOutcome;
use Quraba\Backup\Restic\Installer\ResticInstaller;
use Quraba\Backup\Restic\ResticRelease;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Support\Process\SymfonyProcessFactory;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;

/** Downloads and installs the official pinned Windows artifact on a real Windows runner. */
#[Group('windows-installer')]
final class RealWindowsInstallerTest extends TestCase
{
    use UsesFakeRestic;

    public function test_official_windows_release_installs_and_is_verified(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || getenv('QURABA_BACKUP_TEST_WINDOWS_INSTALLER') !== '1') {
            self::markTestSkipped('The real Windows installer gate requires Windows and QURABA_BACKUP_TEST_WINDOWS_INSTALLER=1.');
        }

        $target = $this->sandbox.'/private/bin/restic.exe';
        $this->config()->set('restic.managed_binary', $target);
        $this->refreshPackageServices();
        $this->app->instance(ProcessFactory::class, new SymfonyProcessFactory);
        // Only this opt-in integration test may access the official release host.
        $this->app->instance(ReleaseDownloader::class, new HttpReleaseDownloader(new Factory, 20, 180));

        $installer = $this->app->make(ResticInstaller::class);
        $plan = $installer->plan();
        self::assertSame('windows/amd64', $plan['platform']);
        self::assertSame('da948ad707ed690426473aaba2046cd61f8f90f6f0e7dab6be0d5796531de67d', $plan['pinned_sha256']);
        self::assertStringEndsWith('restic_0.19.1_windows_amd64.zip', $plan['archive_url']);

        $installed = $installer->install();
        self::assertSame(InstallOutcome::Installed, $installed->outcome);
        self::assertSame(ResticRelease::VERSION, $installed->version->version);
        self::assertSame('windows', $installed->version->goOs);
        self::assertSame('amd64', $installed->version->goArch);
        self::assertSame(InstallOutcome::AlreadyInstalled, $installer->install()->outcome);
        self::assertSame(InstallOutcome::Replaced, $installer->install(force: true)->outcome);
        self::assertSame('windows', $this->app->make(ResticRunner::class)->probeBinary($target)->goOs);

        $export = getenv('QURABA_BACKUP_TEST_WINDOWS_EXPORT_BINARY');
        if (is_string($export) && $export !== '') {
            self::assertTrue(copy($target, $export));
            self::assertSame(ResticRelease::VERSION, $this->app->make(ResticRunner::class)->probeBinary($export)->version);
        }
    }
}
