<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\WorkspaceViolation;
use Quraba\Backup\Support\PathGuard;

/**
 * Validated view of config/restic.php. Construction fails closed on any
 * unsafe or ambiguous value.
 */
final readonly class ResticConfig
{
    /** @var list<string> */
    public const array TIMEOUT_CLASSES = ['version', 'query', 'init', 'backup', 'restore', 'check', 'forget', 'prune'];

    /**
     * @param  array<string, int>  $timeouts
     * @param  array<string, array<string, string>>  $checksums
     */
    private function __construct(
        public bool $enabled,
        public string $version,
        public ?string $configuredBinary,
        public string $managedBinary,
        public bool $allowSystemBinary,
        public ?string $passwordFile,
        public array $timeouts,
        public string $downloadBaseUrl,
        public string $archiveTemplate,
        public string $checksumManifestTemplate,
        public array $checksums,
        public int $maxArchiveBytes,
        public int $maxBinaryBytes,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $version = $config->get('restic.version');

        if ($version !== ResticRelease::VERSION) {
            throw new ConfigurationException(sprintf(
                'restic.version must be the package-pinned Restic version [%s]; a published config must not override it (found [%s]).',
                ResticRelease::VERSION,
                is_scalar($version) ? (string) $version : gettype($version),
            ));
        }

        $formatVersion = $config->get('restic.repository.format_version');

        if ($formatVersion !== ResticRelease::REPOSITORY_VERSION) {
            throw new ConfigurationException(sprintf('restic.repository.format_version must be %d.', ResticRelease::REPOSITORY_VERSION));
        }

        $configuredBinary = $config->get('restic.binary');

        return new self(
            enabled: (bool) $config->get('restic.enabled', true),
            version: ResticRelease::VERSION,
            configuredBinary: $configuredBinary === null || $configuredBinary === '' ? null : self::absolutePath($configuredBinary, 'restic.binary'),
            managedBinary: self::absolutePath($config->get('restic.managed_binary'), 'restic.managed_binary'),
            allowSystemBinary: (bool) $config->get('restic.allow_system_binary', false),
            passwordFile: self::optionalAbsolutePath($config->get('restic.password_file'), 'restic.password_file'),
            timeouts: self::timeouts($config->get('restic.timeouts')),
            downloadBaseUrl: self::httpsUrl($config->get('restic.installer.download_base_url'), 'restic.installer.download_base_url'),
            archiveTemplate: self::template($config->get('restic.installer.archive_template'), 'restic.installer.archive_template'),
            checksumManifestTemplate: self::template($config->get('restic.installer.checksum_manifest_template'), 'restic.installer.checksum_manifest_template'),
            checksums: self::checksums($config->get('restic.installer.checksums')),
            maxArchiveBytes: self::positiveInt($config->get('restic.installer.max_archive_bytes'), 'restic.installer.max_archive_bytes'),
            maxBinaryBytes: self::positiveInt($config->get('restic.installer.max_binary_bytes'), 'restic.installer.max_binary_bytes'),
        );
    }

    public function timeout(string $class): int
    {
        return $this->timeouts[$class] ?? throw new ConfigurationException(sprintf('No Restic timeout configured for [%s].', $class));
    }

    /**
     * Pinned SHA-256 of the release archive for a platform, if the package
     * ships one.
     */
    public function pinnedChecksum(ResticPlatform $platform): ?string
    {
        // The Windows release digest is a code-owned trust anchor. Published
        // configuration cannot substitute a different Windows executable.
        if ($platform->os === 'windows') {
            return ResticRelease::CHECKSUMS[$this->version][$platform->key()] ?? null;
        }

        return $this->checksums[$this->version][$platform->key()] ?? null;
    }

    /**
     * @return array<string, int>
     */
    private static function timeouts(mixed $values): array
    {
        if (! is_array($values)) {
            throw new ConfigurationException('restic.timeouts must be an array.');
        }

        $timeouts = [];

        foreach (self::TIMEOUT_CLASSES as $class) {
            $timeouts[$class] = self::positiveInt($values[$class] ?? null, 'restic.timeouts.'.$class);
        }

        return $timeouts;
    }

    private static function positiveInt(mixed $value, string $key): int
    {
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value <= 0) {
            throw new ConfigurationException(sprintf('[%s] must be a positive integer; zero, negative or "unlimited" values are refused.', $key));
        }

        return $value;
    }

    private static function absolutePath(mixed $value, string $key): string
    {
        if (! is_string($value) || $value === '') {
            throw new ConfigurationException(sprintf('[%s] must be an absolute path.', $key));
        }

        try {
            $normalized = PathGuard::normalizeAbsolute($value);
        } catch (WorkspaceViolation $exception) {
            throw new ConfigurationException(sprintf('[%s] is invalid: %s', $key, $exception->getMessage()));
        }

        if ($normalized === '/' || preg_match('~^[A-Z]:/$~', $normalized) === 1) {
            throw new ConfigurationException(sprintf('[%s] must not be a filesystem root.', $key));
        }

        return $normalized;
    }

    private static function optionalAbsolutePath(mixed $value, string $key): ?string
    {
        return $value === null || $value === '' ? null : self::absolutePath($value, $key);
    }

    private static function httpsUrl(mixed $value, string $key): string
    {
        if (! is_string($value) || ! str_starts_with($value, 'https://')) {
            throw new ConfigurationException(sprintf('[%s] must be an https:// URL.', $key));
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException(sprintf('[%s] must be a plain https URL without credentials, query or fragment.', $key));
        }

        return rtrim($value, '/');
    }

    private static function template(mixed $value, string $key): string
    {
        if (! is_string($value) || ! str_contains($value, '{version}') || str_contains($value, '..') || str_starts_with($value, '/') || str_contains($value, '://')) {
            throw new ConfigurationException(sprintf('[%s] must be a relative release asset template containing {version}.', $key));
        }

        return $value;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function checksums(mixed $value): array
    {
        if (! is_array($value)) {
            throw new ConfigurationException('restic.installer.checksums must be an array.');
        }

        $checksums = [];

        foreach ($value as $version => $platforms) {
            if (! is_array($platforms)) {
                throw new ConfigurationException('restic.installer.checksums entries must map platforms to SHA-256 digests.');
            }

            foreach ($platforms as $platform => $digest) {
                if (! is_string($digest) || preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
                    throw new ConfigurationException(sprintf('Invalid pinned SHA-256 for Restic %s %s.', (string) $version, (string) $platform));
                }

                $checksums[(string) $version][(string) $platform] = $digest;
            }
        }

        return $checksums;
    }
}
