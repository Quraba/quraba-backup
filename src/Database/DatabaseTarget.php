<?php

declare(strict_types=1);

namespace Quraba\Backup\Database;

/**
 * The proven identity of the live database a restore is about to replace:
 * the configured production connection, the database the server reports for
 * it, and the server it is on. Recorded at preflight and re-proven before
 * every destructive statement and before the import is launched.
 */
final readonly class DatabaseTarget
{
    public function __construct(
        public string $connection,
        public string $database,
        public string $server,
        public string $account,
        public string $version,
    ) {}

    public function equals(self $other): bool
    {
        return $this->connection === $other->connection
            && $this->database === $other->database
            && $this->server === $other->server
            && $this->account === $other->account;
    }

    /**
     * @return array{connection: string, name: string, server: string, account: string, version: string}
     */
    public function toArray(): array
    {
        return ['connection' => $this->connection, 'name' => $this->database, 'server' => $this->server, 'account' => $this->account, 'version' => $this->version];
    }
}
