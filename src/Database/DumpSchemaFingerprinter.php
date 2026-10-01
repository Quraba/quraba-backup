<?php

declare(strict_types=1);

namespace Quraba\Backup\Database;

use Quraba\Backup\Exceptions\RestoreFailed;

/** Streams CREATE statements from a MySQL dump without loading row data. */
final class DumpSchemaFingerprinter
{
    public static function fingerprint(string $path): ?string
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw RestoreFailed::reconstructionFailed('the SQL dump cannot be opened for schema validation');
        }

        $hash = hash_init('sha256');
        $inDefinition = false;
        $count = 0;
        try {
            while (($line = fgets($stream)) !== false) {
                if (! $inDefinition && preg_match('/^CREATE (?:TABLE|VIEW|ALGORITHM|DEFINER|TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i', ltrim($line)) === 1) {
                    $inDefinition = true;
                    $count++;
                }
                if ($inDefinition) {
                    hash_update($hash, rtrim($line, "\r\n")."\n");
                    if (preg_match('/;\s*$/', $line) === 1) {
                        $inDefinition = false;
                    }
                }
            }
        } finally {
            fclose($stream);
        }

        if ($count === 0) {
            return null;
        }
        if ($inDefinition) {
            throw RestoreFailed::reconstructionFailed('the SQL dump has an incomplete schema definition');
        }

        return 'sha256:'.hash_final($hash);
    }
}
