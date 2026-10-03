<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic\Installer;

use Quraba\Backup\Exceptions\EnvironmentUnsupported;
use Quraba\Backup\Exceptions\ResticInstallationFailed;
use ZipArchive;

/** Extracts the single expected executable from an already SHA-256 verified ZIP. */
final class VerifiedZipExtractor
{
    public function extract(string $source, string $destination, string $expectedEntry, int $maxBytes): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new EnvironmentUnsupported('The zip extension is required to install Restic on Windows.');
        }

        $zip = new ZipArchive;
        if ($zip->open($source, ZipArchive::RDONLY) !== true) {
            throw new ResticInstallationFailed('The verified Restic ZIP cannot be opened.');
        }

        try {
            if ($zip->numFiles !== 1) {
                throw new ResticInstallationFailed('The Restic ZIP must contain exactly one executable.');
            }

            $entry = $zip->statIndex(0);
            $name = $entry['name'] ?? null;
            if (! is_string($name) || str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')
                || str_contains($name, '..') || $name !== $expectedEntry) {
                throw new ResticInstallationFailed('The Restic ZIP has an unexpected or unsafe entry path.');
            }

            $size = $entry['size'] ?? null;
            if (! is_int($size) || $size <= 0 || $size > $maxBytes) {
                throw new ResticInstallationFailed('The Restic ZIP executable has an invalid or excessive size.');
            }

            if ($zip->getExternalAttributesIndex(0, $opsys, $attributes)) {
                if (! is_int($attributes)) {
                    throw new ResticInstallationFailed('The Restic ZIP entry attributes could not be verified.');
                }
                // Unix symlinks are identified by the high-order file type bits.
                if ($opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new ResticInstallationFailed('The Restic ZIP executable is a symbolic link.');
                }
            }

            $input = $zip->getStream($name);
            $output = @fopen($destination, 'xb');
            if ($input === false || $output === false) {
                if (is_resource($input)) {
                    fclose($input);
                }
                throw new ResticInstallationFailed('The Restic ZIP executable could not be staged.');
            }

            $written = 0;
            try {
                while (! feof($input)) {
                    $chunk = fread($input, 1048576);
                    if ($chunk === false) {
                        throw new ResticInstallationFailed('The Restic ZIP could not be read.');
                    }
                    $written += strlen($chunk);
                    if ($written > $maxBytes || fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new ResticInstallationFailed('The Restic ZIP executable exceeds the limit or could not be written.');
                    }
                }
            } finally {
                fclose($input);
                fclose($output);
            }

            if ($written !== $size) {
                throw new ResticInstallationFailed('The extracted Restic executable size differs from the ZIP entry.');
            }
        } finally {
            $zip->close();
        }
    }
}
