<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use LogicException;

/**
 * S3-compatible credentials. They only ever leave this object as child
 * process environment variables; they are masked in dumps and cannot be
 * serialized.
 */
final class StorageCredentials
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $keyId,
        #[\SensitiveParameter] private readonly string $secret,
    ) {}

    /**
     * @return array{AWS_ACCESS_KEY_ID: string, AWS_SECRET_ACCESS_KEY: string}
     */
    public function toEnvironment(): array
    {
        return [
            'AWS_ACCESS_KEY_ID' => $this->keyId,
            'AWS_SECRET_ACCESS_KEY' => $this->secret,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['keyId' => '[REDACTED]', 'secret' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Storage credentials cannot be serialized.');
    }
}
