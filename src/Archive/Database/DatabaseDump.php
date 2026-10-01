<?php

declare(strict_types=1);

namespace Quraba\Backup\Archive\Database;

use Quraba\Backup\Database\ServerFlavor;

final readonly class DatabaseDump
{
    public function __construct(
        public string $path,
        public int $bytes,
        public string $connection,
        public string $driver,
        public string $database,
        public ServerFlavor $flavor,
        public string $serverVersion,
        public string $tool,
        public string $toolVersion,
        public string $eventPolicy = 'unknown',
        public bool $eventsIncluded = false,
        public bool $routinesIncluded = false,
        public bool $eventPrivilegeProven = false,
        /** @var array{tables: bool, views: bool, triggers: bool, routines: bool} */
        public array $objectPrivilegesProven = ['tables' => false, 'views' => false, 'triggers' => false, 'routines' => false],
    ) {}
}
