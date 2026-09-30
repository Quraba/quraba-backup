<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic\Installer;

use Quraba\Backup\Restic\ResticPlatform;
use Quraba\Backup\Restic\ResticVersionInfo;

final readonly class InstallResult
{
    public function __construct(
        public InstallOutcome $outcome,
        public string $path,
        public ResticPlatform $platform,
        public ResticVersionInfo $version,
        public ?string $archiveSha256 = null,
        public ?string $binarySha256 = null,
    ) {}
}
