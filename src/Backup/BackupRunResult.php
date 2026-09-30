<?php

declare(strict_types=1);

namespace Quraba\Backup\Backup;

use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Models\BackupRun;

final readonly class BackupRunResult
{
    public const int EXIT_COMPLETED = 0;

    public const int EXIT_FAILED = 1;

    public const int EXIT_PARTIAL = 2;

    public const int EXIT_INDETERMINATE = 3;

    /**
     * @param  array<string, ComponentOutcome>  $components
     * @param  list<string>  $warnings
     */
    public function __construct(
        public BackupRun $run,
        public BackupStatus $status,
        public array $components,
        public array $warnings,
        public ?string $manifestLocator,
        public bool $quiescenceReleaseFailed = false,
    ) {}

    public function exitCode(): int
    {
        if ($this->quiescenceReleaseFailed) {
            return self::EXIT_FAILED;
        }

        return match ($this->status) {
            BackupStatus::Completed => self::EXIT_COMPLETED,
            BackupStatus::Partial => self::EXIT_PARTIAL,
            BackupStatus::Indeterminate => self::EXIT_INDETERMINATE,
            default => self::EXIT_FAILED,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_uuid' => $this->run->uuid,
            'profile' => $this->run->profile->value,
            'trigger' => $this->run->trigger->value,
            'status' => $this->status->value,
            'consistency' => $this->run->consistency->value,
            'components' => array_map(static fn (ComponentOutcome $outcome): array => $outcome->toArray(), $this->components),
            'manifest' => $this->manifestLocator,
            'failure_code' => $this->run->failure_code,
            'failure_message' => $this->run->failure_message,
            'warnings' => $this->warnings,
            'quiescence_release_failed' => $this->quiescenceReleaseFailed,
        ];
    }
}
