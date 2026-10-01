<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

use Quraba\Backup\Exceptions\RestoreFailed;

/** Conservatively accepts only literal database-scoped scratch grants. */
final class ScratchAccountGrants
{
    /** @param list<string> $statements */
    public static function assertConfined(array $statements, string $scratchDb): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $scratchDb) !== 1) {
            throw RestoreFailed::scratchUnsafe('the scratch database name cannot be safely checked against account grants');
        }

        $literalDatabase = str_replace('_', '\\_', $scratchDb);
        $hasScratchGrant = false;
        foreach ($statements as $grant) {
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/i', $grant, $matches) !== 1) {
                throw RestoreFailed::scratchUnsafe('the scratch account has an unsupported role or grant');
            }

            $privileges = strtoupper(trim($matches[1]));
            $scope = trim($matches[2]);
            if ($scope === '*.*' && $privileges === 'USAGE') {
                continue;
            }
            // Unescaped underscores in database-level GRANT scopes are
            // wildcards on common MySQL/MariaDB configurations.
            if ($scope !== '`'.$literalDatabase.'`.*' && $scope !== $literalDatabase.'.*') {
                throw RestoreFailed::scratchUnsafe('the scratch account has privileges outside the scratch database');
            }
            $hasScratchGrant = true;
        }

        if (! $hasScratchGrant) {
            throw RestoreFailed::scratchUnsafe('the scratch account has no database-scoped grant');
        }
    }
}
