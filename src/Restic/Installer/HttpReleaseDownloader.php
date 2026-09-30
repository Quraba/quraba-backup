<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic\Installer;

use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use Quraba\Backup\Contracts\ReleaseDownloader;
use Quraba\Backup\Exceptions\StorageUnavailable;
use RuntimeException;
use Throwable;

/**
 * Streams release assets to disk over HTTPS with bounded size and time.
 * Redirects are followed only to other https locations (GitHub release
 * downloads redirect to their object storage).
 */
final readonly class HttpReleaseDownloader implements ReleaseDownloader
{
    public function __construct(
        private Factory $http,
        private int $connectTimeoutSeconds,
        private int $timeoutSeconds,
    ) {
        if ($connectTimeoutSeconds <= 0 || $timeoutSeconds <= 0) {
            throw new InvalidArgumentException('Download timeouts must be positive.');
        }
    }

    public function download(string $url, string $destination, int $maxBytes): void
    {
        if (! str_starts_with($url, 'https://')) {
            throw new StorageUnavailable('Refusing to download a release asset over a non-https URL.');
        }

        try {
            $response = $this->http
                ->withOptions([
                    'sink' => $destination,
                    'verify' => true,
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['https'],
                    ],
                    'progress' => static function (int|float $expected, int|float $downloaded) use ($maxBytes): void {
                        if ($expected > $maxBytes || $downloaded > $maxBytes) {
                            throw new RuntimeException('size limit exceeded');
                        }
                    },
                ])
                ->withUserAgent('quraba-backup-installer')
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->get($url);
        } catch (Throwable $exception) {
            // Transport messages are summarized, never chained: they may carry
            // redirect URLs with signed query strings.
            throw new StorageUnavailable(sprintf('Download of %s failed (%s).', $this->describe($url), $this->summarize($exception)));
        }

        if (! $response->successful()) {
            throw new StorageUnavailable(sprintf('Download of %s failed with HTTP status %d.', $this->describe($url), $response->status()));
        }

        clearstatcache(true, $destination);
        $size = @filesize($destination);

        if ($size === false || $size === 0) {
            throw new StorageUnavailable(sprintf('Download of %s produced no data.', $this->describe($url)));
        }

        if ($size > $maxBytes) {
            throw new StorageUnavailable(sprintf('Download of %s exceeded the %d byte limit.', $this->describe($url), $maxBytes));
        }
    }

    private function describe(string $url): string
    {
        $parts = parse_url($url);

        return is_array($parts) ? ($parts['host'] ?? '?').($parts['path'] ?? '') : 'release asset';
    }

    private function summarize(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'size limit exceeded')) {
            return 'size limit exceeded';
        }

        // Keep the class of problem, drop anything URL-like.
        $message = (string) preg_replace('~https?://\S+~i', '[url]', $message);

        return mb_substr($message, 0, 300);
    }
}
