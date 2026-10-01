<?php

declare(strict_types=1);

namespace Quraba\Backup\Archive;

use Quraba\Backup\Exceptions\ArchiveVerificationFailed;
use Quraba\Backup\Identity\ApplicationIdentity;
use ZipArchive;

/**
 * Proves an application archive before it is uploaded (and again when a
 * remote archive is adopted during reconciliation):
 *
 *  - exists, non-empty, structurally consistent ZIP (ZipArchive::CHECKCONS);
 *  - contains exactly the expected entries, every one AES-256 encrypted;
 *  - the password decrypts every entry, streamed end to end so each entry's
 *    CRC is checked without loading large dumps into memory;
 *  - the database dump is non-empty and .env is present (when required);
 *  - quraba-backup.json names the expected run, application and environment;
 *  - SHA-256 of the complete archive file (streamed).
 *
 * Spatie reporting completion is never treated as success on its own.
 */
final class ArchiveVerifier
{
    private const int CHUNK = 1048576;

    private const int MAX_METADATA_BYTES = 1048576;

    public function verify(string $path, #[\SensitiveParameter] string $password, string $runUuid, ApplicationIdentity $identity, bool $requireEnv = true): ArchiveVerification
    {
        clearstatcache(true, $path);

        if (! is_file($path)) {
            throw new ArchiveVerificationFailed('The archive does not exist.');
        }

        $bytes = (int) filesize($path);

        if ($bytes <= 0) {
            throw new ArchiveVerificationFailed('The archive is empty.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CHECKCONS | ZipArchive::RDONLY);

        if ($opened !== true) {
            throw new ArchiveVerificationFailed(sprintf('The archive is not a readable ZIP file (libzip error %s).', (string) $opened));
        }

        try {
            $expected = array_values(array_filter([
                SpatieArchiveEngine::DATABASE_ENTRY,
                $requireEnv ? SpatieArchiveEngine::ENV_ENTRY : null,
                ArchiveMetadata::FILE_NAME,
            ]));

            $entries = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    throw new ArchiveVerificationFailed(sprintf('Archive entry #%d cannot be read.', $index));
                }

                if ($stat['encryption_method'] !== ZipArchive::EM_AES_256) {
                    throw new ArchiveVerificationFailed(sprintf('Archive entry [%s] is not AES-256 encrypted.', $stat['name']));
                }

                if (! $zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                    throw new ArchiveVerificationFailed(sprintf('Archive entry [%s] has no readable file attributes.', $stat['name']));
                }

                if ($opsys === ZipArchive::OPSYS_UNIX && is_int($attributes)) {
                    $type = ($attributes >> 16) & 0170000;
                    if ($type !== 0 && $type !== 0100000) {
                        throw new ArchiveVerificationFailed(sprintf('Archive entry [%s] is not a regular file.', $stat['name']));
                    }
                }

                $entries[] = $stat['name'];
            }

            $sortedEntries = $entries;
            $sortedExpected = $expected;
            sort($sortedEntries);
            sort($sortedExpected);

            if ($sortedEntries !== $sortedExpected) {
                throw new ArchiveVerificationFailed(sprintf('The archive entries [%s] differ from the expected [%s].', implode(', ', $entries), implode(', ', $expected)));
            }

            if (! $zip->setPassword($password)) {
                throw new ArchiveVerificationFailed('The archive password could not be applied.');
            }

            foreach ($entries as $entry) {
                $read = $this->streamEntry($zip, $entry);

                if ($entry === SpatieArchiveEngine::DATABASE_ENTRY && $read === 0) {
                    throw new ArchiveVerificationFailed('The database dump inside the archive is empty.');
                }
            }

            $metadata = $this->metadata($zip);
        } finally {
            $zip->close();
        }

        foreach (['run_uuid' => $runUuid, 'app_id' => $identity->appId, 'environment' => $identity->environment] as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) {
                throw new ArchiveVerificationFailed(sprintf('Archive metadata [%s] does not match this run (expected %s).', $key, $value));
            }
        }

        if (($metadata['schema_version'] ?? null) !== ArchiveMetadata::SCHEMA_VERSION) {
            throw new ArchiveVerificationFailed('Unsupported archive metadata schema version.');
        }

        $sha256 = hash_file('sha256', $path);

        if ($sha256 === false) {
            throw new ArchiveVerificationFailed('The archive SHA-256 could not be calculated.');
        }

        return new ArchiveVerification($path, $sha256, $bytes, $entries, 'aes256', $metadata);
    }

    /**
     * Reads an entry to the end in chunks: decryption and CRC failures
     * surface as read errors. Returns the number of bytes read.
     */
    private function streamEntry(ZipArchive $zip, string $entry): int
    {
        $stream = $zip->getStreamName($entry);

        if ($stream === false) {
            throw new ArchiveVerificationFailed(sprintf('Archive entry [%s] cannot be opened; the password is wrong or the entry is damaged.', $entry));
        }

        $total = 0;

        try {
            while (! feof($stream)) {
                $chunk = @fread($stream, self::CHUNK);

                if ($chunk === false) {
                    throw new ArchiveVerificationFailed(sprintf('Archive entry [%s] cannot be decrypted or fails its CRC check.', $entry));
                }

                $total += strlen($chunk);

                if ($chunk === '' && ! feof($stream)) {
                    throw new ArchiveVerificationFailed(sprintf('Archive entry [%s] ended unexpectedly.', $entry));
                }
            }
        } finally {
            fclose($stream);
        }

        $status = $zip->getStatusString();

        if ($zip->status !== ZipArchive::ER_OK) {
            throw new ArchiveVerificationFailed(sprintf('Archive entry [%s] failed verification: %s', $entry, $status));
        }

        $expectedSize = $zip->statName($entry)['size'] ?? null;

        if ($expectedSize !== $total) {
            throw new ArchiveVerificationFailed(sprintf('Archive entry [%s] decrypted to %d bytes instead of %s; the password is wrong or the entry is damaged.', $entry, $total, (string) $expectedSize));
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(ZipArchive $zip): array
    {
        $stat = $zip->statName(ArchiveMetadata::FILE_NAME);

        if ($stat === false || $stat['size'] > self::MAX_METADATA_BYTES) {
            throw new ArchiveVerificationFailed('The archive metadata is missing or too large.');
        }

        $json = $zip->getFromName(ArchiveMetadata::FILE_NAME);
        $decoded = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($decoded)) {
            throw new ArchiveVerificationFailed('The archive metadata cannot be decrypted or parsed.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
