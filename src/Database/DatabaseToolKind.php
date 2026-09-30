<?php

declare(strict_types=1);

namespace Quraba\Backup\Database;

enum DatabaseToolKind: string
{
    case Dumper = 'dumper';
    case Client = 'client';

    /**
     * Executable names in preference order for a server flavor. Modern
     * MariaDB names come first unless the server is known to be MySQL.
     *
     * @return list<string>
     */
    public function candidates(ServerFlavor $flavor): array
    {
        return match ([$this, $flavor === ServerFlavor::MySql]) {
            [self::Dumper, true] => ['mysqldump', 'mariadb-dump'],
            [self::Dumper, false] => ['mariadb-dump', 'mysqldump'],
            [self::Client, true] => ['mysql', 'mariadb'],
            [self::Client, false] => ['mariadb', 'mysql'],
        };
    }

    public function configKey(): string
    {
        return match ($this) {
            self::Dumper => 'quraba-backup.database.dump_binary',
            self::Client => 'quraba-backup.database.client_binary',
        };
    }
}
