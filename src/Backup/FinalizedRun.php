<?php

declare(strict_types=1);

namespace Quraba\Backup\Backup;

use Quraba\Backup\Enums\BackupStatus;

final readonly class FinalizedRun
{
    public function __construct(
        public BackupStatus $status,
        public ?string $manifestLocator,
    ) {}
}
