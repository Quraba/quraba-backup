<?php

declare(strict_types=1);

namespace Quraba\Backup\Database;

use Quraba\Backup\Exceptions\RestoreFailed;

/**
 * One streaming pass over a single-database SQL dump (never loaded into
 * memory) that
 *
 *  - refuses statements a single-database dump produced by this package
 *    never contains and that could reach beyond the target database when
 *    the dump is imported: `USE`, `CREATE|DROP DATABASE|SCHEMA`, account
 *    and privilege statements;
 *  - lists the base tables the dump creates, so an import can be verified
 *    against the dump itself, independently of the server version;
 *  - lists the accounts named as DEFINER of views, triggers, routines and
 *    events, so a restore can tell BEFORE it changes anything whether the
 *    importing account may create them.
 *
 * Only statement beginnings outside `DELIMITER` blocks are examined: row
 * data never starts a line (mysqldump escapes line breaks inside values) and
 * routine bodies live inside `DELIMITER ;;` blocks.
 */
final class DumpInspector
{
    private const string FORBIDDEN = '/^\s*(?:\/\*![0-9]*\s*)?(USE\s|CREATE\s+(?:DATABASE|SCHEMA)\b|DROP\s+(?:DATABASE|SCHEMA)\b|GRANT\s|REVOKE\s|(?:CREATE|ALTER|DROP|RENAME)\s+USER\b|SET\s+PASSWORD\b)/i';

    private const string TABLE = '/^CREATE TABLE (?:IF NOT EXISTS )?`((?:[^`]|``)+)`/';

    /**
     * @return array{tables: list<string>, definers: list<string>}
     *
     * @throws RestoreFailed
     */
    public static function inspect(string $path): array
    {
        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            throw RestoreFailed::reconstructionFailed('the SQL dump cannot be opened for inspection');
        }

        $tables = [];
        $definers = [];
        $inBody = false;

        try {
            while (($line = fgets($stream)) !== false) {
                $definer = DefinerFilter::definer($line);

                if ($definer !== null) {
                    $definers[$definer] = true;
                }

                if (preg_match('/^DELIMITER\s+(\S+)/i', $line, $delimiter) === 1) {
                    $inBody = $delimiter[1] !== ';';

                    continue;
                }

                if ($inBody) {
                    continue;
                }

                if (preg_match(self::FORBIDDEN, $line, $forbidden) === 1) {
                    throw RestoreFailed::reconstructionFailed(sprintf('the SQL dump contains a [%s] statement, which a single-database backup never does; refusing to import it', strtoupper(trim((string) preg_replace('/\s+/', ' ', $forbidden[1])))));
                }

                if (preg_match(self::TABLE, $line, $table) === 1) {
                    $tables[] = str_replace('``', '`', $table[1]);
                }
            }
        } finally {
            fclose($stream);
        }

        sort($tables, SORT_STRING);

        $definers = array_keys($definers);
        sort($definers, SORT_STRING);

        return ['tables' => array_values(array_unique($tables)), 'definers' => $definers];
    }
}
