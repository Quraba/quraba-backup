<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\WorkspaceViolation;
use Quraba\Backup\Support\PathGuard;

/**
 * A credential-free Restic repository location.
 *
 * Only two forms are accepted:
 *  - `s3:https://{endpoint-host}/{bucket}/{path}` (Backblaze B2 S3 API);
 *  - an absolute local path (tests and local development only).
 *
 * Every other backend (sftp, rest, rclone, b2 native, plain-http S3) is
 * refused, and a location containing credentials is refused outright.
 */
final readonly class RepositoryLocation
{
    private const string SEGMENT = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

    private function __construct(
        public string $repository,
        public bool $isLocal,
        public ?string $endpoint,
        public ?string $bucket,
        public ?string $path,
        public ?string $region,
    ) {}

    public static function s3(string $endpoint, string $bucket, string $path, ?string $region): self
    {
        $endpoint = self::endpoint($endpoint);
        $bucket = self::bucket($bucket);
        $path = self::objectPath($path);
        $region = $region === null || $region === '' ? self::regionFromEndpoint($endpoint) : self::region($region);

        return new self(sprintf('s3:%s/%s/%s', $endpoint, $bucket, $path), false, $endpoint, $bucket, $path, $region);
    }

    public static function local(string $path): self
    {
        try {
            $normalized = PathGuard::normalizeAbsolute($path);
        } catch (WorkspaceViolation $exception) {
            throw new ConfigurationException('Local Restic repository path is invalid: '.$exception->getMessage());
        }

        if ($normalized === '/' || preg_match('~^[A-Z]:/$~', $normalized) === 1) {
            throw new ConfigurationException('A filesystem root cannot be a Restic repository.');
        }

        return new self($normalized, true, null, null, null, null);
    }

    /**
     * Parses an explicit repository override.
     */
    public static function parse(string $repository, ?string $region): self
    {
        $repository = trim($repository);

        if (str_starts_with($repository, 's3:')) {
            $url = substr($repository, 3);
            $parts = parse_url($url);

            if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
                throw new ConfigurationException('Only s3:https:// Restic repositories are supported; plain-http S3 is refused.');
            }

            if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new ConfigurationException('The Restic repository location must not contain credentials, a query string or a fragment. Credentials belong in QURABA_BACKUP_B2_KEY_ID / QURABA_BACKUP_B2_APPLICATION_KEY.');
            }

            $segments = array_values(array_filter(explode('/', (string) ($parts['path'] ?? '')), static fn (string $s): bool => $s !== ''));

            if (count($segments) < 2) {
                throw new ConfigurationException('An S3 Restic repository needs a bucket and a path prefix: s3:https://{endpoint}/{bucket}/{prefix}.');
            }

            $endpoint = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            $bucket = array_shift($segments);

            return self::s3($endpoint, $bucket, implode('/', $segments), $region);
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $repository) === 1 && ! PathGuard::isAbsolute($repository)) {
            throw new ConfigurationException('Unsupported Restic backend. Use Backblaze B2 through s3:https://… (or an absolute local path for tests).');
        }

        return self::local($repository);
    }

    public static function endpoint(string $endpoint): string
    {
        $endpoint = rtrim(trim($endpoint), '/');
        $parts = parse_url($endpoint);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            throw new ConfigurationException('The B2 endpoint must be an https:// URL such as https://s3.us-west-004.backblazeb2.com.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '')) {
            throw new ConfigurationException('The B2 endpoint must be a bare https://host URL without credentials, path, query or fragment.');
        }

        if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $parts['host']) !== 1) {
            throw new ConfigurationException('The B2 endpoint host is invalid.');
        }

        return 'https://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public static function bucket(string $bucket): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{1,61}[A-Za-z0-9]$/', $bucket) !== 1) {
            throw new ConfigurationException('The B2 bucket name is invalid (3-63 letters, digits and hyphens).');
        }

        return $bucket;
    }

    public static function objectPath(string $path): string
    {
        $segments = explode('/', trim($path, '/'));

        foreach ($segments as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1 || $segment === '.' || $segment === '..' || str_contains($segment, '..')) {
                throw new ConfigurationException(sprintf('Repository path segment [%s] is invalid; use letters, digits, ".", "_" and "-".', mb_substr($segment, 0, 40)));
            }
        }

        return implode('/', $segments);
    }

    public static function regionFromEndpoint(string $endpoint): ?string
    {
        $host = (string) parse_url($endpoint, PHP_URL_HOST);

        if (preg_match('/^s3\.([a-z0-9-]+)\.backblazeb2\.com$/', $host, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public function isBackblaze(): bool
    {
        return $this->endpoint !== null && str_ends_with((string) parse_url($this->endpoint, PHP_URL_HOST), '.backblazeb2.com');
    }

    /**
     * Safe to show to operators: contains no credentials by construction.
     */
    public function display(): string
    {
        return $this->repository;
    }

    private static function region(string $region): string
    {
        if (preg_match('/^[a-z0-9-]{2,32}$/', $region) !== 1) {
            throw new ConfigurationException('The B2/S3 region is invalid.');
        }

        return $region;
    }
}
