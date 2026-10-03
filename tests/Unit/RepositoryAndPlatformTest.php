<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\EnvironmentUnsupported;
use Quraba\Backup\Exceptions\ResticInstallationFailed;
use Quraba\Backup\Restic\Installer\ChecksumManifest;
use Quraba\Backup\Restic\PlatformDetector;
use Quraba\Backup\Restic\RepositoryLocation;
use Quraba\Backup\Restic\ResticPlatform;
use Quraba\Backup\Restic\ResticRelease;
use Quraba\Backup\Restic\ResticReleaseAsset;
use Quraba\Backup\Restic\StorageCredentials;

final class RepositoryAndPlatformTest extends TestCase
{
    public function test_b2_repository_is_composed_without_credentials(): void
    {
        $location = RepositoryLocation::s3('https://s3.us-west-004.backblazeb2.com/', 'my-bucket', 'quraba-backup/6f614a0b-c447-4e36-9758-347858cbb46b/restic', null);

        self::assertSame('s3:https://s3.us-west-004.backblazeb2.com/my-bucket/quraba-backup/6f614a0b-c447-4e36-9758-347858cbb46b/restic', $location->repository);
        self::assertSame('us-west-004', $location->region);
        self::assertTrue($location->isBackblaze());
        self::assertFalse($location->isLocal);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedRepositories(): iterable
    {
        yield 'plain http' => ['s3:http://s3.us-west-004.backblazeb2.com/bucket/prefix'];
        yield 'credentials in url' => ['s3:https://key:secret@s3.us-west-004.backblazeb2.com/bucket/prefix'];
        yield 'query string' => ['s3:https://s3.us-west-004.backblazeb2.com/bucket/prefix?x=1'];
        yield 'bucket without prefix' => ['s3:https://s3.us-west-004.backblazeb2.com/bucket'];
        yield 'sftp backend' => ['sftp:user@host:/srv/restic'];
        yield 'b2 native backend' => ['b2:bucket:path'];
        yield 'rest backend' => ['rest:https://host/'];
        yield 'traversal in prefix' => ['s3:https://s3.us-west-004.backblazeb2.com/bucket/a/../b'];
        yield 'filesystem root' => ['/'];
    }

    #[DataProvider('refusedRepositories')]
    public function test_unsafe_repositories_are_refused(string $repository): void
    {
        $this->expectException(ConfigurationException::class);
        RepositoryLocation::parse($repository, null);
    }

    public function test_explicit_s3_and_local_repositories_are_parsed(): void
    {
        $s3 = RepositoryLocation::parse('s3:https://s3.eu-central-003.backblazeb2.com/bucket-1/quraba/restic', null);
        self::assertSame('eu-central-003', $s3->region);
        self::assertSame('bucket-1', $s3->bucket);

        $path = PHP_OS_FAMILY === 'Windows' ? 'C:/restic-test-repo' : '/srv/restic-test-repo';
        $local = RepositoryLocation::parse($path, null);
        self::assertTrue($local->isLocal);
        self::assertSame($path, $local->repository);
    }

    public function test_endpoint_must_be_bare_https(): void
    {
        foreach (['http://s3.x.backblazeb2.com', 'https://s3.x.backblazeb2.com/path', 'https://u:p@s3.x.backblazeb2.com', 's3.x.backblazeb2.com'] as $bad) {
            try {
                RepositoryLocation::endpoint($bad);
                self::fail($bad.' should be refused');
            } catch (ConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_credentials_are_hidden_from_dumps_and_serialization(): void
    {
        $credentials = new StorageCredentials('key-id-value', 'secret-value');

        self::assertStringNotContainsString('secret-value', print_r($credentials, true));
        self::assertSame(['AWS_ACCESS_KEY_ID' => 'key-id-value', 'AWS_SECRET_ACCESS_KEY' => 'secret-value'], $credentials->toEnvironment());

        $this->expectException(\LogicException::class);
        serialize($credentials);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function platforms(): iterable
    {
        yield 'linux x86_64' => ['Linux', 'x86_64', 'linux_amd64'];
        yield 'linux amd64' => ['Linux', 'amd64', 'linux_amd64'];
        yield 'linux aarch64' => ['Linux', 'aarch64', 'linux_arm64'];
        yield 'linux arm64' => ['Linux', 'arm64', 'linux_arm64'];
        yield 'windows amd64' => ['Windows', 'AMD64', 'windows_amd64'];
        yield 'windows x86_64' => ['Windows', 'x86_64', 'windows_amd64'];
    }

    #[DataProvider('platforms')]
    public function test_supported_platform_mapping(string $os, string $machine, string $key): void
    {
        self::assertSame($key, (new PlatformDetector($os, $machine))->detect()->key());
    }

    public function test_release_assets_have_platform_specific_container_and_executable_names(): void
    {
        $windows = ResticReleaseAsset::forPlatform(ResticPlatform::of('windows', 'amd64'), ResticRelease::ARCHIVE_TEMPLATE);
        self::assertSame('zip', $windows->format);
        self::assertSame('restic_0.19.1_windows_amd64.exe', $windows->archiveExecutable);
        self::assertSame('restic.exe', $windows->managedExecutable);

        $linux = ResticReleaseAsset::forPlatform(ResticPlatform::of('linux', 'arm64'), ResticRelease::ARCHIVE_TEMPLATE);
        self::assertSame('bz2', $linux->format);
        self::assertSame('restic', $linux->managedExecutable);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsupportedPlatforms(): iterable
    {
        yield '32-bit windows' => ['Windows', 'i686'];
        yield 'macos' => ['Darwin', 'arm64'];
        yield '32-bit linux' => ['Linux', 'i686'];
        yield 'linux armv7' => ['Linux', 'armv7l'];
    }

    #[DataProvider('unsupportedPlatforms')]
    public function test_unsupported_platforms_are_refused(string $os, string $machine): void
    {
        $this->expectException(EnvironmentUnsupported::class);
        ResticPlatform::fromHost($os, $machine);
    }

    public function test_checksum_manifest_parsing(): void
    {
        $manifest = ChecksumManifest::parse(str_repeat('a', 64)."  restic_0.19.1_linux_amd64.bz2\n".strtoupper(str_repeat('b', 64))." *restic_0.19.1_linux_arm64.bz2\n");

        self::assertSame(str_repeat('a', 64), $manifest['restic_0.19.1_linux_amd64.bz2']);
        self::assertSame(str_repeat('b', 64), $manifest['restic_0.19.1_linux_arm64.bz2']);
    }

    public function test_checksum_manifest_rejects_garbage_and_conflicts(): void
    {
        foreach (["<html>not found</html>\n", '', str_repeat('a', 64)."  x.bz2\n".str_repeat('b', 64)."  x.bz2\n"] as $bad) {
            try {
                ChecksumManifest::parse($bad);
                self::fail('Manifest should be refused.');
            } catch (ResticInstallationFailed) {
                self::addToAssertionCount(1);
            }
        }
    }
}
