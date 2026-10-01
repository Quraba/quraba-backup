<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

enum DbValidationLevel: string
{
    case Artifact = 'artifact';
    case Schema = 'schema';
    case ScratchImport = 'scratch_import';
}
