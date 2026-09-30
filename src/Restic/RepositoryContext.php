<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

/**
 * Everything a repository-bound Restic process needs: where, which password
 * file, and which storage credentials.
 */
final readonly class RepositoryContext
{
    public function __construct(
        public RepositoryLocation $location,
        public string $passwordFile,
        public ?StorageCredentials $credentials,
    ) {}
}
