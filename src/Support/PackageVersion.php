<?php

declare(strict_types=1);

namespace Quraba\Backup\Support;

use Composer\InstalledVersions;
use OutOfBoundsException;

final class PackageVersion
{
    public const string PACKAGE = 'quraba/quraba-backup';

    public static function current(): string
    {
        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE);
            $reference = InstalledVersions::getReference(self::PACKAGE);
        } catch (OutOfBoundsException) {
            return 'unknown';
        }

        if ($version === null) {
            return 'unknown';
        }

        if ($reference !== null && str_starts_with($version, 'dev-')) {
            return $version.'@'.substr($reference, 0, 12);
        }

        return $version;
    }
}
