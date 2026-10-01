<?php

declare(strict_types=1);

namespace Quraba\Backup\Recovery;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Archive\ArchiveStore;
use Quraba\Backup\Archive\ArchiveVerifier;
use Quraba\Backup\Archive\SpatieArchiveEngine;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Manifest\RemoteManifestCatalog;
use Quraba\Backup\Retention\RetentionTombstoneStore;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Support\PrivateFile;
use Quraba\Backup\Workspace\WorkspaceArea;
use Quraba\Backup\Workspace\WorkspaceManager;
use Throwable;
use ZipArchive;

/**
 * Recovers the archived `.env` of ONE exact run on a clean host, without
 * restoring anything.
 *
 * It needs only the minimal recovery configuration (B2 access, the
 * application ID and the archive password — typically exported as
 * environment variables) and works purely from the immutable remote
 * manifest: no local catalog, no database.
 *
 *   exact run UUID → remote manifest → not expired by retention
 *   → download → SHA-256 must equal the manifest → decrypt and verify the
 *   whole archive → extract ONLY `.env` into a file that is proven private
 *   before a single byte is written.
 *
 * The content is never printed, logged or returned. An existing target is
 * never overwritten unless that is requested explicitly, and the target may
 * not be inside the public web directory.
 */
final readonly class EnvBootstrapper
{
    public const string OVERWRITE_PHRASE = 'OVERWRITE_ENV';

    public function __construct(
        private Repository $config,
        private OperationCoordinator $coordinator,
        private IdentityResolver $identities,
        private RemoteManifestCatalog $manifests,
        private RetentionTombstoneStore $tombstones,
        private ArchiveStore $archives,
        private ArchiveVerifier $verifier,
        private WorkspaceManager $workspaces,
        private string $publicPath,
    ) {}

    /**
     * @return array{run_uuid: string, target: string, bytes: int, archive_sha256: string, overwritten: bool}
     *
     * @throws RestoreFailed
     */
    public function bootstrap(string $runUuid, string $target, bool $overwrite = false): array
    {
        $runUuid = Identifiers::assertUuid($runUuid, 'The run UUID');
        $identity = $this->identities->current();
        $target = $this->target($target, $overwrite);

        $password = $this->config->get('quraba-backup.archive.password');

        if (! is_string($password) || trim($password) === '') {
            throw RestoreFailed::envBootstrapFailed('the archive password (QURABA_BACKUP_ARCHIVE_PASSWORD) is not configured');
        }

        try {
            $manifest = $this->manifests->find($identity, $runUuid);
        } catch (Throwable $exception) {
            throw RestoreFailed::envBootstrapFailed('the remote manifest could not be read: '.$exception->getMessage());
        }

        if ($manifest === null || $manifest->archiveLocator === null || $manifest->archiveSha256 === null) {
            throw RestoreFailed::sourceUnavailable('no remote manifest with a verified application archive exists for this exact run');
        }

        if ($this->tombstones->find($runUuid)?->covers('application_archive') === true) {
            throw RestoreFailed::sourceExpired('the application archive of this run was removed by retention');
        }

        $locks = $this->coordinator->beginRestorePreparation('bootstrap .env');
        $workspace = $this->workspaces->create();

        try {
            $archive = $workspace->path(WorkspaceArea::Restore, 'application.zip');
            $sha = $this->archives->download($manifest->archiveLocator, $archive);

            if (! hash_equals($manifest->archiveSha256, $sha)) {
                throw RestoreFailed::envBootstrapFailed('the downloaded archive SHA-256 differs from the immutable manifest');
            }

            $zip = new ZipArchive;

            if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                throw RestoreFailed::envBootstrapFailed('the downloaded archive is not a readable ZIP');
            }

            $includesEnv = $zip->locateName(SpatieArchiveEngine::ENV_ENTRY) !== false;
            $zip->close();

            if (! $includesEnv) {
                throw RestoreFailed::envBootstrapFailed('this archive was created without the .env file (archive.include_env was off)');
            }

            // Password, structure, encryption and identity of the WHOLE archive.
            $verified = $this->verifier->verify($archive, $password, $runUuid, $identity, true);

            if (! hash_equals($manifest->archiveSha256, $verified->sha256)) {
                throw RestoreFailed::envBootstrapFailed('archive verification produced a different SHA-256');
            }

            if ($zip->open($archive, ZipArchive::RDONLY) !== true || ! $zip->setPassword($password)) {
                throw RestoreFailed::envBootstrapFailed('the archive cannot be opened for extraction');
            }

            try {
                $bytes = $this->extract($zip, $target, $overwrite);
            } finally {
                $zip->close();
            }

            return ['run_uuid' => $runUuid, 'target' => $target, 'bytes' => $bytes, 'archive_sha256' => $verified->sha256, 'overwritten' => $overwrite];
        } catch (RestoreFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw RestoreFailed::envBootstrapFailed($exception->getMessage());
        } finally {
            $workspace->cleanup();
            $locks->release();
        }
    }

    private function target(string $target, bool $overwrite): string
    {
        if (! PathGuard::isAbsolute($target)) {
            throw RestoreFailed::envBootstrapFailed('--target must be an absolute file path');
        }

        $target = PathGuard::normalizeAbsolute($target);
        $directory = dirname($target);
        $realDirectory = PathGuard::real($directory);

        if ($realDirectory === null || ! is_dir($directory) || ! is_writable($directory)) {
            throw RestoreFailed::envBootstrapFailed('the directory of the target does not exist or is not writable');
        }

        $public = PathGuard::real($this->publicPath) ?? $this->publicPath;

        if (PathGuard::isWithin($realDirectory, $public)) {
            throw RestoreFailed::envBootstrapFailed('the target is inside the public web directory; an .env file must never be web-accessible');
        }

        clearstatcache(true, $target);

        if (is_link($target) || is_dir($target)) {
            throw RestoreFailed::envBootstrapFailed('the target is a symbolic link or a directory');
        }

        if (file_exists($target) && ! $overwrite) {
            throw RestoreFailed::envBootstrapFailed(sprintf('[%s] already exists and is never overwritten by default. Choose another --target, or pass --overwrite --confirm=%s', $target, self::OVERWRITE_PHRASE));
        }

        return $target;
    }

    /**
     * Streams the entry into a file that is proven private (0600, owner)
     * before the first byte is written. Overwriting replaces the target by
     * renaming a complete private file over it.
     */
    private function extract(ZipArchive $zip, string $target, bool $overwrite): int
    {
        $source = $zip->getStreamName(SpatieArchiveEngine::ENV_ENTRY);

        if ($source === false) {
            throw RestoreFailed::envBootstrapFailed('the .env entry could not be opened');
        }

        $destination = $overwrite ? $target.'.quraba-'.bin2hex(random_bytes(8)) : $target;
        $bytes = 0;

        try {
            $handle = PrivateFile::create($destination);
        } catch (Throwable $exception) {
            fclose($source);

            throw RestoreFailed::envBootstrapFailed('a private file could not be created at the target: '.$exception->getMessage());
        }

        try {
            while (! feof($source)) {
                $chunk = fread($source, 65536);

                if ($chunk === false || ($chunk !== '' && fwrite($handle, $chunk) !== strlen($chunk))) {
                    throw RestoreFailed::envBootstrapFailed('the .env entry could not be written completely');
                }

                $bytes += strlen($chunk);
            }

            if (! fflush($handle)) {
                throw RestoreFailed::envBootstrapFailed('the .env file could not be flushed');
            }

            PrivateFile::assertStillPrivate($destination, $handle);
        } catch (Throwable $exception) {
            fclose($source);
            fclose($handle);
            PrivateFile::destroy($destination);

            throw $exception;
        }

        fclose($source);
        fclose($handle);

        if ($overwrite && (is_link($target) || ! @rename($destination, $target))) {
            PrivateFile::destroy($destination);

            throw RestoreFailed::envBootstrapFailed('the existing target could not be replaced');
        }

        return $bytes;
    }
}
