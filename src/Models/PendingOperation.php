<?php

declare(strict_types=1);

namespace Quraba\Backup\Models;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Quraba\Backup\Enums\PendingOperationStatus;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Models\Casts\UtcDateTime;
use Quraba\Backup\Security\SecretRedactor;

/**
 * @property int $id
 * @property string $uuid
 * @property PendingOperationType $type
 * @property PendingOperationStatus $status
 * @property string $actor_type
 * @property string $actor_id
 * @property string|null $source_run_uuid
 * @property RestoreProfile|null $restore_profile
 * @property string|null $approval_nonce_hash
 * @property string $idempotency_key
 * @property string|null $related_uuid
 * @property CarbonImmutable|null $requested_at
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $heartbeat_at
 * @property CarbonImmutable|null $finished_at
 * @property array<string, mixed>|null $result
 * @property string|null $failure_code
 * @property string|null $failure_message
 */
final class PendingOperation extends PackageModel
{
    protected $table = 'quraba_pending_operations';

    protected $fillable = [];

    public static function request(
        PendingOperationType $type,
        string $actorType,
        string $actorId,
        ?string $sourceUuid,
        ?RestoreProfile $profile,
        string $idempotencyKey,
        ?string $nonceHash = null,
    ): self {
        $operation = new self;
        $operation->uuid = (string) Str::uuid7();
        $operation->type = $type;
        $operation->status = PendingOperationStatus::Pending;
        $operation->actor_type = $actorType;
        $operation->actor_id = $actorId;
        $operation->source_run_uuid = $sourceUuid;
        $operation->restore_profile = $profile;
        $operation->idempotency_key = $idempotencyKey;
        $operation->approval_nonce_hash = $nonceHash;
        $operation->requested_at = CarbonImmutable::now('UTC');
        $operation->save();

        return $operation;
    }

    /** @param array<string, mixed> $attributes */
    public function move(PendingOperationStatus $from, PendingOperationStatus $to, array $attributes = []): bool
    {
        $now = CarbonImmutable::now('UTC');
        $changed = $this->newQuery()->whereKey($this->getKey())->where('uuid', $this->uuid)->where('status', $from->value)->update([
            'status' => $to->value,
            'updated_at' => $now,
            ...$attributes,
        ]);
        if ($changed === 1) {
            $this->refresh();
        }

        return $changed === 1;
    }

    /** @param array<string, mixed> $result */
    public function finish(PendingOperationStatus $status, array $result = [], ?string $code = null, ?string $message = null): void
    {
        if (! in_array($status, [PendingOperationStatus::Completed, PendingOperationStatus::Failed, PendingOperationStatus::Indeterminate, PendingOperationStatus::Interrupted], true)) {
            throw new \InvalidArgumentException('An operation must finish in a terminal state.');
        }
        $redactor = app(SecretRedactor::class);
        $this->move(PendingOperationStatus::Running, $status, [
            'finished_at' => CarbonImmutable::now('UTC'),
            'heartbeat_at' => CarbonImmutable::now('UTC'),
            'result' => $redactor->redactArray($result),
            'failure_code' => $code,
            'failure_message' => $message === null ? null : $redactor->redact($message),
        ]);
    }

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'type' => PendingOperationType::class,
            'status' => PendingOperationStatus::class,
            'restore_profile' => RestoreProfile::class,
            'result' => 'array',
            'requested_at' => UtcDateTime::class,
            'claimed_at' => UtcDateTime::class,
            'started_at' => UtcDateTime::class,
            'heartbeat_at' => UtcDateTime::class,
            'finished_at' => UtcDateTime::class,
        ];
    }
}
