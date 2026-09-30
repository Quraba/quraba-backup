<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

enum ArtifactStorage: string
{
    case B2Archive = 'b2_archive';
    case Restic = 'restic';
    case B2Manifest = 'b2_manifest';
}
