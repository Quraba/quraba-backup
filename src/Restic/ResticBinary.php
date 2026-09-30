<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

/**
 * A Restic binary that has passed existence, executability and exact
 * version verification.
 */
final readonly class ResticBinary
{
    public function __construct(
        public string $path,
        public BinarySource $source,
        public ResticVersionInfo $version,
    ) {}
}
