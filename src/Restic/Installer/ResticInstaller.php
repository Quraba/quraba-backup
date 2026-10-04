<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic\Installer;

use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;
use Quraba\Backup\Contracts\LockManager;
use Quraba\Backup\Contracts\ReleaseDownloader;
use Quraba\Backup\Coordination\LockName;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\QurabaBackupException;
use Quraba\Backup\Exceptions\ResticInstallationFailed;
use Quraba\Backup\Exceptions\ResticVersionMismatch;
use Quraba\Backup\Restic\PlatformDetector;
use Quraba\Backup\Restic\ResticConfig;
use Quraba\Backup\Restic\ResticPlatform;
use Quraba\Backup\Restic\ResticReleaseAsset;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Restic\ResticVersionInfo;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * Installs the package-pinned Restic release without root, package managers
 * or a shell — suitable for cPanel shared hosting.
 *
 * Sequence (all inside a private staging directory next to the target, so
 * the final rename is atomic on the same filesystem):
 *
 *   1. detect OS/CPU and require a package-pinned SHA-256 for it;
 *   2. download the official SHA256SUMS; its entry must equal the pinned digest;
 *   3. download the platform's .bz2 or ZIP release; its SHA-256 must equal
 *      the pinned digest BEFORE anything is extracted or executed;
 *   4. extract the one expected executable with a size limit (chmod 0700 on Linux);
 *   5. execute `restic version` (via the ResticRunner) and require the exact
 *      pinned version and platform;
 *   6. atomically rename over the managed binary and verify it again.
 *
 * An interrupted install leaves only a staging directory that is never
 * executed and is removed by the next install. Ordinary backup runtime never
 * calls the installer, so Restic is never upgraded implicitly.
 */
