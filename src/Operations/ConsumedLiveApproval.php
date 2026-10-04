<?php

declare(strict_types=1);

namespace Quraba\Backup\Operations;

use Carbon\CarbonImmutable;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Support\PrivateFile;

/** One-use proof issued only by the private approval envelope. */
final class ConsumedLiveApproval
{
    private bool $authorizationIssued = false;

    private function __construct(public readonly string $operationUuid) {}

    public function claimForAuthorization(): void
    {
        if ($this->authorizationIssued) {
            throw new \RuntimeException('The consumed approval has already issued authorization.');
        }
        $this->authorizationIssued = true;
    }

    public static function consume(PendingOperation $operation, PackagePaths $paths): self
    {
        Identifiers::assertUuid($operation->uuid, 'The operation UUID');
        if ($operation->type !== PendingOperationType::LiveRestore) {
            throw new \RuntimeException('The operation is not a live restore.');
        }
        $directory = $paths->root.'/approvals';
        if (is_link($directory) || ! is_dir($directory)) {
            throw new \RuntimeException('The private approval directory is unavailable.');
        }
        PathGuard::assertRealWithin($directory, $paths->root);
        LiveApprovalStore::assertPrivateDirectory($directory);
        $from = $directory.'/'.$operation->uuid.'.json';
        $used = $directory.'/'.$operation->uuid.'.consumed';
        if (is_link($from) || is_link($used) || is_file($used) || ! is_file($from)) {
            throw new \RuntimeException('The one-time approval is absent or already consumed.');
        }
        $handle = @fopen($from, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The one-time approval cannot be opened.');
        }
        try {
            PrivateFile::assertStillPrivate($from, $handle);
            $contents = stream_get_contents($handle, 4097);
        } finally {
            fclose($handle);
        }
        if (! is_string($contents) || strlen($contents) > 4096) {
            throw new \RuntimeException('The one-time approval is invalid.');
        }
        $data = json_decode($contents, true);
        if (! is_array($data)
            || ($data['operation_uuid'] ?? null) !== $operation->uuid
            || ($data['source_run_uuid'] ?? null) !== $operation->source_run_uuid
            || ($data['restore_profile'] ?? null) !== $operation->restore_profile?->value
            || ($data['actor_type'] ?? null) !== $operation->actor_type
            || ($data['actor_id'] ?? null) !== $operation->actor_id
            || ! is_string($data['nonce'] ?? null)
            || ! hash_equals((string) $operation->approval_nonce_hash, hash('sha256', $data['nonce']))
            || ! is_string($data['expires_at'] ?? null)
            || CarbonImmutable::parse($data['expires_at'])->isPast()) {
            throw new \RuntimeException('The one-time approval does not match this request or has expired.');
        }
        if (! @rename($from, $used)) {
            throw new \RuntimeException('The one-time approval could not be consumed.');
        }

        return new self($operation->uuid);
    }
}
