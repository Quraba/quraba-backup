<?php

declare(strict_types=1);

namespace Quraba\Backup\Operations;

use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Support\PrivateFile;

final readonly class LiveApprovalStore
{
    public function __construct(private PackagePaths $paths) {}

    public function issue(PendingOperation $operation, string $nonce): void
    {
        Identifiers::assertUuid($operation->uuid, 'The operation UUID');
        $root = PackagePaths::ensureDirectory($this->paths->root);
        $directory = PackagePaths::ensureDirectory($root.'/approvals');
        PathGuard::assertRealWithin($directory, $root);
        self::assertPrivateDirectory($directory);
        $path = $directory.'/'.$operation->uuid.'.json';
        if (is_link($path) || file_exists($path)) {
            throw new \RuntimeException('The approval already exists.');
        }
        $data = [
            'operation_uuid' => $operation->uuid,
            'source_run_uuid' => $operation->source_run_uuid,
            'restore_profile' => $operation->restore_profile?->value,
            'actor_type' => $operation->actor_type,
            'actor_id' => $operation->actor_id,
            'issued_at' => now('UTC')->toIso8601String(),
            'expires_at' => now('UTC')->addMinutes(10)->toIso8601String(),
            'nonce' => $nonce,
        ];
        RestoreJournalStore::atomicWrite($path, json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function linkRestore(string $operationUuid, string $restoreUuid): void
    {
        $operationUuid = Identifiers::assertUuid($operationUuid, 'The operation UUID');
        $restoreUuid = Identifiers::assertUuid($restoreUuid, 'The restore UUID');
        $path = $this->paths->root.'/approvals/'.$operationUuid.'.consumed';
        if (is_link($path) || ! is_file($path)) {
            throw new \RuntimeException('Consumed live approval evidence is missing.');
        }
        self::assertPrivateDirectory(dirname($path));
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Consumed live approval evidence cannot be opened.');
        }
        try {
            PrivateFile::assertStillPrivate($path, $handle);
            $contents = stream_get_contents($handle, 4097);
        } finally {
            fclose($handle);
        }
        if (! is_string($contents) || strlen($contents) > 4096) {
            throw new \RuntimeException('Consumed live approval evidence is invalid.');
        }
        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['operation_uuid'] ?? null) !== $operationUuid || isset($data['restore_uuid'])) {
            throw new \RuntimeException('Consumed live approval evidence cannot be linked.');
        }
        $data['restore_uuid'] = $restoreUuid;
        RestoreJournalStore::atomicWrite($path, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        $directory = $this->paths->root.'/approvals';
        if (! is_dir($directory) || is_link($directory)) {
            return [];
        }
        $rows = [];
        foreach (glob($directory.'/*.consumed') ?: [] as $path) {
            if (is_link($path)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && is_string($data['operation_uuid'] ?? null)) {
                unset($data['nonce']);
                $rows[] = [
                    'operation_uuid' => $data['operation_uuid'],
                    'restore_uuid' => is_string($data['restore_uuid'] ?? null) ? $data['restore_uuid'] : null,
                    'source_run_uuid' => is_string($data['source_run_uuid'] ?? null) ? $data['source_run_uuid'] : null,
                ];
            }
        }

        return $rows;
    }

    public static function assertPrivateDirectory(string $directory): void
    {
        if (is_link($directory) || ! is_dir($directory)) {
            throw new \RuntimeException('The approval directory is not private.');
        }
        if (PathGuard::isWindows()) {
            return;
        }
        clearstatcache(true, $directory);
        $mode = fileperms($directory);
        $owner = fileowner($directory);
        $ownerMismatch = function_exists('posix_geteuid') && ($owner === false || $owner !== posix_geteuid());
        if ($mode === false || ($mode & 0777) !== 0700 || $ownerMismatch) {
            throw new \RuntimeException('The approval directory must be owned by this user with mode 0700.');
        }
    }
}
