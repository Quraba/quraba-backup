<?php

declare(strict_types=1);

namespace Quraba\Backup\Archive;

use Illuminate\Contracts\Container\Container;
use Quraba\Backup\Contracts\ArchiveEngine;
use Quraba\Backup\Contracts\DatabaseDumper;
use Quraba\Backup\Exceptions\ArchiveCreationFailed;
use Quraba\Backup\Exceptions\QurabaBackupException;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Workspace\WorkspaceArea;
use Spatie\Backup\Config\Config as SpatieConfig;
use Spatie\Backup\Enums\Encryption;
use Spatie\Backup\Tasks\Backup\Zip;
use Throwable;
use ZipArchive;

/**
 * The only class that touches Spatie Laravel Backup.
 *
 * Spatie is used as the archive-building engine (its Zip task and AES
 * encryption), not as the orchestrator: no BackupJob, no Spatie destinations,
 * notifications, cleanup or commands. Spatie's Zip reads its settings from the
 * container's Spatie Config; a private Config instance holding only this
 * run's password is bound for the duration of the build and removed in a
 * finally block, so nothing leaks into the host application's own Spatie
 * configuration or into later operations.
 *
 * Archive contents (and nothing else): database/database.sql, .env,
 * quraba-backup.json. The plaintext dump and metadata files are deleted from
 * the workspace as soon as the encrypted archive is written; .env is read
 * in place and never copied.
 */
final readonly class SpatieArchiveEngine implements ArchiveEngine
{
    public const string DATABASE_ENTRY = 'database/database.sql';

    public const string ENV_ENTRY = '.env';

    public const string ARCHIVE_NAME = 'application.zip';

    public function __construct(
        private Container $container,
        private DatabaseDumper $dumper,
        private ArchiveMetadata $metadata,
        private SecretRedactor $redactor,
        private bool $includeEnv = true,
    ) {}

    public static function isInstalled(): bool
    {
        return class_exists(Zip::class) && class_exists(SpatieConfig::class);
    }

    public function supportsStrongEncryption(): bool
    {
        return class_exists(ZipArchive::class)
            && defined(ZipArchive::class.'::EM_AES_256')
            && Encryption::Aes256->algorithm() === ZipArchive::EM_AES_256
            && ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true);
    }

    public function create(ArchiveRequest $request): CreatedArchive
    {
        // Refuse before any data is produced.
        if (trim($request->password()) === '') {
            throw ArchiveCreationFailed::passwordMissing();
        }

        if (! $this->supportsStrongEncryption()) {
            throw ArchiveCreationFailed::encryptionUnsupported();
        }

        if ($this->includeEnv && (! is_file($request->envPath) || ! is_readable($request->envPath))) {
            throw new ArchiveCreationFailed(sprintf('The .env file [%s] is missing or unreadable; it is a required archive component.', $request->envPath));
        }

        $workspace = $request->workspace;
        $dump = $this->dumper->dump($workspace);
        $metadataPath = $workspace->path(WorkspaceArea::Archive, ArchiveMetadata::FILE_NAME);
        $zipPath = $workspace->path(WorkspaceArea::Archive, self::ARCHIVE_NAME);

        try {
            $metadata = $this->metadata->build($request, $dump, self::DATABASE_ENTRY, $this->includeEnv);

            if (@file_put_contents($metadataPath, (string) json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
                throw new ArchiveCreationFailed('The archive metadata could not be written to the workspace.');
            }

            @chmod($metadataPath, 0600);

            $this->buildZip($zipPath, $request->password(), array_filter([
                self::DATABASE_ENTRY => $dump->path,
                self::ENV_ENTRY => $this->includeEnv ? $request->envPath : null,
                ArchiveMetadata::FILE_NAME => $metadataPath,
            ]));
        } catch (QurabaBackupException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ArchiveCreationFailed('Building the encrypted archive failed: '.$this->redactor->redact($exception->getMessage()));
        } finally {
            // Plaintext copies never outlive the archive build.
            foreach ([$dump->path, $metadataPath] as $plaintext) {
                if (is_file($plaintext)) {
                    @unlink($plaintext);
                }
            }
        }

        @chmod($zipPath, 0600);

        return new CreatedArchive($zipPath, self::DATABASE_ENTRY, $metadata);
    }

    /**
     * @param  array<string, string>  $entries  name in archive => local path
     */
    private function buildZip(string $zipPath, #[\SensitiveParameter] string $password, array $entries): void
    {
        $scoped = SpatieConfig::fromArray([
            'backup' => [
                'name' => 'quraba-backup',
                'password' => $password,
                'encryption' => Encryption::Aes256->value,
                'destination' => [
                    'compression_method' => ZipArchive::CM_DEFLATE,
                    'compression_level' => 6,
                    'filename_prefix' => '',
                    'disks' => [],
                ],
            ],
        ]);

        $this->container->instance(SpatieConfig::class, $scoped);

        try {
            $zip = new Zip($zipPath);

            foreach ($entries as $name => $path) {
                $zip->add($path, $name);
            }

            $zip->close();
        } finally {
            // Never leave this run's password in the container; the host's own
            // (scoped) Spatie binding, if any, is rebuilt from its config on
            // next use.
            $this->container->forgetInstance(SpatieConfig::class);
            unset($scoped);
        }
    }
}
