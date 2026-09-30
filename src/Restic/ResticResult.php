<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Quraba\Backup\Exceptions\QurabaBackupException;

/**
 * Structured outcome of one Restic process. Both streams have already been
 * redacted by the runner; nothing in a result may contain a secret.
 */
final readonly class ResticResult
{
    public const int EXIT_SUCCESS = 0;

    public const int EXIT_FATAL = 1;

    /** Backup finished but could not read some source data (snapshot is incomplete). */
    public const int EXIT_INCOMPLETE = 3;

    public const int EXIT_REPOSITORY_MISSING = 10;

    public const int EXIT_REPOSITORY_LOCKED = 11;

    public const int EXIT_WRONG_PASSWORD = 12;

    public function __construct(
        public ResticOperation $operation,
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $durationSeconds,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === self::EXIT_SUCCESS;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        return ResticJson::document($this->stdout);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function jsonLines(): array
    {
        return ResticJson::lines($this->stdout);
    }

    /**
     * Messages of one `message_type` (e.g. "summary", "error", "status").
     *
     * @return list<array<array-key, mixed>>
     */
    public function messages(string $type): array
    {
        return ResticJson::ofType($this->jsonLines(), $type);
    }

    /**
     * The final `summary` message of a JSON-mode backup/restore, if present.
     *
     * @return array<array-key, mixed>|null
     */
    public function summary(): ?array
    {
        $summaries = $this->messages('summary');

        return $summaries === [] ? null : $summaries[array_key_last($summaries)];
    }

    /**
     * @throws QurabaBackupException
     */
    public function throwIfFailed(): self
    {
        if (! $this->successful()) {
            throw ResticErrorClassifier::classify($this);
        }

        return $this;
    }
}
