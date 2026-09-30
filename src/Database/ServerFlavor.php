<?php

declare(strict_types=1);

namespace Quraba\Backup\Database;

enum ServerFlavor: string
{
    case MySql = 'mysql';
    case MariaDb = 'mariadb';
    case Unknown = 'unknown';

    public static function fromVersionString(string $version): self
    {
        if ($version === '') {
            return self::Unknown;
        }

        return str_contains(strtolower($version), 'mariadb') ? self::MariaDb : self::MySql;
    }
}
