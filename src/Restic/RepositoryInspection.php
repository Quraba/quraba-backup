<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

/**
 * The observed state of the configured repository. Messages are redacted.
 */
final readonly class RepositoryInspection
{
    public function __construct(
        public RepositoryState $state,
        public ?string $location,
        public string $message,
        public ?string $repositoryId = null,
        public ?int $formatVersion = null,
        public ?string $failureCode = null,
    ) {}

    /**
     * @return array{state: string, location: string|null, message: string, repository_id: string|null, format_version: int|null, failure_code: string|null}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'location' => $this->location,
            'message' => $this->message,
            'repository_id' => $this->repositoryId,
            'format_version' => $this->formatVersion,
            'failure_code' => $this->failureCode,
        ];
    }
}
