<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Live;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Operations\ConsumedLiveApproval;

/**
 * Proof that an operator explicitly authorized a LIVE restore.
 *
 * The live restore service cannot be started without one. CLI callers supply
 * BOTH the force flag and the exact configured phrase. The panel worker must
 * instead pass a consumed, one-time private approval. An interactive yes/no
 * is insufficient; a phrase differing by case or whitespace is insufficient.
 */
final readonly class LiveRestoreAuthorization
{
    public const string DEFAULT_PHRASE = 'RESTORE_APPLICATION';

    private function __construct(
        public bool $cleanHost,
        public ?string $queuedOperationUuid = null,
        private ?string $queuedSourceUuid = null,
        private ?RestoreProfile $queuedProfile = null,
    ) {}

    public static function phrase(Repository $config): string
    {
        $phrase = $config->get('quraba-backup.restore.confirmation_phrase', self::DEFAULT_PHRASE);

        // A blank configured phrase must never make every restore "confirmed".
        return is_string($phrase) && strlen(trim($phrase)) >= 8 ? $phrase : self::DEFAULT_PHRASE;
    }

    /**
     * @throws RestoreFailed
     */
    public static function confirm(Repository $config, bool $force, mixed $confirmation, bool $cleanHost = false): self
    {
        $phrase = self::phrase($config);

        if (! $force) {
            throw RestoreFailed::confirmationRequired('--force was not given');
        }

        if (! is_string($confirmation) || ! hash_equals($phrase, $confirmation)) {
            throw RestoreFailed::confirmationRequired(sprintf('re-run with --force --confirm=%s (the phrase must match exactly)', $phrase));
        }

        return new self($cleanHost);
    }

    /** @internal Only the scheduler processor passes a consumed private approval. */
    public static function fromConsumedApproval(ConsumedLiveApproval $approval): self
    {
        $approval->claimForAuthorization();

        return new self(false, $approval->operationUuid, $approval->sourceRunUuid, $approval->restoreProfile);
    }

    public function assertMatches(string $runUuid, RestoreProfile $profile): void
    {
        if ($this->queuedOperationUuid === null) {
            return; // The existing CLI --force + phrase proof is unchanged.
        }
        if ($this->queuedSourceUuid === null || ! hash_equals($this->queuedSourceUuid, $runUuid) || $this->queuedProfile !== $profile) {
            throw RestoreFailed::confirmationRequired('the consumed panel approval does not match the exact source and restore scope');
        }
    }
}
