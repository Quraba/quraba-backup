<?php

declare(strict_types=1);

namespace Quraba\Backup\Backup;

final readonly class MediaSnapshotResult
{
    public function __construct(
        public string $snapshotId,
        public string $repositoryId,
        public bool $adopted,
    ) {}
}
