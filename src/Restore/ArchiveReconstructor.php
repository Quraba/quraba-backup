<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Archive\ArchiveMetadata;
use Quraba\Backup\Archive\ArchiveStore;
use Quraba\Backup\Archive\ArchiveVerifier;
use Quraba\Backup\Archive\SpatieArchiveEngine;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Support\PrivateFile;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceArea;
use ZipArchive;

/**
 * Downloads and verifies the exact archive, then extracts ONLY the SQL dump
 * into the private workspace (hashing it on the way, so it can be re-proven
 * immediately before a live import).
 *
 * The archived `.env` is verified as part of the archive but never
 * extracted here: a restore does not need its content, so no plaintext copy
 * of the application's secrets is ever written by a restore. Recovering
 * `.env` on a clean host is the separate, explicit `bootstrap-env` command.
 */
final readonly class ArchiveReconstructor
{
    public function __construct(
        private ArchiveStore $archives,
        private ArchiveVerifier $verifier,
        private ArchiveMetadata $metadata,
        private Repository $config,
    ) {}

    /** @return array{archive_verified: true, archive_sha256: string, archive_bytes: int, database_dump: string, database_dump_bytes: int|false, database_dump_sha256: string, includes_env: bool, metadata: array<string, mixed>, app_key_compatibility: string, release_compatibility: string} */
    public function reconstruct(RestoreSource $source, ApplicationIdentity $identity, OperationWorkspace $workspace): array
    {
        if ($source->archiveLocator === null || $source->archiveSha256 === null) {
            throw RestoreFailed::sourceUnavailable('no verified archive');
        }

        $password = $this->config->get('quraba-backup.archive.password');
        if (! is_string($password) || $password === '') {
            throw RestoreFailed::reconstructionFailed('an archive password is required');
        }

        $archivePath = $workspace->path(WorkspaceArea::Restore, 'application.zip');
        $downloadedSha = $this->archives->download($source->archiveLocator, $archivePath);
        if (! hash_equals($source->archiveSha256, $downloadedSha)) {
            throw RestoreFailed::reconstructionFailed('the downloaded archive SHA-256 differs from the frozen source');
        }

        $zip = new ZipArchive;
        if ($zip->open($archivePath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw RestoreFailed::reconstructionFailed('the downloaded archive is not a readable ZIP');
        }

        try {
            $includesEnv = $zip->locateName(SpatieArchiveEngine::ENV_ENTRY) !== false;
        } finally {
            $zip->close();
        }

        $verified = $this->verifier->verify($archivePath, $password, $source->runUuid, $identity, $includesEnv);
        if (! hash_equals($source->archiveSha256, $verified->sha256)) {
            throw RestoreFailed::reconstructionFailed('archive verification produced a different SHA-256');
        }

        if ($source->archiveBytes !== null && $verified->bytes !== $source->archiveBytes) {
            throw RestoreFailed::reconstructionFailed('archive size differs from the frozen source');
        }

        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true || ! $zip->setPassword($password)) {
            throw RestoreFailed::reconstructionFailed('the archive cannot be opened for private extraction');
        }

        try {
            [$dump, $dumpSha256] = $this->copyEntry($zip, SpatieArchiveEngine::DATABASE_ENTRY, $workspace->path(WorkspaceArea::Restore, 'database.sql'));
        } finally {
            $zip->close();
        }

        $fingerprint = $verified->metadata['app_key_fingerprint'] ?? null;
        $appKey = $this->config->get('app.key');
        $current = is_string($appKey) && $appKey !== '' ? 'sha256:'.hash('sha256', $appKey) : null;
        $appKeyCompatibility = ! is_string($fingerprint) || $current === null ? 'unknown' : (hash_equals($fingerprint, $current) ? 'match' : 'mismatch');

        $release = $verified->metadata['release_fingerprint'] ?? null;
        $currentRelease = $this->metadata->releaseFingerprint();
        $releaseCompatibility = ! is_string($release) || $currentRelease === null ? 'unknown' : (hash_equals($release, $currentRelease) ? 'match' : 'warning');

        return [
            'archive_verified' => true,
            'archive_sha256' => $verified->sha256,
            'archive_bytes' => $verified->bytes,
            'database_dump' => $dump,
            'database_dump_bytes' => filesize($dump),
            'database_dump_sha256' => $dumpSha256,
            'includes_env' => $includesEnv,
            'metadata' => $verified->metadata,
            'app_key_compatibility' => $appKeyCompatibility,
            'release_compatibility' => $releaseCompatibility,
        ];
    }

    /**
     * @return array{0: string, 1: string} the private file and its SHA-256
     */
    private function copyEntry(ZipArchive $zip, string $entry, string $destination): array
    {
        $source = $zip->getStreamName($entry);
        if ($source === false) {
            throw RestoreFailed::reconstructionFailed('a verified archive entry could not be reopened');
        }

        $target = PrivateFile::create($destination);
        $hash = hash_init('sha256');
        try {
            while (! feof($source)) {
                $chunk = fread($source, 1048576);
                if ($chunk === false || ($chunk !== '' && fwrite($target, $chunk) !== strlen($chunk))) {
                    throw RestoreFailed::reconstructionFailed('an archive entry could not be copied into the private workspace');
                }
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        return [$destination, hash_final($hash)];
    }
}
