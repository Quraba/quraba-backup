<?php

declare(strict_types=1);

namespace Quraba\Backup\Database;

use InvalidArgumentException;
use Quraba\Backup\Support\PrivateFile;

/**
 * A temporary MySQL/MariaDB client option file (`--defaults-extra-file`)
 * holding connection credentials, so they never appear in argv or in the
 * child environment.
 *
 * Quoting follows the option-file parser shared by MySQL and MariaDB
 * (mysys my_default / my_load_defaults), with real-client coverage in CI:
 *
 *  - a value wrapped in matching double quotes has the quotes removed, and
 *    everything between them — including `#`, `;`, spaces and `'` — is kept;
 *  - inside the quotes `\"` is needed so the comment scanner does not treat an
 *    inner quote as the end of the value (an unescaped `"` followed by `#`
 *    would truncate the password there);
 *  - backslash escapes `\\ \" \' \t \n \r \b \s` are decoded; any other
 *    backslash is kept literally. Every backslash is therefore escaped.
 *
 * NUL and line breaks are refused: they cannot be represented safely.
 */
final class MySqlOptionFile
{
    public static function quote(#[\SensitiveParameter] string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException('Database credentials containing NUL or line breaks cannot be written to a MySQL option file.');
        }

        return '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"', "\t" => '\\t']).'"';
    }

    /**
     * Writes a private (proven 0600 on POSIX) option file in $directory and
     * returns its path. The caller must {@see self::destroy()} it.
     */
    public static function write(
        string $directory,
        string $user,
        #[\SensitiveParameter] string $password,
        ?string $host,
        ?int $port,
        ?string $socket,
    ): string {
        $lines = ['[client]', 'user='.self::quote($user), 'password='.self::quote($password)];

        if ($socket !== null && $socket !== '') {
            $lines[] = 'socket='.self::quote($socket);
        } else {
            $lines[] = 'host='.self::quote($host === null || $host === '' ? '127.0.0.1' : $host);

            if ($port !== null) {
                $lines[] = 'port='.$port;
            }
        }

        $path = rtrim($directory, '/\\').'/client-'.bin2hex(random_bytes(8)).'.cnf';
        $handle = PrivateFile::create($path);

        try {
            $contents = implode("\n", $lines)."\n";

            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new InvalidArgumentException('The database option file could not be written completely.');
            }

            if (! fflush($handle)) {
                throw new InvalidArgumentException('The database option file could not be flushed.');
            }

            PrivateFile::assertStillPrivate($path, $handle);
        } catch (\Throwable $exception) {
            fclose($handle);
            PrivateFile::destroy($path);

            throw $exception;
        }

        fclose($handle);

        return $path;
    }

    public static function destroy(string $path): bool
    {
        return PrivateFile::destroy($path);
    }
}
