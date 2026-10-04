<?php

declare(strict_types=1);

namespace Quraba\Backup\Operations;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Enums\PendingOperationStatus;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Models\BackupSetting;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Restore\Live\LiveRestoreAuthorization;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class PendingOperationRequest
{
    public function __construct(
        private Repository $config,
        private LiveApprovalStore $approvals,
        private RestoreJournalStore $journals,
        private QuiescenceProvider $quiescence,
    ) {}

    public function submit(
        PendingOperationType $type,
        Authenticatable $actor,
        ?string $sourceUuid = null,
        ?RestoreProfile $profile = null,
        ?string $phrase = null,
    ): PendingOperation {
        if (! OperationAccess::allows($actor, $type->ability())) {
            throw new AccessDeniedHttpException;
        }
        if (! $this->config->get('quraba-backup.filament.pending_enabled')) {
            throw new \DomainException('Panel background operations are disabled.');
        }
        if (! $this->config->get('quraba-backup.enabled', true)) {
            throw new \DomainException('Quraba Backup is disabled.');
        }
        if (in_array($type, [PendingOperationType::DryRestore, PendingOperationType::LiveRestore], true)) {
            if ($sourceUuid === null || $profile === null) {
                throw new \InvalidArgumentException('An exact source and restore profile are required.');
            }
            $sourceUuid = Identifiers::assertUuid($sourceUuid, 'The source run UUID');
        } elseif ($sourceUuid !== null || $profile !== null) {
            throw new \InvalidArgumentException('This operation does not accept a restore source.');
        }
        if ($type === PendingOperationType::LiveRestore) {
            $this->validateLive($sourceUuid, $profile, $phrase);
        }
        $actorType = $actor::class;
        $identifier = $actor->getAuthIdentifier();
        $actorId = is_string($identifier) || is_int($identifier) ? (string) $identifier : '';
        if ($actorId === '') {
            throw new \DomainException('The requesting actor has no stable identifier.');
        }
        $connection = (new BackupSetting)->getConnection();

        return $connection->transaction(function () use ($type, $actorType, $actorId, $sourceUuid, $profile): PendingOperation {
            BackupSetting::query()->insertOrIgnore(['key' => 'filament.operation_request_guard', 'value' => '{"v":true}', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            BackupSetting::query()->where('key', 'filament.operation_request_guard')->lockForUpdate()->firstOrFail();
            $open = PendingOperation::query()->whereIn('status', array_map(static fn (PendingOperationStatus $s): string => $s->value, [PendingOperationStatus::Pending, PendingOperationStatus::Claimed, PendingOperationStatus::Running]));
            if ((clone $open)->count() >= 20) {
                throw new \DomainException('The pending operation limit has been reached.');
            }
            if ((clone $open)->where('type', $type->value)->where('source_run_uuid', $sourceUuid)->where('restore_profile', $profile?->value)->exists()) {
                throw new \DomainException('An identical operation is already pending or running.');
            }
            $nonce = $type === PendingOperationType::LiveRestore ? bin2hex(random_bytes(32)) : null;
            $operation = PendingOperation::request($type, $actorType, $actorId, $sourceUuid, $profile, (string) Str::uuid(), $nonce === null ? null : hash('sha256', $nonce));
            if ($nonce !== null) {
                $this->approvals->issue($operation, $nonce);
            }

            return $operation;
        });
    }

    private function validateLive(string $sourceUuid, RestoreProfile $profile, ?string $phrase): void
    {
        if (! $this->config->get('quraba-backup.filament.live_restore_enabled')) {
            throw new \DomainException('Panel live restore is disabled.');
        }
        if (! $this->quiescence->claimsQuiescence()) {
            throw new \DomainException('A live restore requires proven quiescence.');
        }
        $all = $this->journals->all();
        if ($all['unreadable'] !== [] || $this->journals->unresolved() !== []) {
            throw new \DomainException('An unresolved or unreadable restore journal blocks a new live restore.');
        }
        $dry = PendingOperation::query()->where('type', PendingOperationType::DryRestore->value)
            ->where('source_run_uuid', $sourceUuid)
            ->where('restore_profile', $profile->value)
            ->latest('id')->first();
        if ($dry === null || $dry->status !== PendingOperationStatus::Completed
            || $dry->finished_at === null || $dry->finished_at->lessThan(now('UTC')->subDay())
            || ($dry->result['ok'] ?? false) !== true || ($dry->result['blockers'] ?? []) !== []) {
            throw new \DomainException('A successful dry restore of this exact source and scope is required within 24 hours.');
        }
        if (! is_string($phrase) || ! hash_equals(LiveRestoreAuthorization::phrase($this->config), $phrase)) {
            throw new \DomainException('The live restore confirmation did not match.');
        }
    }
}
