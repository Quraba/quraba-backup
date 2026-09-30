<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Restic;

use Quraba\Backup\Contracts\ReleaseDownloader;
use Quraba\Backup\Exceptions\EnvironmentUnsupported;
use Quraba\Backup\Exceptions\ResticInstallationFailed;
use Quraba\Backup\Exceptions\ResticVersionMismatch;
use Quraba\Backup\Exceptions\StorageUnavailable;
use Quraba\Backup\Restic\Installer\InstallOutcome;
use Quraba\Backup\Restic\Installer\ResticInstaller;
use Quraba\Backup\Restic\PlatformDetector;
use Quraba\Backup\Restic\ResticRelease;
use Quraba\Backup\Tests\Support\FakeReleaseDownloader;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;

final class ResticInstallerTest extends TestCase
{
    use UsesFakeRestic;

    private const string BASE = ResticRelease::DOWNLOAD_BASE_URL.'/v'.ResticRelease::VERSION;

    private FakeReleaseDownloader $downloader;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('bzcompress')) {
            self::markTestSkipped('ext-bz2 is needed to build the test release archive.');
        }
    }

    private function target(): string
    {
        return $this->sandbox.'/private/bin/restic';
    }

    private function binaryReporting(string $version): string
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/fake-restic.php');

        return str_replace("\$scenario['version'] ?? '0.19.1'", "\$scenario['version'] ?? '{$version}'", $script);
    }

    /**
     * @param  array<string, mixed>  $assetOverrides
     */
    private function installer(string $binaryVersion = ResticRelease::VERSION, ?string $pinned = null, array $assetOverrides = [], string $machine = 'x86_64', string $os = 'Linux'): ResticInstaller
    {
        $archive = (string) bzcompress($this->binaryReporting($binaryVersion), 9);
        $digest = hash('sha256', $archive);
        $name = 'restic_'.ResticRelease::VERSION.'_linux_amd64.bz2';

        $this->config()->set('restic.installer.checksums', [ResticRelease::VERSION => ['linux_amd64' => $pinned ?? $digest]]);

        $this->downloader = new FakeReleaseDownloader([
            self::BASE.'/SHA256SUMS' => $digest.'  '.$name."\n".str_repeat('0', 64).'  restic_'.ResticRelease::VERSION."_linux_arm64.bz2\n",
            self::BASE.'/'.$name => $archive,
            ...$assetOverrides,
        ]);

        $this->refreshPackageServices();
        $this->app->instance(ReleaseDownloader::class, $this->downloader);
        $this->app->instance(PlatformDetector::class, new PlatformDetector($os, $machine));

        return $this->app->make(ResticInstaller::class);
    }

    private function assertNoStagingLeft(): void
    {
        foreach (scandir(dirname($this->target())) ?: [] as $entry) {
            self::assertStringStartsNotWith('.restic-install-', $entry, 'Staging directories must be removed.');
        }
    }

    public function test_installs_verifies_and_is_idempotent(): void
    {
        $result = $this->installer()->install();

        self::assertSame(InstallOutcome::Installed, $result->outcome);
        self::assertSame('0.19.1', $result->version->version);
        self::assertFileExists($this->target());
        self::assertFileExists($this->target().'.install.json');
        self::assertSame('linux/amd64', json_decode((string) file_get_contents($this->target().'.install.json'), true)['platform']);
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0o700, fileperms($this->target()) & 0o777);
        }
        $this->assertNoStagingLeft();

        $downloads = count($this->downloader->requested);
        $again = $this->installer()->install();

        self::assertSame(InstallOutcome::AlreadyInstalled, $again->outcome);
        self::assertCount(0, $this->downloader->requested, 'An installed, verified binary needs no download.');
        self::assertSame(2, $downloads);
    }

    public function test_archive_checksum_mismatch_is_refused_before_extraction(): void
    {
        $installer = $this->installer(assetOverrides: [
            self::BASE.'/restic_'.ResticRelease::VERSION.'_linux_amd64.bz2' => (string) bzcompress("#!/usr/bin/env php\n<?php echo 'tampered';"),
        ]);

        try {
            $installer->install();
            self::fail('A tampered archive must be refused.');
        } catch (ResticInstallationFailed $exception) {
            self::assertSame('restic.checksum_mismatch', $exception->failureCode());
        }

        self::assertFileDoesNotExist($this->target());
        $this->assertNoStagingLeft();
    }

    public function test_official_manifest_must_agree_with_the_pinned_digest(): void
    {
        $installer = $this->installer(pinned: str_repeat('e', 64));

        try {
            $installer->install();
            self::fail('A manifest that disagrees with the pinned digest must be refused.');
        } catch (ResticInstallationFailed $exception) {
            self::assertSame('restic.checksum_mismatch', $exception->failureCode());
        }

        self::assertSame([self::BASE.'/SHA256SUMS'], $this->downloader->requested, 'The archive is not even downloaded.');
        self::assertFileDoesNotExist($this->target());
    }

    public function test_wrong_version_binary_is_never_installed(): void
    {
        try {
            $this->installer(binaryVersion: '0.18.0')->install();
            self::fail('A binary reporting another version must be refused.');
        } catch (ResticVersionMismatch) {
            self::addToAssertionCount(1);
        }

        self::assertFileDoesNotExist($this->target());
        $this->assertNoStagingLeft();
    }

    public function test_existing_wrong_version_requires_explicit_force_and_is_replaced_atomically(): void
    {
        mkdir(dirname($this->target()), 0700, true);
        file_put_contents($this->target(), $this->binaryReporting('0.18.0'));
        chmod($this->target(), 0700);

        try {
            $this->installer()->install();
            self::fail('Implicit upgrades are not allowed.');
        } catch (ResticVersionMismatch $exception) {
            self::assertStringContainsString('--force', $exception->getMessage());
        }

        $result = $this->installer()->install(force: true);

        self::assertSame(InstallOutcome::Replaced, $result->outcome);
        self::assertSame('0.19.1', $result->version->version);
    }

    public function test_interrupted_installs_leave_no_executable_and_are_cleaned_up(): void
    {
        $stale = dirname($this->target()).'/.restic-install-01J00000000000000000000000';
        mkdir($stale, 0700, true);
        file_put_contents($stale.'/restic', 'half-written');

        $installer = $this->installer(assetOverrides: [
            self::BASE.'/restic_'.ResticRelease::VERSION.'_linux_amd64.bz2' => static function (string $destination): void {
                file_put_contents($destination, 'partial');
                throw new StorageUnavailable('connection reset during download');
            },
        ]);

        try {
            $installer->install();
            self::fail('The interrupted download must fail.');
        } catch (StorageUnavailable) {
            self::addToAssertionCount(1);
        }

        self::assertFileDoesNotExist($this->target(), 'No half-written executable may appear at the target.');
        self::assertDirectoryDoesNotExist($stale);
        $this->assertNoStagingLeft();

        self::assertSame(InstallOutcome::Installed, $this->installer()->install()->outcome);
    }

    public function test_platforms_without_a_pinned_checksum_are_refused(): void
    {
        $installer = $this->installer(machine: 'aarch64');

        try {
            $installer->install();
            self::fail('No pinned digest: refuse.');
        } catch (ResticInstallationFailed $exception) {
            self::assertStringContainsString('linux/arm64', $exception->getMessage());
        }

        self::assertSame([], $this->downloader->requested);
    }

    public function test_unsupported_operating_systems_are_refused(): void
    {
        $this->expectException(EnvironmentUnsupported::class);
        $this->installer(os: 'Windows', machine: 'AMD64')->install();
    }

    public function test_install_command(): void
    {
        $this->installer();

        $this->artisan('backup:install-restic')
            ->expectsOutputToContain('Installed and verified Restic 0.19.1')
            ->assertSuccessful();

        $this->installer();

        $this->artisan('backup:install-restic')
            ->expectsOutputToContain('already installed')
            ->assertSuccessful();
    }
}
