<?php

declare(strict_types=1);

namespace Quraba\Backup\Backup;

use Quraba\Backup\Domain\FailureDetails;

/**
 * The result of one backup component (application archive or media snapshot).
 *
 * `uncertain` means the physical outcome could not be proven (for example a
 * timeout after Restic may already have written a snapshot); the component's
 * artifact is left non-terminal for reconciliation instead of being declared
 * failed.
 */
final readonly class ComponentOutcome
{
    public const string VERIFIED = 'verified';

    public const string FAILED = 'failed';

    public const string UNCERTAIN = 'uncertain';

    private function __construct(
        public string $state,
        public ?FailureDetails $failure = null,
        public ?string $identity = null,
    ) {}

    public static function verified(string $identity): self
    {
        return new self(self::VERIFIED, null, $identity);
    }

    public static function failed(FailureDetails $failure): self
    {
        return new self(self::FAILED, $failure);
    }

    public static function uncertain(FailureDetails $failure): self
    {
        return new self(self::UNCERTAIN, $failure);
    }

    public function isVerified(): bool
    {
        return $this->state === self::VERIFIED;
    }

    /**
     * @return array{state: string, identity: string|null, failure_code: string|null, failure_message: string|null}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'identity' => $this->identity,
            'failure_code' => $this->failure?->code,
            'failure_message' => $this->failure?->message,
        ];
    }
}
