<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Illuminate\Config\Repository;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Restic\ResticConfig;
use Quraba\Backup\Restic\ResticPlatform;
use Quraba\Backup\Restic\ResticRelease;
use Quraba\Backup\Tests\TestCase;

final class ResticConfigTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function resticConfig(array $overrides = []): Repository
    {
        $repository = new Repository($this->config()->all());

        foreach ($overrides as $key => $value) {
            $repository->set($key, $value);
        }

        return $repository;
    }

    public function test_package_defaults_are_valid(): void
    {
        $config = ResticConfig::fromConfig($this->resticConfig(['restic.managed_binary' => '/srv/app/storage/app/private/quraba-backup/bin/restic']));

        self::assertSame(ResticRelease::VERSION, $config->version);
        self::assertSame(ResticRelease::CHECKSUMS[ResticRelease::VERSION]['linux_amd64'], $config->pinnedChecksum(ResticPlatform::of('linux', 'amd64')));
        self::assertFalse($config->allowSystemBinary);
    }

    public function test_the_pinned_version_cannot_be_overridden(): void
    {
        $this->expectException(ConfigurationException::class);
        ResticConfig::fromConfig($this->resticConfig(['restic.version' => '0.18.0', 'restic.managed_binary' => '/srv/restic']));
    }

    public function test_zero_and_negative_timeouts_are_refused(): void
    {
        foreach ([0, -1, 'unlimited', null, '0'] as $value) {
            try {
                ResticConfig::fromConfig($this->resticConfig(['restic.timeouts.backup' => $value, 'restic.managed_binary' => '/srv/restic']));
                self::fail('Timeout '.var_export($value, true).' should be refused.');
            } catch (ConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_download_base_must_be_plain_https(): void
    {
        foreach (['http://mirror.example.com', 'https://user:pw@mirror.example.com', 'ftp://x'] as $url) {
            try {
                ResticConfig::fromConfig($this->resticConfig(['restic.installer.download_base_url' => $url, 'restic.managed_binary' => '/srv/restic']));
                self::fail($url.' should be refused.');
            } catch (ConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_managed_binary_must_be_a_clean_absolute_path(): void
    {
        foreach (['relative/restic', '/srv/../etc/restic', '/'] as $path) {
            try {
                ResticConfig::fromConfig($this->resticConfig(['restic.managed_binary' => $path]));
                self::fail($path.' should be refused.');
            } catch (ConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_pinned_checksums_are_well_formed(): void
    {
        foreach (ResticRelease::CHECKSUMS as $platforms) {
            foreach ($platforms as $digest) {
                self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
            }
        }

        self::assertArrayHasKey(ResticRelease::VERSION, ResticRelease::CHECKSUMS);
        self::assertArrayHasKey('linux_amd64', ResticRelease::CHECKSUMS[ResticRelease::VERSION]);
    }
}
