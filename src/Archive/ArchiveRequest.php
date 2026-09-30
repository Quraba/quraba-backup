<?php

declare(strict_types=1);

namespace Quraba\Backup\Archive;

use LogicException;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Workspace\OperationWorkspace;

final class ArchiveRequest
{
    public function __construct(
        public readonly string $runUuid,
        public readonly BackupProfile $profile,
        public readonly ApplicationIdentity $identity,
        public readonly OperationWorkspace $workspace,
        public readonly string $databaseConnection,
        public readonly string $envPath,
        public readonly string $packageVersion,
        #[\SensitiveParameter] private readonly string $password,
    ) {}

    public function password(): string
    {
        return $this->password;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['runUuid' => $this->runUuid, 'password' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Archive requests carry the archive password and cannot be serialized.');
    }
}
