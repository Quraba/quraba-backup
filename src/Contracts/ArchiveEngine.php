<?php

declare(strict_types=1);

namespace Quraba\Backup\Contracts;

use Quraba\Backup\Archive\ArchiveRequest;
use Quraba\Backup\Archive\CreatedArchive;
use Quraba\Backup\Exceptions\ArchiveCreationFailed;

/**
 * Creates the encrypted application archive (database dump, .env and
 * quraba-backup.json) inside the run's operation workspace.
 *
 * Creation alone is never success: the archive must still pass the
 * ArchiveVerifier before it is uploaded.
 */
interface ArchiveEngine
{
    /**
     * @throws ArchiveCreationFailed
     */
    public function create(ArchiveRequest $request): CreatedArchive;

    /**
     * Whether the engine can produce AES-256 encrypted archives on this host.
     */
    public function supportsStrongEncryption(): bool;
}
