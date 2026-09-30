<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

enum BinarySource: string
{
    case Configured = 'configured';
    case Managed = 'managed';
    case System = 'system';
}
