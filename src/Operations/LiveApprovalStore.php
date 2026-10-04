<?php

declare(strict_types=1);

namespace Quraba\Backup\Operations;

use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Support\PrivateFile;
use Throwable;

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
        $stored = $this->readPrivate($path);
        if ($stored !== $data) {
            throw new \RuntimeException('The issued approval differs from its private file.');
        }
    }

    public function linkRestore(string $operationUuid, string $restoreUuid): void
    {
        $operationUuid = Identifiers::assertUuid($operationUuid, 'The operation UUID');
        $restoreUuid = Identifiers::assertUuid($restoreUuid, 'The restore UUID');
        $path = $this->consumedPath($operationUuid);
        $data = $this->readPrivate($path);
        if (($data['operation_uuid'] ?? null) !== $operationUuid || isset($data['restore_uuid'])) {
            throw new \RuntimeException('Consumed live approval evidence cannot be linked.');
        }
        $data['restore_uuid'] = $restoreUuid;
        $this->writeAndVerify($path, $data);
    }

    /** A non-authoritative request outcome; RestoreJournal remains physical truth. */
    public function recordOutcome(string $operationUuid, string $status, ?string $restoreUuid): void
    {
        $operationUuid = Identifiers::assertUuid($operationUuid, 'The operation UUID');
        if (! in_array($status, ['completed', 'failed', 'indeterminate'], true)) {
            throw new \InvalidArgumentException('Invalid panel restore outcome.');
        }
        $path = $this->consumedPath($operationUuid);
        $data = $this->readPrivate($path);
        if (($data['operation_uuid'] ?? null) !== $operationUuid || isset($data['worker_outcome'])) {
            throw new \RuntimeException('Consumed live approval evidence cannot record another outcome.');
        }
        if ($restoreUuid !== null) {
            $restoreUuid = Identifiers::assertUuid($restoreUuid, 'The restore UUID');
            if (($data['restore_uuid'] ?? null) !== $restoreUuid) {
                throw new \RuntimeException('The restore outcome does not match the linked journal.');
            }
        }
        $data['worker_outcome'] = ['status' => $status, 'recorded_at' => now('UTC')->toIso8601String()];
        $this->writeAndVerify($path, $data);
    }

    public function linkedRestoreUuid(string $operationUuid): ?string
    {
        $operationUuid = Identifiers::assertUuid($operationUuid, 'The operation UUID');
        $data = $this->readPrivate($this->consumedPath($operationUuid));
        $value = $data['restore_uuid'] ?? null;

        return is_string($value) ? Identifiers::assertUuid($value, 'The restore UUID') : null;
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
            try {
                $data = $this->readPrivate($path);
            } catch (Throwable) {
                $rows[] = ['operation_uuid' => basename($path, '.consumed'), 'evidence_error' => 'Unreadable private request evidence'];

                continue;
            }
            if (is_string($data['operation_uuid'] ?? null)) {
                unset($data['nonce']);
                $rows[] = [
                    'operation_uuid' => $data['operation_uuid'],
                    'restore_uuid' => is_string($data['restore_uuid'] ?? null) ? $data['restore_uuid'] : null,
                    'source_run_uuid' => is_string($data['source_run_uuid'] ?? null) ? $data['source_run_uuid'] : null,
                    'restore_profile' => is_string($data['restore_profile'] ?? null) ? $data['restore_profile'] : null,
                    'worker_outcome' => is_array($data['worker_outcome'] ?? null) ? $data['worker_outcome'] : null,
                ];
            }
        }

        return $rows;
    }

    private function consumedPath(string $operationUuid): string
    {
        $path = $this->paths->root.'/approvals/'.$operationUuid.'.consumed';
        if (is_link($path) || ! is_file($path)) {
            throw new \RuntimeException('Consumed live approval evidence is missing.');
        }
        self::assertPrivateDirectory(dirname($path));

        return $path;
    }

    /** @return array<string, mixed> */
    private function readPrivate(string $path): array
    {
        if (is_link($path) || ! is_file($path)) {
            throw new \RuntimeException('Private approval evidence is missing.');
        }
        self::assertPrivateDirectory(dirname($path));
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Private approval evidence cannot be opened.');
        }
        try {
            PrivateFile::assertStillPrivate($path, $handle);
            $contents = stream_get_contents($handle, 4097);
        } finally {
            fclose($handle);
        }
        if (! is_string($contents) || strlen($contents) > 4096) {
            throw new \RuntimeException('Private approval evidence is invalid.');
        }
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Private approval evidence is malformed.');
        }
        $result = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new \RuntimeException('Private approval evidence has invalid keys.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function writeAndVerify(string $path, array $data): void
    {
        RestoreJournalStore::atomicWrite($path, json_encode($data, JSON_THROW_ON_ERROR));
        if ($this->readPrivate($path) !== $data) {
            throw new \RuntimeException('The private approval evidence changed during its update.');
        }
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