final readonly class ResticInstaller
{
    private const int MANIFEST_MAX_BYTES = 1048576;

    private const string STAGING_PREFIX = '.restic-install-';

    public function __construct(
        private ResticConfig $config,
        private ResticRunner $runner,
        private PlatformDetector $platforms,
        private ReleaseDownloader $downloader,
        private Bzip2Decompressor $decompressor,
        private VerifiedZipExtractor $zipExtractor,
        private LockManager $locks,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{version: string, platform: string, archive_url: string, manifest_url: string, target: string, pinned_sha256: string|null}
     */
    public function plan(): array
    {
        $platform = $this->platforms->detect();
        $this->rejectLegacyWindowsManagedBinary($platform);
        $asset = ResticReleaseAsset::forPlatform($platform, $this->config->archiveTemplate);

        return [
            'version' => $this->config->version,
            'platform' => $platform->label(),
            'archive_url' => $this->url($asset->archiveTemplate, $platform),
            'manifest_url' => $this->url($this->config->checksumManifestTemplate, $platform),
            'target' => $this->config->managedBinary,
            'pinned_sha256' => $this->config->pinnedChecksum($platform),
        ];
    }

    public function install(bool $force = false): InstallResult
    {
        $platform = $this->platforms->detect();
        $this->rejectLegacyWindowsManagedBinary($platform);
        $expected = $this->config->pinnedChecksum($platform);

        if ($expected === null) {
            throw new ResticInstallationFailed(sprintf('This package release has no pinned SHA-256 for Restic %s on %s; refusing to install an unverifiable binary.', $this->config->version, $platform->label()));
        }

        $target = $this->config->managedBinary;
        $asset = ResticReleaseAsset::forPlatform($platform, $this->config->archiveTemplate);
        $lock = $this->locks->acquire(LockName::ResticInstaller, 'restic installation');

        try {
            $existed = file_exists($target) || is_link($target);

            if ($existed && ! $force) {
                return $this->existingInstallation($target, $platform);
            }

            if (is_link($target)) {
                throw new ResticInstallationFailed('The managed binary path is a link; refusing to replace it.');
            }

            if (is_dir($target)) {
                throw new ResticInstallationFailed(sprintf('The managed binary path [%s] is a directory.', $target));
            }

            $directory = $this->binaryDirectory($target);
            $this->removeStaleStaging($directory);

            $staging = $directory.'/'.self::STAGING_PREFIX.(string) new Ulid;

            if (! @mkdir($staging, 0700)) {
                throw new ResticInstallationFailed(sprintf('Could not create the staging directory in [%s].', $directory));
            }

            try {
                $result = $this->installInto($staging, $target, $platform, $asset, $expected, $existed);
            } finally {
                $this->deleteStaging($staging);
            }

            $this->logger->info('Quraba Backup installed the managed Restic binary.', [
                'version' => $result->version->version,
                'platform' => $platform->label(),
                'path' => $target,
                'archive_sha256' => $result->archiveSha256,
            ]);

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function rejectLegacyWindowsManagedBinary(ResticPlatform $platform): void
    {
        if ($platform->os === 'windows' && ResticConfig::hasExtensionlessResticName($this->config->managedBinary)) {
            throw new ResticInstallationFailed(ResticConfig::WINDOWS_LEGACY_MANAGED_BINARY_MESSAGE);
        }
    }

    private function existingInstallation(string $target, ResticPlatform $platform): InstallResult
    {
        if (is_link($target)) {
            throw new ResticInstallationFailed(sprintf('The managed binary path [%s] is a symbolic link. Re-run with --force to replace it with a verified binary.', $target));
        }

        try {
            $version = $this->runner->probeBinary($target);
        } catch (QurabaBackupException $exception) {
            throw new ResticInstallationFailed(sprintf('A binary already exists at [%s] but could not be verified (%s). Re-run with --force to replace it.', $target, $exception->getMessage()));
        }

        if (! $version->matches($this->config->version)) {
            throw new ResticVersionMismatch(sprintf('The managed binary reports Restic %s; this package is pinned to %s. Re-run "php artisan quraba:backup:install-restic --force" to replace it explicitly.', $version->version, $this->config->version));
        }

        return new InstallResult(InstallOutcome::AlreadyInstalled, $target, $platform, $version);
    }

    private function installInto(string $staging, string $target, ResticPlatform $platform, ResticReleaseAsset $asset, string $expected, bool $existed): InstallResult
    {
        $archiveName = basename($this->url($asset->archiveTemplate, $platform));

        // 1. Official checksum manifest must agree with the pinned digest.
        $manifestPath = $staging.'/SHA256SUMS';
        $this->downloader->download($this->url($this->config->checksumManifestTemplate, $platform), $manifestPath, self::MANIFEST_MAX_BYTES);
        $manifest = ChecksumManifest::parse((string) file_get_contents($manifestPath));

        if (! isset($manifest[$archiveName])) {
            throw new ResticInstallationFailed(sprintf('The official checksum manifest has no entry for %s.', $archiveName));
        }

        if (! hash_equals($expected, $manifest[$archiveName])) {
            throw ResticInstallationFailed::checksumMismatch($archiveName, 'the official SHA256SUMS entry does not match the digest pinned by this package');
        }

        // 2. Archive digest is verified before any decompression or execution.
        $archivePath = $staging.'/'.$archiveName;
        $this->downloader->download($this->url($asset->archiveTemplate, $platform), $archivePath, $this->config->maxArchiveBytes);

        $actual = hash_file('sha256', $archivePath);

        if ($actual === false || ! hash_equals($expected, $actual)) {
            throw ResticInstallationFailed::checksumMismatch($archiveName, 'the downloaded archive does not match the pinned SHA-256; nothing was extracted or executed');
        }

        // 3. Decompress with a size limit and make it executable by the owner only.
        $stagedBinary = $staging.'/'.$asset->managedExecutable;
        if ($asset->format === 'zip') {
            $this->zipExtractor->extract($archivePath, $stagedBinary, $asset->archiveExecutable, $this->config->maxBinaryBytes);
        } else {
            $this->decompressor->decompress($archivePath, $stagedBinary, $this->config->maxBinaryBytes);
        }

        if ($platform->os !== 'windows' && ! @chmod($stagedBinary, 0700)) {
            throw new ResticInstallationFailed('Could not mark the staged Restic binary executable.');
        }

        // 4. Execute the staged binary and require the exact pinned version.
        $version = $this->runner->probeBinary($stagedBinary);
        $this->assertExpectedBinary($version, $platform);

        $binarySha256 = (string) hash_file('sha256', $stagedBinary);

        // 5. Atomic replacement (same directory, same filesystem).
        if (! @rename($stagedBinary, $target)) {
            throw new ResticInstallationFailed(sprintf('Could not atomically move the verified binary to [%s].', $target));
        }

        $final = $this->runner->probeBinary($target);
        $this->assertExpectedBinary($final, $platform);

        $this->writeInstallRecord($target, $platform, $expected, $binarySha256);

        return new InstallResult(
            $existed ? InstallOutcome::Replaced : InstallOutcome::Installed,
            $target,
            $platform,
            $final,
            $expected,
            $binarySha256,
        );
    }

    private function assertExpectedBinary(ResticVersionInfo $version, ResticPlatform $platform): void
    {
        if (! $version->matches($this->config->version)) {
            throw new ResticVersionMismatch(sprintf('The downloaded binary reports Restic %s instead of the pinned %s; refusing to install it.', $version->version, $this->config->version));
        }

        if ($version->goOs !== $platform->os || $version->goArch !== $platform->arch) {
            throw new ResticInstallationFailed(sprintf('The downloaded binary is built for %s/%s, not %s.', $version->goOs, $version->goArch, $platform->label()));
        }
    }

    private function url(string $template, ResticPlatform $platform): string
    {
        return $this->config->downloadBaseUrl.'/'.strtr($template, [
            '{version}' => $this->config->version,
            '{os}' => $platform->os,
            '{arch}' => $platform->arch,
        ]);
    }

    private function binaryDirectory(string $target): string
    {
        try {
            $directory = PackagePaths::ensureDirectory(dirname($target));
            $real = PathGuard::real($directory);
            if ($real === null || (PathGuard::isWindows() && PathGuard::comparable($real) !== PathGuard::comparable(PathGuard::normalizeAbsolute($directory)))) {
                throw new ResticInstallationFailed('The managed binary directory traverses a link or reparse point.');
            }

            return $directory;
        } catch (ConfigurationException $exception) {
            throw new ResticInstallationFailed($exception->getMessage());
        }
    }

    /**
     * Staging directories found while holding the installer lock belong to
     * an interrupted install; they were never executed and are removed.
     */
    private function removeStaleStaging(string $directory): void
    {
        foreach (@scandir($directory) ?: [] as $entry) {
            if (preg_match('/^\.restic-install-[0-9A-HJKMNP-TV-Z]{26}$/', $entry) === 1 && is_dir($directory.'/'.$entry) && ! is_link($directory.'/'.$entry)) {
                $this->deleteStaging($directory.'/'.$entry);
            }
        }
    }

    private function deleteStaging(string $staging): void
    {
        if (is_link($staging) || ! is_dir($staging)) {
            return;
        }

        $realRoot = PathGuard::real($staging);
        $realParent = PathGuard::real(dirname($staging));
        if ($realRoot === null || $realParent === null || PathGuard::comparable($realRoot) !== PathGuard::comparable($realParent.'/'.basename($staging))) {
            return;
        }

        foreach (@scandir($staging) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $staging.'/'.$entry;

            if (is_dir($path) && ! is_link($path)) {
                $real = PathGuard::real($path);
                if ($real === null || ! PathGuard::isWithin($real, $realRoot)) {
                    continue;
                }
                $this->deleteStaging($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($staging);
    }

    private function writeInstallRecord(string $target, ResticPlatform $platform, string $archiveSha256, string $binarySha256): void
    {
        $record = json_encode([
            'version' => $this->config->version,
            'platform' => $platform->label(),
            'archive_sha256' => $archiveSha256,
            'binary_sha256' => $binarySha256,
            'installed_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        ], JSON_PRETTY_PRINT);

        $path = $target.'.install.json';
        $temporary = $path.'.'.(string) new Ulid.'.tmp';

        try {
            if (@file_put_contents($temporary, (string) $record) !== false) {
                @chmod($temporary, 0600);
                @rename($temporary, $path);
            }
        } catch (Throwable) {
            // Diagnostic record only; the verified binary is what matters.
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
