<?php

declare(strict_types=1);

namespace Quraba\Backup\Database;

use Generator;

/**
 * `DEFINER=user@host` clauses of a MySQL/MariaDB dump.
 *
 * A dump records, for every view, trigger, routine and event, the account
 * that defined it. Importing such a statement as ANOTHER account needs a
 * global privilege (SUPER / SET USER) that application accounts on shared
 * hosting do not have: the import then fails in the middle. This class
 * finds those clauses (so a restore can refuse BEFORE it changes anything)
 * and can remove them while a dump is streamed to the client, which makes
 * the importing account the definer.
 *
 * Only statement lines are touched (`CREATE …` and versioned comments
 * `/*!5…`), never row data: an INSERT line is passed through byte for byte.
 */
final class DefinerFilter
{
    private const string CLAUSE = '/\s?DEFINER\s*=\s*(`(?:[^`]|``)*`|\'[^\']*\'|[^\s@`\'*]+)\s*@\s*(`(?:[^`]|``)*`|\'[^\']*\'|[^\s*`\']+)/i';

    private const string STATEMENT = '~^\s*(?:CREATE\s|/\*!5\d)~i';

    /**
     * The definer of a statement line as `user@host`, or null.
     */
    public static function definer(string $line): ?string
    {
        if (preg_match(self::STATEMENT, $line) !== 1 || preg_match(self::CLAUSE, $line, $matches) !== 1) {
            return null;
        }

        return self::unquote($matches[1]).'@'.strtolower(self::unquote($matches[2]));
    }

    public static function strip(string $line): string
    {
        if (preg_match(self::STATEMENT, $line) !== 1) {
            return $line;
        }

        return (string) preg_replace(self::CLAUSE, '', $line);
    }

    /**
     * Streams a dump line by line (constant memory) for a client's stdin,
     * optionally without its DEFINER clauses.
     *
     * @param  resource  $stream
     * @return Generator<int, string>
     */
    public static function stream($stream, bool $strip): Generator
    {
        $buffer = '';

        while (($line = fgets($stream)) !== false) {
            $buffer .= $strip ? self::strip($line) : $line;

            if (strlen($buffer) >= 1048576) {
                yield $buffer;
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            yield $buffer;
        }
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '`' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
            return str_replace($value[0].$value[0], $value[0], substr($value, 1, -1));
        }

        return $value;
    }
}
