<?php

declare(strict_types=1);

namespace Quraba\Backup\Security;

use Illuminate\Contracts\Config\Repository;

/**
 * Collects every secret value the package can know about from configuration,
 * so the redactor can mask them by value.
 *
 * Secrets are read on demand and never cached, stored or returned to callers
 * other than the redactor.
 */
final readonly class KnownSecrets
{
    /** Hard upper bound when reading the Restic password file for redaction. */
    private const int MAX_PASSWORD_FILE_BYTES = 65536;

    public function __construct(private Repository $config) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $secrets = [
            $this->config->get('quraba-backup.storage.b2.key_id'),
            $this->config->get('quraba-backup.storage.b2.application_key'),
            $this->config->get('quraba-backup.archive.password'),
            $this->config->get('restic.repository.key_id'),
            $this->config->get('restic.repository.application_key'),
            $this->config->get('app.key'),
        ];

        foreach ((array) $this->config->get('app.previous_keys', []) as $previousKey) {
            $secrets[] = $previousKey;
        }

        foreach ((array) $this->config->get('database.connections', []) as $connection) {
            if (is_array($connection)) {
                $secrets[] = $connection['password'] ?? null;
            }
        }

        $passwordFile = $this->config->get('restic.password_file');
        if (is_string($passwordFile) && $passwordFile !== '') {
            $contents = $this->readPasswordFile($passwordFile);
            if ($contents !== null) {
                $secrets[] = $contents;
                $secrets[] = rtrim($contents, "\r\n");
                $firstLine = strtok($contents, "\r\n");
                $secrets[] = $firstLine === false ? null : $firstLine;
            }
        }

        return array_values(array_filter(
            $secrets,
            static fn (mixed $secret): bool => is_string($secret) && $secret !== '',
        ));
    }

    private function readPasswordFile(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path, false, null, 0, self::MAX_PASSWORD_FILE_BYTES);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }
}
