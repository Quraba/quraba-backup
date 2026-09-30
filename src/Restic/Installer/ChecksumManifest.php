<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic\Installer;

use Quraba\Backup\Exceptions\ResticInstallationFailed;

/**
 * Parser for a release SHA256SUMS manifest (`<sha256>  <file name>`).
 */
final class ChecksumManifest
{
    /**
     * @return array<string, string> file name => lowercase SHA-256
     */
    public static function parse(string $contents): array
    {
        $entries = [];

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^([0-9a-fA-F]{64})\s+\*?([A-Za-z0-9._\-]+)$/', $line, $matches) !== 1) {
                throw new ResticInstallationFailed('The release checksum manifest contains an unexpected line.');
            }

            $file = $matches[2];
            $digest = strtolower($matches[1]);

            if (isset($entries[$file]) && $entries[$file] !== $digest) {
                throw new ResticInstallationFailed(sprintf('The release checksum manifest lists conflicting digests for %s.', $file));
            }

            $entries[$file] = $digest;
        }

        if ($entries === []) {
            throw new ResticInstallationFailed('The release checksum manifest is empty.');
        }

        return $entries;
    }
}
