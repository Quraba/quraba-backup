<?php

declare(strict_types=1);

namespace Quraba\Backup\Recovery;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Manifest\ManifestStore;
use Quraba\Backup\Models\RepositoryIdentityRecord;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Support\LocalCatalog;
use Throwable;

/**
 * The external recovery materials an operator must hold OUTSIDE this server
 * to rebuild the application on a clean host, and whether each one is
 * configured here right now.
 *
 * It reports "configured" or "missing" and where to set it. It never
 * prints, logs or returns a secret value. The repository ID is an identity,
 * not a secret, and is shown when it is known.
 */
final readonly class RecoveryChecklist
{
    public function __construct(
        private Repository $config,
        private IdentityResolver $identities,
        private ManifestStore $manifests,
        private ResticRunner $restic,
        private LocalCatalog $catalog,
    ) {}

    /**
     * @param  bool  $remote  also read the repository identity from the remote manifests
     * @return array{ready: bool, items: list<array{key: string, label: string, state: string, required: bool, guidance: string}>, repository_id: ?string, repository_id_source: string}
     */
    public function evaluate(bool $remote = false): array
    {
        $appId = $this->config->get('quraba-backup.app_id');
        $items = [
            $this->item('QURABA_BACKUP_APP_ID', 'Application ID', is_string($appId) && Identifiers::isUuid($appId), true, 'The stable UUID of this application. Every remote object lives below it; without it the backups cannot be found.'),
            $this->item('QURABA_BACKUP_B2_ENDPOINT', 'B2 S3 endpoint', $this->filled('quraba-backup.storage.b2.endpoint'), true, 'For example https://s3.us-west-004.backblazeb2.com.'),
            $this->item('QURABA_BACKUP_B2_BUCKET', 'B2 bucket', $this->filled('quraba-backup.storage.b2.bucket'), true, 'The bucket that holds archives, manifests and the Restic repository.'),
            $this->item('QURABA_BACKUP_B2_KEY_ID', 'B2 key ID', $this->filled('quraba-backup.storage.b2.key_id'), true, 'The key ID of a bucket-scoped application key.'),
            $this->item('QURABA_BACKUP_B2_APPLICATION_KEY', 'B2 application key', $this->filled('quraba-backup.storage.b2.application_key'), true, 'The secret of that application key. Shown by Backblaze only once.'),
            $this->item('QURABA_BACKUP_ARCHIVE_PASSWORD', 'Archive password', $this->filled('quraba-backup.archive.password'), true, 'Decrypts the application archives (database dump and .env). Nobody can open them without it.'),
            $this->item('QURABA_BACKUP_RESTIC_PASSWORD_FILE', 'Restic repository password', $this->resticPassword(), true, 'A private file holding the Restic repository password. Without it no media can be restored.'),
            $this->item('QURABA_BACKUP_PREFIX', 'Remote prefix', $this->filled('quraba-backup.storage.b2.prefix'), true, 'The object prefix (default quraba-backup); it must be the one the backups were written under.'),
            $this->item('RESTIC_BINARY', 'Restic binary', $this->resticBinary(), true, 'Install it with "php artisan quraba:backup:install-restic" (pinned and checksum-verified).'),
            $this->item('APP_KEY', 'Application key', $this->filled('app.key'), false, 'Comes back with the archived .env (quraba:backup:bootstrap-env). A restore refuses a backup whose APP_KEY fingerprint differs.'),
            $this->item('QURABA_BACKUP_RECOVERY_SECRETS_ACKNOWLEDGED', 'Secrets stored off this server', (bool) $this->config->get('quraba-backup.recovery_secrets_acknowledged', false), false, 'Set it to true once every item above is kept somewhere that survives the loss of this server.'),
        ];

        [$repositoryId, $source] = $this->repositoryIdentity($remote);
        $ready = true;

        foreach ($items as $item) {
            $ready = $ready && (! $item['required'] || $item['state'] === 'configured');
        }

        return ['ready' => $ready, 'items' => $items, 'repository_id' => $repositoryId, 'repository_id_source' => $source];
    }

    /**
     * @return array{key: string, label: string, state: string, required: bool, guidance: string}
     */
    private function item(string $key, string $label, bool $configured, bool $required, string $guidance): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $configured ? 'configured' : 'missing', 'required' => $required, 'guidance' => $guidance];
    }

    private function filled(string $key): bool
    {
        $value = $this->config->get($key);

        return is_string($value) && trim($value) !== '';
    }

    private function resticPassword(): bool
    {
        $file = $this->config->get('restic.password_file');

        return is_string($file) && $file !== '' && is_file($file) && is_readable($file) && (int) @filesize($file) > 0;
    }

    private function resticBinary(): bool
    {
        try {
            $this->restic->binary();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function repositoryIdentity(bool $remote): array
    {
        try {
            $identity = $this->identities->current();

            if ($this->catalog->has('quraba_backup_repository_identities')) {
                $record = RepositoryIdentityRecord::query()->where('app_id', $identity->appId)->where('environment', $identity->environment)->first();

                if ($record !== null) {
                    return [$record->repository_id, 'local catalog'];
                }
            }

            if ($remote) {
                $id = $this->manifests->repositoryIdConsensus($identity);

                return [$id, $id === null ? 'no remote manifest records a repository' : 'remote manifests'];
            }
        } catch (Throwable) {
            return [null, 'could not be determined'];
        }

        return [null, 'unknown locally (use --remote to read it from the manifests)'];
    }
}
