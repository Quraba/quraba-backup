<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

/** The package's release artifact contract for one supported host. */
final readonly class ResticReleaseAsset
{
    private function __construct(
        public string $archiveTemplate,
        public string $format,
        public string $archiveExecutable,
        public string $managedExecutable,
    ) {}

    public static function forPlatform(ResticPlatform $platform, string $linuxTemplate): self
    {
        if ($platform->os === 'windows') {
            return new self(
                ResticRelease::WINDOWS_ARCHIVE_TEMPLATE,
                'zip',
                sprintf('restic_%s_windows_%s.exe', ResticRelease::VERSION, $platform->arch),
                'restic.exe',
            );
        }

        return new self($linuxTemplate, 'bz2', 'restic', 'restic');
    }
}
