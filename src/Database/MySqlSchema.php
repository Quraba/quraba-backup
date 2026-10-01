<?php

declare(strict_types=1);

namespace Quraba\Backup\Database;

use Closure;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reads and (on explicit request) empties the CURRENT database of one
 * MySQL/MariaDB connection.
 *
 * Shared by the scratch validation database and the exact live database
 * replacement, so both use one reviewed implementation:
 *
 *  - the inventory only ever looks at `*_SCHEMA = DATABASE()`, always on
 *    the primary (write) connection, never a replica;
 *  - every DROP names one inventoried object, schema-qualified with the
 *    database name the caller has proven, with safely quoted identifiers;
 *    nothing is dropped by pattern and `DROP DATABASE` is never issued;
 *  - foreign-key checks are disabled for this session only and re-enabled
 *    in a finally block.
 */
final readonly class MySqlSchema
{
    public const array SYSTEM_SCHEMAS = ['mysql', 'information_schema', 'performance_schema', 'sys'];

    public function __construct(private DatabaseManager $database) {}

    public function currentDatabase(string $connection): ?string
    {
        $row = $this->database->connection($connection)->selectOne('SELECT DATABASE() AS db', [], false);
        $name = is_object($row) ? ($row->db ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function inventory(string $connection): SchemaInventory
    {
        $db = $this->database->connection($connection);
        $rows = [
            ...$db->select('SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()', [], false),
            ...$db->select('SELECT ROUTINE_NAME AS name, ROUTINE_TYPE AS type FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()', [], false),
            ...$db->select("SELECT EVENT_NAME AS name, 'EVENT' AS type FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()", [], false),
            ...$db->select("SELECT TRIGGER_NAME AS name, 'TRIGGER' AS type FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()", [], false),
        ];
        $objects = [];

        foreach ($rows as $row) {
            $name = is_object($row) ? ($row->name ?? null) : null;
            $type = is_object($row) ? ($row->type ?? null) : null;

            if (! is_string($name) || ! is_string($type)) {
                throw new InvalidArgumentException('The database returned an unreadable schema inventory.');
            }

            // A system-versioned table is a base table for our purposes.
            $objects[] = ['type' => $type === 'SYSTEM VERSIONED' ? SchemaInventory::TABLE : $type, 'name' => $name];
        }

        return new SchemaInventory($objects);
    }

    /**
     * Drops exactly the inventoried objects of `$schema`, which must be the
     * connection's current database.
     *
     * @param  (Closure(string, string): void)|null  $dropped  called after each object (type, name)
     *
     * @throws RuntimeException when the connection is not on `$schema` or an object remains afterwards
     */
    public function drop(string $connection, string $schema, SchemaInventory $inventory, ?Closure $dropped = null): void
    {
        if (in_array(strtolower($schema), self::SYSTEM_SCHEMAS, true)) {
            throw new RuntimeException('Refusing to drop objects of a system schema.');
        }

        if ($this->currentDatabase($connection) !== $schema) {
            throw new RuntimeException('The connection is not on the proven database; nothing was dropped.');
        }

        $db = $this->database->connection($connection);
        $db->statement('SET SESSION FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($inventory->droppable() as $object) {
                $statement = match ($object['type']) {
                    SchemaInventory::EVENT => 'DROP EVENT ',
                    SchemaInventory::VIEW => 'DROP VIEW ',
                    SchemaInventory::PROCEDURE => 'DROP PROCEDURE ',
                    SchemaInventory::FUNCTION => 'DROP FUNCTION ',
                    SchemaInventory::SEQUENCE => 'DROP SEQUENCE ',
                    default => 'DROP TABLE ',
                };

                $db->statement($statement.self::quote($schema).'.'.self::quote($object['name']));

                if ($dropped !== null) {
                    $dropped($object['type'], $object['name']);
                }
            }
        } finally {
            $db->statement('SET SESSION FOREIGN_KEY_CHECKS = 1');
        }

        $left = $this->inventory($connection);

        if (! $left->isEmpty()) {
            throw new RuntimeException(sprintf('%d schema object(s) remain after clearing the database.', $left->count()));
        }
    }

    public static function quote(string $identifier): string
    {
        if ($identifier === '' || str_contains($identifier, "\0")) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }

        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
